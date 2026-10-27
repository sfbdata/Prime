<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Inteligencia\Juridico\CatalogoDeQuestoesJuridicas;
use App\Inteligencia\Repository\EstadoDaQuestaoJuridicaRepository;

/**
 * Quantas questões do catálogo ainda não estão resolvidas (pendentes ou em análise; sem linha no banco = pendente).
 * Alimenta o aviso "Questões jurídicas a serem verificadas" do topo, que só o Master vê. Uma consulta curta.
 */
final class ContarQuestoesJuridicasEmAbertoUseCase
{
    public function __construct(
        private readonly CatalogoDeQuestoesJuridicas $catalogo,
        private readonly EstadoDaQuestaoJuridicaRepository $estados,
    ) {
    }

    public function executar(): int
    {
        return count($this->chaves());
    }

    /**
     * As chaves em aberto, na ordem do catálogo. O aviso do topo as leva ao navegador, que guarda as que o Master já
     * viu e só mostra o aviso de novo quando surge uma chave nova.
     *
     * @return list<string>
     */
    public function chaves(): array
    {
        return array_values(array_diff($this->catalogo->chaves(), $this->estados->chavesResolvidas()));
    }
}
