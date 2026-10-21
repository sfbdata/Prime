<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

/**
 * O nível de uma pessoa numa pasta com cadeado (spec pasta-cadeado K2): `Oculto` vê só o cabeçalho
 * (cliente e CPF) e a tela "Pasta trancada"; `Visualizar` lê tudo e não altera nada; `Editar` trabalha
 * normalmente. Sem cadeado, todo mundo do escritório fica em `Editar` (as outras camadas decidem o resto).
 */
enum NivelDeAcesso: string
{
    case Oculto     = 'oculto';
    case Visualizar = 'visualizar';
    case Editar     = 'editar';

    public function podeVer(): bool
    {
        return $this !== self::Oculto;
    }

    public function podeEditar(): bool
    {
        return $this === self::Editar;
    }

    /** Rótulo da grade do modal (desenho L523): Ocultar, Visualizar, Editar. */
    public function rotulo(): string
    {
        return match ($this) {
            self::Oculto     => 'Ocultar',
            self::Visualizar => 'Visualizar',
            self::Editar     => 'Editar',
        };
    }

    /** O verbo das mensagens do pedido ("para visualizar", "para editar"). */
    public function verbo(): string
    {
        return match ($this) {
            self::Oculto     => 'ocultar',
            self::Visualizar => 'visualizar',
            self::Editar     => 'editar',
        };
    }
}
