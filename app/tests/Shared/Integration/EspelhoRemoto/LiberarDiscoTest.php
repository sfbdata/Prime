<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\Shared\Armazenamento\AreaDeLiberados;
use Psr\Log\NullLogger;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Tests\Shared\Doubles\DiscoDaLiberacaoComGanchos;
use App\Shared\EspelhoRemoto\CacheDeLeituraDoEspelho;
use App\Shared\EspelhoRemoto\ArmazenamentoEspelhado;
use App\Shared\Armazenamento\CategoriaComIsolamentoFisico;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Lock\LockFactory;
use App\Shared\EspelhoRemoto\MetadadosRemotos;
use App\Shared\EspelhoRemoto\EspelhoRemoto;
use App\Shared\Armazenamento\DiscoDaLiberacao;
use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\ResolvedorDeCaminhoLocal;
use App\Shared\EspelhoRemoto\Command\LiberarDiscoCommand;
use App\Shared\EspelhoRemoto\Command\RestaurarLoteCommand;
use App\Shared\EspelhoRemoto\Command\EsvaziarLoteCommand;
use App\Shared\EspelhoRemoto\Liberacao\EsvaziadorDeLote;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use App\Shared\EspelhoRemoto\Liberacao\ArquivosDoOperador;
use App\Shared\EspelhoRemoto\Liberacao\DatasDasReferencias;
use App\Shared\EspelhoRemoto\Liberacao\LiberadorDeDisco;
use App\Shared\EspelhoRemoto\Liberacao\RegistroDeLiberacao;
use App\Shared\EspelhoRemoto\Liberacao\RestauradorDeLote;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;
use App\Shared\EspelhoRemoto\Reconciliacao\FonteDeReferencias;
use App\Shared\EspelhoRemoto\Reconciliacao\ReferenciaDeArquivo;
use App\Shared\Scheduler\Repository\FalhaAgendadaRepository;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Shared\Doubles\EspelhoRemotoEmMemoria;
use App\Tests\Shared\Doubles\ProvedorDoEspelhoFixo;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * A liberação do disco sem esvaziar e a volta atrás (spec `leitura-pelo-r2-e-liberacao-de-disco.md`
 * §5.1 a §5.4), contra o disco de teste (`uploads_dir`), o banco de teste e o espelho em memória.
 */
#[CoversClass(LiberarDiscoCommand::class)]
#[CoversClass(RestaurarLoteCommand::class)]
#[CoversClass(LiberadorDeDisco::class)]
#[CoversClass(RestauradorDeLote::class)]
#[CoversClass(EsvaziarLoteCommand::class)]
#[CoversClass(EsvaziadorDeLote::class)]
#[CoversClass(AreaDeLiberados::class)]
#[CoversClass(ArquivosDoOperador::class)]
#[CoversClass(DatasDasReferencias::class)]
#[CoversClass(RegistroDeLiberacao::class)]
final class LiberarDiscoTest extends KernelTestCase
{
    use Factories;

    /** O lote que o relógio do teste gera (09:00 em Brasília). */
    private const LOTE = '20261103-090000';

    private const TENANT = 1;

    private Connection $conexao;
    private MockClock $relogio;
    private ResolvedorDeCaminhoLocal $resolvedor;
    private ArmazenamentoLocal $local;
    private AreaDeLiberados $area;
    private EspelhoRemotoEmMemoria $espelho;
    private OutboxDeReplicacao $outbox;
    private RegistroDeLiberacao $liberacoes;
    private string $manifestos;
    private string $prefixo;

    /** @var list<ReferenciaDeArquivo> */
    private array $referencias = [];

    /** @var list<ChaveDeArquivo> */
    private array $criadas = [];

    private int $proximoId = 900_001;

    /** Troca o disco que o liberador e o restaurador recebem (o dublê com ganchos). */
    private ?DiscoDaLiberacao $discoDosComandos = null;

    /** Troca o espelho que os comandos recebem (o que entrega conteúdo errado). */
    private ?EspelhoRemoto $espelhoDosComandos = null;

