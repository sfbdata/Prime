<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Command\CalcularHashDosDocumentosCommand;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Service\ContadorDePaginasDePdf;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoArmazenado;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MaterializadorDeArquivo;
use App\Shared\Armazenamento\MetadadosDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Shared\EspelhoRemoto\ArmazenamentoEspelhado;
use App\Shared\EspelhoRemoto\CacheDeLeituraDoEspelho;
use App\Shared\EspelhoRemoto\Liberacao\RegistroDeLiberacao;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\EspelhoRemotoEmMemoria;
use App\Tests\Shared\Doubles\ProvedorDoEspelhoFixo;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:documentos:calcular-hash` — o preenchimento do acervo anterior à coluna `sha256`.
 *
 * O que se prova: `--dry-run` lê e não grava; só linhas NULL são tocadas (o hash que já existe
 * não é relido nem regravado — idempotente); `--limite` e `--tenant` recortam a fila; arquivo
 * ausente fica NULL sem derrubar o lote; pane de leitura fica NULL, o resto segue e o código de
 * saída avisa. O armazenamento é o dublê em memória: a leitura é pela CHAVE, e o dublê registra
 * quais foram lidas.
 *
 * Desde o R2: `--a-partir-de` avança a fila em fatias (um ausente no começo não prende a fila); o
 * hash de um arquivo liberado do disco vem da prova do espelho, sem baixar; e o `--paginas` lê o
 * liberado do R2 pelo cache de leitura, respeitando o piso de espaço livre. Esses casos montam o
 * `ArmazenamentoEspelhado` de verdade sobre o disco em memória, com outbox e liberação no banco.
 */
#[CoversClass(CalcularHashDosDocumentosCommand::class)]
final class CalcularHashDosDocumentosCommandTest extends KernelTestCase
{
    private const JA_TINHA = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    private ?string $diretorioDoCache = null;

    protected function tearDown(): void
    {
        if ($this->diretorioDoCache !== null) {
            foreach (glob($this->diretorioDoCache . '/{,.}*', \GLOB_BRACE) ?: [] as $arquivo) {
                if (is_file($arquivo)) {
                    unlink($arquivo);
                }
            }
            if (is_dir($this->diretorioDoCache)) {
                rmdir($this->diretorioDoCache);
            }
            $this->diretorioDoCache = null;
        }

        parent::tearDown();
    }

    #[TestDox('--dry-run lê os arquivos e calcula, mas não grava nada')]
    public function testDryRunNaoGravaNada(): void
    {
        self::bootKernel();
        $memoria = new ArmazenamentoEmMemoria();
        $tenant  = $this->criarTenant();
        $pasta   = $this->criarPasta($tenant);
        $d1      = $this->documento($pasta, $tenant, null, $memoria, 'conteúdo A');
        $d2      = $this->documento($pasta, $tenant, null, $memoria, 'conteúdo B');

        $tester = $this->tester($memoria);
        $tester->execute(['--dry-run' => true, '--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $saida = $this->saida($tester);
        self::assertStringContainsString('modo=simulacao candidatos=2 calculados=2 ja_tinham=0 ausentes=0 invalidos=0 falhas=0', $saida);
        self::assertStringContainsString('restantes=2', $saida, 'nada foi gravado: os dois continuam na fila');
        self::assertNull($this->shaNoBanco($d1));
        self::assertNull($this->shaNoBanco($d2));
        self::assertCount(2, $memoria->lidas, 'o dry-run lê de verdade — é assim que se mede o custo');
    }

    #[TestDox('preenche só quem está NULL, não relê quem já tem hash, e rodar de novo não muda nada')]
    public function testPreencheSoOsNulosEEhIdempotente(): void
    {
        self::bootKernel();
        $memoria = new ArmazenamentoEmMemoria();
        $tenant  = $this->criarTenant();
        $pasta   = $this->criarPasta($tenant);
        $d1      = $this->documento($pasta, $tenant, null, $memoria, 'conteúdo A');
        $d2      = $this->documento($pasta, $tenant, null, $memoria, 'conteúdo B');
        // Já tem hash — e o arquivo tem OUTRO conteúdo, para provar que ele não é relido.
        $d3      = $this->documento($pasta, $tenant, self::JA_TINHA, $memoria, 'conteúdo que não bate com o hash');
        // Sem arquivo no armazenamento.
        $d4      = $this->documento($pasta, $tenant, null, null, '');

        $tester = $this->tester($memoria);
        $tester->execute(['--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), 'arquivo ausente não é pane: ' . $tester->getDisplay());
        $saida = $this->saida($tester);
        self::assertStringContainsString('modo=gravacao candidatos=3 calculados=2 ja_tinham=0 ausentes=1 invalidos=0 falhas=0', $saida);
        self::assertStringContainsString('restantes=1', $saida);

        self::assertSame(hash('sha256', 'conteúdo A'), $this->shaNoBanco($d1));
        self::assertSame(hash('sha256', 'conteúdo B'), $this->shaNoBanco($d2));
        self::assertSame(self::JA_TINHA, $this->shaNoBanco($d3), 'quem já tinha hash não é tocado');
        self::assertNull($this->shaNoBanco($d4), 'arquivo ausente fica NULL');

        self::assertNotContains($this->chaveDe($d3)->comoTexto(), $memoria->lidas, 'quem já tinha hash não é relido');
        self::assertContains($this->chaveDe($d1)->comoTexto(), $memoria->lidas);

        // Segunda execução: a fila é só o ausente; nada do que foi gravado muda.
        $memoria->lidas = [];
        $tester->execute(['--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('candidatos=1 calculados=0 ja_tinham=0 ausentes=1', $this->saida($tester));
        self::assertSame([$this->chaveDe($d4)->comoTexto()], $memoria->lidas, 'só o ausente volta à fila');
        self::assertSame(hash('sha256', 'conteúdo A'), $this->shaNoBanco($d1));
        self::assertSame(self::JA_TINHA, $this->shaNoBanco($d3));
    }

    #[TestDox('--limite recorta a fila em ordem de id e --tenant restringe ao escritório')]
    public function testLimiteETenantRecortamAFila(): void
    {
        self::bootKernel();
        $memoria = new ArmazenamentoEmMemoria();
        $tenant1 = $this->criarTenant();
        $tenant2 = $this->criarTenant();
        $pasta1  = $this->criarPasta($tenant1);
        $pasta2  = $this->criarPasta($tenant2);
        $d1      = $this->documento($pasta1, $tenant1, null, $memoria, 'um');
        $d2      = $this->documento($pasta1, $tenant1, null, $memoria, 'dois');
        $d5      = $this->documento($pasta2, $tenant2, null, $memoria, 'cinco');

        $tester = $this->tester($memoria);
        $tester->execute(['--tenant' => (string) $tenant1->getId(), '--limite' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(hash('sha256', 'um'), $this->shaNoBanco($d1), 'o mais antigo (menor id) entra primeiro');
        self::assertNull($this->shaNoBanco($d2), 'fora do limite');
        self::assertNull($this->shaNoBanco($d5), 'outro escritório');

        $tester->execute(['--tenant' => (string) $tenant2->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(hash('sha256', 'cinco'), $this->shaNoBanco($d5));
        self::assertNull($this->shaNoBanco($d2), 'o escritório 1 não foi tocado nesta execução');
    }

    #[TestDox('pane de leitura num arquivo: ele fica NULL, os outros são preenchidos e o código de saída avisa')]
    public function testPaneDeLeituraNaoDerrubaOLoteMasAvisa(): void
    {
        self::bootKernel();
        $memoria = new ArmazenamentoEmMemoria();
        $tenant  = $this->criarTenant();
        $pasta   = $this->criarPasta($tenant);
        $d1      = $this->documento($pasta, $tenant, null, $memoria, 'quebrado');
        $d2      = $this->documento($pasta, $tenant, null, $memoria, 'íntegro');

        $quebrado = $this->chaveDe($d1);
        $tester   = $this->tester($this->falhaAoAbrir($memoria, $quebrado));
        $tester->execute(['--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $tester->getDisplay());
        $saida = $this->saida($tester);
        self::assertStringContainsString('ERRO: documento #' . $d1->getId() . ': disco com erro de leitura', $saida);
        self::assertStringContainsString('candidatos=2 calculados=1 ja_tinham=0 ausentes=0 invalidos=0 falhas=1', $saida);
        self::assertNull($this->shaNoBanco($d1));
        self::assertSame(hash('sha256', 'íntegro'), $this->shaNoBanco($d2), 'o lote segue depois da pane');
    }

    #[TestDox('--limite que não é inteiro positivo é recusado antes de ler qualquer coisa')]
    public function testOpcaoInvalidaEhRecusada(): void
    {
        self::bootKernel();
        $memoria = new ArmazenamentoEmMemoria();

        $tester = $this->tester($memoria);
        $tester->execute(['--limite' => 'dez']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--limite precisa ser um inteiro positivo', $this->saida($tester));
        self::assertSame([], $memoria->lidas);

        $tester->execute(['--a-partir-de' => '0']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--a-partir-de precisa ser um inteiro positivo', $this->saida($tester));
        self::assertSame([], $memoria->lidas);
    }

    // ----------------------------------------------------------------- --a-partir-de

    #[TestDox('--a-partir-de avança a fila em fatias: o ausente do começo não faz a fatia seguinte repetir os mesmos ids')]
    public function testAPartirDeAvancaAFilaMesmoComAusenteNoComeco(): void
    {
        self::bootKernel();
        $memoria = new ArmazenamentoEmMemoria();
        $tenant  = $this->criarTenant();
        $pasta   = $this->criarPasta($tenant);
        $ausente = $this->documento($pasta, $tenant, null, null, '');
        $d2      = $this->documento($pasta, $tenant, null, $memoria, 'dois');
        $d3      = $this->documento($pasta, $tenant, null, $memoria, 'três');
        $tester  = $this->tester($memoria);

        // Sem cursor, a fatia de 1 pega sempre o ausente: era o defeito em produção.
        $tester->execute(['--tenant' => (string) $tenant->getId(), '--limite' => '1']);
        self::assertStringContainsString('ausentes=1 invalidos=0 falhas=0 bytes=0', $this->saida($tester));
        self::assertStringContainsString(sprintf('ultimo_id=%d a_frente=2', $ausente->getId()), $this->saida($tester));
        self::assertStringContainsString(sprintf('Próxima fatia: repita o comando com --a-partir-de=%d', $ausente->getId()), $this->saida($tester));

        $tester->execute(['--tenant' => (string) $tenant->getId(), '--limite' => '1', '--a-partir-de' => (string) $ausente->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(hash('sha256', 'dois'), $this->shaNoBanco($d2), 'a fatia seguinte começa depois do cursor');
        self::assertNull($this->shaNoBanco($d3), 'fora do limite');
        $saida = $this->saida($tester);
        self::assertStringContainsString('candidatos=1 calculados=1', $saida);
        self::assertStringContainsString(sprintf('restantes=2 alvo=sha256 sem_contagem=0 ultimo_id=%d a_frente=1', $d2->getId()), $saida, 'restantes conta o ausente de trás; a_frente só o que falta depois do cursor');

        $tester->execute(['--tenant' => (string) $tenant->getId(), '--a-partir-de' => (string) $d3->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString(sprintf('Nenhum documento sem hash no escritório %d depois do id %d.', $tenant->getId(), $d3->getId()), $this->saida($tester));
    }

    #[TestDox('--a-partir-de vale também no --paginas')]
    public function testAPartirDeNasPaginas(): void
    {
        self::bootKernel();
        $memoria = new ArmazenamentoEmMemoria();
        $tenant  = $this->criarTenant();
        $pasta   = $this->criarPasta($tenant);
        $d1      = $this->documento($pasta, $tenant, null, $memoria, 'PAGINAS=1');
        $d2      = $this->documento($pasta, $tenant, null, $memoria, 'PAGINAS=2');

        $tester = $this->tester($memoria);
        $tester->execute(['--paginas' => true, '--tenant' => (string) $tenant->getId(), '--a-partir-de' => (string) $d1->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertNull($this->paginasNoBanco($d1), 'id igual ao cursor fica de fora');
        self::assertSame(2, $this->paginasNoBanco($d2));
        self::assertStringContainsString(sprintf('alvo=paginas sem_contagem=0 ultimo_id=%d a_frente=0', $d2->getId()), $this->saida($tester));
        self::assertStringNotContainsString('Próxima fatia', $this->saida($tester), 'a_frente=0: a passada terminou');
    }

    // ----------------------------------------------------------------- arquivo liberado (R2)

    #[TestDox('hash de arquivo fora do disco vem da prova do espelho (liberação aberta ou outbox verificado), sem baixar; sem prova, continua ausente')]
    public function testHashDoLiberadoVemDaProvaSemBaixar(): void
    {
        self::bootKernel();
        $disco    = new ArmazenamentoEmMemoria();
        $espelho  = new EspelhoRemotoEmMemoria();
        $tenant   = $this->criarTenant();
        $pasta    = $this->criarPasta($tenant);
        $noDisco  = $this->documento($pasta, $tenant, null, $disco, 'no disco');
        $liberado = $this->documento($pasta, $tenant, null, null, 'liberado do disco');
        $soOutbox = $this->documento($pasta, $tenant, null, null, 'só na outbox');
        $semProva = $this->documento($pasta, $tenant, null, null, '');

        $this->liberar($liberado, 'liberado do disco', $espelho);
        $this->enviarVerificado($soOutbox, 'só na outbox', $espelho);

        $tester = $this->tester($disco, $this->espelhado($disco, $espelho));
        $tester->execute(['--tenant' => (string) $tenant->getId(), '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('modo=simulacao candidatos=4 calculados=3', $this->saida($tester));
        self::assertStringContainsString('do_espelho=2', $this->saida($tester));
        self::assertNull($this->shaNoBanco($liberado), 'o --dry-run com prova também não grava');

        $tester->execute(['--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $saida = $this->saida($tester);
        self::assertStringContainsString('candidatos=4 calculados=3 ja_tinham=0 ausentes=1 invalidos=0 falhas=0 bytes=' . \strlen('no disco'), $saida, 'o liberado não conta bytes lidos: nada foi lido');
        self::assertStringContainsString('do_espelho=2', $saida);
        self::assertSame(hash('sha256', 'no disco'), $this->shaNoBanco($noDisco));
        self::assertSame(hash('sha256', 'liberado do disco'), $this->shaNoBanco($liberado));
        self::assertSame(hash('sha256', 'só na outbox'), $this->shaNoBanco($soOutbox));
        self::assertNull($this->shaNoBanco($semProva));
        self::assertSame(0, $espelho->downloads, 'o hash do liberado nunca baixa do R2');
    }

    #[TestDox('hash de arquivo fora do disco com a consulta da prova em pane: falha (fica NULL, código de saída avisa), nunca ausente')]
    public function testHashComPaneNaProvaEhFalha(): void
    {
        self::bootKernel();
        $disco    = new ArmazenamentoEmMemoria();
        $tenant   = $this->criarTenant();
        $pasta    = $this->criarPasta($tenant);
        $liberado = $this->documento($pasta, $tenant, null, null, 'liberado do disco');

        $tester = $this->tester($disco, $this->espelhado($disco, new EspelhoRemotoEmMemoria(), outboxForaDoAr: true));
        $tester->execute(['--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('candidatos=1 calculados=0 ja_tinham=0 ausentes=0 invalidos=0 falhas=1', $this->saida($tester));
        self::assertNull($this->shaNoBanco($liberado));
    }

    #[TestDox('--paginas de PDF liberado com o R2 fora do ar: falha (fica NULL, código de saída avisa), nunca ausente')]
    public function testPaginasComR2ForaDoArEhFalha(): void
    {
        self::bootKernel();
        $disco    = new ArmazenamentoEmMemoria();
        $espelho  = new EspelhoRemotoEmMemoria();
        $tenant   = $this->criarTenant();
        $pasta    = $this->criarPasta($tenant);
        $liberado = $this->documento($pasta, $tenant, null, null, 'PAGINAS=7');
        $this->liberar($liberado, 'PAGINAS=7', $espelho);
        $espelho->foraDoAr = true;

        $tester = $this->tester($disco, $this->espelhado($disco, $espelho));
        $tester->execute(['--paginas' => true, '--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('ausentes=0 invalidos=0 falhas=1', $this->saida($tester));
        self::assertNull($this->paginasNoBanco($liberado));
    }

    #[TestDox('--paginas de PDF liberado: lê do R2 pelo cache de leitura e grava a contagem; o PDF no disco não vai ao R2')]
    public function testPaginasDoLiberadoLeDoR2PeloCache(): void
    {
        self::bootKernel();
        $disco    = new ArmazenamentoEmMemoria();
        $espelho  = new EspelhoRemotoEmMemoria();
        $tenant   = $this->criarTenant();
        $pasta    = $this->criarPasta($tenant);
        $noDisco  = $this->documento($pasta, $tenant, null, $disco, 'PAGINAS=2');
        $liberado = $this->documento($pasta, $tenant, null, null, 'PAGINAS=7');
        $this->liberar($liberado, 'PAGINAS=7', $espelho);

        $tester = $this->tester($disco, $this->espelhado($disco, $espelho));
        $tester->execute(['--paginas' => true, '--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(2, $this->paginasNoBanco($noDisco));
        self::assertSame(7, $this->paginasNoBanco($liberado));
        self::assertSame(1, $espelho->downloads, 'só o liberado foi baixado');
        self::assertFileExists($this->diretorioDoCache . '/' . hash('sha256', 'PAGINAS=7'), 'o download ficou no cache de leitura');
        self::assertStringContainsString('candidatos=2 calculados=2 ja_tinham=0 ausentes=0 invalidos=0 falhas=0', $this->saida($tester));
        self::assertStringContainsString('do_espelho=1', $this->saida($tester));
        self::assertNull($this->shaNoBanco($liberado), 'o --paginas não mexe no sha256');
    }

    #[TestDox('--paginas com o piso de espaço livre do cache atingido: falha (fica NULL, código de saída avisa), nada é baixado')]
    public function testPaginasRespeitaOPisoDeEspacoLivre(): void
    {
        self::bootKernel();
        $disco    = new ArmazenamentoEmMemoria();
        $espelho  = new EspelhoRemotoEmMemoria();
        $tenant   = $this->criarTenant();
        $pasta    = $this->criarPasta($tenant);
        $liberado = $this->documento($pasta, $tenant, null, null, 'PAGINAS=7');
        $this->liberar($liberado, 'PAGINAS=7', $espelho);

        $tester = $this->tester($disco, $this->espelhado($disco, $espelho, espacoLivre: 1000));
        $tester->execute(['--paginas' => true, '--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('ausentes=0 invalidos=0 falhas=1', $this->saida($tester), 'piso atingido é falha, não ausência');
        self::assertNull($this->paginasNoBanco($liberado));
        self::assertSame(0, $espelho->downloads, 'o piso recusa antes de baixar');
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Sem `$espelhado`, o próprio dublê em memória faz o papel do decorador (ele implementa as duas
     * interfaces e não tem prova de espelho: fora do disco, é ausente); sem `$contador`, nada é
     * contado (NULL).
     */
    private function tester(ArmazenamentoDeArquivos $armazenamento, (ArmazenamentoDeArquivos&MaterializadorDeArquivo)|null $espelhado = null, ?ContadorDePaginasDePdf $contador = null): CommandTester
    {
        $container = static::getContainer();

        return new CommandTester(new CalcularHashDosDocumentosCommand(
            $container->get(EntityManagerInterface::class),
            $container->get(PastaDocumentoRepository::class),
            $armazenamento,
            new NullLogger(),
            $espelhado ?? ($armazenamento instanceof ArmazenamentoEmMemoria ? $armazenamento : new ArmazenamentoEmMemoria()),
            $contador ?? $this->contadorPorConteudo(),
        ));
    }

    /**
     * O decorador de verdade sobre o disco em memória, com a outbox e o registro de liberação no
     * banco de teste. `$espacoLivre` troca a medida do disco do cache (o piso é o de produção);
     * `$outboxForaDoAr` dá à outbox uma conexão que não abre (porta fechada), para a consulta da
     * prova virar pane.
     */
    private function espelhado(ArmazenamentoEmMemoria $disco, EspelhoRemotoEmMemoria $espelho, ?int $espacoLivre = null, bool $outboxForaDoAr = false): ArmazenamentoEspelhado
    {
        $conexao                = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $relogio                = new MockClock('2026-11-09 12:00:00', 'UTC');
        $this->diretorioDoCache = sys_get_temp_dir() . '/jusprime-teste-calcular-hash-' . bin2hex(random_bytes(6));
        $conexaoDaOutbox        = $outboxForaDoAr
            ? DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => 1, 'dbname' => 'nenhum', 'user' => 'nenhum'])
            : $conexao;

        return new ArmazenamentoEspelhado(
            $disco,
            new OutboxDeReplicacao($conexaoDaOutbox, $relogio),
            new PoliticaDeEspelhamento('pasta_documento'),
            new NullLogger(),
            ProvedorDoEspelhoFixo::com($espelho),
            new CacheDeLeituraDoEspelho(
                $this->diretorioDoCache,
                10_000_000,
                espacoLivre: $espacoLivre === null ? null : static fn (string $diretorio): float => (float) $espacoLivre,
            ),
            new RegistroDeLiberacao($conexao, $relogio),
        );
    }

    /** O documento saiu do disco: está no R2 e tem linha aberta de liberação, sem linha na outbox. */
    private function liberar(PastaDocumento $doc, string $conteudo, EspelhoRemotoEmMemoria $espelho): void
    {
        $chave = ChavesDePasta::documento($doc);
        $espelho->semear($chave, $conteudo);
        $relogio = new MockClock('2026-11-09 12:00:00', 'UTC');
        (new RegistroDeLiberacao(static::getContainer()->get(EntityManagerInterface::class)->getConnection(), $relogio))
            ->registrar('lote-teste', $chave, hash('sha256', $conteudo), \strlen($conteudo));
    }

    /** O documento não está no disco, e a outbox tem o envio `verificado` dele (a prova sem liberação). */
    private function enviarVerificado(PastaDocumento $doc, string $conteudo, EspelhoRemotoEmMemoria $espelho): void
    {
        $chave   = ChavesDePasta::documento($doc);
        $conexao = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $espelho->semear($chave, $conteudo);
        $id = (new OutboxDeReplicacao($conexao, new MockClock('2026-11-09 12:00:00', 'UTC')))
            ->registrarEnvio($chave, hash('sha256', $conteudo), \strlen($conteudo), 'application/pdf');
        $conexao->executeStatement("UPDATE armazenamento_replicacao SET estado = 'verificado' WHERE id = ?", [$id]);
    }

    /**
     * O contador de teste lê o arquivo EMPRESTADO: conteúdo `PAGINAS=N` devolve N; qualquer
     * outro, NULL. Registra os caminhos recebidos para provar o que foi (e não foi) lido.
     */
    private function contadorPorConteudo(): ContadorDePaginasDePdf
    {
        return new class implements ContadorDePaginasDePdf {
            /** @var list<string> */
            public array $conteudosLidos = [];

            public function contar(string $caminhoLocal): ?int
            {
                $conteudo = (string) file_get_contents($caminhoLocal);
                $this->conteudosLidos[] = $conteudo;

                return preg_match('/^PAGINAS=(\d+)$/', $conteudo, $m) === 1 ? (int) $m[1] : null;
            }
        };
    }

    // ----------------------------------------------------------------- --paginas

    #[TestDox('--paginas: preenche só os PDFs sem contagem; não-PDF fica fora da fila; o que o gs não conta fica NULL sem ser pane')]
    public function testPaginasPreencheSoOsPdfsSemContagem(): void
    {
        self::bootKernel();
        $memoria  = new ArmazenamentoEmMemoria();
        $tenant   = $this->criarTenant();
        $pasta    = $this->criarPasta($tenant);
        $pdfA     = $this->documento($pasta, $tenant, null, $memoria, 'PAGINAS=3');
        $pdfB     = $this->documento($pasta, $tenant, null, $memoria, 'PAGINAS=12');
        $jaTinha  = $this->documento($pasta, $tenant, null, $memoria, 'PAGINAS=99', paginas: 4);
        $naoConta = $this->documento($pasta, $tenant, null, $memoria, 'corrompido');
        $ausente  = $this->documento($pasta, $tenant, null, null, '');
        $png      = $this->documento($pasta, $tenant, null, $memoria, 'PAGINAS=5', mime: 'image/png');

        $contador = $this->contadorPorConteudo();
        $tester   = $this->tester($memoria, null, $contador);
        $tester->execute(['--paginas' => true, '--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), 'ausente e "não conta" não são pane: ' . $tester->getDisplay());
        $saida = $this->saida($tester);
        self::assertStringContainsString('modo=gravacao candidatos=4 calculados=2 ja_tinham=0 ausentes=1 invalidos=0 falhas=0', $saida);
        self::assertStringContainsString('restantes=2 alvo=paginas sem_contagem=1', $saida, 'o ausente e o não contado voltam à fila');

        self::assertSame(3, $this->paginasNoBanco($pdfA));
        self::assertSame(12, $this->paginasNoBanco($pdfB));
        self::assertSame(4, $this->paginasNoBanco($jaTinha), 'quem já tinha contagem não é tocado');
        self::assertNull($this->paginasNoBanco($naoConta));
        self::assertNull($this->paginasNoBanco($ausente));
        self::assertNull($this->paginasNoBanco($png), 'não-PDF nem entra na fila');
        self::assertEqualsCanonicalizing(['PAGINAS=3', 'PAGINAS=12', 'corrompido'], $contador->conteudosLidos, 'só a fila foi emprestada ao contador');
        self::assertNull($this->shaNoBanco($pdfA), 'o modo --paginas não mexe no sha256');
    }

    #[TestDox('--paginas --dry-run conta e não grava; --limite recorta a fila')]
    public function testPaginasDryRunELimite(): void
    {
        self::bootKernel();
        $memoria = new ArmazenamentoEmMemoria();
        $tenant  = $this->criarTenant();
        $pasta   = $this->criarPasta($tenant);
        $d1      = $this->documento($pasta, $tenant, null, $memoria, 'PAGINAS=1');
        $d2      = $this->documento($pasta, $tenant, null, $memoria, 'PAGINAS=2');

        $tester = $this->tester($memoria);
        $tester->execute(['--paginas' => true, '--dry-run' => true, '--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('modo=simulacao candidatos=2 calculados=2', $this->saida($tester));
        self::assertNull($this->paginasNoBanco($d1));
        self::assertNull($this->paginasNoBanco($d2));

        $tester->execute(['--paginas' => true, '--limite' => '1', '--tenant' => (string) $tenant->getId()]);

        self::assertSame(1, $this->paginasNoBanco($d1), 'o menor id entra primeiro');
        self::assertNull($this->paginasNoBanco($d2), 'fora do limite');
    }

    #[TestDox('o modo sha256 (sem --paginas) continua não tocando em paginas')]
    public function testShaNaoMexeEmPaginas(): void
    {
        self::bootKernel();
        $memoria = new ArmazenamentoEmMemoria();
        $tenant  = $this->criarTenant();
        $pasta   = $this->criarPasta($tenant);
        $d1      = $this->documento($pasta, $tenant, null, $memoria, 'PAGINAS=3');

        $tester = $this->tester($memoria);
        $tester->execute(['--tenant' => (string) $tenant->getId()]);

        self::assertSame(hash('sha256', 'PAGINAS=3'), $this->shaNoBanco($d1));
        self::assertNull($this->paginasNoBanco($d1));
        self::assertStringContainsString('alvo=sha256 sem_contagem=0', $this->saida($tester));
    }

    private function paginasNoBanco(PastaDocumento $doc): ?int
    {
        $valor = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT paginas FROM pasta_documento WHERE id = :id',
            ['id' => (int) $doc->getId()],
        );

        return $valor === false || $valor === null ? null : (int) $valor;
    }

    /** A saída com o espaço em branco normalizado (progress bar e tabela quebram linhas). */
    private function saida(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant HASH ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('HASH-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    /**
     * Uma linha de documento; com `$memoria`, o arquivo é semeado na chave que a leitura monta.
     * Sem `$memoria`, a linha aponta para um arquivo que não existe.
     */
    private function documento(Pasta $pasta, Tenant $tenant, ?string $sha256, ?ArmazenamentoEmMemoria $memoria, string $conteudo, ?int $paginas = null, string $mime = 'application/pdf'): PastaDocumento
    {
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $doc = (new PastaDocumento())
            ->setTenant($tenant)
            ->setPasta($pasta)
            ->setTitulo('Doc ' . uniqid())
            ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo(bin2hex(random_bytes(16)) . '.pdf')
            ->setNomeOriginal('doc.pdf')
            ->setMimeType($mime)
            ->setTamanhoBytes(\strlen($conteudo))
            ->setSha256($sha256)
            ->setPaginas($paginas);
        $em->persist($doc);
        $em->flush();

        if ($memoria !== null) {
            $memoria->semear(ChavesDePasta::documento($doc), $conteudo);
        }

        return $doc;
    }

    private function chaveDe(PastaDocumento $doc): ChaveDeArquivo
    {
        return ChavesDePasta::documentoPorNome((int) $doc->getTenant()?->getId(), $doc->getCaminhoArquivo());
    }

    /** Direto do banco: o comando faz `clear()`, então a entidade do teste não serve de espelho. */
    private function shaNoBanco(PastaDocumento $doc): ?string
    {
        $valor = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT sha256 FROM pasta_documento WHERE id = :id',
            ['id' => (int) $doc->getId()],
        );

        return $valor === false || $valor === null ? null : (string) $valor;
    }

    /** O dublê, com `abrir()` quebrado numa chave específica — pane de I/O, não ausência. */
    private function falhaAoAbrir(ArmazenamentoEmMemoria $memoria, ChaveDeArquivo $quebrada): ArmazenamentoDeArquivos
    {
        return new class($memoria, $quebrada) implements ArmazenamentoDeArquivos {
            public function __construct(
                private readonly ArmazenamentoEmMemoria $memoria,
                private readonly ChaveDeArquivo $quebrada,
            ) {
            }

            public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado
            {
                return $this->memoria->gravar($destino, $fonte);
            }

            public function abrir(ChaveDeArquivo $chave): mixed
            {
                if ($chave->ehIgualA($this->quebrada)) {
                    throw new FalhaDeArmazenamento('disco com erro de leitura');
                }

                return $this->memoria->abrir($chave);
            }

            public function ler(ChaveDeArquivo $chave): string
            {
                return $this->memoria->ler($chave);
            }

            public function existe(ChaveDeArquivo $chave): bool
            {
                return $this->memoria->existe($chave);
            }

            public function excluir(ChaveDeArquivo $chave): void
            {
                $this->memoria->excluir($chave);
            }

            public function metadados(ChaveDeArquivo $chave): ?MetadadosDeArquivo
            {
                return $this->memoria->metadados($chave);
            }
        };
    }
}
