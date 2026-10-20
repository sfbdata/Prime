<?php

declare(strict_types=1);

namespace App\Comercial\EventListener;

use App\Comercial\Message\EnviarEmail;
use App\Comercial\Repository\EnvioDeEmailRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Falha definitiva de um envio (spec `comercial-e-emails.md` §3.1): quando o Messenger desiste (`willRetry()` falso),
 * o envio vira `falhou` com o erro sanitizado e o conteúdo some (N1). Enquanto houver retry, nada muda: o handler já
 * devolveu o envio a `enfileirado`.
 *
 * Sanitizado = todo endereço de e-mail trocado por `<e-mail>`, até 500 caracteres. O log leva só o id e o erro já
 * sanitizado.
 */
final class FalhaDeEnvioListener
{
    private const TAMANHO_DO_ERRO = 500;
    private const PADRAO_DO_EMAIL = '/[^\s<>()"\',;:]+@[^\s<>()"\',;:]+/u';

    public function __construct(
        private readonly EnvioDeEmailRepository $envios,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function __invoke(WorkerMessageFailedEvent $evento): void
    {
        $mensagem = $evento->getEnvelope()->getMessage();
        if (!$mensagem instanceof EnviarEmail || $evento->willRetry()) {
            return;
        }

        $erro = self::sanitizar(self::causa($evento->getThrowable())->getMessage());
        $this->envios->marcarFalhou($mensagem->envioId, $erro);
        $this->envios->apagarConteudo($mensagem->envioId);
        $this->logger->error('Envio de e-mail falhou de vez.', ['envioId' => $mensagem->envioId, 'erro' => $erro]);
    }

    public static function sanitizar(string $erro): string
    {
        $semEnderecos = (string) preg_replace(self::PADRAO_DO_EMAIL, '<e-mail>', $erro);
        $texto = trim($semEnderecos) === '' ? 'Falha no envio.' : trim($semEnderecos);

        return mb_substr($texto, 0, self::TAMANHO_DO_ERRO);
    }

    /** O Messenger embrulha a exceção do handler; o que interessa é a de dentro. */
    private static function causa(\Throwable $erro): \Throwable
    {
        if ($erro instanceof HandlerFailedException) {
            return array_values($erro->getWrappedExceptions())[0] ?? $erro;
        }

        return $erro;
    }
}
