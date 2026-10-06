<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Command\PurgarLixeiraCommand;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\UseCase\PurgarLixeiraUseCase;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use App\Shared\Doctrine\Filter\LixeiraFilter;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:documentos:purgar-lixeira` — o ÚNICO caminho pelo qual um documento da aba Documentos sai
 * do disco desde o L7. Herda os três casos INV-6 que `ExcluirDocumentoDaPastaTest` provava para a
 * exclusão física: o arquivo sai DEPOIS do banco confirmar; banco que recusa → nada sai; disco que
 * recusa → o banco é autoritativo, o arquivo fica como órfão registrado e o comando não falha.
 *
 * Mais: `--dry-run` não apaga nada; só o VENCIDO (excluído há mais de N dias) sai; a seção vencida
 * leva a subárvore e os arquivos de dentro; `--limite` recorta a fila e a execução seguinte
 * retoma (idempotente); o filtro da lixeira volta ligado no fim.
 *
 * O armazenamento é o dublê em memória: ele registra QUANDO cada chave saiu, e é por isso que dá
 * para afirmar a ordem em relação ao banco. O relógio é fixo (`MockClock`).
 */
#[CoversClass(PurgarLixeiraCommand::class)]
#[CoversClass(PurgarLixeiraUseCase::class)]
final class PurgarLixeiraCommandTest extends KernelTestCase
{
    private const AGORA = '2026-10-07 12:00:00';

    private EntityManagerInterface $em;
    private ArmazenamentoEmMemoria $memoria;
    private Tenant $tenant;
    private User $autor;
    private Pasta $pasta;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = static::getContainer()->get(EntityManagerInterface::class);
        $this->memoria = new ArmazenamentoEmMemoria();

        $this->tenant = new Tenant();
        $this->tenant->setName('Tenant Purga ' . uniqid());
        $this->em->persist($this->tenant);

        $this->autor = new User();
        $this->autor->setEmail('purga_' . uniqid() . '@test.com');
        $this->autor->setFullName('Autor');
        $this->autor->setRoles(['ROLE_USER']);
        $this->autor->setIsActive(true);
        $this->autor->setPassword('dummy');
        $this->em->persist($this->autor);

        $this->pasta = new Pasta();
        $this->pasta->setNup('PURGA-' . uniqid());
        $this->pasta->setTenant($this->tenant);
        $this->em->persist($this->pasta);
        $this->em->flush();
    }

    #[TestDox('--dry-run conta o que sairia e não apaga nada — nem linha, nem arquivo')]
    public function testDryRunNaoApagaNada(): void
    {
        $vencido = $this->documento('vencido.pdf', naLixeiraHa: 45);
        $recente = $this->documento('recente.pdf', naLixeiraHa: 3);
        $secao   = $this->secao('VENCIDA', naLixeiraHa: 40);
        $dentro  = $this->documento('dentro.pdf', secao: $secao, naLixeiraHa: 40);
        $this->em->clear();

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('modo=simulacao dias=30', $this->saida($tester));
        self::assertStringContainsString('candidatos_secoes=1 candidatos_documentos=2 secoes_removidas=1 documentos_removidos=2 arquivos_removidos=0', $this->saida($tester));
        foreach ([$vencido, $recente, $dentro] as $doc) {
            self::assertTrue($this->existeLinha('pasta_documento', (int) $doc->getId()));
            self::assertTrue($this->memoria->existe($this->chaveDe($doc)));
        }
        self::assertTrue($this->existeLinha('pasta_secao', (int) $secao->getId()));
        self::assertSame([], $this->memoria->excluidas);
    }

    #[TestDox('vencido sai (linha e arquivo), e o arquivo só sai DEPOIS de a linha ter saído do banco; o recente fica')]
    public function testVencidoSaiDepoisDoBancoERecenteFica(): void
    {
        $vencido = $this->documento('vencido.pdf', naLixeiraHa: 31);
        $recente = $this->documento('recente.pdf', naLixeiraHa: 29);
        $vivo    = $this->documento('vivo.pdf');
        $this->em->clear();

        $linhasNoMomentoDaRemocao = [];
        $this->memoria->aoExcluir = function (ChaveDeArquivo $chave) use (&$linhasNoMomentoDaRemocao, $vencido): void {
            // Lido pela MESMA conexão: o DELETE já foi executado quando o arquivo sai (INV-6).
            $linhasNoMomentoDaRemocao[$chave->nome] = $this->contarLinhas('pasta_documento', (int) $vencido->getId());
        };

        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('modo=purga dias=30', $this->saida($tester));
        self::assertStringContainsString('candidatos_secoes=0 candidatos_documentos=1 secoes_removidas=0 documentos_removidos=1 arquivos_removidos=1 arquivos_nao_removidos=0', $this->saida($tester));

        self::assertFalse($this->existeLinha('pasta_documento', (int) $vencido->getId()));
        self::assertFalse($this->memoria->existe($this->chaveDe($vencido)));
        self::assertSame([$vencido->getCaminhoArquivo() => 0], $linhasNoMomentoDaRemocao, 'o arquivo saiu com a linha JÁ fora do banco');

        self::assertTrue($this->existeLinha('pasta_documento', (int) $recente->getId()), 'ainda dentro do prazo');
        self::assertTrue($this->memoria->existe($this->chaveDe($recente)));
        self::assertTrue($this->existeLinha('pasta_documento', (int) $vivo->getId()));
        self::assertTrue($this->memoria->existe($this->chaveDe($vivo)));
        self::assertTrue($this->em->getFilters()->isEnabled(LixeiraFilter::NOME), 'o filtro volta ligado');
    }

    #[TestDox('--dias muda o corte: com --dias=10 o que está há 15 dias sai')]
    public function testDiasMudaOCorte(): void
    {
        $quinze = $this->documento('quinze.pdf', naLixeiraHa: 15);
        $cinco  = $this->documento('cinco.pdf', naLixeiraHa: 5);
        $this->em->clear();

        $tester = $this->tester();
        $tester->execute(['--dias' => '10']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFalse($this->existeLinha('pasta_documento', (int) $quinze->getId()));
        self::assertTrue($this->existeLinha('pasta_documento', (int) $cinco->getId()));
    }

    #[TestDox('banco que recusa a purga: nenhuma linha sai, nenhum arquivo sai, o comando falha')]
    public function testBancoQueRecusaNaoApagaArquivos(): void
    {
        $vencido = $this->documento('vencido.pdf', naLixeiraHa: 60);
        $this->em->clear();

        $recusa = new class {
            public int $recusas = 0;

            public function onFlush(OnFlushEventArgs $args): void
            {
                if ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityDeletions() !== []) {
                    ++$this->recusas;

                    throw new \LogicException('banco recusou a purga');
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::onFlush], $recusa);

        try {
            $tester = $this->tester();
            $tester->execute([]);
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::onFlush], $recusa);
        }

        self::assertSame(1, $recusa->recusas);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('A purga parou: banco recusou a purga', $this->saida($tester));
        self::assertTrue($this->existeLinha('pasta_documento', (int) $vencido->getId()));
        self::assertTrue($this->memoria->existe($this->chaveDe($vencido)), 'nada sai do disco antes de o banco confirmar');
        self::assertSame([], $this->memoria->excluidas);
        self::assertTrue($this->em->getFilters()->isEnabled(LixeiraFilter::NOME), 'o filtro volta ligado mesmo com a exceção');
    }

    #[TestDox('disco que recusa DEPOIS do banco: a linha sai, o arquivo fica como órfão listado, e o comando não falha')]
    public function testDiscoQueFalhaDepoisDoBanco(): void
    {
        $vencido = $this->documento('vencido.pdf', naLixeiraHa: 60);
        $outro   = $this->documento('outro.pdf', naLixeiraHa: 60);
        $this->em->clear();

        $chaveQuebrada = $this->chaveDe($vencido);
        $this->memoria->falhaAoExcluir = static fn (ChaveDeArquivo $chave): ?\Throwable => $chave->comoTexto() === $chaveQuebrada->comoTexto()
            ? new FalhaDeArmazenamento('disco recusou')
            : null;

        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), 'falha física pós-banco não é erro do comando: ' . $tester->getDisplay());
        self::assertStringContainsString('documentos_removidos=2 arquivos_removidos=1 arquivos_nao_removidos=1', $this->saida($tester));
        self::assertStringContainsString($chaveQuebrada->comoTexto(), $this->saida($tester), 'o órfão é listado');
        self::assertFalse($this->existeLinha('pasta_documento', (int) $vencido->getId()), 'o banco é autoritativo');
        self::assertTrue($this->memoria->existe($chaveQuebrada), 'órfão recuperável');
        self::assertFalse($this->existeLinha('pasta_documento', (int) $outro->getId()));
        self::assertFalse($this->memoria->existe($this->chaveDe($outro)), 'a falha de um não segura o outro');
    }

    #[TestDox('seção vencida leva a subárvore inteira (filha e documentos) — linhas e arquivos; a filha também na fila não conta duas vezes')]
    public function testSecaoVencidaLevaASubarvore(): void
    {
        $mae    = $this->secao('MAE', naLixeiraHa: 40);
        $filha  = $this->secao('FILHA', pai: $mae, naLixeiraHa: 40);
        $naMae  = $this->documento('na-mae.pdf', secao: $mae, naLixeiraHa: 40);
        $naFilha = $this->documento('na-filha.pdf', secao: $filha, naLixeiraHa: 40);
        $viva   = $this->secao('VIVA');
        $naViva = $this->documento('na-viva.pdf', secao: $viva);
        $this->em->clear();

        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('candidatos_secoes=2 candidatos_documentos=2 secoes_removidas=2 documentos_removidos=2 arquivos_removidos=2', $this->saida($tester));
        foreach ([$mae, $filha] as $secao) {
            self::assertFalse($this->existeLinha('pasta_secao', (int) $secao->getId()));
        }
        foreach ([$naMae, $naFilha] as $doc) {
            self::assertFalse($this->existeLinha('pasta_documento', (int) $doc->getId()));
            self::assertFalse($this->memoria->existe($this->chaveDe($doc)), 'o arquivo de dentro da árvore sai junto');
        }
        self::assertTrue($this->existeLinha('pasta_secao', (int) $viva->getId()));
        self::assertTrue($this->existeLinha('pasta_documento', (int) $naViva->getId()));
        self::assertTrue($this->memoria->existe($this->chaveDe($naViva)));
    }

    #[TestDox('--limite recorta a fila (mais antigos primeiro); rodar de novo retoma, e de novo não faz nada')]
    public function testLimiteEIdempotencia(): void
    {
        $d1 = $this->documento('d1.pdf', naLixeiraHa: 90);
        $d2 = $this->documento('d2.pdf', naLixeiraHa: 60);
        $d3 = $this->documento('d3.pdf', naLixeiraHa: 40);
        $this->em->clear();

        $tester = $this->tester();
        $tester->execute(['--limite' => '2']);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('candidatos_documentos=3 secoes_removidas=0 documentos_removidos=2', $this->saida($tester));
        self::assertFalse($this->existeLinha('pasta_documento', (int) $d1->getId()), 'o mais antigo primeiro');
        self::assertFalse($this->existeLinha('pasta_documento', (int) $d2->getId()));
        self::assertTrue($this->existeLinha('pasta_documento', (int) $d3->getId()), 'fora do limite');

        $tester->execute([]);
        self::assertStringContainsString('candidatos_documentos=1 secoes_removidas=0 documentos_removidos=1', $this->saida($tester));
        self::assertFalse($this->existeLinha('pasta_documento', (int) $d3->getId()));

        $tester->execute([]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('candidatos_secoes=0 candidatos_documentos=0 secoes_removidas=0 documentos_removidos=0 arquivos_removidos=0', $this->saida($tester));
        self::assertCount(3, $this->memoria->excluidas, 'nada é apagado duas vezes');
    }

    #[TestDox('opção inválida é recusada antes de tocar em qualquer coisa')]
    public function testOpcaoInvalida(): void
    {
        $vencido = $this->documento('vencido.pdf', naLixeiraHa: 60);
        $this->em->clear();

        $tester = $this->tester();
        $tester->execute(['--dias' => 'trinta']);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--dias precisa ser um inteiro positivo', $this->saida($tester));

        $tester->execute(['--limite' => '0']);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());

        self::assertTrue($this->existeLinha('pasta_documento', (int) $vencido->getId()));
        self::assertSame([], $this->memoria->excluidas);
    }

    // ----------------------------------------------------------------- helpers

    private function tester(): CommandTester
    {
        $container = static::getContainer();

        return new CommandTester(new PurgarLixeiraCommand(new PurgarLixeiraUseCase(
            $this->em,
            $container->get(PastaDocumentoRepository::class),
            $container->get(PastaSecaoRepository::class),
            $container->get(AcessoALixeira::class),
            new RemocaoAposTransacao($this->memoria, new NullLogger()),
            new MockClock(self::AGORA),
        )));
    }

    /** O arquivo vai para o dublê em memória, pela chave que a leitura usa (`ChavesDePasta::documento`). */
    private function documento(string $nome, ?PastaSecao $secao = null, ?int $naLixeiraHa = null): PastaDocumento
    {
        $doc = (new PastaDocumento())->setTenant($this->tenant);
        $chave = $this->memoria->gravar(
            ChavesDePasta::novoDocumento($doc, 'pdf'),
            FonteDeConteudo::deTexto('conteudo-' . $nome),
        )->chave;

        $doc->setTitulo($nome)
            ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo($chave->nome)
            ->setNomeOriginal($nome)
            ->setMimeType('application/pdf')
            ->setTamanhoBytes(10)
            ->setPasta($this->pasta)
            ->setSecao($secao);
        if ($naLixeiraHa !== null) {
            $doc->marcarExcluido($this->autor, $this->haDias($naLixeiraHa));
        }
        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }

    private function secao(string $nome, ?PastaSecao $pai = null, ?int $naLixeiraHa = null): PastaSecao
    {
        $secao = new PastaSecao();
        $secao->setPasta($this->pasta);
        $secao->setTenant($this->tenant);
        $secao->setNome($nome);
        $secao->setOrdem(1);
        $secao->setPai($pai);
        if ($naLixeiraHa !== null) {
            $secao->marcarExcluido($this->autor, $this->haDias($naLixeiraHa));
        }
        $this->em->persist($secao);
        $this->em->flush();

        return $secao;
    }

    private function haDias(int $dias): \DateTimeImmutable
    {
        return (new \DateTimeImmutable(self::AGORA))->modify(sprintf('-%d days', $dias));
    }

    private function chaveDe(PastaDocumento $doc): ChaveDeArquivo
    {
        return ChavesDePasta::documentoPorNome((int) $this->tenant->getId(), $doc->getCaminhoArquivo());
    }

    private function existeLinha(string $tabela, int $id): bool
    {
        return $this->contarLinhas($tabela, $id) === 1;
    }

    private function contarLinhas(string $tabela, int $id): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM {$tabela} WHERE id = ?", [$id]);
    }

    /** A saída com o espaço em branco normalizado (a tabela quebra linhas). */
    private function saida(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
