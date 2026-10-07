<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O que o modal "Adicionar pagamento" (desenho 1.2.3, dc L.1928-2012) manda ao
 * gravar: os CAMPOS do formulário, como o humano digitou. Nenhum valor de
 * parcela vem daqui — a conta é refeita no servidor, que é a fonte.
 *
 * Não há campo "entrada já recebida": por decisão do dono (07/10/2026), nenhuma
 * operação registra recebimento que não ocorreu — entrada e parcelas nascem
 * PENDENTES e só são quitadas pelo gesto explícito de marcar como recebido.
 *
 * Tudo em texto de propósito: a leitura (separador decimal, data, limites) é
 * regra do UseCase, e um tipo numérico aqui já teria decidido por ele.
 */
final class ParcelamentoDaPastaInput
{
    public const TIPO_CONTRATO = 'contrato';
    public const TIPO_CUSTAS   = 'custas';

    public const BASE_VALOR      = 'valor';
    public const BASE_PERCENTUAL = 'pct';

    public function __construct(
        /** `contrato` ou `custas`. Êxito e sucumbência dependem de migration (item 11b). */
        public readonly string $tipo,
        /** `valor` (valor fixo) ou `pct` (% do valor da causa). */
        public readonly string $base,
        /** Valor total em reais, como digitado ("12.860,00"). Usado quando a base é `valor`. */
        public readonly string $valorTotal,
        /** Percentual sobre o valor da causa ("20"). Usado quando a base é `pct`. */
        public readonly string $percentual,
        /** Entrada em reais; em branco = sem entrada. */
        public readonly string $entrada,
        /** Número de parcelas, como digitado. */
        public readonly string $parcelas,
        /** 1º vencimento no formato do campo `date` (AAAA-MM-DD). */
        public readonly string $primeiroVencimento,
        public readonly bool $comJuros,
        /** Juros ao mês em percentual ("1", "1,5"). Ignorado sem juros. */
        public readonly string $taxaMensal,
        /** Descrição livre opcional; em branco usa as do desenho ("kª parcela · honorários"). */
        public readonly string $descricao = '',
    ) {
    }
}
