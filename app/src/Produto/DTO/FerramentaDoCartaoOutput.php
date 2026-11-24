<?php

declare(strict_types=1);

namespace App\Produto\DTO;

/**
 * Uma linha do cartão "Ferramentas do escritório" no Meu Perfil (spec docs/specs/multiproduto-contratacao-e-voto.md,
 * §9). O estado decide o selo e o botão: ligada (Desligar), desligada (Ligar), travada pelo Master e indisponível
 * (chave global desligada) ficam sem botão.
 */
final class FerramentaDoCartaoOutput
{
    public const LIGADA = 'ligada';
    public const DESLIGADA = 'desligada';
    public const TRAVADA = 'travada';
    public const INDISPONIVEL = 'indisponivel';

    public function __construct(
        public readonly string $produto,
        public readonly string $icone,
        public readonly string $estado,
        /** I.A. ligada sem o aceite do admin: o cartão mostra o link para a página do aceite (regra 4). */
        public readonly bool $precisaAceite = false,
    ) {
    }

    public function temBotao(): bool
    {
        return self::LIGADA === $this->estado || self::DESLIGADA === $this->estado;
    }
}
