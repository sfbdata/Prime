<?php

declare(strict_types=1);

namespace App\Shared\Attribute;

/**
 * Declara, na própria entidade, que ela NÃO é filtrada por tenant e por quê.
 *
 * Substitui a lista central `FORA_DO_ESCOPO` do `TenantAwareCoberturaTest` para entidade NOVA: a lista
 * e o seu teto (um número fixo) davam conflito em quase todo merge. Aqui a declaração mora no arquivo
 * da entidade, que só o PR dela toca, e o teto some. A justificativa continua obrigatória (o teste
 * recusa motivo vazio ou curto) e fica no diff da entidade, onde a revisão a vê.
 *
 * Exceção permanente ao isolamento exige motivo escrito: nunca é conserto de suíte vermelha.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SemIsolamentoDeTenant
{
    public function __construct(
        public readonly string $motivo,
    ) {
    }
}
