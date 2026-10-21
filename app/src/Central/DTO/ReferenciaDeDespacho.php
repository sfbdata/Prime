<?php

declare(strict_types=1);

namespace App\Central\DTO;

use App\Central\Enum\TipoDeItemEncaminhado;
use App\Pasta\Entity\Pasta;

/**
 * A referência que o despacho grava na mensagem (`docs/specs/encaminhar-via-chat-ia.md` §3): a pasta e o tipo do item.
 * Vai pelo `EnviarMensagemUseCase` e entra na mesma transação da mensagem. Na leitura, é ela que decide se o texto sai
 * (`LeituraDasMensagens`).
 */
final class ReferenciaDeDespacho
{
    public function __construct(
        public readonly Pasta $pasta,
        public readonly TipoDeItemEncaminhado $tipo,
    ) {
    }
}
