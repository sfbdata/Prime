<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Service\BaseDoPercentual;
use App\Shared\Service\ValorEmReais;

/**
 * O financeiro da folha impressa, já decidido e formatado.
 *
 * Duas leituras do desenho 1.2.3 com os mesmos lançamentos:
 *  - "Imprimir extrato" (dc 3515): "R$ X recebidos de R$ Y" e uma linha por lançamento —
 *    `descricao: valor · quando`, a MESMA linha da folha `#psExtratoFolha` da aba Financeiro;
 *  - o bloco "Financeiro" que a opção do mesmo nome acrescenta à folha (`impFinanceiro`):
 *    contrato · Contratado · Recebido · A receber, e a tabela Lançamento · Vencimento · Valor ·
 *    Situação.
 *
 * Êxito e sucumbência (desenho 02, `impFinanceiro` L.4849-4860): o previsto fica fora do
 * Contratado, do Recebido e do A receber; o cabeçalho ganha " · Previsto (êxito/sucumbência)
 * R$ X" e a linha dele sai sem vencimento, com o % ao lado do valor e a situação "Previsto".
 * O valor do previsto é o calculado na leitura (`BaseDoPercentual`), o mesmo do card.
 *
 * Os totais são somados em CENTAVOS, como no card Pagamentos (`PastaPagamentosOutput`).
 */
final readonly class PastaExtratoImpressaoOutput
{
    /**
     * @param list<array{descricao: string, vencimento: string, valor: string, percentual: string, quando: string, situacao: string, tom: string}> $linhas
     */
    private function __construct(
        public string $contrato,
        public string $contratadoFormatado,
        public string $recebidoFormatado,
        public string $aReceberFormatado,
        public array $linhas,
        /** Soma dos previstos; vazio quando não há nenhum (o trecho some do cabeçalho). */
        public string $previstoFormatado = '',
    ) {
    }

    /**
     * @param PastaPagamento[] $pagamentos os do card Pagamentos, já ordenados por vencimento
     */
    public static function montar(Pasta $pasta, array $pagamentos, ?\DateTimeImmutable $hoje = null): self
    {
        $hoje       = ($hoje ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $base       = new BaseDoPercentual();
        $contratado = $base->centavosDaBase($pagamentos);
        $recebido   = 0;
        $previsto   = 0;
        $linhas     = [];

        foreach ($pagamentos as $pagamento) {
            $centavos = $base->centavosDe($pagamento, $contratado);
            $linhas[] = self::linha($pagamento, $hoje, $centavos);

            if ($pagamento->estaPago()) {
                $recebido += $centavos;
            } elseif ($pagamento->estaPrevisto()) {
                $previsto += $centavos;
            }
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
            previstoFormatado: $previsto > 0 ? PastaFinanceiroOutput::formatarReais(ValorEmReais::deCentavos($previsto)) : '',
        );
    }

    /** @return array{descricao: string, vencimento: string, valor: string, percentual: string, quando: string, situacao: string, tom: string} */
    private static function linha(PastaPagamento $pagamento, \DateTimeImmutable $hoje, int $centavos): array
    {
        $linha = PastaPagamentoLinhaOutput::montar($pagamento, $hoje, [], $centavos);

        return [
            'descricao'  => $linha->descricao,
            'vencimento' => $pagamento->getVencimento()?->format('d/m/Y') ?? '',
            'valor'      => $linha->valorFormatado,
            'percentual' => $pagamento->estaPrevisto() ? $linha->percentualTexto : '',
            'quando'     => $linha->quando,
            'situacao'   => self::situacao($pagamento, $hoje),
            'tom'        => $linha->tom,
        ];
    }

    /** Os rótulos do desenho (`est`): "Recebido em dd/mm/aaaa", "Previsto", "Vencido" ou "A receber". */
    private static function situacao(PastaPagamento $pagamento, \DateTimeImmutable $hoje): string
    {
        if ($pagamento->estaPago()) {
            $em = $pagamento->getPagoEm();

            return $em !== null ? 'Recebido em ' . $em->format('d/m/Y') : 'Recebido';
        }

        if ($pagamento->estaPrevisto()) {
            return 'Previsto';
        }

        return $pagamento->estaVencido($hoje) ? 'Vencido' : 'A receber';
    }
}
