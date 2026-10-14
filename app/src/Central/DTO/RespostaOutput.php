<?php

declare(strict_types=1);

namespace App\Central\DTO;

use App\Central\Entity\Mensagem;
use App\Central\Service\MencoesDaCentral;

/**
 * A mensagem citada numa resposta (A2): quem escreveu e um trecho, com as menções como texto. É da mesma conversa
 * (invariante da `Mensagem`) — quem vê a resposta já pode ver a original.
 */
final class RespostaOutput
{
    public const TAMANHO_DO_TRECHO = 120;

    private function __construct(
        public readonly int $id,
        public readonly string $autorNome,
        public readonly string $trecho,
    ) {
    }

    public static function fromEntity(Mensagem $original): self
    {
        $texto = MencoesDaCentral::comoTexto($original->getConteudo());
        if (mb_strlen($texto) > self::TAMANHO_DO_TRECHO) {
            $texto = mb_substr($texto, 0, self::TAMANHO_DO_TRECHO) . '…';
        }

        return new self((int) $original->getId(), (string) $original->getAutor()->getFullName(), $texto);
    }
}
