<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/**
 * O banco de horas de uma pessoa num escritório, por lotes (spec ponto-desenho-0811, P51 a, P52 e P73): o número do
 * topo do cartão "Registrar Ponto", os três quadros e a tabela da aba "Banco de Horas".
 */
final class BancoDeHorasOutput
{
    /**
     * @param int $saldoAtualMinutos soma de todos os meses, lote vencido incluído (como no desenho); não zera em janeiro
     * @param int $limiteMinutos acima dele, a faixa amarela e o triângulo do topo
     * @param list<LoteDoBancoDeHorasOutput> $lotes da competência mais antiga para a mais nova
     * @param array<string, int> $saldosPorCompetencia 'AAAA-MM' => minutos de cada mês (folha mais horas pagas), a
     *                                                  conta dos lotes; a /ponto tira dela o total "Saldo do mês"
     */
    public function __construct(
        public readonly int $saldoAtualMinutos,
        public readonly int $limiteMinutos,
        public readonly int $prazoMeses,
        public readonly bool $acimaDoLimite,
        public readonly array $lotes,
        public readonly array $saldosPorCompetencia = [],
    ) {
    }
}
