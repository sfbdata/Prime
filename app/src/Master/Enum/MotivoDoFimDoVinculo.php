<?php

declare(strict_types=1);

namespace App\Master\Enum;

/** Por que um vínculo de convênio foi fechado (spec docs/specs/convenios.md, §3.2 e §6). */
enum MotivoDoFimDoVinculo: string
{
    case ConvenioDesligado = 'convenio_desligado';
    case UfDesligada = 'uf_desligada';
    case Instituicao = 'instituicao';
    case ProvaDivergente = 'prova_divergente';
    case EstadoMudou = 'estado_mudou';
    case Validade = 'validade';
    case Master = 'master';
    case Saiu = 'saiu';
}
