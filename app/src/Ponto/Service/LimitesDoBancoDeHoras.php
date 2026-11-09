<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Tenant\Tenant;

/**
 * Limite do saldo e prazo de compensação do banco de horas de um escritório (spec ponto-desenho-0811, P73): os campos
 * "Limite do banco (horas)" e "Prazo de compensação (meses)" das Configurações do Ponto. O limite só avisa (faixa
 * amarela e triângulo do topo, P52); o prazo dá o vencimento de cada lote (P51 a).
 */
interface LimitesDoBancoDeHoras
{
    /** Acima deste saldo, em minutos, a tela avisa que o banco passou do limite. */
    public function limiteEmMinutos(Tenant $tenant): int;

    /** Meses que cada lote tem para ser compensado: vence no último dia do mês "competência mais prazo". */
    public function prazoEmMeses(Tenant $tenant): int;
}
