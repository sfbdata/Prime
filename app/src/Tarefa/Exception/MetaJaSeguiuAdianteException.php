<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/**
 * Reabrir uma meta que a passagem de etapa já concluiu e levou adiante (decisão do dono, 22/10): bloqueado. Para
 * corrigir, a pessoa volta a etapa pelo modal da passagem. A frase que o usuário lê está no catálogo
 * ({@see self::CHAVE}).
 */
final class MetaJaSeguiuAdianteException extends \DomainException
{
    public const CHAVE = 'metas.passagem.ja_seguiu_adiante';

    public function __construct()
    {
        parent::__construct(self::CHAVE);
    }
}
