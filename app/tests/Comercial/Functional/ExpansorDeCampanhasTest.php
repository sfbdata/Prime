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
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\ResultadoDoEnvio;
use App\Comercial\Message\EnviarEmail;
use App\Comercial\Message\ExpandirCampanhas;
use App\Comercial\Message\VarrerAutomacoes;
use App\Comercial\MessageHandler\EnviarEmailHandler;
use App\Comercial\MessageHandler\ExpandirCampanhasHandler;
use App\Comercial\MessageHandler\VarrerAutomacoesHandler;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\Repository\DestinoDaCampanhaRepository;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Comercial\Service\RecuperacaoDeEnvios;
use App\Comercial\Service\SementeDoExpansor;
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
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * O expansor de campanhas contra o banco (spec comercial-e-emails.md §8.1, §8.5 e §8.7): materialização idempotente
 * (UNIQUE), lote numa transação (queda no meio = nada gravado), contrapressão, teto diário das campanhas, a agendada
 * atrasada cancelada, a recorrente pulando, o "+1 mês" no fim do mês, a chave desligada cancelando (e o envio já na
 * fila virando `cancelado`), o comando de encerrar, a cadeia única, a rede de segurança e a retenção de 24 meses.
 */
#[CoversClass(ExpansorDeCampanhas::class)]
#[CoversClass(ExpandirCampanhasHandler::class)]
#[CoversClass(CampanhaDeEmailRepository::class)]
#[CoversClass(DestinoDaCampanhaRepository::class)]
#[CoversClass(SementeDoExpansor::class)]
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

    #[TestDox('Chave desligada: na volta seguinte, toda ativa vira cancelada com o motivo; o que já estava na fila do Carteiro vira cancelado e não sai')]
    public function testChaveDesligadaCancela(): void
    {
        $emails = $this->emailsDeAtivos(1);
        $naFila = $this->campanha($emails);
        $recorrente = $this->campanha($emails, ['estado' => 'recorrente', 'frequencia' => 'semanal', 'agendada_para' => new \DateTimeImmutable('+1 day'), 'proxima' => new \DateTimeImmutable('+1 day')]);
        $enviada = $this->campanha($emails, ['estado' => 'enviada']);
        $envioId = $this->enfileirarDaCampanha($naFila, $emails[0]);

        self::assertTrue($this->expansor(ligada: false)->pararSeDesligada(new \DateTimeImmutable()));

        foreach ([$naFila, $recorrente] as $id) {
            self::assertSame(['estado' => 'cancelada', 'motivo' => 'Envio de campanhas desligado na instalação'], $this->estado($id, ['estado', 'motivo']));
            self::assertSame([['motivo' => 'Envio de campanhas desligado na instalação', 'total' => 1]], $this->trilha('camp_canc', $id));
        }
        self::assertSame([], $this->trilha('camp_canc', $enviada));
        self::assertSame('enviada', $this->estado($enviada, ['estado'])['estado']);
        static::getContainer()->get(EnviarEmailHandler::class)(new EnviarEmail($envioId));
        self::assertSame('cancelado', $this->conexao->fetchOne('SELECT situacao FROM comercial_envio WHERE id = ?', [$envioId]));
        self::assertFalse($this->conexao->fetchOne('SELECT 1 FROM comercial_envio_conteudo WHERE envio_id = ?', [$envioId]));
        self::assertEmailCount(0);
    }

    #[TestDox('Chave desligada no handler: o EnviarEmail de campanha ATIVA já na fila vira cancelado, sem esperar o expansor')]
    public function testChaveDesligadaNoHandler(): void
    {
        $emails = $this->emailsDeAtivos(1);
        $id = $this->campanha($emails, ['estado' => 'enviando']);
        $envioId = $this->enfileirarDaCampanha($id, $emails[0]);
        static::getContainer()->set(ChaveDasCampanhas::class, new ChaveDasCampanhas(false));

        static::getContainer()->get(EnviarEmailHandler::class)(new EnviarEmail($envioId));

        self::assertSame('cancelado', $this->conexao->fetchOne('SELECT situacao FROM comercial_envio WHERE id = ?', [$envioId]));
        self::assertFalse($this->conexao->fetchOne('SELECT 1 FROM comercial_envio_conteudo WHERE envio_id = ?', [$envioId]));
        self::assertSame('enviando', $this->estado($id, ['estado'])['estado'], 'quem cancela a campanha é o expansor; o handler só não envia');
        self::assertEmailCount(0);
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

    #[TestDox('O handler com a chave do CONTAINER (padrão desligada): a volta cancela as ativas e nada sai')]
    public function testHandlerComAChavePadraoDoContainer(): void
    {
        $id = $this->campanha($this->emailsDeAtivos(2));
        $this->conexao->executeStatement('UPDATE comercial_expansor SET proxima_em = ? WHERE id = 1', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);

        static::getContainer()->get(ExpandirCampanhasHandler::class)(new ExpandirCampanhas());

        self::assertSame(['estado' => 'cancelada', 'motivo' => 'Envio de campanhas desligado na instalação'], $this->estado($id, ['estado', 'motivo']));
        self::assertSame(0, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_campanha_destino WHERE campanha_id = ?', [$id]));
        self::assertSame(0, (int) $this->conexao->fetchOne("SELECT COUNT(*) FROM comercial_envio WHERE origem LIKE 'campanha:%'"));
        self::assertSame([], $this->despachados(EnviarEmail::class));
        self::assertSame([], $this->despachados(ExpandirCampanhas::class), 'sem ativa, a cadeia para');
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

    #[TestDox('A cadeia: só um passo por intervalo se reagenda (60 s); sem campanha ativa, a cadeia para')]
    public function testCadeiaUnica(): void
    {
        static::getContainer()->set(ChaveDasCampanhas::class, new ChaveDasCampanhas(true));
        $this->campanha($this->emailsDeAtivos(1), ['estado' => 'agendada', 'agendada_para' => new \DateTimeImmutable('+3 days'), 'proxima' => new \DateTimeImmutable('+3 days')]);
        $this->conexao->executeStatement('UPDATE comercial_expansor SET proxima_em = ? WHERE id = 1', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);
        $handler = static::getContainer()->get(ExpandirCampanhasHandler::class);

        $handler(new ExpandirCampanhas());
        $handler(new ExpandirCampanhas());

        $passos = $this->despachados(ExpandirCampanhas::class);
        self::assertCount(1, $passos, 'o segundo não reivindica e morre');
        self::assertSame(60000, $passos[0]->last(DelayStamp::class)?->getDelay());

        $this->conexao->executeStatement("UPDATE comercial_campanha SET estado = 'enviada'");
        $this->conexao->executeStatement('UPDATE comercial_expansor SET proxima_em = ? WHERE id = 1', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);
        $this->fila->reset();
        $handler(new ExpandirCampanhas());
        self::assertCount(0, $this->despachados(ExpandirCampanhas::class), 'sem ativa, a cadeia para');
    }

    #[TestDox('Rede de segurança: a varredura de 15 min despacha o expansor se há ativa e ele está mais de 5 min atrasado; senão, não')]
    public function testRedeDeSeguranca(): void
    {
        $semente = static::getContainer()->get(SementeDoExpansor::class);
        $this->conexao->executeStatement('UPDATE comercial_expansor SET proxima_em = ? WHERE id = 1', [(new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s')]);
        self::assertFalse($semente->socorrer(new \DateTimeImmutable()), 'sem campanha ativa, nada');

        $this->campanha($this->emailsDeAtivos(1), ['estado' => 'agendada', 'agendada_para' => new \DateTimeImmutable('+1 day'), 'proxima' => new \DateTimeImmutable('+1 day')]);
        $this->conexao->executeStatement('UPDATE comercial_varredura SET proxima_em = ? WHERE id = 1', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);
        static::getContainer()->get(VarrerAutomacoesHandler::class)(new VarrerAutomacoes());
        self::assertCount(1, $this->despachados(ExpandirCampanhas::class));

        $this->conexao->executeStatement('UPDATE comercial_expansor SET proxima_em = ? WHERE id = 1', [(new \DateTimeImmutable('-2 minutes'))->format('Y-m-d H:i:s')]);
        self::assertFalse($semente->socorrer(new \DateTimeImmutable()), 'atraso de 2 min: a cadeia ainda vive');
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

    private function expansor(bool $ligada = true, int $teto = 1000, ?CarteiroInterface $carteiro = null): ExpansorDeCampanhas
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
            $teto,
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
