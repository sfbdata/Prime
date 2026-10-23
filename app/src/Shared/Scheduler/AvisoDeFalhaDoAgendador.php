<?php

declare(strict_types=1);

namespace App\Shared\Scheduler;

use App\Shared\Email\IdentidadeDeEmail;
use App\Shared\Scheduler\Repository\FalhaAgendadaRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Exception\RunCommandFailedException;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Scheduler\Event\FailureEvent;

/**
 * Avisa quando uma tarefa agendada falha (spec `docs/specs/scheduler-tarefas-agendadas.md`, "Aviso de falha").
 *
 * Um {@see RunCommandMessage} com código de saída diferente de 0, ou um comando que lança exceção, vira
 * {@see RunCommandFailedException} no handler do Symfony; o worker do Messenger a embrulha numa
 * {@see HandlerFailedException} e o Scheduler dispara o {@see FailureEvent}. O transport do agendador não
 * tem nova tentativa nem fila de falhas: este aviso é o único rastro.
 *
 * Sempre registra um erro no log (stderr do contêiner jusprime_scheduler_prod), com o detalhe completo:
 * mensagem e fim da saída do comando. Grava também uma {@see \App\Shared\Scheduler\Entity\FalhaAgendada}
 * (só metadados), que alimenta o aviso na moldura e a Central do Master. Com AGENDADOR_ALERTA_EMAIL preenchida (um ou mais endereços
 * separados por vírgula), manda também um e-mail curto SEM dado de cliente: só o comando, o horário, a
 * classe da exceção e o código de saída (a mensagem e a saída podem citar escritório, pessoa ou e-mail; o
 * detalhe fica no log). Uma falha no envio do e-mail é registrada e engolida: o aviso não pode derrubar o
 * consumidor do agendador. O mesmo vale para a gravação da falha.
 */
#[AsEventListener(event: FailureEvent::class)]
final class AvisoDeFalhaDoAgendador
{
    /** Quanto da saída do comando vai para o log e para o e-mail (o fim, onde costuma estar o erro). */
    private const LIMITE_DA_SAIDA = 2000;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MailerInterface $mailer,
        private readonly IdentidadeDeEmail $identidade,
        private readonly FalhaAgendadaRepository $falhas,
        #[Autowire('%env(string:default::AGENDADOR_ALERTA_EMAIL)%')]
        private readonly string $alertaEmail,
    ) {
    }

    public function __invoke(FailureEvent $evento): void
    {
        $comando = $this->nomeDaTarefa($evento->getMessage());
        $falha   = $this->falhaDoComando($evento->getError());
        $erro    = $falha?->getMessage() ?? $evento->getError()->getMessage();
        $saida   = $this->fimDaSaida($falha?->context->output ?? '');
        $quando  = $evento->getMessageContext()->triggeredAt
            ->setTimezone(new \DateTimeZone(AgendaDeTarefas::FUSO));

        $this->logger->error('Tarefa agendada falhou: {comando}: {erro}', [
            'comando'  => $comando,
            'erro'     => $erro,
            'codigo'   => $falha?->context->exitCode,
            'saida'    => $saida,
            'agendada' => $quando->format(\DATE_ATOM),
            'exception' => $evento->getError(),
        ]);

        $classe = $this->classeDoErro($falha ?? $evento->getError());
        $this->registrarFalha($comando, $quando, $classe, $falha?->context->exitCode);
        $this->avisarPorEmail($comando, $quando, $classe, $falha?->context->exitCode);
    }

    /**
     * Grava a falha para o aviso na tela (Master e AGENDADOR_ALERTA_EMAIL) e a Central do Master. Uma falha
     * aqui (banco fora, tabela ausente) é registrada e engolida: não pode derrubar o consumidor nem o e-mail.
     */
    private function registrarFalha(string $comando, \DateTimeImmutable $quando, string $classe, ?int $codigo): void
    {
        try {
            $this->falhas->registrar($comando, $quando, $classe, $codigo);
        } catch (\Throwable $e) {
            $this->logger->error('Não foi possível gravar a falha da tarefa agendada {comando}: {erro}', [
                'comando'   => $comando,
                'erro'      => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    private function avisarPorEmail(string $comando, \DateTimeImmutable $quando, string $classe, ?int $codigo): void
    {
        if (trim($this->alertaEmail) === '') {
            return;
        }

        // Dentro do try também o endereço: um AGENDADOR_ALERTA_EMAIL mal escrito lança na montagem.
        try {
            $this->mailer->send($this->montarEmail($this->destinatarios(), $comando, $quando, $classe, $codigo));
        } catch (\Throwable $e) {
            $this->logger->error('Não foi possível enviar o e-mail de falha da tarefa agendada {comando}: {erro}', [
                'comando'   => $comando,
                'erro'      => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /** A exceção de origem: a do comando, se ele lançou; senão a RunCommandFailedException do código de saída. */
    private function classeDoErro(\Throwable $erro): string
    {
        return ($erro->getPrevious() ?? $erro)::class;
    }

    /** O nome do comando ("app:pasta:cobrar-checklist"), sem as opções. */
    private function nomeDaTarefa(object $mensagem): string
    {
        if ($mensagem instanceof RunCommandMessage) {
            return strtok(trim($mensagem->input), ' ') ?: $mensagem->input;
        }

        return get_debug_type($mensagem);
    }

    private function falhaDoComando(\Throwable $erro): ?RunCommandFailedException
    {
        if ($erro instanceof RunCommandFailedException) {
            return $erro;
        }

        if ($erro instanceof HandlerFailedException) {
            // As chaves são os nomes dos handlers (strings), não 0, 1...
            return array_values($erro->getWrappedExceptions(RunCommandFailedException::class, true))[0] ?? null;
        }

        return null;
    }

    private function fimDaSaida(string $saida): string
    {
        $saida = trim($saida);

        return mb_strlen($saida) > self::LIMITE_DA_SAIDA
            ? '(...) ' . mb_substr($saida, -self::LIMITE_DA_SAIDA)
            : $saida;
    }

    /** @return list<Address> */
    private function destinatarios(): array
    {
        $enderecos = [];
        foreach (explode(',', $this->alertaEmail) as $endereco) {
            $endereco = trim($endereco);
            if ($endereco !== '') {
                $enderecos[] = new Address($endereco);
            }
        }

        return $enderecos;
    }

    /**
     * Só metadados: nada da mensagem nem da saída do comando, que podem trazer dado de cliente.
     *
     * @param list<Address> $destinatarios
     */
    private function montarEmail(array $destinatarios, string $comando, \DateTimeImmutable $quando, string $classeDoErro, ?int $codigo): Email
    {
        $texto = sprintf(
            "A tarefa agendada %s falhou na execução de %s (horário de Brasília).\n\nErro: %s\nCódigo de saída: %s\n\n"
            . "O detalhe (mensagem e saída do comando) está no log do contêiner: docker logs jusprime_scheduler_prod\n",
            $comando,
            $quando->format('d/m/Y H:i'),
            $classeDoErro,
            $codigo === null ? 'desconhecido' : (string) $codigo,
        );

        $email = (new Email())
            ->to(...$destinatarios)
            ->text($texto);

        return $this->identidade->aplicar($email, 'Tarefa agendada falhou: ' . $comando);
    }
}
