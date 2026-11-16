<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * O painel da Gestão eleitoral depois de agrupar e filtrar: os grupos, as opções do filtro "Entidade" (só aparece com
 * mais de uma) e se havia assembleia antes dos filtros (decide qual vazio do desenho mostrar).
 */
final readonly class PainelAgrupado
{
    /**
     * @param list<GrupoDaEntidade>                          $grupos
     * @param list<array{chave: string, nome: string, cnpj: string}> $entidades todas as entidades, antes dos filtros
     */
    public function __construct(
        public array $grupos,
        public array $entidades,
        public bool $haAssembleias,
    ) {
    }

    public function vazio(): bool
    {
        return [] === $this->grupos;
    }

    /** O filtro "Entidade" só existe com mais de uma entidade (desenho: gfEntTem). */
    public function temFiltroDeEntidade(): bool
    {
        return \count($this->entidades) > 1;
    }
}
