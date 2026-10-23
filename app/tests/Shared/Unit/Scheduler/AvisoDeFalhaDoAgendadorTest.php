<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Scheduler;

use App\Shared\Scheduler\AgendaDeTarefas;
use App\Shared\Email\IdentidadeDeEmail;
use App\Shared\Scheduler\AvisoDeFalhaDoAgendador;
use App\Shared\Scheduler\Repository\FalhaAgendadaRepository;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use App\Tests\Shared\Doubles\MailerQueGuarda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Exception\RunCommandFailedException;
use Symfony\Component\Console\Messenger\RunCommandContext;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Messenger\Envelope as EnvelopeDoMessenger;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;

/**
 * Aviso de falha do agendador (spec docs/specs/scheduler-tarefas-agendadas.md, "Aviso de falha"): sempre loga;
 * grava a falha (só metadados) para o aviso na tela; manda e-mail só com AGENDADOR_ALERTA_EMAIL preenchida;
 * uma falha no e-mail ou na gravação nunca chega ao consumidor.
 */
#[CoversClass(AvisoDeFalhaDoAgendador::class)]
final class AvisoDeFalhaDoAgendadorTest extends TestCase
{
    private LoggerEmMemoria $logger;

    private IdentidadeDeEmail $identidade;

    private FalhaAgendadaRepository $falhas;

    protected function setUp(): void
    {
        $this->falhas     = $this->createStub(FalhaAgendadaRepository::class);
        $this->logger     = new LoggerEmMemoria();
        $this->identidade = new IdentidadeDeEmail('BlueJus <nao-responda@bluejus.com.br>', 'suporte@bluejus.com.br');
    }

    public function testSemEnderecoDeAlertaSoRegistraOErroNoLog(): void
    {
        $mailer = new MailerQueGuarda();

        (new AvisoDeFalhaDoAgendador($this->logger, $mailer, $this->identidade, $this->falhas, ''))
            ->__invoke($this->falhaDoComando('app:pasta:cobrar-checklist --no-interaction', 1, "linha 1\nFalhou no escritório 3"));

        $erros = $this->logger->doNivel('error');
        self::assertCount(1, $erros);
        self::assertSame('app:pasta:cobrar-checklist', $erros[0]['contexto']['comando']);
        self::assertSame('Command "app:pasta:cobrar-checklist --no-interaction" exited with code "1".', $erros[0]['contexto']['erro']);
        self::assertSame(1, $erros[0]['contexto']['codigo']);
        self::assertStringContainsString('Falhou no escritório 3', $erros[0]['contexto']['saida']);
        self::assertSame([], $mailer->enviados);
    }

    public function testEnderecoSoComEspacosContaComoVazio(): void
    {
        $mailer = new MailerQueGuarda();

        (new AvisoDeFalhaDoAgendador($this->logger, $mailer, $this->identidade, $this->falhas, '   '))
            ->__invoke($this->falhaDoComando('app:djen:sincronizar --no-interaction', 1));

        self::assertCount(1, $this->logger->doNivel('error'));
        self::assertSame([], $mailer->enviados);
    }

    public function testComEnderecoDeAlertaLogaEMandaEmailCurto(): void
    {
        $mailer = new MailerQueGuarda();

        (new AvisoDeFalhaDoAgendador($this->logger, $mailer, $this->identidade, $this->falhas, 'ops@exemplo.com, dono@exemplo.com'))
            ->__invoke($this->falhaDoComando('app:dashboard:fotografar --no-interaction', 1, 'Falha ao fotografar o escritório #2'));

        self::assertCount(1, $this->logger->doNivel('error'));
        self::assertCount(1, $mailer->enviados);

        $email = $mailer->enviados[0];
        self::assertSame('[BlueJus] Tarefa agendada falhou: app:dashboard:fotografar', $email->getSubject());
        self::assertSame('nao-responda@bluejus.com.br', $email->getFrom()[0]->getAddress());
        self::assertSame('suporte@bluejus.com.br', $email->getReplyTo()[0]->getAddress(), 'Identidade padrão dos e-mails do sistema.');
        self::assertSame(['ops@exemplo.com', 'dono@exemplo.com'], array_map(static fn ($a) => $a->getAddress(), $email->getTo()));
        $texto = (string) $email->getTextBody();
        self::assertStringContainsString('app:dashboard:fotografar', $texto);
        self::assertStringContainsString('23/10/2026 23:55', $texto, 'Hora da execução no fuso de Brasília.');
        self::assertStringContainsString(RunCommandFailedException::class, $texto);
        self::assertStringContainsString('Código de saída: 1', $texto);
        self::assertStringContainsString('docker logs jusprime_scheduler_prod', $texto);
        self::assertStringNotContainsString('—', $texto);
        self::assertStringNotContainsString('–', $texto);
    }

