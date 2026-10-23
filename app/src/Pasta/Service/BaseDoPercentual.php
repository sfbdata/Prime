<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Shared\Service\ValorEmReais;

/**
 * A base do percentual de êxito e sucumbência, e o valor em reais de quem tem percentual.
 * Ponto ÚNICO da regra: o card, a impressão, a quitação e a correção leem daqui.
 *
 * Decisão C8 do dono (23/10/2026): a base é o "Total contratado", a soma dos lançamentos NÃO
 * previstos da pasta, pagos ou não (o `baseCausa` do desenho 02, dc L.3600). Não é mais o
 * `pasta.valor_causa`. Êxito recebido deixa de ser previsto e passa a somar, como no desenho.
 *
 * Tudo em CENTAVOS INTEIROS; o arredondamento do percentual é o da `CalculadoraDeParcelamento`
 * (meio para cima), o mesmo do "% da causa" do parcelamento.
 */
final class BaseDoPercentual
{
    public function __construct(
        private readonly CalculadoraDeParcelamento $calculadora = new CalculadoraDeParcelamento(),
    ) {
    }

    /**
     * O Total contratado de uma lista de lançamentos (da mesma pasta).
     *
     * @param iterable<PastaPagamento> $pagamentos
     */
    public function centavosDaBase(iterable $pagamentos): int
    {
        $soma = 0;
        foreach ($pagamentos as $pagamento) {
            if (!$pagamento->estaPrevisto()) {
                $soma += ValorEmReais::paraCentavos($pagamento->getValor());
            }
        }

        return $soma;
    }

    /** O Total contratado da pasta, pelos lançamentos dela. */
    public function daPasta(Pasta $pasta): int
    {
        return $this->centavosDaBase($pasta->getPagamentos());
    }

    /** Valor em centavos do lançamento: o gravado, ou o percentual sobre a base. */
    public function centavosDe(PastaPagamento $pagamento, int $baseCentavos): int
    {
        $percentual = $pagamento->getPercentual();
        if ($percentual === null) {
            return ValorEmReais::paraCentavos($pagamento->getValor());
        }

        return $this->calculadora->percentualDe($baseCentavos, $percentual);
    }

    /** O valor do lançamento AGORA, em decimal ("2572.00"), sobre a base da própria pasta. */
    public function valorCalculado(PastaPagamento $pagamento): string
    {
        if ($pagamento->getPercentual() === null) {
            return $pagamento->getValor();
        }

        $pasta = $pagamento->getPasta();

        return $this->valorCalculadoSobre($pagamento, $pasta !== null ? $pasta->getPagamentos() : []);
    }

    /**
     * O valor do lançamento sobre a base dos lançamentos informados: os relidos sob a trava da
     * pasta (`PastaPagamentoRepository::travarPastaELerLancamentos`) na hora de congelar.
     *
     * @param iterable<PastaPagamento> $lancamentosDaPasta
     */
    public function valorCalculadoSobre(PastaPagamento $pagamento, iterable $lancamentosDaPasta): string
    {
        if ($pagamento->getPercentual() === null) {
            return $pagamento->getValor();
        }

        return ValorEmReais::deCentavos($this->centavosDe($pagamento, $this->centavosDaBase($lancamentosDaPasta)));
    }
}
