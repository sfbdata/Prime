<?php

declare(strict_types=1);

namespace App\Produto\Enum;

/**
 * A chave `MULTIPRODUTO_MODO` (spec docs/specs/multiproduto-contratacao-e-voto.md, §11, D2 e D3).
 *
 * - `desligado`: tudo como hoje; a guarda do produto não age (padrão);
 * - `observacao`: a guarda registra no canal de log `multiproduto` a rota que fecharia e deixa seguir;
 * - `bloqueio`: rota de produto que o escritório não tem responde 404.
 *
 * Valor vazio, desconhecido ou com erro de digitação vale `desligado`: a chave nunca fecha nada por engano.
 */
enum ModoDoMultiproduto: string
{
    case Desligado = 'desligado';
    case Observacao = 'observacao';
    case Bloqueio = 'bloqueio';

    public static function doTexto(?string $valor): self
    {
        return self::tryFrom(strtolower(trim((string) $valor))) ?? self::Desligado;
    }
}
