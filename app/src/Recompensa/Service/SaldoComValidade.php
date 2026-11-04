<?php

declare(strict_types=1);

namespace App\Recompensa\Service;

use App\Recompensa\Entity\ProgramaDeRecompensa;

/**
 * Saldo de recompensa com a validade de {@see ProgramaDeRecompensa::VALIDADE_MESES} meses por crédito (decisão do dono,
 * 04/11/2026; desenho 08 VS 03.11.26). Pura, sem banco: recebe os créditos e os débitos com data e devolve o saldo e o
 * que venceu.
 *
 * Regra: cada crédito vence {@see ProgramaDeRecompensa::VALIDADE_MESES} meses depois de ganho. Crédito ganho antes de
 * `validadeDesde` (o deploy) conta como ganho em `validadeDesde`. Débito (pagamento ou estorno) anterior a `validadeDesde`
 * também entra em `validadeDesde`, ou seja, consome antes de qualquer vencimento (o que já foi pago nunca volta a ser saldo).
 * Os eventos andam em ordem de data (no mesmo instante, crédito antes de débito); cada débito consome dos créditos ainda
 * não vencidos na data dele, o que vence primeiro primeiro. Crédito vence no instante exato de ganho + meses. Débito sem
 * crédito disponível se perde (o saldo nunca fica negativo). No fim, saldo = o que sobrou dos créditos não vencidos em
 * `agora`; expirado = o que sobrou dos vencidos.
 *
 * `validadeDesde` nula (programa sem linha no banco): nada vence e o saldo é o de antes da validade,
 * max(0, max(0, créditos - estornos) - pagos), sem depender da ordem.
 */
final class SaldoComValidade
{
    /**
     * @param list<array{valor: int, em: \DateTimeImmutable}> $creditos valores positivos, com a data em que foram ganhos
     * @param list<array{valor: int, em: \DateTimeImmutable}> $estornos valores positivos (o módulo do bônus negativo)
     * @param list<array{valor: int, em: \DateTimeImmutable}> $pagos    valores positivos
     *
     * @return array{saldo: int, expirado: int}
     */
    public static function apurar(array $creditos, array $estornos, array $pagos, ?\DateTimeImmutable $validadeDesde, \DateTimeImmutable $agora): array
    {
        if ($validadeDesde === null) {
            $soma = static fn (array $lista): int => array_sum(array_column($lista, 'valor'));

            return ['saldo' => max(0, max(0, $soma($creditos) - $soma($estornos)) - $soma($pagos)), 'expirado' => 0];
        }

        $abertos = [];
        foreach (self::eventos($creditos, [...$estornos, ...$pagos], $validadeDesde) as $e) {
            if ($e['credito']) {
                $abertos[] = ['vence' => $e['em']->modify('+' . ProgramaDeRecompensa::VALIDADE_MESES . ' months'), 'resta' => $e['valor']];
                usort($abertos, static fn (array $a, array $b): int => $a['vence'] <=> $b['vence']);
                continue;
            }
            $abertos = self::consumir($abertos, $e['valor'], $e['em']);
        }

        $r = ['saldo' => 0, 'expirado' => 0];
        foreach ($abertos as $credito) {
            $r[$credito['vence'] <= $agora ? 'expirado' : 'saldo'] += $credito['resta'];
        }

        return $r;
    }

    /**
     * Créditos e débitos numa fila só, com a data puxada para `validadeDesde`. Estável: no mesmo instante, crédito antes
     * de débito; empate restante mantém a ordem de chegada.
     *
     * @param list<array{valor: int, em: \DateTimeImmutable}> $creditos
     * @param list<array{valor: int, em: \DateTimeImmutable}> $debitos
     *
     * @return list<array{em: \DateTimeImmutable, credito: bool, valor: int}>
     */
    private static function eventos(array $creditos, array $debitos, \DateTimeImmutable $validadeDesde): array
    {
        $eventos = [];
        foreach ($creditos as $c) {
            $eventos[] = ['em' => max($c['em'], $validadeDesde), 'credito' => true, 'valor' => $c['valor']];
        }
        foreach ($debitos as $d) {
            $eventos[] = ['em' => max($d['em'], $validadeDesde), 'credito' => false, 'valor' => $d['valor']];
        }
        usort($eventos, static fn (array $a, array $b): int => [$a['em'], $a['credito'] ? 0 : 1] <=> [$b['em'], $b['credito'] ? 0 : 1]);

        return $eventos;
    }

    /**
     * Um débito consome dos créditos ainda não vencidos na data dele, o que vence primeiro primeiro.
     *
     * @param list<array{vence: \DateTimeImmutable, resta: int}> $abertos em ordem de vencimento
     *
     * @return list<array{vence: \DateTimeImmutable, resta: int}>
     */
    private static function consumir(array $abertos, int $falta, \DateTimeImmutable $em): array
    {
        foreach ($abertos as $i => $credito) {
            if ($falta === 0) {
                break;
            }
            if ($credito['vence'] <= $em || $credito['resta'] === 0) {
                continue;
            }
            $usado = min($falta, $credito['resta']);
            $abertos[$i]['resta'] -= $usado;
            $falta -= $usado;
        }

        return $abertos;
    }
}
