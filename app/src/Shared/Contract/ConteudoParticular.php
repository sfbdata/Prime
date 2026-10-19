<?php

declare(strict_types=1);

namespace App\Shared\Contract;

/**
 * Entidade cujo conteúdo pode ser particular (só quem criou vê), como o compromisso "Só eu" da Agenda.
 * A auditoria grava a ação, quem e quando, mas não o conteúdo particular: quem administra o escritório lê
 * a auditoria, e "Só eu" promete que só a pessoa vê.
 */
interface ConteudoParticular
{
    /**
     * Particular agora, ou antes desta mudança (o changeset do Doctrine: campo => [antes, depois]).
     *
     * @param array<string, array{0: mixed, 1: mixed}> $mudancas
     */
    public function ehParticular(array $mudancas = []): bool;

    /** @return list<string> campos que não vão para a auditoria enquanto for particular */
    public function camposParticulares(): array;
}
