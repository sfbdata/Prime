<?php

declare(strict_types=1);

namespace App\ChatIa\DTO;

use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Exception\ModeloDoChatInvalidoException;
use App\ChatIa\Exception\TituloDoChatInvalidoException;
use App\Inteligencia\Enum\ModeloDeIa;

/**
 * O corpo de `PATCH /chat-ia/conversas/{id}`: `{titulo?, fixada?, modelo?}`. Só o que veio é alterado. O modelo é
 * validado contra {@see ModeloDeIa} (valor fora = 422).
 */
final readonly class AtualizarConversaInput
{
    public function __construct(
        public ?string $titulo = null,
        public ?bool $fixada = null,
        public ?ModeloDeIa $modelo = null,
    ) {
        if ($titulo !== null && mb_strlen(trim($titulo)) > ConversaDeChat::TAMANHO_MAXIMO_DO_TITULO) {
            throw new TituloDoChatInvalidoException();
        }
    }

    /** @param array<string, mixed> $corpo */
    public static function deCorpo(array $corpo): self
    {
        $titulo = $corpo['titulo'] ?? null;
        $fixada = $corpo['fixada'] ?? null;
        $modelo = $corpo['modelo'] ?? null;
        if (($titulo !== null && !\is_string($titulo)) || ($fixada !== null && !\is_bool($fixada))) {
            throw new TituloDoChatInvalidoException('Dados inválidos.');
        }
        if ($modelo !== null && (!\is_string($modelo) || ModeloDeIa::tryFrom($modelo) === null)) {
            throw new ModeloDoChatInvalidoException();
        }

        return new self($titulo, $fixada, \is_string($modelo) ? ModeloDeIa::from($modelo) : null);
    }
}
