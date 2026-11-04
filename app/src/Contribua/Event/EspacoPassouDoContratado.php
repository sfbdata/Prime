<?php

declare(strict_types=1);

namespace App\Contribua\Event;

/**
 * Um envio entrou acima do limite porque a ampliação automática vai dobrar o espaço (a folga de uma dobra, em
 * `ArmazenamentoComCota`). Sai depois do commit, uma vez por escritório em cada requisição; quem ouve mede o uso de
 * novo e dobra (`AmpliarEspacoAutomaticamenteUseCase`).
 */
final class EspacoPassouDoContratado
{
    public function __construct(
        public readonly int $tenantId,
    ) {
    }
}
