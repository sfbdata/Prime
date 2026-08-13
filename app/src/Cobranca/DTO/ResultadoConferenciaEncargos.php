<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * O encargo GRAVADO no banco × o que a nossa fórmula produz na data do próprio snapshot
 * (SPEC docs/specs/cobranca-espelho-da-contabilidade.md §17).
 *
 * É a régua que faltava: nem a conferência (que compara `valor_original`) nem a calibração (que
 * compara a fórmula contra as colunas da planilha) leem o que está escrito em
 * `cobranca_obrigacao.juros/multa/correcao/honorarios`. Sem ela, a Fase 1 não consegue provar o
 * próprio conserto — rodar a calibração antes e depois de matar a dupla contagem dá o mesmo número.
 */
final readonly class ResultadoConferenciaEncargos
{
    /**
     * `$duplicadoPorCampo` existe porque um escalar único escondia coisas de naturezas diferentes:
     * multa duplicada entra no saldo cobrado, honorário **não** entra (`Obrigacao::valorExigivel()`
     * soma principal + juros + multa + correção). Levar um total à contabilidade sem separar os dois
     * seria apresentar como "saldo cobrado duas vezes" algo que em parte não é saldo.
     *
     * @param array<string, int>                                                       $duplicadoPorCampo campo => centavos
     * @param list<array{unidade: string, referencia: ?string, campo: string, gravado: int,
     *                   pelaFormula: int, diferenca: int, duplicado: int, ehParcelaDeAcordo: bool}> $piores
     */
    public function __construct(
        public string $carteira,
        public ?\DateTimeImmutable $dadosAte,
        public int $conferidos,
        public int $semParNoRelatorio,
        public int $coerentes,
        public int $comDuplaContagem,
        public int $divergentes,
        public int $diferencaEmCentavos,
        public int $duplicadoEmCentavos,
        public array $duplicadoPorCampo,
        public array $piores,
    ) {
    }

    public function percentualCoerente(): float
    {
        return $this->conferidos === 0 ? 0.0 : ($this->coerentes / $this->conferidos) * 100;
    }

    /**
     * O veredito, e ele tem TRÊS estados de propósito — o do meio é o que a Fase 1 persegue.
     *
     * `dupla contagem` vence `divergente` mesmo com um único caso: divergência é ruído esperado
     * (snapshot velho, arredondamento), enquanto a assinatura da dupla contagem é dinheiro contado
     * duas vezes e não tem versão pequena aceitável.
     */
    public function veredito(): string
    {
        if ($this->conferidos === 0) {
            return 'sem dado';
        }

        if ($this->comDuplaContagem > 0) {
            return 'dupla contagem';
        }

        return $this->divergentes === 0 ? 'coerente' : 'divergente';
    }
}