    private LockFactory $travas;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao    = static::getContainer()->get(Connection::class);
        $this->relogio    = new MockClock('2026-11-03 12:00:00', 'UTC');
        $this->resolvedor = static::getContainer()->get(ResolvedorDeCaminhoLocal::class);
        $this->local      = new ArmazenamentoLocal($this->resolvedor);
        $this->area       = new AreaDeLiberados($this->resolvedor);
        $this->espelho    = new EspelhoRemotoEmMemoria();
        $this->outbox     = new OutboxDeReplicacao($this->conexao, $this->relogio);
        $this->liberacoes = new RegistroDeLiberacao($this->conexao, $this->relogio);
        $this->manifestos = sys_get_temp_dir() . '/liberacao-teste-' . bin2hex(random_bytes(6));
        $this->prefixo    = 'lib-' . bin2hex(random_bytes(4)) . '-';
        $this->travas     = new LockFactory(new InMemoryStore());
        $this->conexao->executeStatement('DELETE FROM armazenamento_replicacao');
        $this->conexao->executeStatement('DELETE FROM armazenamento_liberacao');
    }

    protected function tearDown(): void
    {
        foreach ($this->criadas as $chave) {
            @unlink($this->resolvedor->caminhoDe($chave));
        }
        $this->apagarArvore(\dirname($this->resolvedor->caminhoNosLiberados(new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 'x'), 'x'), 2));
        $this->apagarArvore($this->manifestos);
        parent::tearDown();
    }

    // ------------------------------------------------------------- liberar

    #[TestDox('sem --executar só simula: conta o candidato e não baixa, não registra nem move; a tela não traz nome de arquivo')]
    public function testSimulacaoNaoMudaNada(): void
    {
        $chave = $this->arquivoProvado('Contrato de Antônio.pdf', 'conteúdo frio');

        $tester = $this->liberar([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Candidatos: 1 arquivo(s)', $tester->getDisplay());
        self::assertStringContainsString('Simulação', $tester->getDisplay());
        self::assertStringNotContainsString('Antônio', $tester->getDisplay());
        self::assertNotNull($this->area->noDisco($chave), 'o arquivo continua no lugar');
        self::assertFalse($this->area->estaNosLiberados($chave, self::LOTE));
        self::assertNull($this->liberacoes->abertaDe($this->remota($chave)));
        self::assertSame(0, $this->espelho->downloads, 'a simulação não vai ao R2');
        self::assertDirectoryDoesNotExist($this->manifestos);
    }

    #[TestDox('com --executar: baixa do R2, abre a linha do lote, move para .liberados sem apagar e grava o manifesto')]
    public function testExecutarMoveERegistra(): void
    {
        $chave = $this->arquivoProvado('a.pdf', 'conteúdo frio');

        $tester = $this->liberar(['--executar' => true, '--confirmo-reconciliacao-limpa' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Liberados: 1 arquivo(s)', $tester->getDisplay());
        self::assertNull($this->area->noDisco($chave), 'saiu do lugar');
        self::assertTrue($this->area->estaNosLiberados($chave, self::LOTE), 'foi para os liberados do lote');
        self::assertSame(hash('sha256', 'conteúdo frio'), $this->area->sha256NosLiberados($chave, self::LOTE));
        $linha = $this->liberacoes->abertaDe($this->remota($chave));
        self::assertNotNull($linha);
        self::assertSame(self::LOTE, $linha->lote);
        self::assertSame(1, $this->espelho->downloads, 'o R2 foi provado por download');

        $manifesto = (string) file_get_contents($this->manifestos . '/' . self::LOTE . '.tsv');
        self::assertStringContainsString($this->remota($chave) . "\t" . hash('sha256', 'conteúdo frio') . "\t" . \strlen('conteúdo frio'), $manifesto);
    }

    #[TestDox('SHA local diferente do envio verificado (mesmo tamanho) e R2 divergente: pulados, nada se move e nenhuma linha fica aberta')]
    public function testShaDivergentePula(): void
    {
        $localMudou = $this->arquivoProvado('local.pdf', 'abcd', shaDoEnvio: hash('sha256', 'abce'));
        $r2Errado   = $this->arquivoProvado('r2.pdf', 'wxyz', corpoNoR2: 'wxyy');

        $tester = $this->liberar(['--executar' => true, '--confirmo-reconciliacao-limpa' => true]);

        self::assertStringContainsString(LiberadorDeDisco::SHA_LOCAL_DIVERGENTE, $tester->getDisplay());
        self::assertStringContainsString(LiberadorDeDisco::FALHA_NO_R2, $tester->getDisplay());
        self::assertStringContainsString('Liberados: 0 arquivo(s)', $tester->getDisplay());
        foreach ([$localMudou, $r2Errado] as $chave) {
            self::assertNotNull($this->area->noDisco($chave));
            self::assertNull($this->liberacoes->abertaDe($this->remota($chave)));
        }
    }

    #[TestDox('destino já ocupado em .liberados: pula, não sobrescreve, e a linha aberta fecha')]
    public function testDestinoExistentePula(): void
    {
        $chave   = $this->arquivoProvado('ocupado.pdf', 'conteúdo frio');
        $destino = $this->resolvedor->caminhoNosLiberados($chave, self::LOTE);
        mkdir(\dirname($destino), 0o750, true);
        file_put_contents($destino, 'outro');

        $tester = $this->liberar(['--executar' => true, '--confirmo-reconciliacao-limpa' => true]);

        self::assertStringContainsString(LiberadorDeDisco::DESTINO_EXISTE, $tester->getDisplay());
        self::assertSame('outro', file_get_contents($destino), 'nunca sobrescreve');
        self::assertNotNull($this->area->noDisco($chave), 'o original fica');
        self::assertNull($this->liberacoes->abertaDe($this->remota($chave)), 'a linha fechou');
    }

    #[TestDox('frio: mtime recente, atime recente e data do banco recente ficam fora; --sem-acesso ignora só o atime')]
    public function testFrioRespeitado(): void
    {
        $this->arquivoProvado('novo.pdf', 'recente', idadeEmDias: 10);
        $acessado = $this->arquivoProvado('acessado.pdf', 'acessado', acessoHaDias: 5);
        $pasta    = PastaFactory::createOne();
        $tenant   = (int) $pasta->getTenant()->getId();
        $id       = (int) $this->conexao->fetchOne(
            "INSERT INTO pasta_documento (titulo, categoria, caminho_arquivo, nome_original, mime_type, tamanho_bytes, uploaded_at, pasta_id, tenant_id, ordem)
             VALUES ('t', 'outros', 'x', 'x', 'application/pdf', 1, ?, ?, ?, 0) RETURNING id",
            [$this->relogio->now()->modify('-5 days')->format('Y-m-d H:i:s'), $pasta->getId(), $tenant],
        );
        $this->arquivoProvado('banco-recente.pdf', 'pelo banco', tenant: $tenant, idDaLinha: $id);

        $tester = $this->liberar([]);
        self::assertStringContainsString('Candidatos: 0 arquivo(s)', $tester->getDisplay());
        self::assertMatchesRegularExpression('/' . LiberadorDeDisco::RECENTE . '\s+2/', $tester->getDisplay(), 'mtime e data do banco');
        self::assertMatchesRegularExpression('/' . LiberadorDeDisco::ACESSADO_RECENTEMENTE . '\s+1/', $tester->getDisplay());

        $tester = $this->liberar(['--sem-acesso' => true, '--executar' => true, '--confirmo-reconciliacao-limpa' => true]);
        self::assertStringContainsString('Liberados: 1 arquivo(s)', $tester->getDisplay());
        self::assertTrue($this->area->estaNosLiberados($acessado, self::LOTE));
    }

    #[TestDox('do mais antigo para o mais novo: com --lote=1 sai o mais velho')]
    public function testOrdemDoMaisAntigo(): void
    {
        $novo  = $this->arquivoProvado('novo.pdf', 'menos velho', idadeEmDias: 100);
        $velho = $this->arquivoProvado('velho.pdf', 'mais velho', idadeEmDias: 400);

        $this->liberar(['--lote' => '1', '--executar' => true, '--confirmo-reconciliacao-limpa' => true]);

        self::assertTrue($this->area->estaNosLiberados($velho, self::LOTE));
        self::assertNotNull($this->area->noDisco($novo));
    }

    #[TestDox('exclusão registrada depois do envio e linha já aberta: ficam fora dos candidatos')]
    public function testExclusaoEJaLiberadoFicamFora(): void
    {
        $excluido = $this->arquivoProvado('excluido.pdf', 'excluído');
        $this->outbox->registrarExclusao($excluido);
        $liberado = $this->arquivoProvado('liberado.pdf', 'liberado');
        $this->liberacoes->registrar('outro-lote', $liberado, hash('sha256', 'liberado'), \strlen('liberado'));

        $tester = $this->liberar([]);

        self::assertStringContainsString('Candidatos: 0 arquivo(s)', $tester->getDisplay());
        self::assertStringContainsString(LiberadorDeDisco::SEM_ENVIO_VERIFICADO, $tester->getDisplay());
        self::assertStringContainsString(LiberadorDeDisco::JA_LIBERADO, $tester->getDisplay());
    }

    #[TestDox('recusa: categoria fora das duas aceitas, categoria não ligada, execução sem a confirmação ou com falha recente da reconciliação')]
    public function testRecusas(): void
    {
        $chave = $this->arquivoProvado('a.pdf', 'conteúdo frio');

        self::assertSame(Command::INVALID, $this->liberar(['--categoria' => 'cliente_documento'])->getStatusCode());
        self::assertSame(Command::INVALID, $this->liberar(['--categoria' => 'nao_existe'])->getStatusCode());

        $desligada = $this->liberar([], new PoliticaDeEspelhamento('cliente_documento'));
        self::assertSame(Command::FAILURE, $desligada->getStatusCode());
        self::assertStringContainsString('não está ligada', $desligada->getDisplay());

        $semConfirmar = $this->liberar(['--executar' => true]);
        self::assertSame(Command::FAILURE, $semConfirmar->getStatusCode());
        self::assertStringContainsString('--confirmo-reconciliacao-limpa', $semConfirmar->getDisplay());

        static::getContainer()->get(FalhaAgendadaRepository::class)->registrar(
            LiberarDiscoCommand::COMANDO_DA_RECONCILIACAO,
            $this->relogio->now()->modify('-2 days'),
            'RunCommandFailedException',
            1,
        );
        $comFalha = $this->liberar(['--executar' => true, '--confirmo-reconciliacao-limpa' => true]);
        self::assertSame(Command::FAILURE, $comFalha->getStatusCode());
        self::assertStringContainsString('reconciliação agendada falhou', $comFalha->getDisplay());

        self::assertNotNull($this->area->noDisco($chave), 'nenhuma recusa move nada');
        self::assertSame(0, $this->espelho->downloads);
    }

    // ------------------------------------------------------------- restaurar

    #[TestDox('restaurar: a simulação não muda nada; executar devolve ao lugar com o mesmo SHA e fecha a linha')]
    public function testRestaurarDevolveEFecha(): void
    {
        $chave = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $this->liberar(['--executar' => true, '--confirmo-reconciliacao-limpa' => true]);

        $simulado = $this->restaurar(['--lote' => self::LOTE]);
        self::assertSame(Command::SUCCESS, $simulado->getStatusCode());
        self::assertStringContainsString(RestauradorDeLote::DEVOLVERIA, $simulado->getDisplay());
        self::assertNull($this->area->noDisco($chave));
        self::assertNotNull($this->liberacoes->abertaDe($this->remota($chave)));

        $executado = $this->restaurar(['--lote' => self::LOTE, '--executar' => true]);
        self::assertStringContainsString(RestauradorDeLote::DEVOLVIDO, $executado->getDisplay());
        self::assertSame(hash('sha256', 'conteúdo frio'), $this->area->sha256NoDisco($chave));
        self::assertFalse($this->area->estaNosLiberados($chave, self::LOTE));
        self::assertNull($this->liberacoes->abertaDe($this->remota($chave)), 'a linha fechou');
    }

    #[TestDox('restaurar com o original já presente: pula e avisa, sem sobrescrever; a cópia e a linha ficam')]
    public function testRestaurarComOriginalPresentePula(): void
    {
        $chave = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $this->liberar(['--executar' => true, '--confirmo-reconciliacao-limpa' => true]);
        $this->local->gravar($chave, FonteDeConteudo::deTexto('regravado'));

        $tester = $this->restaurar(['--lote' => self::LOTE, '--executar' => true]);

        self::assertStringContainsString(RestauradorDeLote::ORIGINAL_EXISTE, $tester->getDisplay());
        self::assertStringContainsString('Confira à mão', $tester->getDisplay());
        self::assertSame('regravado', $this->local->ler($chave));
        self::assertTrue($this->area->estaNosLiberados($chave, self::LOTE));
        self::assertNotNull($this->liberacoes->abertaDe($this->remota($chave)));
    }

    #[TestDox('restaurar fora dos liberados (lote esvaziado): baixa do R2, confere o SHA, grava no lugar e fecha a linha')]
    public function testRestaurarPeloR2(): void
    {
        $chave = $this->chave('esvaziado.pdf');
        $this->espelho->semear($chave, 'só no R2');
        $this->liberacoes->registrar('lote-antigo', $chave, hash('sha256', 'só no R2'), \strlen('só no R2'));

        $tester = $this->restaurar(['--chave' => $this->remota($chave), '--executar' => true]);

        self::assertStringContainsString(RestauradorDeLote::BAIXADO, $tester->getDisplay());
        self::assertSame('só no R2', $this->local->ler($chave));
        self::assertNull($this->liberacoes->abertaDe($this->remota($chave)));
    }

    #[TestDox('restaurar não devolve ao disco o que o sistema mandou apagar (exclusão depois do envio)')]
    public function testRestaurarComExclusaoPula(): void
    {
        $chave = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $this->liberar(['--executar' => true, '--confirmo-reconciliacao-limpa' => true]);
        $this->outbox->registrarExclusao($chave);

        $tester = $this->restaurar(['--lote' => self::LOTE, '--executar' => true]);

        self::assertStringContainsString(RestauradorDeLote::EXCLUSAO_REGISTRADA, $tester->getDisplay());
        self::assertNull($this->area->noDisco($chave));
    }

    #[TestDox('--baixar-para: confere o R2 numa pasta do operador (OK ou DIVERGE) sem tocar no disco, nos liberados nem na linha')]
    public function testBaixarParaNaoTocaNoDisco(): void
    {
        $chave = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $this->liberar(['--executar' => true, '--confirmo-reconciliacao-limpa' => true]);
        $pasta = $this->manifestos . '/conferencia';
        mkdir($pasta, 0o700, true);

        $ok = $this->restaurar(['--chave' => $this->remota($chave), '--baixar-para' => $pasta]);
        self::assertSame(Command::SUCCESS, $ok->getStatusCode(), $ok->getDisplay());
        self::assertStringContainsString('OK', $ok->getDisplay());
        self::assertSame('conteúdo frio', file_get_contents($pasta . '/' . $chave->nome));
        self::assertNull($this->area->noDisco($chave), 'o disco do sistema não muda');
        self::assertTrue($this->area->estaNosLiberados($chave, self::LOTE));
        self::assertNotNull($this->liberacoes->abertaDe($this->remota($chave)));

        self::assertSame(Command::INVALID, $this->restaurar(['--lote' => self::LOTE, '--baixar-para' => $pasta])->getStatusCode());
        $semPasta = $this->restaurar(['--chave' => $this->remota($chave), '--baixar-para' => $pasta . '/nao-existe']);
        self::assertSame(Command::FAILURE, $semPasta->getStatusCode());
        self::assertStringContainsString('não existe', $semPasta->getDisplay());
        self::assertStringNotContainsString('DIVERGE', $semPasta->getDisplay());

        // O R2 entrega outro conteúdo, do mesmo tamanho, sem lançar: o SHA medido é que acusa.
        $this->espelhoDosComandos = $this->espelhoQueMente();
        mkdir($pasta . '/outra');
        $diverge = $this->restaurar(['--chave' => $this->remota($chave), '--baixar-para' => $pasta . '/outra']);
        self::assertSame(Command::FAILURE, $diverge->getStatusCode());
        self::assertStringContainsString('DIVERGE: o arquivo baixado não tem o SHA-256', $diverge->getDisplay());
        self::assertNotNull($this->liberacoes->abertaDe($this->remota($chave)), 'a conferência não mexe na linha');
    }

    // ------------------------------------------------------------- revisão de 0c4f0b078

    #[TestDox('R2 que entrega conteúdo errado sem lançar: liberar pula como r2_divergente; restaurar pelo R2 não grava nada e a linha fica aberta')]
    public function testR2QueMente(): void
    {
        $chave                    = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $this->espelhoDosComandos = $this->espelhoQueMente();

        $tester = $this->executar();
        self::assertStringContainsString(LiberadorDeDisco::R2_DIVERGENTE, $tester->getDisplay());
        self::assertNotNull($this->area->noDisco($chave));
        self::assertNull($this->liberacoes->abertaDe($this->remota($chave)));

        $soNoR2 = $this->chave('esvaziado.pdf');
        $this->espelho->semear($soNoR2, 'só no R2');
        $this->liberacoes->registrar('lote-antigo', $soNoR2, hash('sha256', 'só no R2'), \strlen('só no R2'));
        $restaurado = $this->restaurar(['--chave' => $this->remota($soNoR2), '--executar' => true]);
        self::assertStringContainsString(RestauradorDeLote::R2_DIVERGENTE, $restaurado->getDisplay());
        self::assertNull($this->area->noDisco($soNoR2), 'nada gravado no lugar');
        self::assertNotNull($this->liberacoes->abertaDe($this->remota($soNoR2)));
        self::assertSame([], glob($this->resolvedor->diretorioDosParciaisDaLiberacao() . '/*') ?: [], 'o parcial não fica');
    }

    #[TestDox('regravado no disco durante o download: mudou_no_disco, a linha fecha e o arquivo novo fica no lugar')]
    public function testMudouNoDiscoDuranteODownload(): void
    {
        $chave                    = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $local                    = $this->local;
        $real                     = $this->espelho;
        $this->espelhoDosComandos = new class ($real, static function () use ($local, $chave): void {
            $local->gravar($chave, FonteDeConteudo::deTexto('conteúdo novo'));
        }) implements EspelhoRemoto {
            public function __construct(private readonly EspelhoRemotoEmMemoria $real, private readonly \Closure $noMeio)
            {
            }

            public function enviar(ChaveDeArquivo $chave, mixed $conteudo, int $tamanhoBytes, string $sha256, string $mimeType): void
            {
            }

            public function consultar(ChaveDeArquivo $chave): ?MetadadosRemotos
            {
                return $this->real->consultar($chave);
            }

            public function existe(ChaveDeArquivo $chave): bool
            {
                return $this->real->existe($chave);
            }

            public function baixar(ChaveDeArquivo $chave, mixed $destino, int $tamanhoEsperado, string $sha256Esperado): void
            {
                ($this->noMeio)();
                $this->real->baixar($chave, $destino, $tamanhoEsperado, $sha256Esperado);
            }

            public function excluir(ChaveDeArquivo $chave): void
            {
            }
        };

        $tester = $this->executar();

        self::assertStringContainsString(LiberadorDeDisco::MUDOU_NO_DISCO, $tester->getDisplay());
        self::assertSame('conteúdo novo', $this->local->ler($chave));
        self::assertFalse($this->area->estaNosLiberados($chave, self::LOTE));
        self::assertNull($this->liberacoes->abertaDe($this->remota($chave)));
    }

    #[TestDox('falha depois de mover: a cópia fica nos liberados, a linha aberta, e o manifesto registra a situação')]
    public function testFalhaDepoisDeMover(): void
    {
        $chave                         = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $disco                         = new DiscoDaLiberacaoComGanchos($this->area);
        $disco->antesDeMedirNosLiberados = static function (): void {
            throw new FalhaDeArmazenamento('disco ilegível (simulado)');
        };
        $this->discoDosComandos = $disco;

        $tester = $this->executar();

        self::assertStringContainsString(LiberadorDeDisco::FALHA_DEPOIS_DE_MOVER, $tester->getDisplay());
        self::assertTrue($this->area->estaNosLiberados($chave, self::LOTE));
        self::assertNotNull($this->liberacoes->abertaDe($this->remota($chave)));
        self::assertStringContainsString("\t" . LiberadorDeDisco::FALHA_DEPOIS_DE_MOVER . "\n", (string) file_get_contents($this->manifestos . '/' . self::LOTE . '.tsv'));
    }

    #[TestDox('regravado entre a medida e o movimento e o lugar ocupado de novo: copia_presa_nos_liberados, linha aberta, no manifesto; com o lugar livre, a cópia volta (mudou_no_disco)')]
    public function testCopiaPresaNosLiberados(): void
    {
        $presa  = $this->arquivoProvado('presa.pdf', 'conteúdo frio');
        $volta  = $this->arquivoProvado('volta.pdf', 'conteúdo frio');
        $disco  = new DiscoDaLiberacaoComGanchos($this->area);
        $local  = $this->local;
        $resolv = $this->resolvedor;
        $disco->depoisDeMover = static function (ChaveDeArquivo $chave, string $lote) use ($presa, $local, $resolv): void {
            file_put_contents($resolv->caminhoNosLiberados($chave, $lote), 'outra coisa!!');
            if ($chave->nome === $presa->nome) {
                $local->gravar($chave, FonteDeConteudo::deTexto('upload novo'));
            }
        };
        $this->discoDosComandos = $disco;

        $tester = $this->executar();

        self::assertStringContainsString(LiberadorDeDisco::COPIA_PRESA_NOS_LIBERADOS, $tester->getDisplay());
        self::assertStringContainsString(LiberadorDeDisco::MUDOU_NO_DISCO, $tester->getDisplay());
        self::assertTrue($this->area->estaNosLiberados($presa, self::LOTE));
        self::assertSame('upload novo', $this->local->ler($presa));
        self::assertNotNull($this->liberacoes->abertaDe($this->remota($presa)), 'presa: a linha fica aberta');
        self::assertSame('outra coisa!!', $this->local->ler($volta), 'a cópia voltou ao lugar');
        self::assertNull($this->liberacoes->abertaDe($this->remota($volta)));
        self::assertStringContainsString($this->remota($presa) . "\t", (string) file_get_contents($this->manifestos . '/' . self::LOTE . '.tsv'));
    }

    #[TestDox('falha ao mover depois de abrir a linha: nenhuma linha fica aberta sem o arquivo ter saído')]
    public function testLinhaNaoFicaAbertaSemMover(): void
    {
        $chave               = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $disco               = new DiscoDaLiberacaoComGanchos($this->area);
        $disco->antesDeMover = static function (): void {
            throw new FalhaDeArmazenamento('rename recusado (simulado)');
        };
        $this->discoDosComandos = $disco;

        $tester = $this->executar();

        self::assertStringContainsString(LiberadorDeDisco::FALHA_NO_DISCO, $tester->getDisplay());
        self::assertNotNull($this->area->noDisco($chave));
        self::assertNull($this->liberacoes->abertaDe($this->remota($chave)));
    }

    #[TestDox('a medição não aquece o arquivo: duas simulações seguidas com o critério de acesso, e ele continua candidato com o mesmo atime')]
    public function testSimulacaoPreservaOAtime(): void
    {
        $chave   = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $caminho = $this->resolvedor->caminhoDe($chave);
        clearstatcache();
        $atime = fileatime($caminho);

        foreach ([1, 2] as $rodada) {
            $tester = $this->liberar([]);
            self::assertStringContainsString('para o mais novo): 1 arquivo(s)', $tester->getDisplay(), 'rodada ' . $rodada);
            clearstatcache();
            self::assertSame($atime, fileatime($caminho), 'rodada ' . $rodada);
        }
        self::assertStringContainsString('Tempo da seleção:', $tester->getDisplay());
    }

    #[TestDox('várias linhas do banco para a mesma chave: vale a data mais nova; linha na lixeira tira a chave dos candidatos')]
    public function testDataMaisNovaELixeira(): void
    {
        $pasta  = PastaFactory::createOne();
        $tenant = (int) $pasta->getTenant()->getId();
        $linha  = fn (int $dias, bool $naLixeira = false): int => (int) $this->conexao->fetchOne(
            "INSERT INTO pasta_documento (titulo, categoria, caminho_arquivo, nome_original, mime_type, tamanho_bytes, uploaded_at, pasta_id, tenant_id, ordem, excluido_em)
             VALUES ('t', 'outros', 'x', 'x', 'application/pdf', 1, ?, ?, ?, 0, ?) RETURNING id",
            [$this->relogio->now()->modify(sprintf('-%d days', $dias))->format('Y-m-d H:i:s'), $pasta->getId(), $tenant, $naLixeira ? $this->relogio->now()->format('Y-m-d H:i:s') : null],
        );

        $duas = $this->arquivoProvado('duas.pdf', 'duas linhas', tenant: $tenant, idDaLinha: $linha(400));
        $this->referencias[] = new ReferenciaDeArquivo('pasta_documento', $linha(5), $duas, CategoriaDeArquivo::PASTA_DOCUMENTO);
        $this->arquivoProvado('lixeira.pdf', 'na lixeira', tenant: $tenant, idDaLinha: $linha(400, true));
        $this->arquivoProvado('velha.pdf', 'velha', tenant: $tenant, idDaLinha: $linha(400));

        $tester = $this->liberar([]);

        self::assertStringContainsString('Candidatos: 1 arquivo(s)', $tester->getDisplay(), 'só a velha');
        self::assertMatchesRegularExpression('/' . LiberadorDeDisco::RECENTE . '\s+1/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/' . LiberadorDeDisco::NA_LIXEIRA . '\s+1/', $tester->getDisplay());
    }

    #[TestDox('restaurar --chave com o original no lugar e o mesmo SHA: ja_no_lugar, e a linha fecha')]
    public function testRestaurarComOriginalIgualFecha(): void
    {
        $chave = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $this->executar();
        $this->local->gravar($chave, FonteDeConteudo::deTexto('conteúdo frio'));

        $tester = $this->restaurar(['--chave' => $this->remota($chave), '--executar' => true]);

        self::assertStringContainsString(RestauradorDeLote::JA_NO_LUGAR, $tester->getDisplay());
        self::assertNull($this->liberacoes->abertaDe($this->remota($chave)));
        self::assertSame('conteúdo frio', $this->local->ler($chave));
    }

    #[TestDox('excluir um liberado apaga a cópia em .liberados, fecha a linha e registra a exclusão na outbox')]
    public function testExcluirLiberadoApagaACopia(): void
    {
        $chave = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $this->executar();

        $this->espelhado()->excluir($chave);

        self::assertFalse($this->area->estaNosLiberados($chave, self::LOTE));
        self::assertNull($this->liberacoes->abertaDe($this->remota($chave)));
        self::assertTrue($this->outbox->temExclusaoDepoisDoUltimoEnvio($this->remota($chave)));
    }

    #[TestDox('purga por prefixo apaga as cópias liberadas do escritório na categoria, sem tocar em outro escritório nem em outra categoria')]
    public function testPrefixoApagaSoOsDoEscritorio(): void
    {
        $doUm     = $this->arquivoProvado('img-1.png', 'imagem um', tenant: 990_001, categoria: CategoriaDeArquivo::PASTA_IMAGEM_EDITOR);
        $doOutro  = $this->arquivoProvado('img-2.png', 'imagem dois', tenant: 990_002, categoria: CategoriaDeArquivo::PASTA_IMAGEM_EDITOR);
        $documento = $this->arquivoProvado('doc.pdf', 'documento', tenant: 990_001);
        $this->executar(['--categoria' => 'pasta_imagem_editor']);
        $this->relogio->modify('+1 second');
        $this->executar();
        $loteDoDocumento = '20261103-090001';

        $this->espelhado('pasta_documento,pasta_imagem_editor')->excluirPrefixo(EscopoDeArquivo::deTenant(990_001), CategoriaComIsolamentoFisico::PASTA_IMAGEM_EDITOR);

        self::assertFalse($this->area->estaNosLiberados($doUm, self::LOTE));
        self::assertNull($this->liberacoes->abertaDe($this->remota($doUm)));
        self::assertTrue($this->outbox->temExclusaoDepoisDoUltimoEnvio($this->remota($doUm)), 'o objeto sai também do R2');
        self::assertTrue($this->area->estaNosLiberados($doOutro, self::LOTE), 'outro escritório');
        self::assertNotNull($this->liberacoes->abertaDe($this->remota($doOutro)));
        self::assertTrue($this->area->estaNosLiberados($documento, $loteDoDocumento), 'outra categoria');
        self::assertNotNull($this->liberacoes->abertaDe($this->remota($documento)));
    }

    #[TestDox('execução única: com a trava tomada, liberar e restaurar recusam sem mexer em nada')]
    public function testTravaDeExecucaoUnica(): void
    {
        $chave = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $trava = $this->travas->createLock(LiberadorDeDisco::TRAVA);
        self::assertTrue($trava->acquire());

        $liberar = $this->executar();
        self::assertSame(Command::FAILURE, $liberar->getStatusCode());
        self::assertStringContainsString('em andamento', $liberar->getDisplay());
        self::assertNotNull($this->area->noDisco($chave));

        $this->liberacoes->registrar('lote-x', $chave, hash('sha256', 'conteúdo frio'), \strlen('conteúdo frio'));
        $restaurar = $this->restaurar(['--lote' => 'lote-x', '--executar' => true]);
        self::assertSame(Command::FAILURE, $restaurar->getStatusCode());
        self::assertStringContainsString('em andamento', $restaurar->getDisplay());
        $trava->release();
    }

    #[TestDox('manifesto que não grava: o resumo sai mesmo assim, com o aviso, e o comando falha')]
    public function testFalhaNoManifestoNaoPerdeOResumo(): void
    {
        $this->arquivoProvado('a.pdf', 'conteúdo frio');
        file_put_contents($this->manifestos, 'um arquivo onde devia haver o diretório');

        $tester = $this->executar();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Liberados: 1 arquivo(s)', $tester->getDisplay());
        self::assertStringContainsString('manifesto não foi gravado', $tester->getDisplay());
        @unlink($this->manifestos);
    }

    #[TestDox('no container, o decorador recebe o disco da liberação (sem ele a exclusão não apagaria a cópia)')]
    public function testDecoradorDoContainerApagaLiberados(): void
    {
        $decorador = static::getContainer()->get(ArmazenamentoEspelhado::class);

        self::assertInstanceOf(DiscoDaLiberacao::class, (new \ReflectionProperty(ArmazenamentoEspelhado::class, 'liberados'))->getValue($decorador));
    }

    // ------------------------------------------------------------- esvaziar

    #[TestDox('esvaziar antes de 7 dias: nada sai; a simulação depois de 7 dias conta sem apagar')]
    public function testEsvaziarRespeitaOsSeteDiasESimula(): void
    {
        $chave = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $this->executar();

        $cedo = $this->esvaziar(['--lote' => self::LOTE, '--executar' => true, '--confirmo-backup-testado' => true]);
        self::assertStringContainsString(EsvaziadorDeLote::RECENTE, $cedo->getDisplay());
        self::assertTrue($this->area->estaNosLiberados($chave, self::LOTE));

        $this->relogio->modify('+8 days');
        $simulado = $this->esvaziar(['--lote' => self::LOTE]);
        self::assertSame(Command::SUCCESS, $simulado->getStatusCode());
        self::assertStringContainsString(EsvaziadorDeLote::ESVAZIARIA, $simulado->getDisplay());
        self::assertTrue($this->area->estaNosLiberados($chave, self::LOTE));
        self::assertStringNotContainsString($this->prefixo, $simulado->getDisplay(), 'a tela não traz nome de arquivo');
    }

    #[TestDox('esvaziar com --executar exige --confirmo-backup-testado')]
    public function testEsvaziarExigeOBackup(): void
    {
        $chave = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $this->executar();
        $this->relogio->modify('+8 days');

        $tester = $this->esvaziar(['--lote' => self::LOTE, '--executar' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--confirmo-backup-testado', $tester->getDisplay());
        self::assertTrue($this->area->estaNosLiberados($chave, self::LOTE));
    }

    #[TestDox('esvaziar apaga a cópia, a linha fica aberta, e o restaurar traz de volta pelo R2')]
    public function testEsvaziarApagaERestauraPeloR2(): void
    {
        $chave = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $this->executar();
        $this->relogio->modify('+8 days');

        $tester = $this->esvaziar(['--lote' => self::LOTE, '--executar' => true, '--confirmo-backup-testado' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Esvaziados: 1 arquivo(s)', $tester->getDisplay());
        self::assertFalse($this->area->estaNosLiberados($chave, self::LOTE));
        self::assertNull($this->area->noDisco($chave));
        self::assertNotNull($this->liberacoes->abertaDe($this->remota($chave)), 'a linha continua aberta');

        $denovo = $this->esvaziar(['--lote' => self::LOTE, '--executar' => true, '--confirmo-backup-testado' => true]);
        self::assertStringContainsString(EsvaziadorDeLote::JA_FORA, $denovo->getDisplay());

        $restaurado = $this->restaurar(['--lote' => self::LOTE, '--executar' => true]);
        self::assertStringContainsString(RestauradorDeLote::BAIXADO, $restaurado->getDisplay());
        self::assertSame('conteúdo frio', $this->local->ler($chave));
    }

    #[TestDox('esvaziar não apaga quando o R2 entrega outro conteúdo, a cópia mudou, ou a outbox não prova mais o envio')]
    public function testEsvaziarSoComTodasAsProvas(): void
    {
        $mente   = $this->arquivoProvado('mente.pdf', 'conteúdo frio');
        $copia   = $this->arquivoProvado('copia.pdf', 'outro frio');
        $apagado = $this->arquivoProvado('apagado.pdf', 'mais um frio');
        $this->executar();
        $this->relogio->modify('+8 days');
        file_put_contents($this->resolvedor->caminhoNosLiberados($copia, self::LOTE), 'mexido no disco');
        $this->outbox->registrarExclusao($apagado);

        $this->espelhoDosComandos = $this->espelhoQueMente();
        $tester = $this->esvaziar(['--lote' => self::LOTE, '--executar' => true, '--confirmo-backup-testado' => true]);

        self::assertStringContainsString(EsvaziadorDeLote::R2_DIVERGENTE, $tester->getDisplay());
        self::assertStringContainsString(EsvaziadorDeLote::COPIA_DIVERGENTE, $tester->getDisplay());
        self::assertStringContainsString(EsvaziadorDeLote::EXCLUSAO_REGISTRADA, $tester->getDisplay());
        self::assertStringContainsString('Esvaziados: 0 arquivo(s)', $tester->getDisplay());
        foreach ([$mente, $copia, $apagado] as $chave) {
            self::assertTrue($this->area->estaNosLiberados($chave, self::LOTE));
        }
    }

    #[TestDox('esvaziar não apaga com um envio mais novo ainda não verificado (regravação em curso)')]
    public function testEsvaziarComEnvioPendentePula(): void
    {
        $chave = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $this->executar();
        $this->relogio->modify('+8 days');
        $this->outbox->registrarEnvio($chave, hash('sha256', 'novo'), 4, 'application/pdf');

        $tester = $this->esvaziar(['--lote' => self::LOTE, '--executar' => true, '--confirmo-backup-testado' => true]);

        self::assertStringContainsString(EsvaziadorDeLote::SEM_ENVIO_VERIFICADO, $tester->getDisplay());
        self::assertTrue($this->area->estaNosLiberados($chave, self::LOTE));
    }

    #[TestDox('esvaziar divide a trava com liberar e restaurar')]
    public function testEsvaziarRespeitaATrava(): void
    {
        $chave = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $this->executar();
        $this->relogio->modify('+8 days');
        $trava = $this->travas->createLock(LiberadorDeDisco::TRAVA);
        self::assertTrue($trava->acquire());

        $tester = $this->esvaziar(['--lote' => self::LOTE, '--executar' => true, '--confirmo-backup-testado' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('em andamento', $tester->getDisplay());
        self::assertTrue($this->area->estaNosLiberados($chave, self::LOTE));
        $trava->release();
    }

    #[TestDox('esvaziar com a outbox limpa (envio apagado pela limpeza de 90 dias): a linha vira a prova, como na leitura')]
    public function testEsvaziarComOutboxLimpa(): void
    {
        $chave = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $this->executar();
        $this->relogio->modify('+100 days');
        $this->conexao->executeStatement('DELETE FROM armazenamento_replicacao');

        $tester = $this->esvaziar(['--lote' => self::LOTE, '--executar' => true, '--confirmo-backup-testado' => true]);

        self::assertStringContainsString('Esvaziados: 1 arquivo(s)', $tester->getDisplay());
        self::assertFalse($this->area->estaNosLiberados($chave, self::LOTE));
    }

    #[TestDox('esvaziar não apaga com o original de volta no lugar, nem com o R2 fora do ar; a simulação não chama o R2')]
    public function testEsvaziarComOriginalOuR2ForaDoAr(): void
    {
        $volta = $this->arquivoProvado('volta.pdf', 'conteúdo frio');
        $fora  = $this->arquivoProvado('fora.pdf', 'outro frio');
        $this->executar();
        $this->relogio->modify('+8 days');
        $this->local->gravar($volta, FonteDeConteudo::deTexto('conteúdo frio'));
        $this->espelho->excluir($fora);
        $this->espelhoDosComandos = $this->espelhoQueLanca();

        $simulado = $this->esvaziar(['--lote' => self::LOTE]);
        self::assertStringContainsString(EsvaziadorDeLote::ESVAZIARIA, $simulado->getDisplay(), 'a simulação não baixa');

        $this->espelhoDosComandos = null;
        $tester = $this->esvaziar(['--lote' => self::LOTE, '--executar' => true, '--confirmo-backup-testado' => true]);

        self::assertStringContainsString(EsvaziadorDeLote::ORIGINAL_NO_LUGAR, $tester->getDisplay());
        self::assertStringContainsString(EsvaziadorDeLote::FALHA_NO_R2, $tester->getDisplay());
        self::assertTrue($this->area->estaNosLiberados($volta, self::LOTE));
        self::assertTrue($this->area->estaNosLiberados($fora, self::LOTE));
    }

    #[TestDox('esvaziar com o espelho não configurado: falha e não apaga nada')]
    public function testEsvaziarSemEspelho(): void
    {
        $chave = $this->arquivoProvado('a.pdf', 'conteúdo frio');
        $this->executar();
        $this->relogio->modify('+8 days');
        $comando = new EsvaziarLoteCommand(
            new EsvaziadorDeLote($this->outbox, $this->area, $this->relogio, new NullLogger()),
            $this->liberacoes,
            ProvedorDoEspelhoFixo::naoConfigurado(),
            $this->travas,
        );
        $tester = new CommandTester($comando);
        $tester->execute(['--lote' => self::LOTE, '--executar' => true, '--confirmo-backup-testado' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertTrue($this->area->estaNosLiberados($chave, self::LOTE));
    }

    #[TestDox('no container, o comando de esvaziar está registrado')]
    public function testEsvaziarNoContainer(): void
    {
        self::assertInstanceOf(EsvaziarLoteCommand::class, static::getContainer()->get(EsvaziarLoteCommand::class));
    }

    // ------------------------------------------------------------- apoio

    /**
     * Um arquivo no disco de teste, com o envio `verificado` na outbox, o objeto no R2, a referência no
     * banco (fonte falsa) e as datas pedidas.
     */
    private function arquivoProvado(
        string $nome,
        string $conteudo,
        int $idadeEmDias = 200,
        int $acessoHaDias = 60,
        ?string $shaDoEnvio = null,
        ?string $corpoNoR2 = null,
        int $tenant = self::TENANT,
        ?int $idDaLinha = null,
        CategoriaDeArquivo $categoria = CategoriaDeArquivo::PASTA_DOCUMENTO,
    ): ChaveDeArquivo {
        $chave = $this->chave($nome, $tenant, $categoria);
        $this->local->gravar($chave, FonteDeConteudo::deTexto($conteudo));
        $agora = $this->relogio->now()->getTimestamp();
        touch($this->resolvedor->caminhoDe($chave), $agora - $idadeEmDias * 86_400, $agora - $acessoHaDias * 86_400);

        $id = $this->outbox->registrarEnvio($chave, $shaDoEnvio ?? hash('sha256', $conteudo), \strlen($conteudo), 'application/pdf');
        $this->conexao->executeStatement("UPDATE armazenamento_replicacao SET estado = 'verificado' WHERE id = ?", [$id]);
        $this->espelho->semear($chave, $corpoNoR2 ?? $conteudo);
        $this->referencias[] = new ReferenciaDeArquivo($categoria->value, $idDaLinha ?? $this->proximoId++, $chave, $categoria);

        return $chave;
    }

    private function chave(string $nome, int $tenant = self::TENANT, CategoriaDeArquivo $categoria = CategoriaDeArquivo::PASTA_DOCUMENTO): ChaveDeArquivo
    {
        $chave           = new ChaveDeArquivo(EscopoDeArquivo::deTenant($tenant), $categoria, $this->prefixo . $nome);
        $this->criadas[] = $chave;

        return $chave;
    }

    private function remota(ChaveDeArquivo $chave): string
    {
        return LayoutDeChaveRemota::chaveDe($chave);
    }

    /** @param array<string, mixed> $opcoes */
    private function liberar(array $opcoes, ?PoliticaDeEspelhamento $politica = null): CommandTester
    {
        $fontes = new class ($this->referencias) implements FonteDeReferencias {
            /** @param list<ReferenciaDeArquivo> $referencias */
            public function __construct(private readonly array $referencias)
            {
            }

            public function referencias(): iterable
            {
                return $this->referencias;
            }
        };
        $liberador = new LiberadorDeDisco($fontes, new DatasDasReferencias($this->conexao), $this->outbox, $this->liberacoes, $this->discoDosComandos ?? $this->area, $this->relogio);
        $comando   = new LiberarDiscoCommand(
            $liberador,
            new ArquivosDoOperador($this->manifestos),
            ProvedorDoEspelhoFixo::com($this->espelhoDosComandos ?? $this->espelho),
            $politica ?? new PoliticaDeEspelhamento('pasta_documento,pasta_imagem_editor'),
            $this->outbox,
            static::getContainer()->get(FalhaAgendadaRepository::class),
            $this->relogio,
            $this->travas,
        );
        $tester = new CommandTester($comando);
        $tester->execute($opcoes + ['--categoria' => 'pasta_documento']);

        return $tester;
    }

    /** @param array<string, mixed> $opcoes */
    private function executar(array $opcoes = []): CommandTester
    {
        return $this->liberar($opcoes + ['--executar' => true, '--confirmo-reconciliacao-limpa' => true]);
    }

    private function espelhado(string $categorias = 'pasta_documento'): ArmazenamentoEspelhado
    {
        return new ArmazenamentoEspelhado(
            $this->local,
            $this->outbox,
            new PoliticaDeEspelhamento($categorias),
            new NullLogger(),
            ProvedorDoEspelhoFixo::naoConfigurado(),
            new CacheDeLeituraDoEspelho(sys_get_temp_dir() . '/jusprime-cache-nao-usado', 0),
            $this->liberacoes,
            $this->area,
        );
    }

    /** @param array<string, mixed> $opcoes */
    private function restaurar(array $opcoes): CommandTester
    {
        $comando = new RestaurarLoteCommand(
            new RestauradorDeLote($this->outbox, $this->liberacoes, $this->discoDosComandos ?? $this->area),
            $this->liberacoes,
            new ArquivosDoOperador($this->manifestos),
            ProvedorDoEspelhoFixo::com($this->espelhoDosComandos ?? $this->espelho),
            $this->travas,
        );
        $tester = new CommandTester($comando);
        $tester->execute($opcoes);

        return $tester;
    }

    /** @param array<string, mixed> $opcoes */
    private function esvaziar(array $opcoes): CommandTester
    {
        $comando = new EsvaziarLoteCommand(
            new EsvaziadorDeLote($this->outbox, $this->discoDosComandos ?? $this->area, $this->relogio, new NullLogger()),
            $this->liberacoes,
            ProvedorDoEspelhoFixo::com($this->espelhoDosComandos ?? $this->espelho),
            $this->travas,
        );
        $tester = new CommandTester($comando);
        $tester->execute($opcoes);

        return $tester;
    }

    /** Um espelho que falha o teste se for chamado (a simulação não pode baixar). */
    private function espelhoQueLanca(): EspelhoRemoto
    {
        return new class implements EspelhoRemoto {
            public function enviar(ChaveDeArquivo $chave, mixed $conteudo, int $tamanhoBytes, string $sha256, string $mimeType): void
            {
                throw new \LogicException('não devia chamar o R2');
            }

            public function consultar(ChaveDeArquivo $chave): ?MetadadosRemotos
            {
                throw new \LogicException('não devia chamar o R2');
            }

            public function existe(ChaveDeArquivo $chave): bool
            {
                throw new \LogicException('não devia chamar o R2');
            }

            public function baixar(ChaveDeArquivo $chave, mixed $destino, int $tamanhoEsperado, string $sha256Esperado): void
            {
                throw new \LogicException('não devia chamar o R2');
            }

            public function excluir(ChaveDeArquivo $chave): void
            {
                throw new \LogicException('não devia chamar o R2');
            }
        };
    }

    /** Um espelho que responde como o em memória, mas no download entrega bytes errados do mesmo tamanho, sem lançar. */
    private function espelhoQueMente(): EspelhoRemoto
    {
        return new class ($this->espelho) implements EspelhoRemoto {
            public function __construct(private readonly EspelhoRemotoEmMemoria $real)
            {
            }

            public function enviar(ChaveDeArquivo $chave, mixed $conteudo, int $tamanhoBytes, string $sha256, string $mimeType): void
            {
                $this->real->enviar($chave, $conteudo, $tamanhoBytes, $sha256, $mimeType);
            }

            public function consultar(ChaveDeArquivo $chave): ?MetadadosRemotos
            {
                return $this->real->consultar($chave);
            }

            public function existe(ChaveDeArquivo $chave): bool
            {
                return $this->real->existe($chave);
            }

            public function baixar(ChaveDeArquivo $chave, mixed $destino, int $tamanhoEsperado, string $sha256Esperado): void
            {
                fwrite($destino, str_repeat('?', $tamanhoEsperado));
            }

            public function excluir(ChaveDeArquivo $chave): void
            {
                $this->real->excluir($chave);
            }
        };
    }

    private function apagarArvore(string $diretorio): void
    {
        if (!is_dir($diretorio) || is_link($diretorio)) {
            return;
        }
        foreach (scandir($diretorio) ?: [] as $entrada) {
            if ($entrada === '.' || $entrada === '..') {
                continue;
            }
            $caminho = $diretorio . '/' . $entrada;
            is_dir($caminho) && !is_link($caminho) ? $this->apagarArvore($caminho) : @unlink($caminho);
        }
        @rmdir($diretorio);
    }
}
