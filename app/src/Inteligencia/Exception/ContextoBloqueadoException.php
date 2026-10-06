<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * O contexto NÃO pode sair do escritório: a pasta tem processo com `nivelSigilo > 0` (segredo de
 * justiça). Lançada ANTES de qualquer chamada ao provedor — é a regra D4 da spec.
 */
final class ContextoBloqueadoException extends \DomainException
{
    public function __construct(public readonly string $numeroProcesso)
    {
        parent::__construct(sprintf(
            'Processo %s está em segredo de justiça: o conteúdo não pode ser enviado à IA.',
            $numeroProcesso,
        ));
    }

    public function motivo(): string
    {
        return 'contexto_bloqueado';
    }
}
