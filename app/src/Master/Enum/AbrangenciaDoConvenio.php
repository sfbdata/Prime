<?php

declare(strict_types=1);

namespace App\Master\Enum;

/** Onde o convênio vale (spec docs/specs/convenios.md, §3.1). */
enum AbrangenciaDoConvenio: string
{
    /** Todo o país. */
    case Nacional = 'nacional';
    /** Só os escritórios de uma UF (a coluna `uf` do convênio). */
    case Uf = 'uf';
    /** Só quem prova ser membro da instituição. */
    case Instituicao = 'instituicao';
}
