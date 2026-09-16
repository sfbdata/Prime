<?php

declare(strict_types=1);

namespace App\Tests\Sync\Functional;

use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoArmazenado;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\ConsultaDeDestinoDaTransacao;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Shared\Service\ArquivoStorageInterface;
use App\Sync\DTO\ResultadoReconciliacaoPasta;
use App\Sync\Enum\ModoSincronizacao;
use App\Sync\Service\ReconciliadorDePasta;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\ArmazenamentoEspiao;
use App\Tests\Shared\Doubles\ConsultaDeDestinoFixa;
use App\Tests\Shared\Doubles\FalhaDeCommitArmavel;
use App\Tests\Sync\Support\FakeGoogleDriveClient;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Drive → sistema (Via B), depois da E2.4B: o arquivo baixado é gravado por chave, e os metadados
 * persistidos seguem a D16.
 *
 *  - **tamanho**: o do conteúdo GRAVADO, nunca o `size` da listagem do Drive quando diverge. O
 *    fake do Drive já diverge por construção (semeia um tamanho e baixa `conteudo-fake-<id>`);
 *  - **MIME**: o do Drive quando é um MIME válido; senão o `application/octet-stream` que o sync
 *    sempre gravou — nada é inferido, nem pela extensão nem pelo conteúdo.
 *
 * O reconciliador é montado à mão para escolher o backend: o dublê em memória guarda o escopo (R1)
 * e injeta falha; o disco de teste é o único que mede MIME de verdade e o único em que a limpeza
 * do item que falhou é observável. Desde a E2.5 essa limpeza é por chave e, quando a falha é da
 * transação, só acontece se o banco PROVAR que nada foi confirmado.
 */
#[CoversClass(ReconciliadorDePasta::class)]
final class ReconciliadorDownloadPorChaveTest extends KernelTestCase
{
    use Factories;

    private const PASTA_NO_DRIVE = 'CASE-E24B';

    /** @var list<ChaveDeArquivo> gravadas no disco de teste, para apagar no fim */
    private array $gravadasNoDisco = [];

    protected function tearDown(): void
    {
        FalhaDeCommitArmavel::desarmar();

        if ($this->gravadasNoDisco !== []) {
            $disco = static::getContainer()->get(ArmazenamentoDeArquivos::class);
            foreach ($this->gravadasNoDisco as $chave) {
                $disco->excluir($chave);
            }
        }

        parent::tearDown();
    }

