<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AbasDaPastaOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\AbaOcultaRecusadaException;
use App\Pasta\Repository\AbaOcultaDaPastaRepository;
use App\Tenant\Service\RegraDosPrimarios;

/**
 * A regra única de "esta pessoa vê esta aba desta pasta?" (spec pasta-ocultar-aba §1, D2).
 *
 *  - Quem configura é o Master ou um primário ativo do escritório (`RegraDosPrimarios::podeGerirPrimarios`,
 *    o mesmo critério: Master = ROLE_SUPER_ADMIN, primário = vínculo ativo com o perfil de sistema).
 *  - Quem configura NUNCA é ocultado (desenho L.4377).
 *  - Para os demais: sem regra = vê; com regra, `paraTodos` ou o id na lista = não vê.
 *
 * Usada pela tela (abas e painéis não renderizados), pela barreira das rotas
 * (`BarreiraDeAbaOcultaListener`) e pela visibilidade do financeiro (`VisibilidadeDoFinanceiroDaPasta`).
 */
final class VisibilidadeDasAbasDaPasta
{
    public function __construct(
        private readonly AbaOcultaDaPastaRepository $regras,
        private readonly RegraDosPrimarios $primarios,
    ) {
    }

    public function podeConfigurar(User $user, Tenant $tenant): bool
    {
        return $this->primarios->podeGerirPrimarios($user, $tenant);
    }

    /**
     * A guarda das escritas (spec §2): a pasta é do escritório da sessão e quem pede configura.
     *
     * @throws AbaOcultaRecusadaException
     */
    public function garantirQuePodeConfigurar(Pasta $pasta, User $quem, Tenant $tenant): void
    {
        if ($pasta->getTenant() !== $tenant) {
            throw AbaOcultaRecusadaException::pastaDeOutroEscritorio();
        }
        if (!$this->podeConfigurar($quem, $tenant)) {
            throw AbaOcultaRecusadaException::soPrimario();
        }
    }

    public function ocultaPara(Pasta $pasta, AbaDaPasta $aba, User $user): bool
    {
        $tenant = $pasta->getTenant();
        if ($tenant === null) {
            return false;
        }

        $regra = $this->regras->daAba($pasta, $aba, $tenant);
        if ($regra === null || !$regra->alcanca((int) $user->getId())) {
            return false;
        }

        // Só consulta o vínculo quando a regra alcançaria a pessoa: o caso comum (sem regra) é uma consulta.
        return !$this->podeConfigurar($user, $tenant);
    }

    /**
     * As abas desta pasta que a pessoa não vê, com uma consulta às regras (e, se houver regra que a
     * alcance, uma ao vínculo).
     *
     * @return list<AbaDaPasta>
     */
    public function abasOcultasPara(Pasta $pasta, User $user): array
    {
        $tenant = $pasta->getTenant();
        if ($tenant === null) {
            return [];
        }

        return $this->ocultasEntre($this->regras->daPasta($pasta, $tenant), $user, $tenant);
    }

    /** O estado das abas para a tela da pasta: o que esconder e, para quem configura, as regras. */
    public function estadoParaTela(Pasta $pasta, User $user): AbasDaPastaOutput
    {
        $tenant = $pasta->getTenant();
        if ($tenant === null) {
            return AbasDaPastaOutput::todasVisiveis();
        }

        $regras = $this->regras->daPasta($pasta, $tenant);
        $configura = $this->podeConfigurar($user, $tenant);

        return AbasDaPastaOutput::montar($regras, $configura ? [] : $this->ocultasEntre($regras, $user, $tenant), $configura);
    }

    /**
     * @param list<AbaOcultaDaPasta> $regras
     *
     * @return list<AbaDaPasta>
     */
    private function ocultasEntre(array $regras, User $user, Tenant $tenant): array
    {
        $userId = (int) $user->getId();
        $alcancam = array_values(array_filter($regras, static fn (AbaOcultaDaPasta $r): bool => $r->alcanca($userId)));
        if ($alcancam === [] || $this->podeConfigurar($user, $tenant)) {
            return [];
        }

        return array_map(static fn (AbaOcultaDaPasta $r): AbaDaPasta => $r->getAba(), $alcancam);
    }
}
