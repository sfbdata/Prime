<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/**
 * Um dia da tabela da folha em PDF, já no texto que a página desenha (desenho 16 L667 a L686; spec ponto-desenho-0811,
 * E16, P35 e seção 8): o dia, a semana ("FERIADO" no feriado sem batida), as quatro batidas em HH:MM (vazias quando
 * faltam), as horas trabalhadas, as extras e o saldo do mês (vazios quando o dia não tem conta, como o dia futuro) e as
 * observações do dia unidas por " · ".
 *
 * As marcas dizem a cor: fora de sequência (batidas e observação em vermelho) e registro incompleto (observação em
 * ocre). Os ajustes do dia ficam também à parte, só para o desenho (decisão 27): é a parte da observação que se corta
 * primeiro quando ela não cabe na coluna. Montada por {@see \App\Ponto\Service\LinhasDaFolhaImpressa}.
 */
final readonly class LinhaDaFolhaImpressa
{
    /**
     * @param string       $data          o dia em 'AAAA-MM-DD'
     * @param list<string> $batidas       entrada, início do intervalo, fim do intervalo e saída, em HH:MM ou vazias
     * @param ?string      $feriado       o nome do feriado do dia, ou null
     * @param list<string> $justificativas a situação de cada justificativa do dia (abonado, pendente, rejeitado)
     * @param string       $ajustes        os ajustes do dia ("<Batida> ajustada em dd/mm/aaaa", unidos por " · "), o
     *                                     começo da observação; vazio no dia sem ajuste. Só para o desenho: fica fora
     *                                     da impressão dos registros, que já tem a observação inteira
     */
    public function __construct(
        public string $data,
        public string $dia,
        public string $semana,
        public bool $feriadoSemBatida,
        public array $batidas,
        public string $trabalhadas,
        public string $extras,
        public string $saldo,
        public bool $saldoNegativo,
        public string $observacao,
        public bool $foraDeSequencia,
        public bool $incompleto,
        public ?string $feriado,
        public array $justificativas,
        public string $ajustes = '',
    ) {
    }

    /**
     * O que este dia entra na impressão dos registros (decisão 3 da E16): data, batidas, trabalhado, extra, saldo,
     * observações (com ajuste e feriado), feriado, sequência, incompleto e a situação das justificativas. Sem id e sem
     * segundos: só o que a folha imprime.
     *
     * @return array<string, mixed>
     */
    public function paraAImpressao(): array
    {
        return [
            'data'              => $this->data,
            'batidas'           => $this->batidas,
            'trabalhadas'       => $this->trabalhadas,
            'extras'            => $this->extras,
            'saldo'             => $this->saldo,
            'observacao'        => $this->observacao,
            'feriado'           => $this->feriado,
            'fora_de_sequencia' => $this->foraDeSequencia,
            'incompleto'        => $this->incompleto,
            'justificativas'    => $this->justificativas,
        ];
    }
}
