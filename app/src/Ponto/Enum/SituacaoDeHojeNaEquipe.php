<?php

declare(strict_types=1);

namespace App\Ponto\Enum;

use App\Ponto\Service\BatidasEscolhidas;

/**
 * A situação de hoje de cada pessoa na aba "Equipe" da /ponto (spec `docs/specs/ponto-desenho-0811.md`, E5, P54;
 * desenho 16, `STQ` e `meuSt` L777). A regra é a do desenho, sobre as batidas que a folha escolhe para o dia: sem
 * entrada, "Sem registro"; com saída, "Encerrada"; com repouso e sem retorno, "Em intervalo"; senão, "Em jornada".
 *
 * O valor é o sufixo da classe do selo na tela (`pe-eq-selo--<valor>`), que leva a cor do desenho.
 */
enum SituacaoDeHojeNaEquipe: string
{
    case EmJornada = 'jornada';
    case EmIntervalo = 'intervalo';
    case SemRegistro = 'sem';
    case Encerrada = 'encerrada';

    public static function daEscolha(BatidasEscolhidas $escolha): self
    {
        if ($escolha->entrada === null) {
            return self::SemRegistro;
        }
        if ($escolha->saida !== null) {
            return self::Encerrada;
        }
        if ($escolha->repouso !== null && $escolha->retorno === null) {
            return self::EmIntervalo;
        }

        return self::EmJornada;
    }

    public function rotulo(): string
    {
        return match ($this) {
            self::EmJornada => 'Em jornada',
            self::EmIntervalo => 'Em intervalo',
            self::SemRegistro => 'Sem registro',
            self::Encerrada => 'Encerrada',
        };
    }
}
