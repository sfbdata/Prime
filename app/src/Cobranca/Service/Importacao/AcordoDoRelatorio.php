<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Acordo reconhecido na coluna "Informações do acordo" do relatório TOPLIFE (spec
 * `docs/specs/cobranca-importar-linhas-acordo.md` §3.1): formato `Acordo <N> - Parc. <p>/<t>`.
 * `numero` amarra as parcelas do mesmo acordo (dedup por carteira + número — o número é sequencial
 * por carteira, nunca globalmente único); `parcelaIndice`/`parcelaTotal` são o `p`/`t` do relatório
 * (ex.: "1/3" → índice 1, total 3 — as parcelas 2 e 3 não estão nesta linha).
 *
 * Value object fonte-agnóstico, análogo ao `BoletoImportavel` — o UseCase de importação (parte -B)
 * usa isto para achar/criar o `Acordo` (via identidade externa `numeroExterno`) e anexar a parcela.
 */
final class AcordoDoRelatorio
{
    public function __construct(
        public readonly int $numero,
        public readonly int $parcelaIndice,
        public readonly int $parcelaTotal,
    ) {
    }
}