    #[TestDox('R1: a chave gravada é a que a leitura monta a partir do documento persistido')]
    public function testChaveGravadaEhADaLeitura(): void
    {
        self::bootKernel();
        [$tenantId, $pastaId] = $this->pastaVinculada();
        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-CHAVE', 'sentenca.pdf', self::PASTA_NO_DRIVE);

        $memoria = new ArmazenamentoEmMemoria();
        $r       = $this->importar($memoria, $pastaId, $fake);

        self::assertSame(1, $r->arquivosBaixados, implode("\n", $r->mensagens));
        $doc     = $this->documentoDoDrive('F-CHAVE');
        $gravada = $memoria->ultimaGravada();
        self::assertSame(CategoriaDeArquivo::PASTA_DOCUMENTO, $gravada->categoria);
        self::assertSame($tenantId, $gravada->escopo->tenantIdOuNull());
        self::assertTrue($gravada->ehIgualA(ChavesDePasta::documento($doc)), 'gravação e leitura divergem');
        self::assertSame('conteudo-fake-F-CHAVE', $memoria->ler($gravada));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $doc->getCaminhoArquivo());
    }

    /** D16. Antes da E2.4B o documento guardava os 999.999 bytes informados pelo Drive. */
    #[TestDox('D16: o tamanho persistido é o do conteúdo recebido, e a divergência fica registrada')]
    public function testTamanhoEhODoConteudoRecebido(): void
    {
        self::bootKernel();
        [, $pastaId] = $this->pastaVinculada();
        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-TAM', 'laudo.pdf', self::PASTA_NO_DRIVE, 999_999);

        $memoria = new ArmazenamentoEmMemoria();
        $r       = $this->importar($memoria, $pastaId, $fake);

        $doc = $this->documentoDoDrive('F-TAM');
        self::assertSame(strlen('conteudo-fake-F-TAM'), $doc->getTamanhoBytes());
        self::assertSame(strlen($memoria->ler(ChavesDePasta::documento($doc))), $doc->getTamanhoBytes());
        self::assertStringContainsString(
            '[aviso] drive_file_id=F-TAM: o Drive informou 999999 bytes e chegaram 19',
            implode("\n", $r->mensagens),
        );
        self::assertSame(0, $r->erros);
    }

    /** Distingue "o que o storage mediu" de "strlen do que baixou", que num teste comum coincidem. */
    #[TestDox('D16: o tamanho persistido é o que o storage relata depois de gravar')]
    public function testTamanhoVemDoStorage(): void
    {
        self::bootKernel();
        [, $pastaId] = $this->pastaVinculada();
        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-REL', 'laudo.pdf', self::PASTA_NO_DRIVE, 999_999);

        $memoria                  = new ArmazenamentoEmMemoria();
        $memoria->tamanhoRelatado = 4242;
        $this->importar($memoria, $pastaId, $fake);

        self::assertSame(4242, $this->documentoDoDrive('F-REL')->getTamanhoBytes());
    }

    #[TestDox('D16: tamanho igual ao do Drive não gera aviso')]
    public function testTamanhoIgualNaoGeraAviso(): void
    {
        self::bootKernel();
        [, $pastaId] = $this->pastaVinculada();
        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-OK', 'laudo.pdf', self::PASTA_NO_DRIVE, strlen('conteudo-fake-F-OK'));

        $r = $this->importar(new ArmazenamentoEmMemoria(), $pastaId, $fake);

        self::assertSame(1, $r->arquivosBaixados);
        self::assertSame([], $r->mensagens);
    }

    /** @return iterable<string, array{string}> */
    public static function mimesValidos(): iterable
    {
        yield 'planilha' => ['application/vnd.ms-excel'];
        yield 'octet-stream vindo do Drive' => ['application/octet-stream'];
        yield 'caixa mista, como o libmagic grava' => ['text/x-Algol68'];
        yield 'com +' => ['image/svg+xml'];
    }

    /**
     * D16: o conteúdo do fake é texto puro (o disco mede `text/plain`) — então o MIME do documento
     * só pode ter vindo do Drive.
     */
    #[DataProvider('mimesValidos')]
    #[TestDox('D16: MIME válido do Drive é preservado ($mime)')]
    public function testMimeValidoDoDriveEhPreservado(string $mime): void
    {
        self::bootKernel();
        [, $pastaId] = $this->pastaVinculada();
        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-MIME', 'arquivo.xls', self::PASTA_NO_DRIVE, 10, $mime);

        $this->importar($this->disco(), $pastaId, $fake);

        self::assertSame($mime, $this->documentoDoDrive('F-MIME')->getMimeType());
    }

    /** @return iterable<string, array{string, string}> */
    public static function mimesInvalidos(): iterable
    {
        yield 'vazio' => ['vazio', ''];
        yield 'sem barra' => ['sem barra', 'lixo'];
        yield 'com parâmetro' => ['com parâmetro', 'text/plain; charset=utf-8'];
        yield 'quebra de linha no fim' => ['quebra de linha no fim', "application/pdf\n"];
        yield 'maior que a coluna' => ['maior que a coluna', str_repeat('a', 60) . '/' . str_repeat('b', 60)];
    }

    /**
     * Sem MIME válido do Drive vale o `application/octet-stream` que o sync sempre gravou para MIME
     * vazio — não a extensão (`.pdf` aqui) e não o MIME medido (`text/plain` no disco). Antes da
     * E2.4B, um valor não vazio ia cru para o banco, e um maior que a coluna derrubava a rodada.
     */
    #[DataProvider('mimesInvalidos')]
    #[TestDox('D16: MIME inválido do Drive ($caso) → application/octet-stream, sem inferir')]
    public function testMimeInvalidoViraOctetStream(string $caso, string $mime): void
    {
        self::bootKernel();
        [, $pastaId] = $this->pastaVinculada();
        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-INV', 'parece.pdf', self::PASTA_NO_DRIVE, 10, $mime);

        $r = $this->importar($this->disco(), $pastaId, $fake);

        self::assertSame(1, $r->arquivosBaixados, $caso . ': ' . implode("\n", $r->mensagens));
        self::assertSame('application/octet-stream', $this->documentoDoDrive('F-INV')->getMimeType(), $caso);
    }

    #[TestDox('falha do storage: nenhuma linha, erro contado, rodada não fatal, temporário removido')]
    public function testFalhaDoStorageNaoCriaLinha(): void
    {
        self::bootKernel();
        [, $pastaId] = $this->pastaVinculada();
        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-FALHA', 'sentenca.pdf', self::PASTA_NO_DRIVE);
        $fake->seedArquivo('F-DEPOIS', 'outro.pdf', self::PASTA_NO_DRIVE);

        $falha                = new ArmazenamentoEmMemoria();
        $falha->falhaAoGravar = new FalhaDeArmazenamento('disco cheio');

        $r = $this->importar($falha, $pastaId, $fake);

        self::assertSame(2, $r->erros);
        self::assertFalse($r->fatal);
        self::assertSame(0, $r->arquivosBaixados);
        self::assertStringContainsString('[erro] drive_file_id=F-FALHA (Drive→sistema): disco cheio', implode("\n", $r->mensagens));
        self::assertSame([], $falha->gravadas);
        self::assertSame(0, $this->linhasDoDrive(['F-FALHA', 'F-DEPOIS']));
        $this->assertTemporariosNaoSobraram($fake, 2);
    }

    #[TestDox('sucesso: o temporário do download é consumido pela gravação e não sobra')]
    public function testTemporarioNaoSobraNoSucesso(): void
    {
        self::bootKernel();
        [$tenantId, $pastaId] = $this->pastaVinculada();
        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-TMP', 'sentenca.pdf', self::PASTA_NO_DRIVE);

        $r = $this->importar($this->disco(), $pastaId, $fake);

        self::assertSame(1, $r->arquivosBaixados);
        $this->assertTemporariosNaoSobraram($fake, 1);
        $doc = $this->documentoDoDrive('F-TMP');
        self::assertSame('conteudo-fake-F-TMP', $this->disco()->ler(ChavesDePasta::documentoPorNome($tenantId, $doc->getCaminhoArquivo())));
    }

    /** @return iterable<string, array{string, string}> */
    public static function extensoes(): iterable
    {
        yield 'maiúscula' => ['SENTENCA.PDF', 'pdf'];
        yield 'sem extensão' => ['sem extensao', 'bin'];
        yield 'nome esquisito (D8)' => ['arquivo. açaí - 02 junho 2025', 'bin'];
        yield 'ponto no fim' => ['termina.', 'bin'];
    }

    #[DataProvider('extensoes')]
    #[TestDox('D8: a extensão do nome no Drive é saneada pelo storage ($nome → .$extensao)')]
    public function testExtensaoSaneada(string $nome, string $extensao): void
    {
        self::bootKernel();
        [, $pastaId] = $this->pastaVinculada();
        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-EXT', $nome, self::PASTA_NO_DRIVE);

        $this->importar(new ArmazenamentoEmMemoria(), $pastaId, $fake);

        $doc = $this->documentoDoDrive('F-EXT');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.' . $extensao . '$/', $doc->getCaminhoArquivo());
        self::assertSame($nome, $doc->getNomeOriginal());
    }

    /**
     * O limite da coluna vale para o tamanho RECEBIDO. Sem a guarda, o INSERT estouraria, o
     * EntityManager fecharia e a rodada inteira viraria fatal. Contra o DISCO: o arquivo que já
     * tinha sido gravado tem de sumir — senão sobraria órfão por uma falha prevista.
     */
    #[TestDox('tamanho recebido acima do INT4: erro do item, arquivo removido do disco, rodada segue')]
    public function testTamanhoRecebidoAcimaDoLimiteNaoDeixaOrfao(): void
    {
        self::bootKernel();
        [, $pastaId] = $this->pastaVinculada();
        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-GIGANTE', 'video.mp4', self::PASTA_NO_DRIVE);
        $fake->seedArquivo('F-NORMAL', 'normal.pdf', self::PASTA_NO_DRIVE);

        $disco                  = $this->disco();
        $espiao                 = new ArmazenamentoEspiao($disco);
        $espiao->depoisDeGravar = static fn (ArquivoArmazenado $gravado, int $ordem): ArquivoArmazenado => $ordem === 1
            ? new ArquivoArmazenado($gravado->chave, 2_147_483_648, $gravado->mimeType)
            : $gravado;

        try {
            $r = $this->importar($espiao, $pastaId, $fake);
        } finally {
            array_push($this->gravadasNoDisco, ...$espiao->gravadas);
        }

        self::assertFalse($r->fatal);
        self::assertSame(1, $r->erros);
        self::assertSame(1, $r->arquivosBaixados);
        self::assertStringContainsString('acima do limite da coluna de tamanho', implode("\n", $r->mensagens));
        self::assertSame(0, $this->linhasDoDrive(['F-GIGANTE']));
        self::assertSame(1, $this->linhasDoDrive(['F-NORMAL']));
        self::assertCount(2, $espiao->gravadas);
        self::assertFalse($disco->existe($espiao->gravadas[0]), 'o arquivo do item recusado ficou órfão no disco');
        self::assertTrue($disco->existe($espiao->gravadas[1]));
    }

    /**
     * O banco recusa o item depois de o arquivo estar gravado: a linha não nasce e o arquivo sai do
     * disco. A recusa acontece ANTES do COMMIT, então a limpeza não precisa perguntar nada ao banco.
     *
     * A recusa é simulada no `onFlush`. Desde a E2.5 o flush roda dentro da transação explícita da
     * `TransacaoComArquivoNovo`, que fecha o EntityManager em QUALQUER falha (como o
     * `wrapInTransaction`): a rodada vira fatal, o mesmo que uma recusa real do INSERT já causava.
     */
    #[TestDox('banco recusou o item: sem linha e arquivo removido do disco')]
    public function testBancoRecusadoNaoDeixaOrfao(): void
    {
        self::bootKernel();
        [, $pastaId] = $this->pastaVinculada();
        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-RECUSADO', 'sentenca.pdf', self::PASTA_NO_DRIVE);

        $recusa = new class {
            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entidade) {
                    if ($entidade instanceof PastaDocumento) {
                        throw new \RuntimeException('banco recusou o documento');
                    }
                }
            }
        };
        $eventos = static::getContainer()->get(EntityManagerInterface::class)->getEventManager();
        $eventos->addEventListener([Events::onFlush], $recusa);

        $disco  = $this->disco();
        $espiao = new ArmazenamentoEspiao($disco);

        try {
            $r = $this->importar($espiao, $pastaId, $fake);
        } finally {
            $eventos->removeEventListener([Events::onFlush], $recusa);
            array_push($this->gravadasNoDisco, ...$espiao->gravadas);
        }

        self::assertSame(1, $r->erros);
        self::assertTrue($r->fatal, 'a transação fechou o EntityManager: a rodada para');
        self::assertSame(0, $r->arquivosBaixados);
        self::assertStringContainsString('banco recusou o documento', implode("\n", $r->mensagens));
        self::assertSame(0, $this->linhasDoDrive(['F-RECUSADO']));
        self::assertCount(1, $espiao->gravadas, 'o arquivo devia ter sido gravado antes da recusa');
        self::assertFalse($disco->existe($espiao->gravadas[0]), 'o arquivo do item recusado ficou órfão no disco');
        $this->assertTemporariosNaoSobraram($fake, 1);
    }

    /**
     * E2.5: o COMMIT do item chega ao banco e a resposta se perde. O documento existe e aponta para
     * o arquivo — e sem prova do destino (sob o DAMA a consulta real diz "em andamento") o arquivo
     * FICA. Antes da E2.5 este catch o apagava. O EntityManager fechou: a rodada vira fatal, como
     * em qualquer falha de COMMIT.
     */
    #[TestDox('COMMIT do item com resposta perdida: o documento existe, o arquivo fica, a rodada para')]
    public function testCommitComRespostaPerdidaPreservaOArquivo(): void
    {
        self::bootKernel();
        [, $pastaId] = $this->pastaVinculada();
        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-PERDIDO', 'sentenca.pdf', self::PASTA_NO_DRIVE);

        $disco  = $this->disco();
        $espiao = new ArmazenamentoEspiao($disco);
        FalhaDeCommitArmavel::perderRespostaDoProximoCommit();

        try {
            $r = $this->importar($espiao, $pastaId, $fake);
        } finally {
            array_push($this->gravadasNoDisco, ...$espiao->gravadas);
        }

        self::assertSame(1, FalhaDeCommitArmavel::$disparos);
        self::assertTrue($r->fatal);
        self::assertSame(1, $this->linhasDoDrive(['F-PERDIDO']), 'o banco confirmou o documento');
        self::assertCount(1, $espiao->gravadas);
        self::assertTrue($disco->existe($espiao->gravadas[0]), 'o documento aponta para o arquivo: ele não pode ter saído');
        self::assertSame([], $espiao->excluidas);
    }

    #[TestDox('COMMIT do item recusado e o banco PROVA aborted: sem linha, e o arquivo sai')]
    public function testCommitRecusadoComProvaRemoveOArquivo(): void
    {
        self::bootKernel();
        [, $pastaId] = $this->pastaVinculada();
        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-ABORTADO', 'sentenca.pdf', self::PASTA_NO_DRIVE);

        $disco    = $this->disco();
        $espiao   = new ArmazenamentoEspiao($disco);
        $consulta = new ConsultaDeDestinoFixa(DestinoDaTransacao::NaoConfirmada);
        FalhaDeCommitArmavel::recusarProximoCommit();

        try {
            $r = $this->importar($espiao, $pastaId, $fake, $consulta);
        } finally {
            array_push($this->gravadasNoDisco, ...$espiao->gravadas);
        }

        self::assertTrue($r->fatal);
        self::assertCount(1, $consulta->perguntados);
        self::assertSame(0, $this->linhasDoDrive(['F-ABORTADO']));
        self::assertCount(1, $espiao->gravadas);
        self::assertFalse($disco->existe($espiao->gravadas[0]), 'com aborted provado, o arquivo do item sai');
    }

    /**
     * Falha física na limpeza do item (antes da transação): não derruba a rodada e vira aviso no
     * resultado — o órfão tem rastro.
     */
    #[TestDox('limpeza do item que falha: rodada segue e o órfão aparece no resultado')]
    public function testLimpezaQueFalhaViraAviso(): void
    {
        self::bootKernel();
        [, $pastaId] = $this->pastaVinculada();
        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-GIGANTE', 'video.mp4', self::PASTA_NO_DRIVE);
        $fake->seedArquivo('F-NORMAL', 'normal.pdf', self::PASTA_NO_DRIVE);

        $disco                  = $this->disco();
        $espiao                 = new ArmazenamentoEspiao($disco);
        $espiao->depoisDeGravar = static fn (ArquivoArmazenado $gravado, int $ordem): ArquivoArmazenado => $ordem === 1
            ? new ArquivoArmazenado($gravado->chave, 2_147_483_648, $gravado->mimeType)
            : $gravado;
        $espiao->falhaAoExcluir = static fn (): \Throwable => new FalhaDeArmazenamento('disco ilegível');

        try {
            $r = $this->importar($espiao, $pastaId, $fake);
        } finally {
            array_push($this->gravadasNoDisco, ...$espiao->gravadas);
        }

        self::assertFalse($r->fatal);
        self::assertSame(1, $r->arquivosBaixados);
        self::assertTrue($disco->existe($espiao->gravadas[0]));
        self::assertStringContainsString('não pôde ser removido e ficou órfão', implode("\n", $r->mensagens));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function importar(
        ArmazenamentoDeArquivos $armazenamento,
        int $pastaId,
        FakeGoogleDriveClient $fake,
        ?ConsultaDeDestinoDaTransacao $consulta = null,
    ): ResultadoReconciliacaoPasta {
        $container     = static::getContainer();
        $em            = $container->get(EntityManagerInterface::class);
        $remocao       = new RemocaoAposTransacao($armazenamento, new NullLogger());
        $reconciliador = new ReconciliadorDePasta(
            $em,
            $container->get(ArquivoStorageInterface::class),
            $armazenamento,
            new TransacaoComArquivoNovo(
                $em,
                $consulta ?? $container->get(ConsultaDeDestinoDaTransacao::class),
                $remocao,
                new NullLogger(),
            ),
            $remocao,
            $container->get(PastaSecaoRepository::class),
            (string) $container->getParameter('uploads_dir'),
        );

        $r = new ResultadoReconciliacaoPasta();
        $reconciliador->reconciliarArquivosDaPasta($pastaId, $fake, false, $r, ModoSincronizacao::Importar);

        if ($armazenamento === $this->disco()) {
            foreach ($this->linhasCaminho($pastaId) as $caminho) {
                $this->gravadasNoDisco[] = ChavesDePasta::documentoPorNome($this->tenantDaPasta($pastaId), $caminho);
            }
        }

        return $r;
    }

    private function assertTemporariosNaoSobraram(FakeGoogleDriveClient $fake, int $downloads): void
    {
        self::assertCount($downloads, $fake->destinosDeDownload);
        foreach ($fake->destinosDeDownload as $temporario) {
            self::assertFileDoesNotExist($temporario, 'o download temporário ficou para trás');
        }
    }

    private function disco(): ArmazenamentoDeArquivos
    {
        return static::getContainer()->get(ArmazenamentoDeArquivos::class);
    }

    /** @return array{0: int, 1: int} */
    private function pastaVinculada(): array
    {
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'driveFolderId' => self::PASTA_NO_DRIVE]);

        return [(int) $tenant->getId(), (int) $pasta->getId()];
    }

    private function documentoDoDrive(string $driveFileId): PastaDocumento
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(PastaDocumento::class)->findOneBy(['driveFileId' => $driveFileId])
            ?? self::fail('documento não importado: ' . $driveFileId);
    }

    /** @param list<string> $driveFileIds */
    private function linhasDoDrive(array $driveFileIds): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM pasta_documento WHERE drive_file_id IN (:ids)',
            ['ids' => $driveFileIds],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    /** @return list<string> */
    private function linhasCaminho(int $pastaId): array
    {
        return array_map('strval', static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchFirstColumn(
            'SELECT caminho_arquivo FROM pasta_documento WHERE pasta_id = :p',
            ['p' => $pastaId],
        ));
    }

    private function tenantDaPasta(int $pastaId): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT tenant_id FROM pasta WHERE id = :p',
            ['p' => $pastaId],
        );
    }
}
