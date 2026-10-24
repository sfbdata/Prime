<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\EspelhoRemoto\Command\EspelhoSituacaoCommand;
use App\Shared\EspelhoRemoto\Command\LimparReplicacaoCommand;
use App\Shared\EspelhoRemoto\Command\ReconciliarEspelhoCommand;
use App\Shared\EspelhoRemoto\Command\ReplicarEspelhoCommand;
use App\Shared\EspelhoRemoto\EspelhoRemoto;
use App\Shared\EspelhoRemoto\FabricaDoEspelhoRemoto;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;
use App\Shared\EspelhoRemoto\ProvedorDoEspelhoRemoto;
use App\Shared\EspelhoRemoto\Reconciliacao\AlertaDaReconciliacao;
use App\Shared\EspelhoRemoto\Reconciliacao\FonteDeReferencias;
use App\Shared\EspelhoRemoto\Reconciliacao\ReconciliadorDoEspelho;
use App\Shared\EspelhoRemoto\Reconciliacao\ReferenciaDeArquivo;
use App\Shared\EspelhoRemoto\Replica\ReplicadorDeArquivos;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\EspelhoRemotoEmMemoria;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * O modo agendado (`--se-ligado`) dos comandos do espelho (P2.5) e o alerta da reconciliação (P2.7).
 *
 * Desligado: sucesso, "espelho desligado", sem tocar no R2 nem na outbox. Ligado: a reconciliação
 * confere só as categorias da política e falha quando há divergência que exige ação, com mensagem só
 * de contagens (sem nome de arquivo, chave remota ou nome de pessoa).
 */
#[CoversClass(ReplicarEspelhoCommand::class)]
#[CoversClass(ReconciliarEspelhoCommand::class)]
#[CoversClass(LimparReplicacaoCommand::class)]
#[CoversClass(EspelhoSituacaoCommand::class)]
#[CoversClass(AlertaDaReconciliacao::class)]
#[CoversClass(OutboxDeReplicacao::class)]
final class ModoAgendadoDoEspelhoTest extends KernelTestCase
{
    private const NOME_DE_PESSOA = 'Foto de Antônio Carlos.jpg';
    private const CONTEUDO_SUSPEITO = 'erro-401-do-drive';

