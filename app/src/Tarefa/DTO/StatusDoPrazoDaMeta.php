<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Entity\Tarefa\Tarefa;
use App\Tarefa\Service\AgrupadorDeMetas;

/**
 * Pílula de status de uma meta em Minhas Metas (desenho de 13/10, `04 - Minhas Metas 1.0.dc.html`):
 * Atrasada · Vence em breve · No prazo · Sem prazo · Em revisão · Concluída.
 *
 * Não tem regra própria: traduz o balde de `AgrupadorDeMetas::classificar`, a mesma regra que escolhe
 * o grupo da lista. Assim a pílula é igual nos dois modos (cartões e lista) e nunca contradiz o grupo
 * em que a meta aparece — inclusive a meta que vence hoje, que cai em "Vence em breve".
 *
 * O `tom` é o sufixo das classes `.mm-pilula.tom-*` do minhas-metas.css.
 */
final class StatusDoPrazoDaMeta
{
    private const PILULAS = [
        'atrasadas'  => ['Atrasada', 'danger'],
        'proximas'   => ['Vence em breve', 'warn'],
        'depois'     => ['No prazo', 'ok'],
        'sem_prazo'  => ['Sem prazo', 'cinza'],
        'em_revisao' => ['Em revisão', 'accent'],
        'concluidas' => ['Concluída', 'ok'],
    ];

    private function __construct(
        public readonly string $rotulo,
        public readonly string $tom,
    ) {
    }

    public static function de(Tarefa $meta, ?\DateTimeImmutable $hoje = null): self
    {
        [$rotulo, $tom] = self::PILULAS[AgrupadorDeMetas::classificar($meta, $hoje)];

        return new self($rotulo, $tom);
    }
}
