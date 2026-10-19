<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AbaOcultaResultadoOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\AbaOcultaRecusadaException;
use App\Pasta\Repository\AbaOcultaDaPastaRepository;
use App\Pasta\Service\EquipeQuePodeTerAbaOculta;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use Psr\Clock\ClockInterface;

/**
 * "Salvar" do modal "Ocultar aba" (spec pasta-ocultar-aba §2.2, desenho `abaVisVals.salvar` L.4426).
 *
 * Os ids chegam da tela e não são confiáveis: só valem os de quem pode ter a aba oculta neste
 * escritório (`EquipeQuePodeTerAbaOculta`); id de outro escritório, de vínculo inativo, de primário
 * ou do Master é ignorado em silêncio. Depois do filtro: nenhum → a regra é apagada; todos os
 * elegíveis → "toda a equipe"; alguns → a lista.
 */
final class DefinirQuemNaoVeAbaUseCase
{
    public function __construct(
        private readonly AbaOcultaDaPastaRepository $regras,
        private readonly VisibilidadeDasAbasDaPasta $visibilidade,
        private readonly EquipeQuePodeTerAbaOculta $equipe,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * @param list<int> $userIds
     *
     * @throws AbaOcultaRecusadaException
     */
    public function executar(Pasta $pasta, AbaDaPasta $aba, User $quem, Tenant $tenant, array $userIds): AbaOcultaResultadoOutput
    {
        $this->visibilidade->garantirQuePodeConfigurar($pasta, $quem, $tenant);

        $elegiveis = $this->equipe->idsDoEscritorio($tenant);
        $escolhidos = array_values(array_intersect($elegiveis, array_map('intval', $userIds)));

        // Com a pasta travada: a primeira regra gravada ao mesmo tempo por duas pessoas não vira 500.
        return $this->regras->comTravaDaPasta($pasta, function () use ($pasta, $aba, $quem, $tenant, $escolhidos, $elegiveis): AbaOcultaResultadoOutput {
            $regra = $this->regras->daAba($pasta, $aba, $tenant);
            if ($escolhidos === []) {
                return $this->apagar($regra, $aba);
            }

            $paraTodos = \count($escolhidos) === \count($elegiveis);
            $this->regras->salvar($this->aplicar($regra, $pasta, $aba, $quem, $tenant, $escolhidos, $paraTodos), flush: true);

            return AbaOcultaResultadoOutput::ocultaPara($aba, \count($escolhidos), $paraTodos);
        });
    }

    private function apagar(?AbaOcultaDaPasta $regra, AbaDaPasta $aba): AbaOcultaResultadoOutput
    {
        if ($regra !== null) {
            $this->regras->remover($regra, flush: true);
        }

        return AbaOcultaResultadoOutput::visivelParaTodos($aba);
    }

    /** @param list<int> $escolhidos */
    private function aplicar(?AbaOcultaDaPasta $regra, Pasta $pasta, AbaDaPasta $aba, User $quem, Tenant $tenant, array $escolhidos, bool $paraTodos): AbaOcultaDaPasta
    {
        $agora = $this->relogio->now();
        if ($regra === null) {
            return $paraTodos
                ? AbaOcultaDaPasta::paraTodaAEquipe($tenant, $pasta, $aba, $quem, $agora)
                : AbaOcultaDaPasta::paraAlguns($tenant, $pasta, $aba, $escolhidos, $quem, $agora);
        }

        if ($paraTodos) {
            $regra->ocultarParaTodos($quem, $agora);

            return $regra;
        }
        $regra->ocultarPara($escolhidos, $quem, $agora);

        return $regra;
    }
}
