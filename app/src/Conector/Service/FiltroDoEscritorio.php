<?php

declare(strict_types=1);

namespace App\Conector\Service;

/**
 * O filtro `tenant` do Doctrine, visto pelo PortaoDoConector (spec mcp-remoto-oauth 4.1): ligar com o escritório do
 * token e conferir que ficou ligado. Interface própria para o teste simular a falha (ligar que lança, ligar que não
 * pega) sem dublar o EntityManager (regra de app/tests/CLAUDE.md).
 *
 * Só o PortaoDoConector usa (`PortaoDoConectorArquiteturaTest`).
 */
interface FiltroDoEscritorio
{
    /** Liga o filtro `tenant` com o escritório. Pode lançar; quem chama confere depois com {@see estaLigadoEm()}. */
    public function ligar(int $tenantId): void;

    /** O filtro está ligado E o parâmetro `tenant` é exatamente este escritório. */
    public function estaLigadoEm(int $tenantId): bool;
}
