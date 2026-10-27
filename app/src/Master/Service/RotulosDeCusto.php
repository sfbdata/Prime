<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Master\Enum\CategoriaDeCusto;
use App\Master\Enum\OrigemDoCusto;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Rótulos pt_BR de categoria e origem, do mesmo catálogo da tela (master.custos.js.*). */
final class RotulosDeCusto
{
    /** @return array<string, string> código => rótulo */
    public static function categorias(TranslatorInterface $tradutor): array
    {
        $rotulos = [];
        foreach (CategoriaDeCusto::cases() as $c) {
            $rotulos[$c->value] = $tradutor->trans('master.custos.js.categoria.' . $c->value);
        }

        return $rotulos;
    }

    /** @return array<string, string> código => rótulo */
    public static function origens(TranslatorInterface $tradutor): array
    {
        $rotulos = [];
        foreach (OrigemDoCusto::cases() as $o) {
            $rotulos[$o->value] = $tradutor->trans('master.custos.js.origem.' . $o->value);
        }

        return $rotulos;
    }
}
