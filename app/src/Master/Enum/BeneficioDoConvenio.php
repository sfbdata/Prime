<?php

declare(strict_types=1);

namespace App\Master\Enum;

/**
 * O que o convênio dá (spec docs/specs/convenios.md, §3.1 e §4). Um por convênio; `beneficio_valor` diz quanto:
 * gratuidade 0, desconto percentual de 1 a 100, desconto fixo em centavos, espaço extra em GB.
 */
enum BeneficioDoConvenio: string
{
    case Gratuidade = 'gratuidade';
    case DescontoPercentual = 'desconto_percentual';
    case DescontoFixo = 'desconto_fixo';
    case EspacoExtra = 'espaco_extra';

    /**
     * Benefício que mexe em dinheiro ou em espaço. Só ele segue valendo com a chave geral desligada (§6.1): a chave é de
     * exibição e de emergência; o benefício real termina por convênio, com aviso.
     */
    public function ehReal(): bool
    {
        return $this !== self::Gratuidade;
    }
}
