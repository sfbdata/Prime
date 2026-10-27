<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Service\BaseDoPercentual;
use App\Shared\Service\ValorEmReais;

/**
 * O card Pagamentos do trilho da aba Financeiro, inteiro e já formatado.
 *
 * Os totais são somados em CENTAVOS INTEIROS. Somar `decimal` como float é
 * como se perde centavo em silêncio, e aqui o número somado é mostrado ao lado
 * das parcelas que o compõem — a divergência apareceria na tela.
 *
 * ÊXITO E SUCUMBÊNCIA (spec pasta-exito-sucumbencia, decisão 2 do dono): o previsto fica FORA
 * do contratado, do recebido e da barra. Ele só aparece como linha e no
 * "+ R$ X previsto em …" (`previstoFormatado` + `previstoEm`). O contratado é o mesmo número
 * do card "Total contratado" da faixa e a base do percentual (`BaseDoPercentual`, C8).
 */
final readonly class PastaPagamentosOutput
{
    public function __construct(
        public int $total,
        public int $quantidadePagos,
        public string $recebidoFormatado,
        /** Total contratado: a soma das linhas não previstas, pagas ou não. */
        public string $contratadoFormatado,
        public int $contratadoCentavos,
        /** Inteiro de 0 a 100, para a largura da barra. */
        public int $percentual,
        /** @var PastaPagamentoLinhaOutput[] pendentes e vencidos (por vencimento), depois os previstos */
        public array $proximos,
        /** @var PastaPagamentoLinhaOutput[] todos, na ordem do repositório, para o "ver todos" e o extrato */
        public array $todos,
        /** Soma dos previstos (êxito e sucumbência não recebidos). */
        public string $previstoFormatado = 'R$ 0,00',
        /** `''` (sem previsto), `exito`, `sucumbencia` ou `exito_sucumbencia`: o "previsto em …". */
        public string $previstoEm = '',
        /** Parcelas não pagas e não previstas com vencimento passado (faixa vermelha do cartão, dc 27.10). */
        public int $vencidasQuantidade = 0,
        public string $vencidasFormatado = 'R$ 0,00',
    ) {}

    /**
     * @param PastaPagamento[] $pagamentos já ordenados por vencimento pelo repositório
     * @param array<int, list<CorrecaoDeValorDoPagamentoOutput>> $correcoesPorPagamento
     *        do audit_log, por id do pagamento (`PastaPagamentoRepository::correcoesDeValor`)
     */
    public static function montar(array $pagamentos, ?\DateTimeImmutable $hoje = null, array $correcoesPorPagamento = []): self
    {
        $hoje  = $hoje ?? new \DateTimeImmutable('today');
        $base  = new BaseDoPercentual();
        $total = $base->centavosDaBase($pagamentos);

        $recebido = 0;
        $previsto = 0;
        $vencidasN = 0;
        $vencidasC = 0;
        $tipos    = [];
        $linhas   = ['todos' => [], 'pendentes' => [], 'previstos' => []];

        foreach ($pagamentos as $pagamento) {
            $centavos = $base->centavosDe($pagamento, $total);
            $linha    = PastaPagamentoLinhaOutput::montar($pagamento, $hoje, $correcoesPorPagamento[(int) $pagamento->getId()] ?? [], $centavos);
            $linhas['todos'][] = $linha;

            if ($pagamento->estaPago()) {
                $recebido += $centavos;
            } elseif ($pagamento->estaPrevisto()) {
                $previsto += $centavos;
                $tipos[$pagamento->getTipo()->value] = true;
                $linhas['previstos'][] = $linha;
            } else {
                $linhas['pendentes'][] = $linha;
                if ($pagamento->estaVencido($hoje)) {
                    ++$vencidasN;
                    $vencidasC += $centavos;
                }
            }
        }

        return new self(
            total: count($pagamentos),
            quantidadePagos: count(array_filter($pagamentos, static fn (PastaPagamento $p): bool => $p->estaPago())),
            recebidoFormatado: PastaFinanceiroOutput::formatarReais(ValorEmReais::deCentavos($recebido)),
            contratadoFormatado: PastaFinanceiroOutput::formatarReais(ValorEmReais::deCentavos($total)),
            contratadoCentavos: $total,
            // Sem nada contratado a barra fica vazia: 0/0 não é 100% de nada.
            percentual: $total > 0 ? (int) round($recebido * 100 / $total) : 0,
            proximos: [...$linhas['pendentes'], ...$linhas['previstos']],
            todos: $linhas['todos'],
            previstoFormatado: PastaFinanceiroOutput::formatarReais(ValorEmReais::deCentavos($previsto)),
            previstoEm: self::previstoEm($tipos),
            vencidasQuantidade: $vencidasN,
            vencidasFormatado: PastaFinanceiroOutput::formatarReais(ValorEmReais::deCentavos($vencidasC)),
        );
    }

    /** @param array<string, true> $tipos os tipos com previsto */
    private static function previstoEm(array $tipos): string
    {
        return match (true) {
            isset($tipos['exito'], $tipos['sucumbencia']) => 'exito_sucumbencia',
            isset($tipos['exito'])                        => 'exito',
            isset($tipos['sucumbencia'])                  => 'sucumbencia',
            default                                       => '',
        };
    }
}
