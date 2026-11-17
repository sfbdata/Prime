<?php

declare(strict_types=1);

namespace App\Voto\Enum;

/**
 * O papel de uma pessoa na equipe própria de uma assembleia (desenho 18, `ROT` e a lista "Permissões" de Perfis).
 * Fora daqui, de propósito: a Administração (Master e primário, que nunca ficam na tabela), o Guardião e a Validação
 * (dependem de C1 e C4, fora da W2-7).
 *
 * O papel é o TETO da pessoa naquela assembleia: a Comissão Eleitoral edita; a Auditoria, o Fiscal e o Conselho
 * Eleitoral só leem.
 */
enum PapelNaEquipe: string
{
    case Comissao = 'comissao';
    case Auditoria = 'auditoria';
    case Fiscal = 'fiscal';
    case Conselho = 'conselho';

    public function rotulo(): string
    {
        return match ($this) {
            self::Comissao => 'Comissão Eleitoral',
            self::Auditoria => 'Auditoria',
            self::Fiscal => 'Fiscal',
            self::Conselho => 'Conselho Eleitoral',
        };
    }

    public function nivel(): NivelNaAssembleia
    {
        return match ($this) {
            self::Comissao => NivelNaAssembleia::Editar,
            self::Auditoria, self::Fiscal, self::Conselho => NivelNaAssembleia::Visualizar,
        };
    }
}
