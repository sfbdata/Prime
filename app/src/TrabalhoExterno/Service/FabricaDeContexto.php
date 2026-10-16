<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Entity\Auth\User;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Repository\ConfiguracaoCentralRepository;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;

/** Monta o {@see Contexto} da requisição: usuário logado, escritório da sessão, perfil e configuração. */
final class FabricaDeContexto
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ResolvedorDePerfil $resolvedor,
        private readonly ConfiguracaoCentralRepository $configuracoes,
        private readonly ConviteDemandaRepository $convites,
        private readonly UserTenantRepository $vinculos,
    ) {
    }

    /** Null quando o usuário não tem perfil no Trabalho Externo (o controller nega o acesso). */
    public function para(mixed $usuario): ?Contexto
    {
        if (!$usuario instanceof User) {
            return null;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $configuracao = $this->configuracoes->atual();
        $perfil = $this->resolvedor->resolver($usuario, $tenant, $configuracao);

        if ($perfil === Perfil::Master) {
            $escritorios = [];
            foreach ($this->vinculos->findActiveByUser($usuario) as $vinculo) {
                if ($vinculo->getTenant()?->getId() !== null) {
                    $escritorios[] = (int) $vinculo->getTenant()->getId();
                }
            }

            return new Contexto($usuario, $tenant, $perfil, $configuracao, escritoriosDoPrimario: $escritorios);
        }
        if ($perfil !== null) {
            return new Contexto($usuario, $tenant, $perfil, $configuracao);
        }

        // Sem perfil no escritório (ou sem escritório nenhum), mas com convite vigente: Convidado.
        // O convite de CADA demanda é aplicado pelo LocalizadorDeDemanda (perfil por demanda).
        return $this->convites->vigentesDo($usuario, new \DateTimeImmutable('today')) !== []
            ? new Contexto($usuario, $tenant, Perfil::Convidado, $configuracao)
            : null;
    }
}