    public function testEmailNaoLevaAMensagemNemASaidaDoComando(): void
    {
        $mailer = new MailerQueGuarda();

        (new AvisoDeFalhaDoAgendador($this->logger, $mailer, $this->identidade, $this->falhas, 'ops@exemplo.com'))
            ->__invoke($this->falhaDoComando('app:perfil:resumo-diario --no-interaction', 1, 'Enviado para ana@cliente.com.br (Escritório Silva)'));

        $texto = (string) $mailer->enviados[0]->getTextBody();
        self::assertStringNotContainsString('ana@cliente.com.br', $texto);
        self::assertStringNotContainsString('Escritório Silva', $texto);
        self::assertStringNotContainsString('exited with code', $texto, 'A mensagem crua fica só no log.');
        self::assertStringContainsString('ana@cliente.com.br', $this->logger->doNivel('error')[0]['contexto']['saida'], 'O log guarda o detalhe.');
    }

    public function testExcecaoLancadaPeloComandoMostraAClasseDeOrigemSemAMensagem(): void
    {
        $mailer   = new MailerQueGuarda();
        $mensagem = new RunCommandMessage('app:djen:sincronizar --no-interaction');
        $falha    = new RunCommandFailedException(
            new \DomainException('CPF 123.456.789-00 inválido'),
            new RunCommandContext($mensagem, 1, ''),
        );
        $erro = new HandlerFailedException(new EnvelopeDoMessenger($mensagem), ['console.messenger.execute_command_handler' => $falha]);

        (new AvisoDeFalhaDoAgendador($this->logger, $mailer, $this->identidade, $this->falhas, 'ops@exemplo.com'))
            ->__invoke($this->evento($mensagem, $erro));

        $texto = (string) $mailer->enviados[0]->getTextBody();
        self::assertStringContainsString('Erro: DomainException', $texto);
        self::assertStringNotContainsString('123.456.789-00', $texto);
    }

    public function testFalhaNoEnvioDoEmailEhRegistradaENaoPropaga(): void
    {
        $mailer = new MailerQueGuarda(falhar: true);

        (new AvisoDeFalhaDoAgendador($this->logger, $mailer, $this->identidade, $this->falhas, 'ops@exemplo.com'))
            ->__invoke($this->falhaDoComando('app:perfil:resumo-diario --no-interaction', 1));

        $erros = $this->logger->doNivel('error');
        self::assertCount(2, $erros, 'O erro da tarefa e o erro do envio.');
        self::assertSame('app:perfil:resumo-diario', $erros[1]['contexto']['comando']);
        self::assertInstanceOf(TransportException::class, $erros[1]['contexto']['exception']);
    }

    public function testEnderecoMalEscritoNaoPropaga(): void
    {
        $mailer = new MailerQueGuarda();

        (new AvisoDeFalhaDoAgendador($this->logger, $mailer, $this->identidade, $this->falhas, 'isto não é e-mail'))
            ->__invoke($this->falhaDoComando('app:perfil:resumo-diario --no-interaction', 1));

        self::assertCount(2, $this->logger->doNivel('error'));
        self::assertSame([], $mailer->enviados);
    }