    private Connection $conexao;
    private ArmazenamentoEmMemoria $local;
    private EspelhoRemotoEmMemoria $espelho;
    private OutboxDeReplicacao $outbox;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao = static::getContainer()->get(Connection::class);
        $this->conexao->executeStatement('DELETE FROM armazenamento_replicacao');
        $this->local   = new ArmazenamentoEmMemoria();
        $this->espelho = new EspelhoRemotoEmMemoria();
        $this->outbox  = new OutboxDeReplicacao($this->conexao, new MockClock('2026-10-24 12:00:00', 'UTC'));
    }

    #[TestDox('desligado no container (sem ESPELHO_R2_* e política vazia): replicar e reconciliar com --se-ligado saem com sucesso sem tocar na outbox')]
    public function testDesligadoNoContainerNaoTocaNaTabela(): void
    {
        $aplicacao = new Application(self::$kernel ?? self::bootKernel());
        // Sem a tabela: qualquer leitura ou escrita na outbox lançaria. O DAMA desfaz o RENAME no fim.
        $this->conexao->executeStatement('ALTER TABLE armazenamento_replicacao RENAME TO armazenamento_replicacao_escondida');

        foreach (['armazenamento:replicar-espelho', 'armazenamento:reconciliar-espelho'] as $nome) {
            $tester = new CommandTester($aplicacao->find($nome));

            self::assertSame(Command::SUCCESS, $tester->execute(['--se-ligado' => true]), $nome);
            self::assertStringContainsString('Espelho desligado', $tester->getDisplay(), $nome);
        }
    }

    #[TestDox('limpar com --se-ligado: sem a tabela da outbox, sai com sucesso e "espelho desligado"')]
    public function testLimparSemTabelaPula(): void
    {
        $this->conexao->executeStatement('ALTER TABLE armazenamento_replicacao RENAME TO armazenamento_replicacao_escondida');
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('armazenamento:limpar-replicacao'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--se-ligado' => true]));
        self::assertStringContainsString('Espelho desligado', $tester->getDisplay());
    }

    #[TestDox('limpar com --se-ligado e política vazia: a retenção continua valendo (a linha antiga sai)')]
    public function testLimparComPoliticaVaziaContinuaApagando(): void
    {
        $id = $this->outbox->registrarEnvio($this->chave(CategoriaDeArquivo::PASTA_DOCUMENTO, 'antigo.pdf'), hash('sha256', 'x'), 1, 'application/pdf');
        $this->conexao->executeStatement(
            "UPDATE armazenamento_replicacao SET estado = 'verificado', atualizado_em = (now() AT TIME ZONE 'UTC') - interval '100 days' WHERE id = ?",
            [$id],
        );
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('armazenamento:limpar-replicacao'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--se-ligado' => true]));
        self::assertFalse($this->conexao->fetchOne('SELECT 1 FROM armazenamento_replicacao WHERE id = ?', [$id]));
    }

    #[TestDox('política ligada mas sem ESPELHO_R2_*: replicar e reconciliar com --se-ligado saem com sucesso e a pendente fica intocada')]
    public function testPoliticaLigadaSemCredencialPula(): void
    {
        $id          = $this->outbox->registrarEnvio($this->chave(CategoriaDeArquivo::FOTO_PERFIL, 'p.jpg'), hash('sha256', 'x'), 1, 'image/jpeg');
        $politica    = new PoliticaDeEspelhamento('foto_perfil');
        $semR2       = new FabricaDoEspelhoRemoto();
        $replicar    = new ReplicarEspelhoCommand(static::getContainer()->get(ReplicadorDeArquivos::class), $semR2, $this->outbox, $politica);
        $reconciliar = new ReconciliarEspelhoCommand($this->reconciliador([]), $semR2, $politica, $this->outbox, new MockClock());

        foreach ([$replicar, $reconciliar] as $comando) {
            $tester = new CommandTester($comando);
            self::assertSame(Command::SUCCESS, $tester->execute(['--se-ligado' => true]));
            self::assertStringContainsString('Espelho desligado', $tester->getDisplay());
        }

        self::assertSame(
            ['estado' => 'pendente', 'tentativas' => 0],
            array_map(static fn (mixed $v): mixed => is_numeric($v) ? (int) $v : $v, (array) $this->conexao->fetchAssociative('SELECT estado, tentativas FROM armazenamento_replicacao WHERE id = ?', [$id])),
        );

        // Sem a opção, o comportamento de antes: recusa com falha.
        self::assertSame(Command::FAILURE, (new CommandTester($replicar))->execute([]));
    }

    #[TestDox('ligado, com divergência (ausente no espelho e linha em falhou): FALHA, com contagem por classe e categoria e sem nome nem chave')]
    public function testDivergenciaFalhaSemNomeNemChave(): void
    {
        $foto = $this->noDisco(CategoriaDeArquivo::FOTO_PERFIL, self::NOME_DE_PESSOA, 'foto');
        // Categoria desligada: ausente no espelho, mas fora da conferência agendada.
        $pasta = $this->noDisco(CategoriaDeArquivo::PASTA_DOCUMENTO, 'RG de Antônio.pdf', 'rg');
        $falha = $this->outbox->registrarEnvio($this->chave(CategoriaDeArquivo::CLIENTE_DOCUMENTO, 'CPF de Antônio.pdf'), hash('sha256', 'c'), 1, 'application/pdf');
        $this->conexao->executeStatement("UPDATE armazenamento_replicacao SET estado = 'falhou' WHERE id = ?", [$falha]);

        $tester = $this->reconciliarLigado([$foto, $pasta]);

        self::assertSame(Command::FAILURE, $tester->execute(['--se-ligado' => true]));
        $saida = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('ausente_no_espelho: 1 (foto_perfil: 1)', $saida);
        self::assertStringContainsString('falhou_na_outbox: 1 (cliente_documento: 1)', $saida);
        self::assertStringNotContainsString('pasta_documento', $saida, 'categoria desligada não é conferida');
        foreach (['Antônio', 'Carlos', '.jpg', '.pdf', 'core/'] as $proibido) {
            self::assertStringNotContainsString($proibido, $saida);
        }
    }

    #[TestDox('ligado, sem divergência que exija ação (igual, suspeito conhecido do Drive, presente sem SHA): SUCESSO')]
    public function testSemDivergenciaPassa(): void
    {
        $igual    = $this->noDisco(CategoriaDeArquivo::FOTO_PERFIL, 'igual.jpg', 'foto', noEspelho: true);
        $suspeito = $this->noDisco(CategoriaDeArquivo::FOTO_PERFIL, 'drive.jpg', self::CONTEUDO_SUSPEITO);

        $comSha = $this->reconciliarLigado([$igual, $suspeito]);
        self::assertSame(Command::SUCCESS, $comSha->execute(['--se-ligado' => true]));
        self::assertStringContainsString('suspeito_erro_do_drive', $comSha->getDisplay(), 'o suspeito aparece na contagem');

        $semSha = $this->reconciliarLigado([$igual]);
        self::assertSame(Command::SUCCESS, $semSha->execute(['--se-ligado' => true, '--sem-sha' => true]));
        self::assertStringContainsString('presente_sem_sha_conferido', $semSha->getDisplay());
    }

    #[TestDox('--fatia=auto confere o SHA só da fatia do dia em Brasília (sábado = 6); "todas" confere tudo; fatia inválida é recusada')]
    public function testFatiaDoSha(): void
    {
        $naFatia   = null;
        $foraDela  = null;
        for ($i = 0; $naFatia === null || $foraDela === null; ++$i) {
            $chave = $this->chave(CategoriaDeArquivo::FOTO_PERFIL, 'n' . $i . '.jpg');
            if (ReconciliadorDoEspelho::fatiaDe($chave) === 6) {
                $naFatia ??= $chave;
            } else {
                $foraDela ??= $chave;
            }
        }
        $refs = [];
        foreach ([$naFatia, $foraDela] as $chave) {
            // Mesmo tamanho, conteúdo diferente: só o SHA pega.
            $this->local->gravar($chave, FonteDeConteudo::deTexto('aaaa'));
            $stream = fopen('php://memory', 'r+b');
            self::assertIsResource($stream);
            fwrite($stream, 'bbbb');
            $this->espelho->enviar($chave, $stream, 4, hash('sha256', 'bbbb'), 'image/jpeg');
            $refs[] = new ReferenciaDeArquivo('user_profiles', \count($refs) + 1, $chave, CategoriaDeArquivo::FOTO_PERFIL);
        }

        // 24/10/2026, 12h UTC = sábado, 9h em Brasília: fatia 6.
        $auto = $this->reconciliarLigado($refs);
        self::assertSame(Command::FAILURE, $auto->execute(['--se-ligado' => true, '--fatia' => 'auto']));
        $saida = preg_replace('/\s+/', ' ', $auto->getDisplay()) ?? '';
        self::assertStringContainsString('fatia 6 de 7', $saida);
        self::assertStringContainsString('sha_divergente: 1 (foto_perfil: 1)', $saida);
        self::assertStringContainsString('presente_sem_sha_conferido', $saida);

        $todas = $this->reconciliarLigado($refs);
        self::assertSame(Command::FAILURE, $todas->execute(['--se-ligado' => true]));
        self::assertStringContainsString('sha_divergente: 2 (foto_perfil: 2)', preg_replace('/\s+/', ' ', $todas->getDisplay()) ?? '');

        foreach (['7', 'x', '-1'] as $invalida) {
            self::assertSame(Command::INVALID, $this->reconciliarLigado($refs)->execute(['--fatia' => $invalida]), $invalida);
        }
    }

    #[TestDox('espelho-situacao: configurado sim ou não, categorias, outbox e idade da pendente; nunca imprime endpoint, bucket, chave nem segredo')]
    public function testSituacaoNaoImprimeSegredo(): void
    {
        $this->outbox->registrarEnvio($this->chave(CategoriaDeArquivo::FOTO_PERFIL, self::NOME_DE_PESSOA), hash('sha256', 'x'), 1, 'image/jpeg');
        $duasHorasDepois = new OutboxDeReplicacao($this->conexao, new MockClock('2026-10-24 14:05:00', 'UTC'));
        $fabrica         = new FabricaDoEspelhoRemoto('https://conta-secreta.r2.cloudflarestorage.com', 'bucket-secreto', 'CHAVE-SECRETA', 'SEGREDO-X');
        $tester          = new CommandTester(new EspelhoSituacaoCommand($fabrica, new PoliticaDeEspelhamento('foto_perfil,cliente_documento'), $duasHorasDepois));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $saida = $tester->getDisplay();
        self::assertMatchesRegularExpression('/preenchidas\s+sim/', $saida);
        self::assertStringContainsString('foto_perfil, cliente_documento', $saida);
        self::assertMatchesRegularExpression('/pendente\s+1/', $saida);
        self::assertStringContainsString('Pendente mais antiga: há 2 h 05 min', $saida);
        foreach (['conta-secreta', 'cloudflarestorage', 'bucket-secreto', 'CHAVE-SECRETA', 'SEGREDO-X', 'Antônio'] as $proibido) {
            self::assertStringNotContainsString($proibido, $saida);
        }

        $desligado = new CommandTester(new EspelhoSituacaoCommand(new FabricaDoEspelhoRemoto(), PoliticaDeEspelhamento::desligada(), $this->outbox));
        self::assertSame(Command::SUCCESS, $desligado->execute([]));
        self::assertMatchesRegularExpression('/preenchidas\s+não/', $desligado->getDisplay());
        self::assertMatchesRegularExpression('/Categorias ligadas\s+nenhuma/', $desligado->getDisplay());
    }

    /** @param list<ReferenciaDeArquivo> $referencias */
    private function reconciliarLigado(array $referencias): CommandTester
    {
        $espelho  = $this->espelho;
        $provedor = new class ($espelho) implements ProvedorDoEspelhoRemoto {
            public function __construct(private readonly EspelhoRemoto $espelho)
            {
            }

            public function estaConfigurado(): bool
            {
                return true;
            }

            public function criar(): EspelhoRemoto
            {
                return $this->espelho;
            }
        };

        return new CommandTester(new ReconciliarEspelhoCommand($this->reconciliador($referencias), $provedor, new PoliticaDeEspelhamento('foto_perfil,cliente_documento'), $this->outbox, new MockClock('2026-10-24 12:00:00', 'UTC')));
    }

    private function noDisco(CategoriaDeArquivo $categoria, string $nome, string $conteudo, bool $noEspelho = false): ReferenciaDeArquivo
    {
        $chave = $this->chave($categoria, $nome);
        $this->local->gravar($chave, FonteDeConteudo::deTexto($conteudo));
        if ($noEspelho) {
            $stream = fopen('php://memory', 'r+b');
            self::assertIsResource($stream);
            fwrite($stream, $conteudo);
            $this->espelho->enviar($chave, $stream, \strlen($conteudo), hash('sha256', $conteudo), 'image/jpeg');
        }

        return new ReferenciaDeArquivo($categoria->value, random_int(1, 1_000_000), $chave, $categoria);
    }

    private function chave(CategoriaDeArquivo $categoria, string $nome): ChaveDeArquivo
    {
        $escopo = $categoria === CategoriaDeArquivo::FOTO_PERFIL ? EscopoDeArquivo::global() : EscopoDeArquivo::deTenant(7);

        return new ChaveDeArquivo($escopo, $categoria, $nome);
    }

    /** @param list<ReferenciaDeArquivo> $referencias */
    private function reconciliador(array $referencias): ReconciliadorDoEspelho
    {
        $fonte = new class ($referencias) implements FonteDeReferencias {
            /** @param list<ReferenciaDeArquivo> $referencias */
            public function __construct(private readonly array $referencias)
            {
            }

            public function referencias(): iterable
            {
                return $this->referencias;
            }
        };

        return new ReconciliadorDoEspelho($fonte, $this->local, $this->outbox, hash('sha256', self::CONTEUDO_SUSPEITO));
    }
}
