<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Command\CopiarArquivosAcervoCommand;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Repository\TenantRepository;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoArmazenado;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\ArmazenamentoEspiao;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:acervo:copiar-arquivos` não tinha teste nenhum até a E2.4B, quando migrou para o
 * armazenamento por chave (D15).
 *
 * A regra que este teste existe para travar: **o acervo do operador é EMPRESTADO.** O comando
 * copia; nunca move, apaga, renomeia nem muda o modo da origem — nem no sucesso, nem na falha. A
 * origem é fotografada inteira (inode, modo, mtime, tamanho, SHA-256) antes e depois, porque
 * "o arquivo ainda existe" passaria também com uma origem movida e copiada de volta.
 *
 * Os testes de disco usam o `ArmazenamentoLocal` do container (`uploads_dir` de teste) — o
 * atalho de mover só existe lá. Os de chave e de falha usam o dublê em memória, que guarda o
 * escopo (R1).
 */
#[CoversClass(CopiarArquivosAcervoCommand::class)]
final class CopiarArquivosAcervoCommandTest extends KernelTestCase
{
    use Factories;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private string $acervo = '';

    /** @var list<ChaveDeArquivo> gravadas no disco de teste, para apagar no fim */
    private array $gravadasNoDisco = [];

    protected function tearDown(): void
    {
        if ($this->gravadasNoDisco !== []) {
            $disco = static::getContainer()->get(ArmazenamentoDeArquivos::class);
            foreach ($this->gravadasNoDisco as $chave) {
                $disco->excluir($chave);
            }
        }

        if ($this->acervo !== '') {
            $this->removerArvore($this->acervo);
        }

        parent::tearDown();
    }

    #[TestDox('D15: copia os arquivos e a origem fica intacta — conteúdo, inode, modo e mtime')]
    public function testCopiaPreservaAOrigemEGravaConteudoIdentico(): void
    {
        self::bootKernel();
        [$tenantId, $pastaId] = $this->pastaDeUmEscritorio();

        $origens = [
            'a.pdf'        => $this->arquivoNoAcervo($pastaId, 'a.pdf', self::PDF . 'a'),
            'B.PDF'        => $this->arquivoNoAcervo($pastaId, 'B.PDF', self::PDF . 'b'),
            'sem_extensao' => $this->arquivoNoAcervo($pastaId, 'sem_extensao', "texto puro\n"),
            'c.pdf'        => $this->arquivoNoAcervo($pastaId, 'PETICOES/c.pdf', self::PDF . 'c'),
        ];
        $antes = array_map($this->foto(...), $origens);

        $tester = $this->tester(static::getContainer()->get(ArmazenamentoDeArquivos::class));
        $tester->execute(['--diretorio' => $this->acervo, '--tenant-id' => (string) $tenantId]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Concluído sem erros', $tester->getDisplay());

        clearstatcache();
        foreach ($origens as $nome => $origem) {
            self::assertSame($antes[$nome], $this->foto($origem), $nome . ': a origem emprestada mudou');
        }

        $linhas = $this->linhasDaPasta($pastaId);
        foreach ($linhas as $linha) {
            $this->gravadasNoDisco[] = ChavesDePasta::documentoPorNome($tenantId, $linha['caminho_arquivo']);
        }
        self::assertSame(['B.PDF', 'a.pdf', 'c.pdf', 'sem_extensao'], array_keys($linhas));

        $disco = static::getContainer()->get(ArmazenamentoDeArquivos::class);
        foreach ($linhas as $nome => $linha) {
            $chave = ChavesDePasta::documentoPorNome($tenantId, $linha['caminho_arquivo']);

            self::assertSame(
                $antes[$nome]['sha256'],
                hash('sha256', $disco->ler($chave)),
                $nome . ': o conteúdo armazenado difere da origem',
            );
            self::assertSame($antes[$nome]['tamanho'], (int) $linha['tamanho_bytes'], $nome . ': tamanho');
            self::assertSame($tenantId, (int) $linha['tenant_id']);
        }

        // Extensão saneada pelo storage (D8): minúscula, e sem extensão vira `bin`, não `hash.`.
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $linhas['a.pdf']['caminho_arquivo']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $linhas['B.PDF']['caminho_arquivo']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.bin$/', $linhas['sem_extensao']['caminho_arquivo']);

        // MIME medido pelo storage sobre o conteúdo gravado.
        self::assertSame('application/pdf', $linhas['a.pdf']['mime_type']);
        self::assertSame('text/plain', $linhas['sem_extensao']['mime_type']);

        self::assertNull($linhas['a.pdf']['secao_id']);
        self::assertNotNull($linhas['c.pdf']['secao_id'], 'arquivo de subpasta vai para a seção');
    }

    #[TestDox('R1: a chave gravada tem o escopo do escritório e é a que a leitura monta do registro')]
    public function testChaveGravadaEhADaLeitura(): void
    {
        self::bootKernel();
        [$tenantId, $pastaId] = $this->pastaDeUmEscritorio();
        $this->arquivoNoAcervo($pastaId, 'a.pdf', self::PDF);

        $memoria = new ArmazenamentoEmMemoria();
        $tester  = $this->tester($memoria);
        $tester->execute(['--diretorio' => $this->acervo, '--tenant-id' => (string) $tenantId]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $documentos = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(PastaDocumento::class)->findBy(['pasta' => $pastaId]);
        self::assertCount(1, $documentos);

        $gravada = $memoria->ultimaGravada();
        self::assertSame(CategoriaDeArquivo::PASTA_DOCUMENTO, $gravada->categoria);
        self::assertSame($tenantId, $gravada->escopo->tenantIdOuNull());
        self::assertTrue($gravada->ehIgualA(ChavesDePasta::documento($documentos[0])), 'gravação e leitura divergem');
        self::assertSame(self::PDF, $memoria->ler($gravada));
    }

    /**
     * Na cópia comum, origem e cópia têm o mesmo tamanho e o mesmo MIME — o teste acima não
     * distingue "medido pelo storage" de "lido da origem". Aqui o storage relata outros valores.
     */
    #[TestDox('tamanho e MIME persistidos são os que o storage relata sobre o que gravou')]
    public function testTamanhoEMimeVemDoStorage(): void
    {
        self::bootKernel();
        [$tenantId, $pastaId] = $this->pastaDeUmEscritorio();
        $this->arquivoNoAcervo($pastaId, 'a.pdf', self::PDF);

        $memoria                  = new ArmazenamentoEmMemoria();
        $memoria->tamanhoRelatado = 4242;
        $memoria->mimeRelatado    = 'application/x-medido-pelo-storage';

        $this->tester($memoria)->execute(['--diretorio' => $this->acervo, '--tenant-id' => (string) $tenantId]);

        $linha = $this->linhasDaPasta($pastaId)['a.pdf'] ?? self::fail('documento não importado');
        self::assertSame(4242, (int) $linha['tamanho_bytes']);
        self::assertSame('application/x-medido-pelo-storage', $linha['mime_type']);
    }

    #[TestDox('falha do storage: nenhuma linha, erro contado, origem intacta e o comando segue')]
    public function testFalhaDoStorageNaoCriaLinha(): void
    {
        self::bootKernel();
        [$tenantId, $pastaId] = $this->pastaDeUmEscritorio();
        $origem = $this->arquivoNoAcervo($pastaId, 'a.pdf', self::PDF);
        $antes  = $this->foto($origem);

        $memoria                = new ArmazenamentoEmMemoria();
        $memoria->falhaAoGravar = new FalhaDeArmazenamento('disco cheio');

        $tester = $this->tester($memoria);
        $tester->execute(['--diretorio' => $this->acervo, '--tenant-id' => (string) $tenantId]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('ERRO: a.pdf: disco cheio', $tester->getDisplay());
        self::assertSame([], $this->linhasDaPasta($pastaId));
        self::assertSame([], $memoria->gravadas);
        clearstatcache();
        self::assertSame($antes, $this->foto($origem));
    }

    /**
     * Falha ao LER a origem (o operador deixou um arquivo sem permissão): conta erro, não cria
     * linha, não toca na origem, e o arquivo seguinte da mesma pasta entra normalmente — o
     * documento a meio caminho não pode ir parar no `flush` da pasta.
     */
    #[TestDox('D15: origem ilegível → erro do item, origem intacta, o resto da pasta é importado')]
    public function testOrigemIlegivelNaoDerrubaAPastaNemTocaNaOrigem(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignora permissão; a falha de leitura não é observável');
        }

        self::bootKernel();
        [$tenantId, $pastaId] = $this->pastaDeUmEscritorio();
        $ilegivel = $this->arquivoNoAcervo($pastaId, 'a.pdf', self::PDF . 'a');
        $legivel  = $this->arquivoNoAcervo($pastaId, 'b.pdf', self::PDF . 'b');
        chmod($ilegivel, 0o000);
        $antes = $this->foto($ilegivel);

        try {
            $tester = $this->tester(static::getContainer()->get(ArmazenamentoDeArquivos::class));
            $tester->execute(['--diretorio' => $this->acervo, '--tenant-id' => (string) $tenantId]);
            clearstatcache();
            $depois = $this->foto($ilegivel);
        } finally {
            chmod($ilegivel, 0o644);
        }

        $linhas = $this->linhasDaPasta($pastaId);
        foreach ($linhas as $linha) {
            $this->gravadasNoDisco[] = ChavesDePasta::documentoPorNome($tenantId, $linha['caminho_arquivo']);
        }

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('ERRO: a.pdf', $tester->getDisplay());
        self::assertSame($antes, $depois, 'a origem ilegível mudou');
        self::assertFileExists($legivel);
        self::assertSame(['b.pdf'], array_keys($linhas));
    }

    /**
     * O pior caso para a origem: o storage chegou a gravar e mesmo assim a operação falhou. Com a
     * origem emprestada nada muda nela; se alguém trocar para `consumirOrigem: true`, o disco
     * move o arquivo do operador para o volume e ele some do acervo — este teste cai.
     */
    #[TestDox('D15: falha depois da escrita no disco não consome a origem, e não cria linha')]
    public function testFalhaDepoisDeGravarNaoConsomeAOrigem(): void
    {
        self::bootKernel();
        [$tenantId, $pastaId] = $this->pastaDeUmEscritorio();
        $primeira = $this->arquivoNoAcervo($pastaId, 'a.pdf', self::PDF . 'a');
        $segunda  = $this->arquivoNoAcervo($pastaId, 'b.pdf', self::PDF . 'b');
        $antes    = [$this->foto($primeira), $this->foto($segunda)];

        $disco                          = static::getContainer()->get(ArmazenamentoDeArquivos::class);
        $falhaNaSegunda                 = new ArmazenamentoEspiao($disco);
        $falhaNaSegunda->depoisDeGravar = static fn (ArquivoArmazenado $gravado, int $ordem): ArquivoArmazenado => $ordem === 2
            ? throw new FalhaDeArmazenamento('falhou depois de gravar')
            : $gravado;

        $tester = $this->tester($falhaNaSegunda);
        $tester->execute(['--diretorio' => $this->acervo, '--tenant-id' => (string) $tenantId]);
        array_push($this->gravadasNoDisco, ...$falhaNaSegunda->gravadas);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('ERRO: b.pdf: falhou depois de gravar', $tester->getDisplay());
        clearstatcache();
        self::assertSame($antes[0], $this->foto($primeira), 'a.pdf: a origem mudou');
        self::assertSame($antes[1], $this->foto($segunda), 'b.pdf: a origem foi consumida pela gravação que falhou');
        self::assertSame(['a.pdf'], array_keys($this->linhasDaPasta($pastaId)));
        self::assertCount(2, $falhaNaSegunda->gravadas);
        self::assertSame(self::PDF . 'b', $disco->ler($falhaNaSegunda->gravadas[1]));
    }

    /**
     * O `flush` é por pasta. Se o banco recusar, os arquivos já gravados daquela pasta ficam sem
     * linha — órfão recuperável (INV-6), não apagado aqui. O comando precisa dizer QUAIS são.
     */
    #[TestDox('banco recusou a pasta: o comando lista as chaves gravadas que ficaram sem linha e relança')]
    public function testFlushRecusadoListaOsArquivosSemLinha(): void
    {
        self::bootKernel();
        [$tenantId, $pastaId] = $this->pastaDeUmEscritorio();
        $origem = $this->arquivoNoAcervo($pastaId, 'a.pdf', self::PDF . 'a');
        $this->arquivoNoAcervo($pastaId, 'b.pdf', self::PDF . 'b');
        $antes = $this->foto($origem);

        $recusa = new class {
            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entidade) {
                    if ($entidade instanceof PastaDocumento) {
                        throw new \RuntimeException('banco recusou a pasta');
                    }
                }
            }
        };
        $eventos = static::getContainer()->get(EntityManagerInterface::class)->getEventManager();
        $eventos->addEventListener([Events::onFlush], $recusa);

        $memoria = new ArmazenamentoEmMemoria();
        $tester  = $this->tester($memoria);

        $recusada = null;
        try {
            $tester->execute(['--diretorio' => $this->acervo, '--tenant-id' => (string) $tenantId]);
        } catch (\RuntimeException $e) {
            $recusada = $e;
        } finally {
            $eventos->removeEventListener([Events::onFlush], $recusa);
        }

        self::assertNotNull($recusada, 'a recusa do banco devia propagar');
        self::assertSame('banco recusou a pasta', $recusada->getMessage());

        $saida = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertCount(2, $memoria->gravadas);
        self::assertStringContainsString('2 arquivo(s) gravados podem ter ficado sem linha', (string) $saida);
        self::assertStringContainsString('confira no banco antes de apagar', (string) $saida);
        foreach ($memoria->gravadas as $gravada) {
            self::assertStringContainsString($gravada->nome, (string) $saida);
            self::assertTrue($memoria->existe($gravada), 'o órfão não é apagado pelo comando');
        }
        clearstatcache();
        self::assertSame($antes, $this->foto($origem));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function tester(ArmazenamentoDeArquivos $armazenamento): CommandTester
    {
        $container = static::getContainer();

        return new CommandTester(new CopiarArquivosAcervoCommand(
            $container->get(EntityManagerInterface::class),
            $container->get(TenantRepository::class),
            $container->get(PastaSecaoRepository::class),
            $armazenamento,
        ));
    }

    /** @return array{0: int, 1: int} */
    private function pastaDeUmEscritorio(): array
    {
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant]);

        $this->acervo = sys_get_temp_dir() . '/acervo-e24b-' . bin2hex(random_bytes(6));

        return [(int) $tenant->getId(), (int) $pasta->getId()];
    }

    private function arquivoNoAcervo(int $pastaId, string $relativo, string $conteudo): string
    {
        $caminho = $this->acervo . '/' . $pastaId . '/' . $relativo;
        if (!is_dir(\dirname($caminho))) {
            mkdir(\dirname($caminho), 0o755, true);
        }
        file_put_contents($caminho, $conteudo);
        // O modo e a data em que um operador deixaria o arquivo — nada que o storage produziria.
        chmod($caminho, 0o640);
        touch($caminho, 1_600_000_000);
        clearstatcache();

        return $caminho;
    }

    /** @return array{ino: int, modo: int, mtime: int, tamanho: int, sha256: string} */
    private function foto(string $caminho): array
    {
        $stat = stat($caminho);
        self::assertIsArray($stat, 'a origem sumiu: ' . $caminho);
        $modo = $stat['mode'] & 0o7777;

        // Arquivo sem permissão de leitura: o hash sai com a permissão devolvida por um instante.
        if (($modo & 0o400) === 0) {
            chmod($caminho, 0o600);
            $sha = (string) hash_file('sha256', $caminho);
            chmod($caminho, $modo);
            touch($caminho, $stat['mtime']);
        } else {
            $sha = (string) hash_file('sha256', $caminho);
        }

        return [
            'ino'     => $stat['ino'],
            'modo'    => $modo,
            'mtime'   => $stat['mtime'],
            'tamanho' => $stat['size'],
            'sha256'  => $sha,
        ];
    }

    /** @return array<string, array<string, mixed>> por nome original, em ordem de byte */
    private function linhasDaPasta(int $pastaId): array
    {
        $linhas = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAllAssociative(
            'SELECT nome_original, caminho_arquivo, tamanho_bytes, mime_type, secao_id, tenant_id
               FROM pasta_documento WHERE pasta_id = :p',
            ['p' => $pastaId],
        );

        $porNome = [];
        foreach ($linhas as $linha) {
            $porNome[(string) $linha['nome_original']] = $linha;
        }

        // Ordem por byte, e não pela collation do banco — que pode pôr `a.pdf` antes de `B.PDF`.
        ksort($porNome, SORT_STRING);

        return $porNome;
    }

    private function removerArvore(string $raiz): void
    {
        if (!is_dir($raiz)) {
            return;
        }

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterador as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @chmod($item->getPathname(), 0o644);
                @unlink($item->getPathname());
            }
        }

        @rmdir($raiz);
    }
}
