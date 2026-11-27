<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Campanha\ChaveDasCampanhas;
use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\Campanha\ExpansorDeCampanhas;
use App\Comercial\Campanha\PoliticaDeDestinatarios;
use App\Comercial\Campanha\PublicosDaCampanha;
use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\CotaDeEnvio;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\ResultadoDoEnvio;
use App\Comercial\Message\EnviarEmail;
use App\Comercial\Message\ExpandirCampanhas;
use App\Comercial\MessageHandler\EnviarEmailHandler;
use App\Comercial\MessageHandler\ExpandirCampanhasHandler;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\Repository\DestinoDaCampanhaRepository;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Comercial\Service\RecuperacaoDeEnvios;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Comercial\UseCase\EncerrarCampanhaUseCase;
use App\Comercial\UseCase\PurgarDadosDoComercialUseCase;
use App\Tests\Comercial\Support\CriaCampanhas;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * O expansor de campanhas contra o banco (spec comercial-e-emails.md §8.1, §8.5 e §8.7): materialização idempotente
 * (UNIQUE), lote numa transação (queda no meio = nada gravado), contrapressão, teto diário das campanhas, a agendada
 * atrasada cancelada, a recorrente pulando, o "+1 mês" no fim do mês, a chave desligada segurando sem cancelar (e o envio
 * já na fila voltando para ela até a religação, M8.4), o comando de encerrar, uma volta por janela (sem reagendamento) e a retenção de 24 meses.
 */
#[CoversClass(ExpansorDeCampanhas::class)]
#[CoversClass(ExpandirCampanhasHandler::class)]
#[CoversClass(CampanhaDeEmailRepository::class)]
#[CoversClass(DestinoDaCampanhaRepository::class)]
#[CoversClass(RecuperacaoDeEnvios::class)]
#[CoversClass(EncerrarCampanhaUseCase::class)]
#[CoversClass(\App\Comercial\Command\EncerrarCampanhaCommand::class)]
final class ExpansorDeCampanhasTest extends KernelTestCase
{
    use CriaCampanhas;

