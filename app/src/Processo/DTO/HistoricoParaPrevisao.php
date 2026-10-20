<?php

declare(strict_types=1);

namespace App\Processo\DTO;

/**
 * O que a "Situação atual" do Push precisa do HISTÓRICO (as movimentações da pasta além da mais
 * recente): quantas são, para o "histórico de N movimentações" (dc L.5114), e se alguma fala em
 * contestação, para o `exige` da regra de réplica (dc L.5098). Ler o teor inteiro de cada uma só
 * para isso era caro; a consulta responde as duas perguntas no banco.
 */
final readonly class HistoricoParaPrevisao
{
    /** @param int $total movimentações da lista, contando a mais recente */
    public function __construct(
        public int $total,
        public bool $temContestacao,
    ) {
    }
}
