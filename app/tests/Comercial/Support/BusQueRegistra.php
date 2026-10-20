<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Support;

use App\Comercial\Message\EnviarEmail;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Bus de teste: guarda cada envelope despachado e, no momento do despacho, o nível de transação da conexão e se o
 * registro do envio já está visível nela. Com `falhar`, lança depois de registrar (a fila caiu).
 */
final class BusQueRegistra implements MessageBusInterface
{
    /** @var list<array{envioId: int, nivel: int, registroVisivel: bool}> */
    public array $despachos = [];

    /** @var list<Envelope> */
    public array $envelopes = [];

    public function __construct(
        private readonly Connection $conexao,
        private readonly bool $falhar = false,
    ) {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        \assert($message instanceof EnviarEmail);
        $this->despachos[] = [
            'envioId' => $message->envioId,
            'nivel' => $this->conexao->getTransactionNestingLevel(),
            'registroVisivel' => $this->conexao->fetchOne('SELECT 1 FROM comercial_envio WHERE id = ?', [$message->envioId]) !== false,
        ];
        if ($this->falhar) {
            throw new \RuntimeException('Fila indisponível.');
        }
        $envelope = new Envelope($message, $stamps);
        $this->envelopes[] = $envelope;

        return $envelope;
    }
}
