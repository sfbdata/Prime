<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Tenant\Service\SituacaoDaConta;
use App\TrabalhoExterno\Entity\ConviteDemanda;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;

/**
 * A porta única de "demanda por código". Escolhe a consulta escopada pelo perfil (Central ou
 * escritório) e, por cima, confere a política. Qualquer falha é a mesma {@see DemandaNaoEncontrada}.
 *
 * O perfil Convidado é POR DEMANDA (spec §8): se a demanda não está no escopo do perfil de quem
 * pede, mas ele tem convite vigente para ela, o contexto devolvido é o de Convidado daquela demanda.
 * Por isso os dois métodos devolvem a demanda junto com o contexto que vale para ela. Se o escritório dono
 * está suspenso, o contexto de Convidado vem marcado como só leitura (`Contexto::donoForaDoAr`); se foi excluído,
 * o convite não conta e a demanda some para o Convidado (decisão do dono, 22/10).
 */
final class LocalizadorDeDemanda
{
    public function __construct(
        private readonly DemandaExternaRepository $repositorio,
        private readonly ConviteDemandaRepository $convites,
        private readonly PoliticaDaDemanda $politica,
        private readonly SituacaoDaConta $situacao,
    ) {
    }

    /** @return array{0: DemandaExterna, 1: Contexto} */
    public function localizar(Contexto $ctx, string $codigo): array
    {
        $id = DemandaExterna::idDoCodigo($codigo);
        if ($id === null) {
            throw new DemandaNaoEncontrada();
        }

        $demanda = match (true) {
            $ctx->ehInterno() => $this->repositorio->daCentralPorId($id),
            $ctx->tenant !== null && $ctx->perfil !== Perfil::Convidado => $this->repositorio->doEscritorioPorId($id, $ctx->tenant),
            default => null,
        };
        if ($demanda !== null && $this->politica->podeVer($ctx, $demanda)) {
            return [$demanda, $ctx];
        }

        foreach ($this->convitesVigentes($ctx) as $convite) {
            $comoConvidado = $this->comoConvidado($ctx, $convite);
            if ($convite->getDemanda()->getId() === $id && $this->politica->podeVer($comoConvidado, $convite->getDemanda())) {
                return [$convite->getDemanda(), $comoConvidado];
            }
        }

        throw new DemandaNaoEncontrada();
    }

    /**
     * As demandas que o usuário enxerga no painel, cada uma com o contexto que vale para ela: as do
     * perfil (Central ou escritório) e, depois, as dos convites vigentes.
     *
     * @return list<array{0: DemandaExterna, 1: Contexto}>
     */
    public function visiveis(Contexto $ctx): array
    {
        $pares = [];
        $vistas = [];

        if ($ctx->perfil !== Perfil::Convidado && !$this->politica->abaBloqueada($ctx)) {
            $demandas = match (true) {
                $ctx->ehInterno() => $this->repositorio->daCentral(),
                $ctx->tenant !== null => $this->repositorio->doEscritorio($ctx->tenant),
                default => [],
            };
            foreach ($demandas as $d) {
                if ($this->politica->podeVer($ctx, $d)) {
                    $pares[] = [$d, $ctx];
                    $vistas[(int) $d->getId()] = true;
                }
            }
        }

        foreach ($this->convitesVigentes($ctx) as $convite) {
            $d = $convite->getDemanda();
            $comoConvidado = $this->comoConvidado($ctx, $convite);
            if (!isset($vistas[(int) $d->getId()]) && $this->politica->podeVer($comoConvidado, $d)) {
                $pares[] = [$d, $comoConvidado];
                $vistas[(int) $d->getId()] = true;
            }
        }

        return $pares;
    }

    /** O Convidado só lê a demanda de escritório fora do ar (docs/specs/te-convidado-escritorio-fora-do-ar.md). */
    private function comoConvidado(Contexto $ctx, ConviteDemanda $convite): Contexto
    {
        return $ctx->comoConvidado($convite, $this->situacao->foraDoAr($convite->getDemanda()->getTenant()));
    }

    /**
     * Os convites que dão acesso hoje. O de demanda de escritório excluído não dá nem leitura
     * (docs/specs/te-convidado-escritorio-fora-do-ar.md, pergunta (ii) respondida pelo dono em 22/10).
     *
     * @return list<ConviteDemanda>
     */
    private function convitesVigentes(Contexto $ctx): array
    {
        if ($ctx->ehInterno()) {
            return [];
        }

        return array_values(array_filter(
            $this->convites->vigentesDo($ctx->usuario, new \DateTimeImmutable('today')),
            fn (ConviteDemanda $c): bool => !$this->situacao->excluida($c->getDemanda()->getTenant()),
        ));
    }
}