    public function testExcecaoDoComandoSemRunCommandFailedAindaLogaONome(): void
    {
        $mensagem = new RunCommandMessage('app:sync:reconciliar --tenant-id=1 --usuario-id=1 --modo=enviar --no-interaction');
        $erro     = new HandlerFailedException(new EnvelopeDoMessenger($mensagem), ['console.messenger.execute_command_handler' => new \RuntimeException('banco fora do ar')]);

        (new AvisoDeFalhaDoAgendador($this->logger, new MailerQueGuarda(), $this->identidade, $this->falhas, ''))
            ->__invoke($this->evento($mensagem, $erro));

        $erros = $this->logger->doNivel('error');
        self::assertCount(1, $erros);
        self::assertSame('app:sync:reconciliar', $erros[0]['contexto']['comando']);
        self::assertStringContainsString('banco fora do ar', $erros[0]['contexto']['erro']);
    }

    public function testVoltaDasCadeiasMostraONomeCurtoDaMensagemDeDentro(): void
    {
        $dentro   = new \ArrayObject(['email' => 'cliente@exemplo.com']);
        $mensagem = new RedispatchMessage($dentro, 'comercial');
        $erro     = new HandlerFailedException(new EnvelopeDoMessenger($mensagem), ['messenger.redispatch_message_handler' => new \RuntimeException('banco fora do ar')]);
        $falhas   = $this->createMock(FalhaAgendadaRepository::class);
        $falhas->expects(self::once())->method('registrar')->with('ArrayObject', self::anything(), \RuntimeException::class, null);
        $mailer = new MailerQueGuarda();

        (new AvisoDeFalhaDoAgendador($this->logger, $mailer, $this->identidade, $falhas, 'ops@exemplo.com'))
            ->__invoke($this->evento($mensagem, $erro));

        self::assertSame('ArrayObject', $this->logger->doNivel('error')[0]['contexto']['comando']);
        self::assertCount(1, $mailer->enviados);
        $email = $mailer->enviados[0];
        self::assertStringContainsString('ArrayObject', (string) $email->getSubject());
        self::assertStringNotContainsString('RedispatchMessage', (string) $email->getSubject());
        self::assertStringNotContainsString('cliente@exemplo.com', (string) $email->getTextBody(), 'nada do conteúdo da mensagem');
    }

    public function testSegundaFalhaDoMesmoComandoDentroDe60MinNaoMandaEmailMasGravaELoga(): void
    {
        // A falha agendada é às 23h55 de Brasília; a anterior do mesmo comando, às 23h00 (55 min antes).
        $falhas = $this->createMock(FalhaAgendadaRepository::class);
        $falhas->method('ultimaDoComando')->with('app:dashboard:fotografar')
            ->willReturn(new \DateTimeImmutable('2026-10-23 23:00:00', new \DateTimeZone(AgendaDeTarefas::FUSO)));
        $falhas->expects(self::once())->method('registrar');
        $mailer = new MailerQueGuarda();

        (new AvisoDeFalhaDoAgendador($this->logger, $mailer, $this->identidade, $falhas, 'ops@exemplo.com'))
            ->__invoke($this->falhaDoComando('app:dashboard:fotografar --no-interaction', 1));

        self::assertCount(0, $mailer->enviados, 'dentro de 60 min da anterior, sem e-mail');
        self::assertCount(1, $this->logger->doNivel('error'), 'o log continua');
    }

    public function testFalhaDoMesmoComandoDepoisDe60MinMandaEmail(): void
    {
        $falhas = $this->createStub(FalhaAgendadaRepository::class);
        $falhas->method('ultimaDoComando')
            ->willReturn(new \DateTimeImmutable('2026-10-23 22:55:00', new \DateTimeZone(AgendaDeTarefas::FUSO)));
        $mailer = new MailerQueGuarda();

        (new AvisoDeFalhaDoAgendador($this->logger, $mailer, $this->identidade, $falhas, 'ops@exemplo.com'))
            ->__invoke($this->falhaDoComando('app:dashboard:fotografar --no-interaction', 1));

        self::assertCount(1, $mailer->enviados, 'a anterior foi há 60 min: avisa de novo');
    }

    public function testConsultaDaFalhaAnteriorQueFalhaNaoSeguraOEmail(): void
    {
        $falhas = $this->createStub(FalhaAgendadaRepository::class);
        $falhas->method('ultimaDoComando')->willThrowException(new \RuntimeException('banco fora'));
        $mailer = new MailerQueGuarda();

        (new AvisoDeFalhaDoAgendador($this->logger, $mailer, $this->identidade, $falhas, 'ops@exemplo.com'))
            ->__invoke($this->falhaDoComando('app:dashboard:fotografar --no-interaction', 1));

        self::assertCount(1, $mailer->enviados);
    }

