<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

/**
 * Estado derivado do link externo do Push (spec push-link-externo §1). Não é gravado: sai de
 * `revogado_em`, `expira_em` e do pedido de renovação, na hora da leitura, com o `$agora` de quem
 * pergunta. A ordem de precedência é revogado > expirado > ativo; "renovação pedida" é um expirado
 * com pedido sem resposta.
 */
enum EstadoDoLinkExterno: string
{
    case Ativo = 'ativo';
    case Expirado = 'expirado';
    case RenovacaoPedida = 'renovacao_pedida';
    case Revogado = 'revogado';

    /** Rótulos da lista "Links desta pasta" ([EXP] 5219). */
    public function rotulo(): string
    {
        return match ($this) {
            self::Ativo => 'Ativo',
            self::Expirado => 'Expirado',
            self::RenovacaoPedida => 'Renovação pedida',
            self::Revogado => 'Revogado',
        };
    }
}
