<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use App\Service\TenantBootstrapService;
use App\Tenant\DTO\CriarEscritorioInput;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Criação self-service de um escritório por um usuário logado (o advogado abre a
 * própria banca). O criador vira dono (via TenantBootstrapService, que cria o perfil
 * admin e o vínculo). Regras: OAB obrigatória (RN03) e limite de escritórios próprios
 * (RN08). A criação é atômica — o bootstrap faz um único flush.
 */
final class CriarEscritorioUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantRepository $tenantRepository,
        private readonly TenantBootstrapService $bootstrap,
        private readonly int $tenantMaxPorUsuario,
    ) {}

    public function executar(CriarEscritorioInput $input, User $criador): Tenant
    {
        $oabNumero = $criador->getOabNumero() ?? $input->oabNumero;
        $oabUf     = $criador->getOabUf() ?? $input->oabUf;

        $this->validarOab($oabNumero, $oabUf);

        if ($this->tenantRepository->contarPorCriador($criador) >= $this->tenantMaxPorUsuario) {
            throw new \DomainException(sprintf(
                'Você atingiu o limite de %d escritórios próprios.',
                $this->tenantMaxPorUsuario,
            ));
        }

        // Grava a OAB na conta do usuário se ainda não tinha (colaborador virando dono).
        if ($criador->getOabNumero() === null) {
            $criador->setOabNumero($oabNumero);
            $criador->setOabUf($oabUf);
        }

        $tenant = new Tenant();
        $tenant->setName($input->nome);
        $this->em->persist($tenant);

        // bootstrap seta criadoPor, cria perfil admin + vínculo dono + seed, e faz flush().
        $this->bootstrap->bootstrap($tenant, $criador);

        return $tenant;
    }

    private function validarOab(?string $numero, ?string $uf): void
    {
        if ($numero === null || $numero === '' || $uf === null || $uf === '') {
            throw new \InvalidArgumentException('Informe a OAB (número e UF) para criar um escritório.');
        }

        if (preg_match('/^\d+$/', $numero) !== 1) {
            throw new \InvalidArgumentException('Número da OAB deve conter apenas dígitos.');
        }

        if (preg_match('/^[A-Z]{2}$/', $uf) !== 1) {
            throw new \InvalidArgumentException('UF da OAB deve ter exatamente 2 letras maiúsculas.');
        }
    }
}
