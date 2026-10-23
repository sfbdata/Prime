<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Scheduler;

use App\Shared\Scheduler\AgendaDeTarefas;
use App\Shared\Scheduler\AvisoDeFalhaDoAgendador;
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
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;

/**
 * Aviso de falha do agendador (spec docs/specs/scheduler-tarefas-agendadas.md, "Aviso de falha"): sempre loga;
 * manda e-mail só com AGENDADOR_ALERTA_EMAIL preenchida; uma falha no e-mail nunca chega ao consumidor.
 */
#[CoversClass(AvisoDeFalhaDoAgendador::class)]
final class AvisoDeFalhaDoAgendadorTest extends TestCase
{
    private const REMETENTE = 'BlueJus <nao-responda@bluejus.com.br>';

    private LoggerEmMemoria $logger;

    protected function setUp(): void
    {
        $this->logger = new LoggerEmMemoria();
    }

    public function testSemEnderecoDeAlertaSoRegistraOErroNoLog(): void
    {
        $mailer = new MailerQueGuarda();

        (new AvisoDeFalhaDoAgendador($this->logger, $mailer, self::REMETENTE, ''))
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

        (new AvisoDeFalhaDoAgendador($this->logger, $mailer, self::REMETENTE, '   '))
            ->__invoke($this->falhaDoComando('app:djen:sincronizar --no-interaction', 1));

        self::assertCount(1, $this->logger->doNivel('error'));
        self::assertSame([], $mailer->enviados);
    }

    public function testComEnderecoDeAlertaLogaEMandaEmailCurto(): void
    {
        $mailer = new MailerQueGuarda();

        (new AvisoDeFalhaDoAgendador($this->logger, $mailer, self::REMETENTE, 'ops@exemplo.com, dono@exemplo.com'))
            ->__invoke($this->falhaDoComando('app:dashboard:fotografar --no-interaction', 1, 'Falha ao fotografar o escritório #2'));

        self::assertCount(1, $this->logger->doNivel('error'));
        self::assertCount(1, $mailer->enviados);

        $email = $mailer->enviados[0];
        self::assertSame('[BlueJus] Tarefa agendada falhou: app:dashboard:fotografar', $email->getSubject());
        self::assertSame('nao-responda@bluejus.com.br', $email->getFrom()[0]->getAddress());
        self::assertSame(['ops@exemplo.com', 'dono@exemplo.com'], array_map(static fn ($a) => $a->getAddress(), $email->getTo()));
        $texto = (string) $email->getTextBody();
        self::assertStringContainsString('23/10/2026 23:55', $texto, 'Hora da execução no fuso de Brasília.');
        self::assertStringContainsString('Falha ao fotografar o escritório #2', $texto);
        self::assertStringNotContainsString('—', $texto);
        self::assertStringNotContainsString('–', $texto);
    }

    public function testFalhaNoEnvioDoEmailEhRegistradaENaoPropaga(): void
    {
        $mailer = new MailerQueGuarda(falhar: true);

        (new AvisoDeFalhaDoAgendador($this->logger, $mailer, self::REMETENTE, 'ops@exemplo.com'))
            ->__invoke($this->falhaDoComando('app:perfil:resumo-diario --no-interaction', 1));

        $erros = $this->logger->doNivel('error');
        self::assertCount(2, $erros, 'O erro da tarefa e o erro do envio.');
        self::assertSame('app:perfil:resumo-diario', $erros[1]['contexto']['comando']);
        self::assertInstanceOf(TransportException::class, $erros[1]['contexto']['exception']);
    }

    public function testEnderecoMalEscritoNaoPropaga(): void
    {
        $mailer = new MailerQueGuarda();

        (new AvisoDeFalhaDoAgendador($this->logger, $mailer, self::REMETENTE, 'isto não é e-mail'))
            ->__invoke($this->falhaDoComando('app:perfil:resumo-diario --no-interaction', 1));

        self::assertCount(2, $this->logger->doNivel('error'));
        self::assertSame([], $mailer->enviados);
    }

    public function testExcecaoDoComandoSemRunCommandFailedAindaLogaONome(): void
    {
        $mensagem = new RunCommandMessage('app:sync:reconciliar --tenant-id=1 --usuario-id=1 --modo=enviar --no-interaction');
        $erro     = new HandlerFailedException(new EnvelopeDoMessenger($mensagem), ['console.messenger.execute_command_handler' => new \RuntimeException('banco fora do ar')]);

        (new AvisoDeFalhaDoAgendador($this->logger, new MailerQueGuarda(), self::REMETENTE, ''))
            ->__invoke($this->evento($mensagem, $erro));

        $erros = $this->logger->doNivel('error');
        self::assertCount(1, $erros);
        self::assertSame('app:sync:reconciliar', $erros[0]['contexto']['comando']);
        self::assertStringContainsString('banco fora do ar', $erros[0]['contexto']['erro']);
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

    private function evento(RunCommandMessage $mensagem, \Throwable $erro): FailureEvent
    {
        $agenda  = new AgendaDeTarefas(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
        $gatilho = CronExpressionTrigger::fromSpec('55 23 * * *', null, AgendaDeTarefas::FUSO);
        // 02h55 UTC de 24/10 = 23h55 de 23/10 em Brasília.
        $contexto = new MessageContext('default', 'id', $gatilho, new \DateTimeImmutable('2026-10-24 02:55:00', new \DateTimeZone('UTC')));

        return new FailureEvent($agenda, $contexto, $mensagem, $erro);
    }
}
