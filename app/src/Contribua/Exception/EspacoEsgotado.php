<?php

declare(strict_types=1);

namespace App\Contribua\Exception;

use App\Contribua\DTO\RetratoDoEspaco;

/**
 * Envio recusado porque o escritório passaria do limite de espaço.
 *
 * Não é `FalhaDeArmazenamento` (o storage está são; quem recusa é a regra de negócio) nem
 * `InvalidArgumentException` (vários controllers capturam essa e a mostrariam como erro do
 * arquivo). Chega ao kernel e vira resposta no `EspacoEsgotadoListener`.
 */
final class EspacoEsgotado extends \RuntimeException
{
    /** Texto do desenho 08 (`bj-plano.js`, aviso em 100%). */
    public const MENSAGEM = 'Espaço esgotado: novos envios estão pausados. Consultar e baixar arquivos continua liberado.';

    public function __construct(
        public readonly RetratoDoEspaco $retrato,
    ) {
        parent::__construct(self::MENSAGEM);
    }
}
