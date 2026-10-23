<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * Os dados que o filtro da campanha lê de cada pessoa (desenho 05 VS 21.10.26, `At` L631; decisões do coordenador
 * em comercial-e-emails.md §9). Nulo = "sem essa informação no cadastro". Quem não tem conta (lista importada,
 * Individual ou Base de contatos sem cadastro) vem sem nada: não tem papel, OAB nem plano.
 */
final class AtributosDoDestinatario
{
    /** @param list<string> $planos os códigos de plano dos escritórios válidos da pessoa */
    public function __construct(
        public readonly ?string $genero = null,
        public readonly ?\DateTimeImmutable $nascimento = null,
        public readonly ?string $uf = null,
        public readonly ?string $papel = null,
        public readonly ?string $cargo = null,
        public readonly ?string $oab = null,
        public readonly array $planos = [],
    ) {
    }
}
