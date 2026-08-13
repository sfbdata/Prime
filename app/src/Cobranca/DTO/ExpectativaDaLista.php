<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * O que o dono APROVOU antes da escrita — a trava que impede a reconciliação de rodar sobre uma lista
 * diferente da que ele viu (SPEC `cobranca-espelho-da-contabilidade.md` §17.11).
 *
 * 🔑 **Existe porque o universo é re-derivado no momento da escrita.** A reconciliação chama a régua de
 * novo, e a régua lê o ÚLTIMO lote de cada carteira: se um lote entrar entre a aprovação e o
 * `--aplicar`, o conjunto muda e o comando escreveria sobre uma lista que ninguém viu — sem aviso.
 * Com esta expectativa ele **aborta**; sem ela, adivinharia.
 *
 * Os dois números são os que a régua reporta (contagem de dívidas e total duplicado), **não** o efeito
 * real da escrita: no honorário os dois divergem de propósito (INV-CE9, a coluna L da linha `1.15`
 * volta para o campo). A trava confere o que foi aprovado, e o que foi aprovado é a lista da régua.
 */
final readonly class ExpectativaDaLista
{
    public function __construct(
        public int $dividas,
        public int $totalDuplicadoEmCentavos,
    ) {
    }

    public function confere(int $dividas, int $totalDuplicadoEmCentavos): bool
    {
        return $this->dividas === $dividas
            && $this->totalDuplicadoEmCentavos === $totalDuplicadoEmCentavos;
    }
}
