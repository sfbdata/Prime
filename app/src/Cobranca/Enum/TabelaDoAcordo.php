<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Qual das duas tabelas de uma aba do relatório de acordos a linha veio
 * (SPEC docs/specs/cobranca-espelho-quatro-relatorios.md §3.2).
 *
 * 🔑 **Existe porque as duas tabelas falam de dinheiros DIFERENTES na mesma aba**, e um leitor que as
 * misturasse somaria a dívida antiga com a renegociada:
 *
 * - `ContasOriginais` — o que o devedor devia ANTES do acordo, conta a conta (`Valor original (R$)`).
 * - `Parcelas` — o que ele passou a dever DEPOIS (`Valor acordado (R$)`), já renegociado.
 *
 * Distinguir pela coluna `Parcela` estar preenchida seria inferência: funciona hoje e passa a mentir
 * no dia em que uma parcela vier sem o rótulo. O balde é gravado, não deduzido.
 */
enum TabelaDoAcordo: string
{
    case ContasOriginais = 'contas_originais';
    case Parcelas = 'parcelas';
}
