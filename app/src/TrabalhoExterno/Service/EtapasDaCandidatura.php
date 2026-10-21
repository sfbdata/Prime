<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Candidatura;
use App\TrabalhoExterno\Enum\StatusCandidatura;

/**
 * A linha do tempo da candidatura (`etapas`, desenho 06 l.1039 a 1046), calculada no servidor: os
 * passos, o estado de cada um (feito, atual, futuro, nao), a data (dd/mm/aa), os dias entre um passo e
 * o seguinte e o total. "Enviado ao escritório" só existe na vaga com dono. Com "Não selecionado", o
 * último passo alcançado aparece feito (lacuna 16; o protótipo o deixava apagado) e entra o passo
 * "Não selecionado".
 */
final class EtapasDaCandidatura
{
    private const DIA = 86400;

    /** @var list<array{0: string, 1: string, 2: string}> */
    private const PASSOS = [
        ['recebida', 'Candidatura', 'bi-send'],
        ['analise', 'Em análise', 'bi-search'],
        ['enviado', 'Enviado ao escritório', 'bi-building-check'],
        ['entrevista', 'Entrevista', 'bi-chat-dots'],
        ['aprovado', 'Aprovado', 'bi-hand-thumbs-up'],
        ['contratado', 'Contratado', 'bi-trophy'],
    ];

    /** @return array{passos: list<array{k: string, rot: string, ic: string, estado: string, data: string, dias: string}>, total: string} */
    public function calcular(Candidatura $c, \DateTimeImmutable $agora): array
    {
        $dono = $c->getVaga()->temDono();
        $passos = array_values(array_filter(self::PASSOS, static fn (array $p): bool => $dono || $p[0] !== 'enviado'));
        $datas = $this->datas($c, $dono);
        $ix = $this->indiceAtual($c, $passos, $datas, $dono);
        $nao = $c->getStatus() === StatusCandidatura::Nao;

        $linha = [];
        foreach ($passos as $i => [$k, $rot, $ic]) {
            $estado = $this->estado($i, $ix, $k, $nao);
            $linha[] = [
                'k' => $k,
                'rot' => $rot,
                'ic' => $estado === 'feito' && $i > 0 ? 'bi-check-lg' : $ic,
                'estado' => $estado,
                'data' => isset($datas[$k]) && $i <= $ix ? $datas[$k]->format('d/m/y') : '',
                'dias' => $this->dias($passos, $datas, $i, $ix, $nao, $agora),
            ];
        }
        if ($nao) {
            $linha[] = ['k' => 'nao', 'rot' => 'Não selecionado', 'ic' => 'bi-x-lg', 'estado' => 'nao', 'data' => isset($datas['nao']) ? $datas['nao']->format('d/m/y') : '', 'dias' => ''];
        }

        return ['passos' => $linha, 'total' => self::emDias($this->totalDeDias($c, $datas, $agora))];
    }

    /** @return array<string, \DateTimeImmutable> etapa → quando; o envio da vaga com dono conta desde a candidatura */
    private function datas(Candidatura $c, bool $dono): array
    {
        $datas = ['recebida' => $c->getEnviadaEm()];
        foreach ($c->getDatas() as $k => $iso) {
            $datas[$k] = new \DateTimeImmutable($iso);
        }
        if ($dono && $c->isEnviadoAoEscritorio() && !isset($datas['enviado'])) {
            $datas['enviado'] = $c->getEnviadaEm();
        }

        return $datas;
    }

    /**
     * O passo de agora: o do status ("Em análise" já enviado conta como "Enviado ao escritório"); com
     * "Não selecionado", o último passo que tem data.
     *
     * @param list<array{0: string, 1: string, 2: string}> $passos
     * @param array<string, \DateTimeImmutable>           $datas
     */
    private function indiceAtual(Candidatura $c, array $passos, array $datas, bool $dono): int
    {
        $ordem = array_column($passos, 0);
        $status = $c->getStatus();
        if ($status === StatusCandidatura::Nao) {
            $alcancados = array_keys(array_filter($ordem, static fn (string $k): bool => isset($datas[$k])));

            return $alcancados === [] ? -1 : max($alcancados);
        }
        $k = $status === StatusCandidatura::Analise && $dono && isset($datas['enviado']) ? 'enviado' : $status->value;
        $i = array_search($k, $ordem, true);

        return is_int($i) ? $i : -1;
    }

    private function estado(int $i, int $ix, string $k, bool $nao): string
    {
        return match (true) {
            $i < $ix, $i === $ix && ($k === 'contratado' || $nao) => 'feito',
            $i === $ix => 'atual',
            default => 'futuro',
        };
    }

    /**
     * Os dias entre este passo e o seguinte, quando os dois foram alcançados; no passo atual, os dias
     * desde que chegou nele, com "(atual)".
     *
     * @param list<array{0: string, 1: string, 2: string}> $passos
     * @param array<string, \DateTimeImmutable>           $datas
     */
    private function dias(array $passos, array $datas, int $i, int $ix, bool $nao, \DateTimeImmutable $agora): string
    {
        if ($i >= count($passos) - 1 || !isset($datas[$passos[$i][0]])) {
            return '';
        }
        $de = $datas[$passos[$i][0]];
        $ate = $datas[$passos[$i + 1][0]] ?? null;
        if ($ate !== null && $i + 1 <= $ix) {
            return self::emDias(self::diasEntre($de, $ate));
        }

        return $i === $ix && !$nao ? self::emDias(self::diasEntre($de, $agora)) . ' (atual)' : '';
    }

    /** @param array<string, \DateTimeImmutable> $datas */
    private function totalDeDias(Candidatura $c, array $datas, \DateTimeImmutable $agora): int
    {
        $fim = $c->getStatus() === StatusCandidatura::Contratado && isset($datas['contratado']) ? $datas['contratado'] : $agora;

        return self::diasEntre($c->getEnviadaEm(), $fim);
    }

    private static function diasEntre(\DateTimeImmutable $de, \DateTimeImmutable $ate): int
    {
        return max(0, (int) round(($ate->getTimestamp() - $de->getTimestamp()) / self::DIA));
    }

    private static function emDias(int $n): string
    {
        return $n . ($n === 1 ? ' dia' : ' dias');
    }
}
