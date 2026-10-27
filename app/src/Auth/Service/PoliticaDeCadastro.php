<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\Exception\CadastroNaoDisponivel;
use App\Shared\Contract\ConfiguracaoDaPlataforma;

/**
 * A política de cadastro da plataforma (configuração `cadastro` do Master; spec `login-config-cadastro.md`):
 * `on` aberto, `convite` só por convite, `off` fechado. Valor fora dos três cai em `on` (como o padrão do catálogo).
 *
 * A política nunca bloqueia login nem o convite da plataforma (emitido pelo Master).
 */
final class PoliticaDeCadastro
{
    public const ABERTO = 'on';
    public const SO_POR_CONVITE = 'convite';
    public const FECHADO = 'off';

    public function __construct(private readonly ConfiguracaoDaPlataforma $configuracao)
    {
    }

    public function atual(): string
    {
        $valor = $this->configuracao->valor('cadastro');

        return in_array($valor, [self::SO_POR_CONVITE, self::FECHADO], true) ? $valor : self::ABERTO;
    }

    public function publicoAberto(): bool
    {
        return $this->atual() === self::ABERTO;
    }

    /** As portas públicas (e-mail, Google, Facebook): só com o cadastro aberto. */
    public function exigirPublicoAberto(): void
    {
        $atual = $this->atual();
        if ($atual !== self::ABERTO) {
            throw new CadastroNaoDisponivel($atual);
        }
    }

    /** Convite que cria conta nova (escritório, Trabalho Externo): só o cadastro fechado recusa. */
    public function exigirConviteDeContaNova(): void
    {
        if ($this->atual() === self::FECHADO) {
            throw new CadastroNaoDisponivel(self::FECHADO);
        }
    }

    public static function chaveDoAviso(string $politica): string
    {
        return 'entrada.cadastro.aviso.' . ($politica === self::FECHADO ? self::FECHADO : self::SO_POR_CONVITE);
    }
}
