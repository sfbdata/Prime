<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/**
 * Um lote do banco de horas (spec ponto-desenho-0811, P51 a; desenho 16 L206 e L776): o saldo de uma competência.
 * Mês com saldo positivo é crédito, que vence; mês com saldo negativo é débito ("Compensação"), que abate os créditos
 * do mais antigo para o mais novo. Os números vêm em minutos; a tela formata.
 */
final class LoteDoBancoDeHorasOutput
{
    /**
     * @param string $competencia "MM/AAAA"
     * @param int $creditoMinutos o saldo do mês quando positivo; 0 no lote de compensação
     * @param int $compensadoMinutos o quanto do crédito os débitos já usaram; no lote de compensação, o débito do mês
     * @param int $saldoMinutos o crédito que sobra para compensar; 0 no lote de compensação
     * @param ?\DateTimeImmutable $venceEm último dia do mês "competência mais prazo"; nulo no lote de compensação
     * @param string $situacao "No prazo", "Vence em N dias", "Vencido", "Compensado" ou "Compensação"
     * @param string $cor fundo do selo da situação, a cor do desenho
     */
    public function __construct(
        public readonly string $competencia,
        public readonly int $creditoMinutos,
        public readonly int $compensadoMinutos,
        public readonly int $saldoMinutos,
        public readonly ?\DateTimeImmutable $venceEm,
        public readonly string $situacao,
        public readonly string $cor,
    ) {
    }
}
