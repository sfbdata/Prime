<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\PastaPagamento;
use App\Shared\Service\ValorEmReais;

/**
 * O card Pagamentos do trilho da aba Financeiro, inteiro e já formatado.
 *
 * Os totais são somados em CENTAVOS INTEIROS. Somar `decimal` como float é
 * como se perde centavo em silêncio, e aqui o número somado é mostrado ao lado
 * das parcelas que o compõem — a divergência apareceria na tela.
 */
final readonly class PastaPagamentosOutput
{
    public function __construct(
        public int $total,
        public int $quantidadePagos,
        public string $recebidoFormatado,
        public string $previstoFormatado,
        /** Inteiro de 0 a 100, para a largura da barra. */
        public int $percentual,
        /** @var PastaPagamentoLinhaOutput[] pendentes e vencidos, do mais próximo ao mais distante */
        public array $proximos,
        /** @var PastaPagamentoLinhaOutput[] todos, na mesma ordem, para o "ver todos" */
        public array $todos,
    ) {}

    /**
     * @param PastaPagamento[] $pagamentos já ordenados por vencimento pelo repositório
     */
    public static function montar(array $pagamentos, ?\DateTimeImmutable $hoje = null): self
    {
        $hoje = $hoje ?? new \DateTimeImmutable('today');

        $previstoCentavos = 0;
        $recebidoCentavos = 0;
        $quantidadePagos  = 0;
        $todos            = [];
        $proximos         = [];

        foreach ($pagamentos as $pagamento) {
            $centavos          = ValorEmReais::paraCentavos($pagamento->getValor());
            $previstoCentavos += $centavos;

            if ($pagamento->estaPago()) {
                $recebidoCentavos += $centavos;
                ++$quantidadePagos;
            }

            $linha   = PastaPagamentoLinhaOutput::montar($pagamento, $hoje);
            $todos[] = $linha;

            if (!$pagamento->estaPago()) {
                $proximos[] = $linha;
            }
        }

        return new self(
            total: count($pagamentos),
            quantidadePagos: $quantidadePagos,
            recebidoFormatado: PastaFinanceiroOutput::formatarReais(ValorEmReais::deCentavos($recebidoCentavos)),
            previstoFormatado: PastaFinanceiroOutput::formatarReais(ValorEmReais::deCentavos($previstoCentavos)),
            // Sem nada previsto a barra fica vazia: 0/0 não é 100% de nada.
            percentual: $previstoCentavos > 0
                ? (int) round($recebidoCentavos * 100 / $previstoCentavos)
                : 0,
            proximos: $proximos,
            todos: $todos,
        );
    }
}
