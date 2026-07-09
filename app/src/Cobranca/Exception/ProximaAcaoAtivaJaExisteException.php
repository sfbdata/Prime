<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Tentativa de definir uma nova próxima ação enquanto o Caso de Cobrança já possui uma ação ativa
 * (pendente): o caso possui NO MÁXIMO uma próxima ação manual ativa por vez (SPEC §14). Para definir
 * outra, o gestor deve concluir a atual (registrando o resultado) primeiro.
 */
final class ProximaAcaoAtivaJaExisteException extends \DomainException
{
    public function __construct(int $casoId)
    {
        parent::__construct(sprintf(
            'Caso de cobrança %d já possui uma próxima ação ativa; conclua-a antes de definir outra.',
            $casoId,
        ));
    }
}
