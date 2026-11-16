<?php

declare(strict_types=1);

namespace App\Voto\Enum;

/**
 * O nível de uma pessoa numa assembleia pelo teto da equipe (spec docs/specs/voto-equipe-e-master-1611.md, seção 1.2),
 * no molde do `NivelDeAcesso` do cadeado da pasta. É TETO, não permissão: `Editar` aqui não dá acesso que a guarda do
 * módulo `voto` ({@see \App\Voto\Service\GuardaDaEquipeDoVoto}) recuse.
 */
enum NivelNaAssembleia: string
{
    case Oculto = 'oculto';
    case Visualizar = 'visualizar';
    case Editar = 'editar';
}
