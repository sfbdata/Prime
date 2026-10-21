<?php

declare(strict_types=1);

namespace App\Master\Enum;

/**
 * Os três tipos de divulgação do desenho 05 (Perfis 1.2, VS 21.10.26, nota do bloco "Seccionais visíveis"):
 * "material enviado, reunião, parceria". A cor é a do ponto do chip (spec master-seccionais-oab, DONO: as
 * cores do trecho truncado do desenho).
 *
 * OBSOLETO desde a Version20261020149500 (desenho 05 inteiro): a divulgação virou a marca da UF na própria
 * SeccionalOab (status, cor e data). Nada mais lê nem grava aqui; a tabela `master_divulgacao_seccional` fica no
 * banco até a limpeza combinada com o dono (o DROP vai nessa limpeza, não agora).
 */
enum TipoDeDivulgacao: string
{
    case Material = 'material';
    case Reuniao  = 'reuniao';
    case Parceria = 'parceria';

    public function rotulo(): string
    {
        return match ($this) {
            self::Material => 'Material enviado',
            self::Reuniao  => 'Reunião',
            self::Parceria => 'Parceria',
        };
    }
}