    private Connection $conexao;
    private InMemoryTransport $fila;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao = static::getContainer()->get(Connection::class);
        $this->fila = static::getContainer()->get('messenger.transport.comercial');
        $this->fila->reset();
    }

    #[TestDox('O filtro congelado (§9) volta a valer na execução: só quem atende é materializado; a lista antiga (sem filtro) ainda lê')]
    public function testFiltroCongeladoReaplicado(): void
    {
        $ana = $this->cadastradoAtivo('ana', 'Ana');
        $this->perfil($ana, \App\Profile\Enum\Genero::Feminino);
        $beto = $this->cadastradoAtivo('beto', 'Beto');
        $this->perfil($beto, \App\Profile\Enum\Genero::Masculino);
        $emails = [(string) $ana->getEmail(), (string) $beto->getEmail()];
        $informados = array_map(static fn (string $e): array => ['email' => $e, 'nome' => ''], $emails);
        $comFiltro = $this->campanha($emails, ['parametros' => ['informados' => $informados, 'filtro' => ['gen' => 'F']], 'total' => 1]);
        $antiga = $this->campanha($emails);

        $this->expansor()->materializarDevidas(new \DateTimeImmutable());

        self::assertSame([(string) $ana->getEmail()], $this->destinosDe($comFiltro));
        self::assertSame($emails, $this->destinosDe($antiga), 'sem filtro, a lista de antes, inteira');
    }

    #[TestDox('Filtro num público sem lista (primários): reaplicado na execução; filtro guardado inválido cancela a campanha com motivo próprio')]
    public function testFiltroEmPublicoDeSistemaEFiltroInvalido(): void
    {
        $ana = $this->usuario('ana.prim');
        $this->vincular($ana, true);
        $prim = $this->campanha([], ['publico' => 'prim', 'parametros' => ['informados' => null, 'filtro' => ['q' => (string) $ana->getEmail()]], 'total' => 1]);
        $ruim = $this->campanha([], ['publico' => 'prim', 'parametros' => ['informados' => null, 'filtro' => ['gen' => 'X']], 'total' => 1]);

        $this->expansor()->materializarDevidas(new \DateTimeImmutable());

        self::assertSame([(string) $ana->getEmail()], $this->destinosDe($prim));
        self::assertSame([], $this->destinosDe($ruim));
        $estado = $this->conexao->fetchAssociative('SELECT estado, motivo, publico_parametros FROM comercial_campanha WHERE id = ?', [$ruim]);
        self::assertIsArray($estado);
        self::assertSame(['cancelada', ExpansorDeCampanhas::MOTIVO_DO_FILTRO, null], [$estado['estado'], $estado['motivo'], $estado['publico_parametros']]);
    }

    /** @return list<string> */
    private function destinosDe(int $campanha): array
    {
        return array_map('strval', $this->conexao->fetchFirstColumn('SELECT email FROM comercial_campanha_destino WHERE campanha_id = ? ORDER BY id', [$campanha]));
    }

    #[TestDox('Materializa a execução uma vez só (UNIQUE), libera lotes de 10 com contrapressão e conclui a única como enviada')]
    public function testLoteContrapressaoEConclusao(): void
    {
        $emails = $this->emailsDeAtivos(12);
        $id = $this->campanha($emails);
        $expansor = $this->expansor();
        $agora = new \DateTimeImmutable();

        self::assertSame(1, $expansor->materializarDevidas($agora));
        self::assertSame(0, $expansor->materializarDevidas($agora), 'a execução em curso não é materializada de novo');
        $repetidos = array_map(static fn (string $e): DestinatarioDaCampanha => new DestinatarioDaCampanha($e), $emails);
        self::assertSame(0, static::getContainer()->get(DestinoDaCampanhaRepository::class)->materializar($id, 1, $repetidos, $agora), 'UNIQUE');
        self::assertSame(['estado' => 'enviando', 'execucoes' => 1, 'total' => 12], $this->estado($id, ['estado', 'execucoes', 'total']));

        self::assertSame(10, $expansor->liberarLote($agora));
        self::assertSame(0, $expansor->liberarLote($agora), 'contrapressão: 10 envios de campanha ainda na fila');
        $this->conexao->executeStatement("UPDATE comercial_envio SET situacao = 'enviado', enviado_em = ? WHERE origem = ?", [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'campanha:' . $id]);
        self::assertSame(2, $expansor->liberarLote($agora));
        self::assertSame(1, $expansor->concluir());

        self::assertSame(['estado' => 'enviada', 'publico_parametros' => null], $this->estado($id, ['estado', 'publico_parametros']));
        self::assertSame(12, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_envio WHERE origem = ?', ['campanha:' . $id]));
        self::assertSame(0, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_campanha_destino WHERE campanha_id = ? AND envio_id IS NULL', [$id]));
    }

    #[TestDox('A campanha adiada pela edição (M5.3) entre a leitura do expansor e a trava não é materializada: travarDevida reconfere a data')]
    public function testAdiadaDepoisDaLeituraNaoTrava(): void
    {
        $id = $this->campanha($this->emailsDeAtivos(1));
        $agora = new \DateTimeImmutable();
        $repositorio = static::getContainer()->get(CampanhaDeEmailRepository::class);
        self::assertNotNull($repositorio->travarDevida($id, 'na_fila', 0, $agora), 'devida agora');

        $this->conexao->executeStatement('UPDATE comercial_campanha SET proxima_execucao = ? WHERE id = ?', [$agora->modify('+3 days')->format('Y-m-d H:i:s'), $id]);

        self::assertNull($repositorio->travarDevida($id, 'na_fila', 0, $agora));
        self::assertSame(0, $this->expansor()->materializarDevidas($agora));
        self::assertSame(['estado' => 'na_fila', 'execucoes' => 0], $this->estado($id, ['estado', 'execucoes']));
    }

    #[TestDox('O envio da campanha: lista comercial_campanhas, origem campanha:<id>, rastrear pedido (a confirmação de leitura da campanha), layout do modo, reply-to da política (null)')]
    public function testFormaDoEnvio(): void
    {
        [$email] = $this->emailsDeAtivos(1);
        $id = $this->campanha([$email], ['rastrear_leitura' => true]);
        $expansor = $this->expansor();
        $expansor->materializarDevidas(new \DateTimeImmutable());
        $expansor->liberarLote(new \DateTimeImmutable());

        $envio = $this->conexao->fetchAssociative(
            'SELECT e.destinatario, e.nome, e.tipo, e.lista, e.origem, e.situacao, c.rastrear, c.layout, c.reply_to
             FROM comercial_envio e JOIN comercial_envio_conteudo c ON c.envio_id = e.id WHERE e.origem = ?',
            ['campanha:' . $id],
        );
        self::assertSame([
            'destinatario' => $email, 'nome' => 'Nome ' . $email, 'tipo' => 'lista', 'lista' => 'comercial_campanhas',
            'origem' => 'campanha:' . $id, 'situacao' => 'enfileirado', 'rastrear' => true, 'layout' => 'linhas', 'reply_to' => null,
        ], $envio);
        $envios = array_filter($this->fila->getSent(), static fn ($e): bool => $e->getMessage() instanceof EnviarEmail);
        self::assertCount(1, $envios);
        self::assertSame(
            'Você recebeu este e-mail porque tem conta no BlueJus ou confirmou seu endereço para receber novidades do BlueJus.',
            $this->conexao->fetchOne('SELECT c.motivo_do_envio FROM comercial_envio e JOIN comercial_envio_conteudo c ON c.envio_id = e.id WHERE e.origem = ?', ['campanha:' . $id]),
            'C1: a campanha Individual leva a frase da lista informada no rodapé',
        );
    }

    #[TestDox('F3g: campanha sem confirmação de leitura não pede rastreio no envio')]
    public function testSemConfirmacaoDeLeituraNaoPedeRastreio(): void
    {
        [$email] = $this->emailsDeAtivos(1);
        $id = $this->campanha([$email]);
        $expansor = $this->expansor();
        $expansor->materializarDevidas(new \DateTimeImmutable());
        $expansor->liberarLote(new \DateTimeImmutable());

        self::assertFalse((bool) $this->conexao->fetchOne(
            'SELECT c.rastrear FROM comercial_envio e JOIN comercial_envio_conteudo c ON c.envio_id = e.id WHERE e.origem = ?',
            ['campanha:' . $id],
        ));
    }

    #[TestDox('Queda no meio do lote: a transação desfaz tudo; nenhum envio sobra e nenhum destinatário fica marcado')]
    public function testQuedaNoMeioDoLote(): void
    {
        $id = $this->campanha($this->emailsDeAtivos(5));
        $real = static::getContainer()->get(CarteiroInterface::class);
        $quebra = new class ($real) implements CarteiroInterface {
            private int $chamadas = 0;

            public function __construct(private readonly CarteiroInterface $real)
            {
            }

            public function enviar(MensagemDeEmail $mensagem): ResultadoDoEnvio
            {
                if (++$this->chamadas === 3) {
                    throw new \RuntimeException('O banco caiu.');
                }

                return $this->real->enviar($mensagem);
            }
        };
        $expansor = $this->expansor(carteiro: $quebra);
        $expansor->materializarDevidas(new \DateTimeImmutable());

        try {
            $expansor->liberarLote(new \DateTimeImmutable());
            self::fail('a queda deveria subir');
        } catch (\RuntimeException $e) {
            self::assertSame('O banco caiu.', $e->getMessage());
        }

        self::assertSame(0, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_envio WHERE origem = ?', ['campanha:' . $id]));
        self::assertSame(5, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_campanha_destino WHERE campanha_id = ? AND envio_id IS NULL', [$id]));
        self::assertSame(5, $this->expansor()->liberarLote(new \DateTimeImmutable()), 'na volta seguinte, os 5 saem uma vez só');
    }

    #[TestDox('Teto diário das campanhas: com teto 3, saem 3 hoje e o resto espera')]
    public function testTetoDiario(): void
    {
        $id = $this->campanha($this->emailsDeAtivos(5));
        $expansor = $this->expansor(teto: 3);
        $expansor->materializarDevidas(new \DateTimeImmutable());

        self::assertSame(3, $expansor->liberarLote(new \DateTimeImmutable()));
        $this->conexao->executeStatement("UPDATE comercial_envio SET situacao = 'enviado', enviado_em = ? WHERE origem = ?", [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'campanha:' . $id]);
        self::assertSame(0, $expansor->liberarLote(new \DateTimeImmutable()));
        self::assertSame(2, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_campanha_destino WHERE campanha_id = ? AND envio_id IS NULL', [$id]));
    }

    #[TestDox('Cota corrida: os demais e-mails acima da reserva encolhem o lote da campanha (teto 10, campanhas 8, reserva 2, demais 5)')]
    public function testDemaisAcimaDaReservaEncolhemACampanha(): void
    {
        $id = $this->campanha($this->emailsDeAtivos(8));
        $agora = new \DateTimeImmutable();
        for ($i = 0; $i < 5; ++$i) {
            $this->conexao->executeStatement(
                "INSERT INTO comercial_envio (token, destinatario, assunto, tipo, origem, situacao, criado_em, enviado_em, aberturas, cliques)
                 VALUES (:token, 'outro@exemplo.com', 'x', 'transacional', 'sistema', 'enviado', :em, :em, 0, 0)",
                ['token' => bin2hex(random_bytes(16)), 'em' => $agora->modify('-2 hours')->format('Y-m-d H:i:s')],
            );
        }
        $expansor = $this->expansor(teto: 8, tetoTotal: 10);
        $expansor->materializarDevidas($agora);

        self::assertSame(5, $expansor->liberarLote($agora), 'cabem 10 - 5 = 5, não os 8 do teto das campanhas');
        self::assertSame(3, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_campanha_destino WHERE campanha_id = ? AND envio_id IS NULL', [$id]));
    }

    #[TestDox('Nada é recuperado: a agendada que acorda mais de 24 h atrasada é cancelada sem enviar; com 23 h, sai')]
    public function testAgendadaAtrasada(): void
    {
        $emails = $this->emailsDeAtivos(1);
        $agora = new \DateTimeImmutable();
        $perdida = $this->campanha($emails, ['estado' => 'agendada', 'agendada_para' => $agora->modify('-25 hours'), 'proxima' => $agora->modify('-25 hours')]);
        $atrasada = $this->campanha($emails, ['estado' => 'agendada', 'agendada_para' => $agora->modify('-23 hours'), 'proxima' => $agora->modify('-23 hours')]);

        $this->expansor()->materializarDevidas($agora);

        self::assertSame(['estado' => 'cancelada', 'motivo' => ExpansorDeCampanhas::MOTIVO_DO_ATRASO, 'execucoes' => 0], $this->estado($perdida, ['estado', 'motivo', 'execucoes']));
        self::assertSame(0, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_campanha_destino WHERE campanha_id = ?', [$perdida]));
        self::assertSame([['motivo' => ExpansorDeCampanhas::MOTIVO_DO_ATRASO, 'total' => 1]], $this->trilha('camp_canc', $perdida));
        self::assertSame(['estado' => 'enviando', 'execucoes' => 1], $this->estado($atrasada, ['estado', 'execucoes']));
        self::assertSame([], $this->trilha('camp_canc', $atrasada));
    }

    #[TestDox('Recorrente que perdeu a vez pula para a próxima data futura sem enviar; no horário, executa e marca a seguinte (+7 dias)')]
    public function testRecorrentePulando(): void
    {
        $emails = $this->emailsDeAtivos(2);
        $agora = new \DateTimeImmutable('2030-06-20 10:00:00');
        $ancora = $agora->modify('-10 days');
        $perdeu = $this->campanha($emails, ['estado' => 'recorrente', 'frequencia' => 'semanal', 'agendada_para' => $ancora, 'proxima' => $agora->modify('-3 days')]);
        $emDia = $this->campanha($emails, ['estado' => 'recorrente', 'frequencia' => 'semanal', 'agendada_para' => $agora->modify('-1 minute'), 'proxima' => $agora->modify('-1 minute')]);

        $this->expansor()->materializarDevidas($agora);

        self::assertSame(['estado' => 'recorrente', 'execucoes' => 0, 'proxima_execucao' => '2030-06-24 10:00:00'], $this->estado($perdeu, ['estado', 'execucoes', 'proxima_execucao']));
        self::assertSame(0, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_campanha_destino WHERE campanha_id = ?', [$perdeu]));
        $linha = $this->estado($emDia, ['estado', 'execucoes', 'total', 'proxima_execucao']);
        self::assertSame(['estado' => 'recorrente', 'execucoes' => 1, 'total' => 2, 'proxima_execucao' => '2030-06-27 09:59:00'], $linha);
        self::assertNotNull($this->estado($emDia, ['publico_parametros'])['publico_parametros'], 'a recorrente guarda a lista para a próxima execução');
    }

    #[TestDox('Mensal no fim do mês: 31/01 executa e marca 28/02; 28/02 executa e marca 31/03 (âncora no dia original)')]
    public function testMensalNoFimDoMes(): void
    {
        $ancora = new \DateTimeImmutable('2031-01-31 09:00:00');
        $id = $this->campanha($this->emailsDeAtivos(1), ['estado' => 'recorrente', 'frequencia' => 'mensal', 'agendada_para' => $ancora, 'proxima' => $ancora]);

        $this->expansor()->materializarDevidas($ancora->modify('+30 seconds'));
        self::assertSame('2031-02-28 09:00:00', $this->estado($id, ['proxima_execucao'])['proxima_execucao']);
        $this->conexao->executeStatement('UPDATE comercial_campanha_destino SET envio_id = 0 WHERE campanha_id = ?', [$id]);

        $this->expansor()->materializarDevidas(new \DateTimeImmutable('2031-02-28 09:00:30'));
        self::assertSame(['execucoes' => 2, 'proxima_execucao' => '2031-03-31 09:00:00'], $this->estado($id, ['execucoes', 'proxima_execucao']));
    }

    #[TestDox('B1 reaplicado a cada execução: quem foi desativado depois da confirmação sai da execução')]
    public function testB1ReaplicadoNaExecucao(): void
    {
        $ativo = $this->cadastradoAtivo();
        $desativado = $this->cadastradoAtivo();
        $id = $this->campanha([(string) $ativo->getEmail(), (string) $desativado->getEmail()]);
        $this->conexao->executeStatement('UPDATE "user" SET is_active = false WHERE id = ?', [$desativado->getId()]);

        $this->expansor()->materializarDevidas(new \DateTimeImmutable());

        self::assertSame([(string) $ativo->getEmail()], array_map('strval', $this->conexao->fetchFirstColumn('SELECT email FROM comercial_campanha_destino WHERE campanha_id = ?', [$id])));
        self::assertSame(1, $this->estado($id, ['total'])['total']);
    }

    #[TestDox('Chave desligada (M8.4): a volta para e as ativas ficam como estão, esperando a religação; nada é cancelado')]
    public function testChaveDesligadaEspera(): void
    {
        $emails = $this->emailsDeAtivos(1);
        $naFila = $this->campanha($emails);
        $recorrente = $this->campanha($emails, ['estado' => 'recorrente', 'frequencia' => 'semanal', 'agendada_para' => new \DateTimeImmutable('+1 day'), 'proxima' => new \DateTimeImmutable('+1 day')]);

        self::assertTrue($this->expansor(ligada: false)->envioDesligado());
        self::assertFalse($this->expansor()->envioDesligado());

        foreach ([$naFila => 'na_fila', $recorrente => 'recorrente'] as $id => $estado) {
            self::assertSame(['estado' => $estado, 'motivo' => null], $this->estado($id, ['estado', 'motivo']));
            self::assertSame([], $this->trilha('camp_canc', $id));
        }
        self::assertSame(0, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_campanha_destino WHERE campanha_id IN (?, ?)', [$naFila, $recorrente]));
    }

    #[TestDox('Chave desligada no painel (M8.4): o EnviarEmail de campanha já na fila volta para ela e confere de novo em 5 min; religada, sai')]
    public function testChaveDesligadaNoHandler(): void
    {
        $emails = $this->emailsDeAtivos(1);
        $id = $this->campanha($emails, ['estado' => 'enviando']);
        $envioId = $this->enfileirarDaCampanha($id, $emails[0]);
        $alterar = static::getContainer()->get(\App\Comercial\UseCase\AlterarEnvioDeCampanhasUseCase::class);
        $master = $this->cadastradoAtivo();
        $alterar->executar(false, $master);
        $this->fila->reset();

        static::getContainer()->get(EnviarEmailHandler::class)(new EnviarEmail($envioId));

        self::assertSame('enfileirado', $this->conexao->fetchOne('SELECT situacao FROM comercial_envio WHERE id = ?', [$envioId]));
        self::assertNotFalse($this->conexao->fetchOne('SELECT 1 FROM comercial_envio_conteudo WHERE envio_id = ?', [$envioId]), 'o corpo fica para quando religar');
        self::assertSame('enviando', $this->estado($id, ['estado'])['estado']);
        $reenvio = $this->despachados(EnviarEmail::class);
        self::assertCount(1, $reenvio);
        self::assertSame(EnviarEmailHandler::ESPERA_DA_CHAVE_SEGUNDOS * 1000, $reenvio[0]->last(\Symfony\Component\Messenger\Stamp\DelayStamp::class)?->getDelay());
        self::assertEmailCount(0);

        $alterar->executar(true, $master);
        static::getContainer()->get(EnviarEmailHandler::class)(new EnviarEmail($envioId));

        self::assertSame('enviado', $this->conexao->fetchOne('SELECT situacao FROM comercial_envio WHERE id = ?', [$envioId]));
        self::assertEmailCount(1);
    }

    #[TestDox('Comando numa campanha enviada com fila: vira cancelada, os envios enfileirados viram cancelado e o handler não manda; sem fila, falha')]
    public function testComandoNumaEnviadaComFila(): void
    {
        $emails = $this->emailsDeAtivos(2);
        $id = $this->campanha($emails, ['estado' => 'enviada', 'parametros' => null]);
        $naFila = $this->enfileirarDaCampanha($id, $emails[0]);
        $saiu = $this->enfileirarDaCampanha($id, $emails[1]);
        $this->conexao->executeStatement("UPDATE comercial_envio SET situacao = 'enviado' WHERE id = ?", [$saiu]);
        $semFila = $this->campanha($emails, ['estado' => 'enviada', 'parametros' => null]);
        $comando = new CommandTester((new Application(self::$kernel))->find('app:comercial:encerrar-campanha'));

        self::assertSame(Command::SUCCESS, $comando->execute(['id' => (string) $id]));
        self::assertStringContainsString('1 e-mail(s) que estavam na fila foram cancelados.', $comando->getDisplay());
        self::assertSame(['cancelado', 'enviado'], array_map('strval', $this->conexao->fetchFirstColumn('SELECT situacao FROM comercial_envio WHERE id IN (?, ?) ORDER BY id', [$naFila, $saiu])));
        self::assertFalse($this->conexao->fetchOne('SELECT 1 FROM comercial_envio_conteudo WHERE envio_id = ?', [$naFila]));
        self::assertSame(['estado' => 'cancelada', 'motivo' => 'Encerrada pelo console'], $this->estado($id, ['estado', 'motivo']));
        self::assertSame([['publico' => 'indiv', 'frequencia' => 'unica', 'total' => 2]], $this->trilha('camp_fim', $id));
        static::getContainer()->set(ChaveDasCampanhas::class, new ChaveDasCampanhas(true));
        static::getContainer()->get(EnviarEmailHandler::class)(new EnviarEmail($naFila));
        self::assertSame('cancelado', $this->conexao->fetchOne('SELECT situacao FROM comercial_envio WHERE id = ?', [$naFila]));
        self::assertEmailCount(0);

        self::assertSame(Command::FAILURE, $comando->execute(['id' => (string) $semFila]));
        self::assertSame('enviada', $this->estado($semFila, ['estado'])['estado']);
    }

    #[TestDox('No máximo uma vez: o enviando parado de campanha vira incerto e não volta; teste_campanha e automação voltam à fila')]
    public function testRecuperacaoNoMaximoUmaVez(): void
    {
        [$email] = $this->emailsDeAtivos(1);
        $id = $this->campanha([$email]);
        $daCampanha = $this->enfileirarDaCampanha($id, $email);
        $teste = $this->enfileirarDaCampanha($id, $email, 'teste_campanha');
        $automacao = $this->enfileirarDaCampanha($id, $email, 'automacao:val');
        $envios = static::getContainer()->get(EnvioDeEmailRepository::class);
        $vinteMinutosAtras = new \DateTimeImmutable('-20 minutes');
        foreach ([$daCampanha, $teste, $automacao] as $envioId) {
            self::assertTrue($envios->reivindicar($envioId, $vinteMinutosAtras));
        }
        $this->fila->reset();

        static::getContainer()->get(RecuperacaoDeEnvios::class)->recuperar(new \DateTimeImmutable());

        $situacao = fn (int $envioId): string => (string) $this->conexao->fetchOne('SELECT situacao FROM comercial_envio WHERE id = ?', [$envioId]);
        self::assertSame(['incerto', 'enfileirado', 'enfileirado'], [$situacao($daCampanha), $situacao($teste), $situacao($automacao)]);
        self::assertFalse($this->conexao->fetchOne('SELECT 1 FROM comercial_envio_conteudo WHERE envio_id = ?', [$daCampanha]), 'o incerto não guarda o corpo');
        self::assertNotFalse($this->conexao->fetchOne('SELECT 1 FROM comercial_envio_conteudo WHERE envio_id = ?', [$teste]));
        static::getContainer()->set(ChaveDasCampanhas::class, new ChaveDasCampanhas(true));
        static::getContainer()->get(EnviarEmailHandler::class)(new EnviarEmail($daCampanha));
        self::assertSame('incerto', $situacao($daCampanha), 'um EnviarEmail atrasado não reenvia o incerto');
        self::assertEmailCount(0);
    }

    #[TestDox('O handler com a chave do CONTAINER (padrão do ambiente desligado, painel sem valor): a volta não mexe nas ativas e nada sai')]
    public function testHandlerComAChavePadraoDoContainer(): void
    {
        $id = $this->campanha($this->emailsDeAtivos(2));
        $this->conexao->executeStatement('UPDATE comercial_expansor SET proxima_em = ? WHERE id = 1', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);

        static::getContainer()->get(ExpandirCampanhasHandler::class)(new ExpandirCampanhas());

        self::assertSame(['estado' => 'na_fila', 'motivo' => null], $this->estado($id, ['estado', 'motivo']));
        self::assertSame(0, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_campanha_destino WHERE campanha_id = ?', [$id]));
        self::assertSame(0, (int) $this->conexao->fetchOne("SELECT COUNT(*) FROM comercial_envio WHERE origem LIKE 'campanha:%'"));
        self::assertSame([], $this->despachados(EnviarEmail::class));
        self::assertSame([], $this->despachados(ExpandirCampanhas::class), 'o handler não se reagenda');
        self::assertEmailCount(0);
    }

    #[TestDox('Quem se descadastra entre a materialização e o envio não recebe; o e-mail de campanha montado leva List-Unsubscribe com One-Click')]
    public function testDescadastroDepoisDeMaterializarEListUnsubscribe(): void
    {
        [$fica, $sai] = $this->emailsDeAtivos(2);
        $id = $this->campanha([$fica, $sai]);
        $expansor = $this->expansor();
        $expansor->materializarDevidas(new \DateTimeImmutable());
        static::getContainer()->get(DescadastroDeEmailInterface::class)->descadastrar(null, $sai, ComposicaoDaCampanha::LISTA, 'teste');

        self::assertSame(2, $expansor->liberarLote(new \DateTimeImmutable()));

        $situacoes = $this->conexao->fetchAllKeyValue('SELECT destinatario, situacao FROM comercial_envio WHERE origem = ?', ['campanha:' . $id]);
        self::assertSame(['enfileirado', 'bloqueado_descadastro'], [$situacoes[$fica], $situacoes[$sai]]);
        $despachados = array_map(static fn ($e): int => $e->getMessage()->envioId, $this->despachados(EnviarEmail::class));
        self::assertCount(1, $despachados, 'só quem não saiu da lista vai para a fila');
        static::getContainer()->set(ChaveDasCampanhas::class, new ChaveDasCampanhas(true));
        static::getContainer()->get(EnviarEmailHandler::class)(new EnviarEmail($despachados[0]));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertSame($fica, $email->getTo()[0]->getAddress());
        self::assertMatchesRegularExpression('#^<https?://[^>]+>$#', (string) $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
        self::assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
    }

    #[TestDox('Contatos salvos: só os elegíveis (sem descadastrado nem convite recente) viram envio, marcam o convite e levam o descadastro; a segunda campanha dentro do intervalo pula todos')]
    public function testContatosSalvosRespeitamIntervaloEDescadastro(): void
    {
        $agora = new \DateTimeImmutable();
        $sufixo = uniqid();
        [$novo, $antigo, $recente, $saiu] = ['novo' . $sufixo . '@exemplo.com', 'antigo' . $sufixo . '@exemplo.com', 'recente' . $sufixo . '@exemplo.com', 'saiu' . $sufixo . '@exemplo.com'];
        $this->conexao->executeStatement('DELETE FROM comercial_contato');
        foreach ([[$novo, null], [$antigo, $agora->modify('-10 days')], [$recente, $agora->modify('-2 days')], [$saiu, null]] as [$email, $ultimo]) {
            $this->conexao->executeStatement(
                "INSERT INTO comercial_contato (email, nome, origem, criado_em, atualizado_em, ultimo_convite_em) VALUES (?, 'Contato', 'importacao', ?, ?, ?)",
                [$email, $agora->format('Y-m-d H:i:s'), $agora->format('Y-m-d H:i:s'), $ultimo?->format('Y-m-d H:i:s')],
            );
        }
        static::getContainer()->get(DescadastroDeEmailInterface::class)->descadastrar(null, $saiu, ComposicaoDaCampanha::LISTA, 'teste');
        $primeira = $this->campanha([], ['publico' => 'contatos', 'parametros' => null, 'total' => 2]);
        $expansor = $this->expansor();

        $expansor->materializarDevidas($agora);

        self::assertSame([$novo, $antigo], $this->destinosDe($primeira), 'sem o B1 (nunca se cadastraram), mas sem o descadastrado e sem o convite recente');
        $marcados = $this->conexao->fetchAllKeyValue('SELECT email, ultimo_convite_em IS NOT NULL AND ultimo_convite_em >= ? FROM comercial_contato', [$agora->modify('-1 minute')->format('Y-m-d H:i:s')]);
        $marcados = array_map('boolval', $marcados);
        self::assertEqualsCanonicalizing([$novo => true, $antigo => true, $recente => false, $saiu => false], $marcados);
        self::assertTrue($marcados[$novo] && $marcados[$antigo] && !$marcados[$recente] && !$marcados[$saiu]);
        self::assertSame($agora->modify('-2 days')->format('Y-m-d'), (new \DateTimeImmutable((string) $this->conexao->fetchOne('SELECT ultimo_convite_em FROM comercial_contato WHERE email = ?', [$recente])))->format('Y-m-d'), 'quem foi pulado não é remarcado');

        self::assertSame(2, $expansor->liberarLote($agora));
        $despachados = array_map(static fn ($e): int => $e->getMessage()->envioId, $this->despachados(EnviarEmail::class));
        self::assertCount(2, $despachados);
        static::getContainer()->set(ChaveDasCampanhas::class, new ChaveDasCampanhas(true));
        static::getContainer()->get(EnviarEmailHandler::class)(new EnviarEmail($despachados[0]));
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertSame($novo, $email->getTo()[0]->getAddress());
        self::assertMatchesRegularExpression('#^<https?://[^>]+>$#', (string) $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString(), 'o convite a contato salvo leva o descadastro');
        self::assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
        self::assertStringContainsString('Você recebeu este e-mail porque seu endereço profissional está numa lista de contatos profissionais importada pelo BlueJus.', (string) $email->getHtmlBody(), 'C1: o rodapé diz que o contato veio de uma lista importada');
        self::assertStringNotContainsString('se cadastrou no BlueJus ou autorizou', (string) $email->getHtmlBody());

        $segunda = $this->campanha([], ['publico' => 'contatos', 'parametros' => null, 'total' => 2]);
        $expansor->materializarDevidas(new \DateTimeImmutable());

        self::assertSame([], $this->destinosDe($segunda), 'dentro do intervalo, ninguém recebe a segunda campanha');
        self::assertSame('enviada', $this->estado($segunda, ['estado'])['estado']);
    }

    #[TestDox('Modelo vagas (§11): a recorrente resolve a Base de contatos pelo modelo_chave; cada destino sai pela lista dele (descadastro, List-Unsubscribe e Carteiro)')]
    public function testModeloDeVagasLevaAListaDoDestino(): void
    {
        $marca = uniqid();
        [$lead, $dupla, $vaga, $saiu] = ["lead.$marca@exemplo.com", "dupla.$marca@exemplo.com", "vaga.$marca@exemplo.com", "saiu.$marca@exemplo.com"];
        foreach ([['comercial_campanhas', $lead], ['comercial_campanhas', $dupla], ['vagas_alerta', $dupla], ['vagas_alerta', $vaga], ['vagas_alerta', $saiu]] as [$lista, $email]) {
            $this->conexao->insert('comercial_confirmacao_endereco', ['lista' => $lista, 'email' => $email, 'pedido_em' => '2026-10-01 10:00:00', 'confirmado_em' => '2026-10-01 10:05:00', 'tenant_id' => null]);
        }
        $c = static::getContainer();
        $mensagem = MensagemDaCampanha::deArray((array) (new \App\Comercial\Campanha\ModelosDeCampanha())->fixo('vagas'));
        $agora = new \DateTimeImmutable();
        $leads = ['publico' => 'leads', 'parametros' => null, 'estado' => 'recorrente', 'frequencia' => 'semanal', 'agendada_para' => $agora->modify('-1 minute'), 'proxima' => $agora->modify('-1 minute')];
        $vagas = $this->campanha([], $leads + ['modelo_chave' => 'vagas', 'conteudo' => $c->get(ComposicaoDaCampanha::class)->congelar($mensagem)]);
        $convite = $this->campanha([], $leads);

        $expansor = $this->expansor();
        $expansor->materializarDevidas($agora);
        $nossos = fn (int $id): array => array_values(array_filter(
            $this->conexao->fetchAllAssociative('SELECT email, lista FROM comercial_campanha_destino WHERE campanha_id = ? ORDER BY id', [$id]),
            static fn (array $l): bool => str_contains((string) $l['email'], $marca),
        ));

        self::assertSame([
            ['email' => $lead, 'lista' => 'comercial_campanhas'], ['email' => $dupla, 'lista' => 'comercial_campanhas'],
            ['email' => $vaga, 'lista' => 'vagas_alerta'], ['email' => $saiu, 'lista' => 'vagas_alerta'],
        ], $nossos($vagas));
        self::assertSame([['email' => $lead, 'lista' => 'comercial_campanhas'], ['email' => $dupla, 'lista' => 'comercial_campanhas']], $nossos($convite), 'outro modelo não traz vagas_alerta');

        // Sai de vagas_alerta entre a materialização e o envio: o Carteiro confere a lista do destino.
        $c->get(DescadastroDeEmailInterface::class)->descadastrar(null, $saiu, PublicosDaCampanha::LISTA_DE_VAGAS, 'teste');
        $this->conexao->executeStatement("UPDATE comercial_campanha SET estado = 'cancelada' WHERE id = ?", [$convite]);
        $expansor->liberarLote($agora);
        $envios = $this->conexao->fetchAllAssociativeIndexed('SELECT destinatario, id, lista, situacao FROM comercial_envio WHERE origem = ?', ['campanha:' . $vagas]);
        self::assertSame(['vagas_alerta', 'enfileirado'], [$envios[$vaga]['lista'], $envios[$vaga]['situacao']]);
        self::assertSame(['vagas_alerta', 'bloqueado_descadastro'], [$envios[$saiu]['lista'], $envios[$saiu]['situacao']]);
        self::assertSame('comercial_campanhas', $envios[$dupla]['lista']);

        $c->set(ChaveDasCampanhas::class, new ChaveDasCampanhas(true));
        $c->get(EnviarEmailHandler::class)(new EnviarEmail((int) $envios[$vaga]['id']));
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        $link = $c->get(DescadastroDeEmailInterface::class)->gerarLink(null, $vaga, PublicosDaCampanha::LISTA_DE_VAGAS);
        self::assertSame('<' . $link . '>', $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
        self::assertStringContainsString(htmlspecialchars($link, ENT_QUOTES), (string) $email->getHtmlBody(), 'o link de descadastro do corpo é o da lista de vagas');
        self::assertStringContainsString('Você recebeu este e-mail porque confirmou seu endereço para receber os alertas de vagas do BlueJus.', (string) $email->getHtmlBody(), 'C1: o rodapé segue a lista do destino');
        self::assertSame(
            'Você recebeu este e-mail porque confirmou seu endereço para receber convites, ofertas e novidades do BlueJus.',
            $this->conexao->fetchOne('SELECT motivo_do_envio FROM comercial_envio_conteudo WHERE envio_id = ?', [$envios[$dupla]['id']]),
            'quem está nas duas listas sai pela Base de contatos, com a frase dela',
        );
    }

    #[TestDox('Modelo vagas gravado sem a âncora (campanha antiga): a execução não alcança quem só autorizou vagas_alerta')]
    public function testVagasSemAncoraNaoAlcancaAListaDeVagas(): void
    {
        $marca = uniqid();
        [$lead, $vaga] = ["lead.$marca@exemplo.com", "vaga.$marca@exemplo.com"];
        foreach ([['comercial_campanhas', $lead], ['vagas_alerta', $vaga]] as [$lista, $email]) {
            $this->conexao->insert('comercial_confirmacao_endereco', ['lista' => $lista, 'email' => $email, 'pedido_em' => '2026-10-01 10:00:00', 'confirmado_em' => '2026-10-01 10:05:00', 'tenant_id' => null]);
        }
        $agora = new \DateTimeImmutable();
        $semAncora = MensagemDaCampanha::deArray(['chave' => 'vagas', 'nome' => 'Vagas antiga', 'modo' => 'blocos', 'assunto' => 'Vagas', 'selo' => '', 'titulo' => 'Vagas', 'blocos' => [['t' => 'texto', 'a' => 'Olá']]]);
        $id = $this->campanha([], [
            'publico' => 'leads', 'parametros' => null, 'estado' => 'recorrente', 'frequencia' => 'semanal',
            'agendada_para' => $agora->modify('-1 minute'), 'proxima' => $agora->modify('-1 minute'),
            'modelo_chave' => 'vagas', 'modo' => 'blocos', 'conteudo' => static::getContainer()->get(ComposicaoDaCampanha::class)->congelar($semAncora),
        ]);

        $this->expansor()->materializarDevidas($agora);

        $emails = array_values(array_filter(
            array_map('strval', $this->conexao->fetchFirstColumn('SELECT email FROM comercial_campanha_destino WHERE campanha_id = ? ORDER BY id', [$id])),
            static fn (string $e): bool => str_contains($e, $marca),
        ));
        self::assertSame([$lead], $emails);
    }

    #[TestDox('cancelarNaFilaDaOrigem: só o enfileirado vira cancelado; o enviando (já com o worker) não é tocado')]
    public function testCancelarSoOEnfileirado(): void
    {
        [$email] = $this->emailsDeAtivos(1);
        $id = $this->campanha([$email]);
        $enfileirado = $this->enfileirarDaCampanha($id, $email);
        $enviando = $this->enfileirarDaCampanha($id, $email);
        $envios = static::getContainer()->get(EnvioDeEmailRepository::class);
        $envios->reivindicar($enviando, new \DateTimeImmutable());

        self::assertSame(1, $envios->cancelarNaFilaDaOrigem('campanha:' . $id));

        self::assertSame(['cancelado', 'enviando'], array_map('strval', $this->conexao->fetchFirstColumn('SELECT situacao FROM comercial_envio WHERE id IN (?, ?) ORDER BY id', [$enfileirado, $enviando])));
    }

    #[TestDox('Comando app:comercial:encerrar-campanha: encerra a ativa e audita camp_fim; repetir ou id inválido falha com a mensagem')]
    public function testComandoDeEncerrar(): void
    {
        $id = $this->campanha($this->emailsDeAtivos(2), ['estado' => 'recorrente', 'frequencia' => 'mensal', 'agendada_para' => new \DateTimeImmutable('+2 days'), 'proxima' => new \DateTimeImmutable('+2 days'), 'total' => 2]);
        $comando = new CommandTester((new Application(self::$kernel))->find('app:comercial:encerrar-campanha'));

        self::assertSame(Command::SUCCESS, $comando->execute(['id' => (string) $id]));
        self::assertStringContainsString('Campanha ' . $id . ' encerrada.', $comando->getDisplay());
        self::assertSame(['estado' => 'cancelada', 'motivo' => 'Encerrada pelo console', 'publico_parametros' => null], $this->estado($id, ['estado', 'motivo', 'publico_parametros']));
        $trilha = $this->conexao->fetchAssociative("SELECT changes, actor_user_id FROM audit_log WHERE action = 'camp_fim' AND entity_id = ?", [(string) $id]);
        self::assertIsArray($trilha);
        self::assertSame(['publico' => 'indiv', 'frequencia' => 'mensal', 'total' => 2], json_decode((string) $trilha['changes'], true));

        self::assertSame(Command::FAILURE, $comando->execute(['id' => (string) $id]));
        self::assertStringContainsString('Campanha não encontrada ou já encerrada.', $comando->getDisplay());
        self::assertSame(Command::INVALID, $comando->execute(['id' => 'abc']));
    }

    #[TestDox('Uma volta por janela: com ativa, reivindica por meio intervalo (30 s) e não se reagenda; a repetida não roda; sem ativa, sai sem tocar na linha')]
    public function testUmaVoltaPorJanela(): void
    {
        static::getContainer()->set(ChaveDasCampanhas::class, new ChaveDasCampanhas(true));
        $this->campanha($this->emailsDeAtivos(1), ['estado' => 'agendada', 'agendada_para' => new \DateTimeImmutable('+3 days'), 'proxima' => new \DateTimeImmutable('+3 days')]);
        $this->conexao->executeStatement('UPDATE comercial_expansor SET proxima_em = ? WHERE id = 1', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);
        $handler = static::getContainer()->get(ExpandirCampanhasHandler::class);
        $antes = new \DateTimeImmutable();

        $handler(new ExpandirCampanhas());
        $proxima = new \DateTimeImmutable((string) $this->conexao->fetchOne('SELECT proxima_em FROM comercial_expansor WHERE id = 1'));
        self::assertEqualsWithDelta($antes->getTimestamp() + ExpandirCampanhasHandler::VEZ_SEGUNDOS, $proxima->getTimestamp(), 5, 'a vez seguinte abre em meio intervalo');

        $marcador = '2000-01-01 00:00:00';
        $this->conexao->executeStatement('UPDATE comercial_expansor SET executada_em = ? WHERE id = 1', [$marcador]);
        $handler(new ExpandirCampanhas());
        self::assertSame($marcador, $this->conexao->fetchOne('SELECT executada_em FROM comercial_expansor WHERE id = 1'), 'a segunda, dentro da janela, não reivindica e não roda');
        self::assertSame([], $this->despachados(ExpandirCampanhas::class), 'nenhuma volta se reagenda');

        $this->conexao->executeStatement("UPDATE comercial_campanha SET estado = 'enviada'");
        $this->conexao->executeStatement('UPDATE comercial_expansor SET proxima_em = ? WHERE id = 1', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);
        $handler(new ExpandirCampanhas());
        self::assertSame($marcador, $this->conexao->fetchOne('SELECT executada_em FROM comercial_expansor WHERE id = 1'), 'sem ativa, sai cedo: nem reivindica');
    }

    #[TestDox('Retenção: o destinatário com mais de 24 meses fica com o e-mail em SHA-256 com o sal e sem o nome; o recente não muda')]
    public function testRetencaoDe24Meses(): void
    {
        $id = $this->campanha(['antigo@exemplo.com']);
        $destinos = static::getContainer()->get(DestinoDaCampanhaRepository::class);
        $destinos->materializar($id, 1, [new DestinatarioDaCampanha('antigo@exemplo.com', 'Antigo')], new \DateTimeImmutable('-25 months'));
        $destinos->materializar($id, 2, [new DestinatarioDaCampanha('recente@exemplo.com', 'Recente')], new \DateTimeImmutable('-23 months'));
        $purga = static::getContainer()->get(PurgarDadosDoComercialUseCase::class);
        $antes = $purga->executar(new \DateTimeImmutable(), true);

        $purga->executar(new \DateTimeImmutable(), false);

        $segredo = static::getContainer()->getParameter('kernel.secret');
        \assert(\is_string($segredo));
        self::assertSame(['email' => hash('sha256', $segredo . 'antigo@exemplo.com'), 'nome' => null], $this->conexao->fetchAssociative('SELECT email, nome FROM comercial_campanha_destino WHERE campanha_id = ? AND execucao = 1', [$id]));
        self::assertSame(['email' => 'recente@exemplo.com', 'nome' => 'Recente'], $this->conexao->fetchAssociative('SELECT email, nome FROM comercial_campanha_destino WHERE campanha_id = ? AND execucao = 2', [$id]));
        self::assertSame($antes - 1, $purga->executar(new \DateTimeImmutable(), true), 'o já anonimizado não conta de novo');
    }

    private function expansor(bool $ligada = true, int $teto = 1000, ?CarteiroInterface $carteiro = null, int $tetoTotal = 1500): ExpansorDeCampanhas
    {
        $c = static::getContainer();

        return new ExpansorDeCampanhas(
            $c->get(CampanhaDeEmailRepository::class),
            $c->get(DestinoDaCampanhaRepository::class),
            $c->get(EnvioDeEmailRepository::class),
            $c->get(PublicosDaCampanha::class),
            $c->get(ComposicaoDaCampanha::class),
            $carteiro ?? $c->get(CarteiroInterface::class),
            new PoliticaDeDestinatarios(),
            new ChaveDasCampanhas($ligada),
            new NullLogger(),
            new CotaDeEnvio($c->get(EnvioDeEmailRepository::class), $c->get(DestinoDaCampanhaRepository::class), new MockClock(), $tetoTotal, $teto, 10, 80),
            $c->get(\App\Comercial\Campanha\ImagensDaCampanha::class),
            $c->get(\App\Comercial\Campanha\MotivoDoEnvioDaCampanha::class),
        );
    }

    /** @return list<string> e-mails de usuários ativos (passam no B1 do Individual) */
    private function emailsDeAtivos(int $quantos): array
    {
        return array_map(fn (): string => (string) $this->cadastradoAtivo()->getEmail(), range(1, $quantos));
    }

    private function enfileirarDaCampanha(int $id, string $email, ?string $origem = null): int
    {
        $c = static::getContainer();
        $mensagem = MensagemDaCampanha::deArray((array) (new \App\Comercial\Campanha\ModelosDeCampanha())->fixo('convite'));

        return $c->get(CarteiroInterface::class)->enviar($c->get(ComposicaoDaCampanha::class)->mensagemPara($mensagem, $email, null, $origem ?? 'campanha:' . $id))->envioId;
    }

    /** @return list<array<string, mixed>> os `changes` das linhas da trilha com a ação, da campanha */
    private function trilha(string $acao, int $id): array
    {
        $linhas = $this->conexao->fetchFirstColumn('SELECT changes FROM audit_log WHERE action = ? AND entity_id = ? ORDER BY id', [$acao, (string) $id]);

        return array_map(static fn (mixed $c): array => (array) json_decode((string) $c, true), $linhas);
    }

    /**
     * @param list<string> $colunas
     *
     * @return array<string, mixed>
     */
    private function estado(int $id, array $colunas): array
    {
        $linha = $this->conexao->fetchAssociative('SELECT ' . implode(', ', $colunas) . ' FROM comercial_campanha WHERE id = ?', [$id]);
        self::assertIsArray($linha);

        return $linha;
    }

    /**
     * @param class-string $classe
     *
     * @return list<\Symfony\Component\Messenger\Envelope>
     */
    private function despachados(string $classe): array
    {
        return array_values(array_filter($this->fila->getSent(), static fn ($e): bool => $e->getMessage() instanceof $classe));
    }
}
