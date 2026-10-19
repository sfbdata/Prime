<?php

declare(strict_types=1);

namespace App\Contribua\Event;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Segura os eventos de domínio do Contribua até o trabalho dar certo, e só então os despacha.
 *
 * O envio de arquivo grava antes do COMMIT de quem o persiste; se o banco recusar depois, o aviso de
 * "espaço quase cheio" não pode ter saído. Por isso os eventos esperam o fim do trabalho:
 *  - requisição HTTP com resposta de sucesso (abaixo de 400) → despacha; com erro ou exceção → descarta;
 *  - comando de console que termina com 0 → despacha; com outro código → descarta;
 *  - mensagem do worker tratada → despacha; com falha → descarta.
 * Quem ouve recebe o evento depois do commit e pode confiar que o dado existe.
 */
final class EventosDoEspacoAposConfirmar implements ResetInterface
{
    /** @var list<object> */
    private array $pendentes = [];

    public function __construct(
        private readonly EventDispatcherInterface $eventos,
    ) {
    }

    public function adiar(object $evento): void
    {
        $this->pendentes[] = $evento;
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -1024)]
    public function aoResponder(ResponseEvent $evento): void
    {
        if ($evento->isMainRequest()) {
            $evento->getResponse()->getStatusCode() < 400 ? $this->despachar() : $this->reset();
        }
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 1024)]
    public function aoFalhar(ExceptionEvent $evento): void
    {
        $this->reset();
    }

    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function aoTerminarComando(ConsoleTerminateEvent $evento): void
    {
        $evento->getExitCode() === 0 ? $this->despachar() : $this->reset();
    }

    #[AsEventListener(event: WorkerMessageHandledEvent::class)]
    public function aoTratarMensagem(WorkerMessageHandledEvent $evento): void
    {
        $this->despachar();
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function aoFalharMensagem(WorkerMessageFailedEvent $evento): void
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->pendentes = [];
    }

    private function despachar(): void
    {
        [$eventos, $this->pendentes] = [$this->pendentes, []];
        foreach ($eventos as $evento) {
            $this->eventos->dispatch($evento);
        }
    }
}
