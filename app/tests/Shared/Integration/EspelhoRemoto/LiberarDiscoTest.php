<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\Shared\Armazenamento\AreaDeLiberados;
use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\ResolvedorDeCaminhoLocal;
use App\Shared\EspelhoRemoto\Command\LiberarDiscoCommand;
use App\Shared\EspelhoRemoto\Command\RestaurarLoteCommand;
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
        self::assertStringContainsString('nada foi sobrescrito', $tester->getDisplay());
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

        $this->espelho->semear($chave, 'corrompido');
        $diverge = $this->restaurar(['--chave' => $this->remota($chave), '--baixar-para' => $pasta . '/outra']);
        self::assertSame(Command::FAILURE, $diverge->getStatusCode());
        self::assertSame(Command::INVALID, $this->restaurar(['--lote' => self::LOTE, '--baixar-para' => $pasta])->getStatusCode());

        mkdir($pasta . '/outra');
        $diverge = $this->restaurar(['--chave' => $this->remota($chave), '--baixar-para' => $pasta . '/outra']);
        self::assertSame(Command::FAILURE, $diverge->getStatusCode());
        self::assertStringContainsString('DIVERGE', $diverge->getDisplay());
        self::assertFileDoesNotExist($pasta . '/outra/' . $chave->nome, 'o download que não confere não fica');
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
    ): ChaveDeArquivo {
        $chave = $this->chave($nome, $tenant);
        $this->local->gravar($chave, FonteDeConteudo::deTexto($conteudo));
        $agora = $this->relogio->now()->getTimestamp();
        touch($this->resolvedor->caminhoDe($chave), $agora - $idadeEmDias * 86_400, $agora - $acessoHaDias * 86_400);

        $id = $this->outbox->registrarEnvio($chave, $shaDoEnvio ?? hash('sha256', $conteudo), \strlen($conteudo), 'application/pdf');
        $this->conexao->executeStatement("UPDATE armazenamento_replicacao SET estado = 'verificado' WHERE id = ?", [$id]);
        $this->espelho->semear($chave, $corpoNoR2 ?? $conteudo);
        $this->referencias[] = new ReferenciaDeArquivo('pasta_documento', $idDaLinha ?? $this->proximoId++, $chave, CategoriaDeArquivo::PASTA_DOCUMENTO);

        return $chave;
    }

    private function chave(string $nome, int $tenant = self::TENANT): ChaveDeArquivo
    {
        $chave           = new ChaveDeArquivo(EscopoDeArquivo::deTenant($tenant), CategoriaDeArquivo::PASTA_DOCUMENTO, $this->prefixo . $nome);
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
        $liberador = new LiberadorDeDisco($fontes, new DatasDasReferencias($this->conexao), $this->outbox, $this->liberacoes, $this->area, $this->relogio);
        $comando   = new LiberarDiscoCommand(
            $liberador,
            new ArquivosDoOperador($this->manifestos),
            ProvedorDoEspelhoFixo::com($this->espelho),
            $politica ?? new PoliticaDeEspelhamento('pasta_documento,pasta_imagem_editor'),
            $this->outbox,
            static::getContainer()->get(FalhaAgendadaRepository::class),
            $this->relogio,
        );
        $tester = new CommandTester($comando);
        $tester->execute($opcoes + ['--categoria' => 'pasta_documento']);

        return $tester;
    }

    /** @param array<string, mixed> $opcoes */
    private function restaurar(array $opcoes): CommandTester
    {
        $comando = new RestaurarLoteCommand(
            new RestauradorDeLote($this->outbox, $this->liberacoes, $this->area),
            $this->liberacoes,
            new ArquivosDoOperador($this->manifestos),
            ProvedorDoEspelhoFixo::com($this->espelho),
        );
        $tester = new CommandTester($comando);
        $tester->execute($opcoes);

        return $tester;
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
