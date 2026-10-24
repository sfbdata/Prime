<?php

declare(strict_types=1);

namespace App\Central\DTO;

/** Um aviso do sino na Caixa da Central (F1), já no vocabulário do desenho (grupo, rótulo, ícone, cor, nível). */
final class AvisoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $tipo,
        public readonly ?string $grupo,
        public readonly string $rotulo,
        public readonly string $icone,
        public readonly string $cor,
        public readonly int $nivel,
        public readonly string $titulo,
        public readonly ?string $texto,
        public readonly ?string $url,
        public readonly bool $lido,
        public readonly string $criadoEm,
        /** F3: quando a pessoa deu baixa (null = em aberto). */
        public readonly ?string $resolvidoEm = null,
        /** Rótulo da ação de abrir o aviso (ex.: "Ver sugestão" no Contribua); null = o "Abrir" genérico. */
        public readonly ?string $acao = null,
    ) {
    }
}
