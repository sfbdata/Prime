<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;

/**
 * A porta única de "demanda por código". Escolhe a consulta escopada pelo perfil (Central ou
 * escritório) e, por cima, confere a política. Qualquer falha é a mesma {@see DemandaNaoEncontrada}.
 */
final class LocalizadorDeDemanda
{
    public function __construct(
        private readonly DemandaExternaRepository $repositorio,
        private readonly PoliticaDaDemanda $politica,
    ) {
    }

    public function localizar(Contexto $ctx, string $codigo): DemandaExterna
    {
        $id = DemandaExterna::idDoCodigo($codigo);
        if ($id === null) {
            throw new DemandaNaoEncontrada();
        }

        $demanda = match (true) {
            $ctx->ehInterno() => $this->repositorio->daCentralPorId($id),
            $ctx->tenant !== null => $this->repositorio->doEscritorioPorId($id, $ctx->tenant),
            default => null,
        };

        if ($demanda === null || !$this->politica->podeVer($ctx, $demanda)) {
            throw new DemandaNaoEncontrada();
        }

        return $demanda;
    }

    /**
     * As demandas que o perfil enxerga, para o painel.
     *
     * @return list<DemandaExterna>
     */
    public function visiveis(Contexto $ctx): array
    {
        if ($this->politica->abaBloqueada($ctx)) {
            return [];
        }

        $demandas = match (true) {
            $ctx->ehInterno() => $this->repositorio->daCentral(),
            $ctx->tenant !== null => $this->repositorio->doEscritorio($ctx->tenant),
            default => [],
        };

        return array_values(array_filter($demandas, fn (DemandaExterna $d): bool => $this->politica->podeVer($ctx, $d)));
    }
}