    public function testGravaAFalhaComOComandoAHoraDeBrasiliaAClasseEOCodigo(): void
    {
        $falhas = $this->createMock(FalhaAgendadaRepository::class);
        $falhas->expects(self::once())->method('registrar')->with(
            'app:djen:sincronizar',
            self::callback(static fn (\DateTimeImmutable $em): bool => $em->format('Y-m-d H:i:s e') === '2026-10-23 23:55:00 America/Sao_Paulo'),
            RunCommandFailedException::class,
            2,
        );

        (new AvisoDeFalhaDoAgendador($this->logger, new MailerQueGuarda(), $this->identidade, $falhas, ''))
            ->__invoke($this->falhaDoComando('app:djen:sincronizar --dias=2 --no-interaction', 2, 'saída com dado de cliente'));

        self::assertCount(1, $this->logger->doNivel('error'));
    }

    public function testExcecaoDoComandoGravaAClasseDeOrigemSemCodigoDeSaida(): void
    {
        $mensagem = new RunCommandMessage('app:sync:reconciliar --no-interaction');
        $erro     = new HandlerFailedException(new EnvelopeDoMessenger($mensagem), ['console.messenger.execute_command_handler' => new \RuntimeException('banco fora do ar')]);
        $falhas   = $this->createMock(FalhaAgendadaRepository::class);
        $falhas->expects(self::once())->method('registrar')
            ->with('app:sync:reconciliar', self::anything(), \RuntimeException::class, null);

        (new AvisoDeFalhaDoAgendador($this->logger, new MailerQueGuarda(), $this->identidade, $falhas, ''))
            ->__invoke($this->evento($mensagem, $erro));
    }

    public function testFalhaAoGravarEhRegistradaENaoImpedeOEmail(): void
    {
        $falhas = $this->createStub(FalhaAgendadaRepository::class);
        $falhas->method('registrar')->willThrowException(new \RuntimeException('tabela ausente'));
        $mailer = new MailerQueGuarda();

        (new AvisoDeFalhaDoAgendador($this->logger, $mailer, $this->identidade, $falhas, 'ops@exemplo.com'))
            ->__invoke($this->falhaDoComando('app:djen:sincronizar --dias=2 --no-interaction', 1));

        $erros = $this->logger->doNivel('error');
        self::assertCount(2, $erros, 'O erro da tarefa e o erro da gravação.');
        self::assertStringContainsString('gravar a falha', $erros[1]['mensagem']);
        self::assertSame('tabela ausente', $erros[1]['contexto']['erro']);
        self::assertCount(1, $mailer->enviados, 'O e-mail sai mesmo sem a gravação.');
    }

    /** Como o worker entrega: RunCommandFailedException embrulhada numa HandlerFailedException. */
    private function falhaDoComando(string $input, int $codigo, string $saida = ''): FailureEvent
    {
        $mensagem = new RunCommandMessage($input);
        $falha    = new RunCommandFailedException(
            sprintf('Command "%s" exited with code "%s".', $input, $codigo),
            new RunCommandContext($mensagem, $codigo, $saida),
        );

        // Chave = nome do handler, como o HandleMessageMiddleware monta.
        return $this->evento($mensagem, new HandlerFailedException(
            new EnvelopeDoMessenger($mensagem),
            ['console.messenger.execute_command_handler' => $falha],
        ));
    }

    private function evento(object $mensagem, \Throwable $erro): FailureEvent
    {
        $agenda  = new AgendaDeTarefas(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
        $gatilho = CronExpressionTrigger::fromSpec('55 23 * * *', null, AgendaDeTarefas::FUSO);
        // 02h55 UTC de 24/10 = 23h55 de 23/10 em Brasília.
        $contexto = new MessageContext('default', 'id', $gatilho, new \DateTimeImmutable('2026-10-24 02:55:00', new \DateTimeZone('UTC')));

        return new FailureEvent($agenda, $contexto, $mensagem, $erro);
    }
}
