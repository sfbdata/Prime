<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\TipoDeDemanda;
use App\Tarefa\Exception\PassagemRecusadaException;

/**
 * Para onde a pasta vai na passagem de etapa (desenhos 04 L174 e 04B L169; spec `minhas-metas-esteira-2210.md`,
 * L13 e L14). Tudo pela posição da etapa no catálogo, para valer também quando a etapa atual não está na sequência
 * do tipo escolhido (consultoria em Documentação): a próxima é a primeira da sequência depois da atual; uma etapa
 * adiante dela salta as do meio; uma anterior à atual é volta.
 */
final class RotaDaPassagem
{
    /** A próxima etapa da sequência depois da atual; `null` na última (a pasta encerra). */
    public static function proximaPadrao(TipoDeDemanda $tipo, EtapaDaPasta $atual): ?EtapaDaPasta
    {
        foreach ($tipo->sequencia() as $etapa) {
            if ($etapa->posicao() > $atual->posicao()) {
                return $etapa;
            }
        }

        return null;
    }

    /** O destino escolhido no menu: tem de estar na sequência do tipo e não ser a etapa atual. */
    public static function destinoEscolhido(TipoDeDemanda $tipo, EtapaDaPasta $atual, string $valor): EtapaDaPasta
    {
        $destino = EtapaDaPasta::tryFrom($valor);
        if ($destino === null || $destino === $atual || $tipo->posicaoDe($destino) === null) {
            throw PassagemRecusadaException::etapaInvalida();
        }

        return $destino;
    }

    public static function ehVolta(EtapaDaPasta $atual, EtapaDaPasta $destino): bool
    {
        return $destino->posicao() < $atual->posicao();
    }

    /**
     * As etapas saltadas que a meta nova leva: as herdadas que ficam antes do destino (numa volta, as que ficaram
     * depois deixam de ser saltadas, porque a pasta vai passar por elas de novo) e as da sequência entre a atual e
     * o destino, quando ele está adiante da próxima.
     *
     * @param list<EtapaDaPasta> $herdadas
     *
     * @return list<EtapaDaPasta>
     */
    public static function saltadas(array $herdadas, TipoDeDemanda $tipo, EtapaDaPasta $atual, EtapaDaPasta $destino): array
    {
        $saltadas = [];
        foreach (self::herdadasQueFicam($herdadas, $destino) as $etapa) {
            $saltadas[$etapa->value] = $etapa;
        }
        foreach ($tipo->sequencia() as $etapa) {
            if ($etapa->posicao() > $atual->posicao() && $etapa->posicao() < $destino->posicao()) {
                $saltadas[$etapa->value] = $etapa;
            }
        }
        $lista = array_values($saltadas);
        usort($lista, static fn (EtapaDaPasta $a, EtapaDaPasta $b): int => $a->posicao() <=> $b->posicao());

        return $lista;
    }

    /**
     * As saltadas que a meta nova herda da atual. Regra do desenho (04B L175, `pul: (m.pul || []).concat(pulL)`):
     * herda todas, mesmo numa volta, sem tirar as que ficam depois do destino. Está aqui sozinha porque o dono
     * ainda pode decidir outra coisa (por exemplo, a volta limpar as saltadas adiante do destino); a troca é só aqui.
     *
     * @param iterable<EtapaDaPasta> $herdadas
     *
     * @return list<EtapaDaPasta>
     */
    public static function herdadasQueFicam(iterable $herdadas, EtapaDaPasta $destino): array
    {
        $ficam = [];
        foreach ($herdadas as $etapa) {
            $ficam[] = $etapa;
        }

        return $ficam;
    }
}
