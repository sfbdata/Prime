<?php

declare(strict_types=1);

namespace App\Master\Enum;

/**
 * Os três tipos de divulgação do desenho 05 (Perfis 1.2, VS 21.10.26, nota do bloco "Seccionais visíveis"):
 * "material enviado, reunião, parceria". A cor é a do ponto do chip (spec master-seccionais-oab, DONO: as
 * cores do trecho truncado do desenho).
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

    /**
     * Chave do rótulo no catálogo (spec idioma-do-sistema, P2b): quem leva o tipo à tela traduz por ela; o rótulo()
     * acima é o português (o CatalogoDasRecusasDoMasterTest confere que são iguais).
     */
    public function chave(): string
    {
        return 'master.seccionais.tipo.' . $this->value;
    }
}
