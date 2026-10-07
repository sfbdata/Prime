<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Shared\Service\ValorEmReais;

/**
 * O financeiro da folha impressa, já decidido e formatado.
 *
 * Duas leituras do desenho 1.2.3 com os mesmos lançamentos:
 *  - "Imprimir extrato" (dc 3515): "R$ X recebidos de R$ Y" e uma linha por lançamento —
 *    `descricao: valor · quando`, a MESMA linha da folha `#psExtratoFolha` da aba Financeiro;
 *  - o bloco "Financeiro" que a opção do mesmo nome acrescenta à folha (`impFinanceiro`):
 *    contrato · Contratado · Recebido · A receber, e a tabela Lançamento · Vencimento · Valor ·
 *    Situação. "Previsto (êxito/sucumbência)" do protótipo não entra: o sistema não tem esse tipo
 *    de lançamento.
 *
 * Os totais são somados em CENTAVOS, como no card Pagamentos (`PastaPagamentosOutput`).
 */
final readonly class PastaExtratoImpressaoOutput
{
    /**
     * @param list<array{descricao: string, vencimento: string, valor: string, quando: string, situacao: string, tom: string}> $linhas
     */
    private function __construct(
        public string $contrato,
        public string $contratadoFormatado,
        public string $recebidoFormatado,
        public string $aReceberFormatado,
        public array $linhas,
    ) {
    }

    /**
     * @param PastaPagamento[] $pagamentos os do card Pagamentos, já ordenados por vencimento
     */
    public static function montar(Pasta $pasta, array $pagamentos, ?\DateTimeImmutable $hoje = null): self
    {
        $hoje       = ($hoje ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $contratado = 0;
        $recebido   = 0;
        $linhas     = [];

        foreach ($pagamentos as $pagamento) {
            $centavos    = ValorEmReais::paraCentavos($pagamento->getValor());
            $contratado += $centavos;
            $linha       = PastaPagamentoLinhaOutput::montar($pagamento, $hoje);

            if ($pagamento->estaPago()) {
                $recebido += $centavos;
            }

            $linhas[] = [
                'descricao'  => $linha->descricao,
                'vencimento' => $pagamento->getVencimento()->format('d/m/Y'),
                'valor'      => $linha->valorFormatado,
                'quando'     => $linha->quando,
                'situacao'   => self::situacao($pagamento, $hoje),
                'tom'        => $linha->tom,
            ];
        }

        $contrato = match (true) {
            $pasta->isProBono()                          => 'Pró-bono',
            $pasta->getSituacaoContrato() === 'PENDENTE' => 'Contrato pendente de assinatura',
            default                                      => 'Contrato assinado',
        };

        return new self(
            contrato: $contrato,
            contratadoFormatado: PastaFinanceiroOutput::formatarReais(ValorEmReais::deCentavos($contratado)),
            recebidoFormatado: PastaFinanceiroOutput::formatarReais(ValorEmReais::deCentavos($recebido)),
            aReceberFormatado: PastaFinanceiroOutput::formatarReais(ValorEmReais::deCentavos(max(0, $contratado - $recebido))),
            linhas: $linhas,
        );
    }

    /** Os rótulos do desenho (`est`): "Recebido em dd/mm/aaaa", "Vencido" ou "A receber". */
    private static function situacao(PastaPagamento $pagamento, \DateTimeImmutable $hoje): string
    {
        if ($pagamento->estaPago()) {
            $em = $pagamento->getPagoEm();

            return $em !== null ? 'Recebido em ' . $em->format('d/m/Y') : 'Recebido';
        }

        return $pagamento->estaVencido($hoje) ? 'Vencido' : 'A receber';
    }
}
