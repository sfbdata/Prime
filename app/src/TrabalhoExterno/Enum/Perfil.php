<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/**
 * Papel de quem acessa o Trabalho Externo. É resolvido no servidor a partir do usuário logado
 * (ver `ResolvedorDePerfil`), nunca escolhido pelo navegador.
 */
enum Perfil: string
{
    case Master = 'master';
    case Equipe = 'equipe';
    case Solicitante = 'solicitante';
    case Convidado = 'convidado';

    /** Master e Equipe formam a Central; Solicitante e Convidado são externos a ela. */
    public function ehInterno(): bool
    {
        return $this === self::Master || $this === self::Equipe;
    }

    public function rotulo(): string
    {
        return match ($this) {
            self::Master => 'Master',
            self::Equipe => 'Equipe',
            self::Solicitante => 'Solicitante',
            self::Convidado => 'Convidado',
        };
    }

    /** Texto ao lado do nome no cabeçalho do desenho (`PERFIS[p][3]`). */
    public function papel(): string
    {
        return match ($this) {
            self::Master => 'Master BlueJus',
            self::Equipe => 'Secundário do Master',
            self::Solicitante => 'Solicitante',
            self::Convidado => 'Correspondente convidado',
        };
    }
}
