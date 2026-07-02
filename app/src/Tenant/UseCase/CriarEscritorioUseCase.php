<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Auth\Enum\StatusOab;
use App\Auth\Service\ValidadorOab;
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
 *
 * A validação de FORMATO da OAB usa o ValidadorOab (ponto único — fecha a #4). A OAB é
 * também verificada contra o CNA e o resultado é gravado no criador (dormente por ora →
 * `nao_verificada`). O gate de `confirmada` para criar entra no Passo 3.
 */
final class CriarEscritorioUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantRepository $tenantRepository,
        private readonly TenantBootstrapService $bootstrap,
        private readonly ValidadorOab $validadorOab,
        private readonly int $tenantMaxPorUsuario,
    ) {}

    public function executar(CriarEscritorioInput $input, User $criador): Tenant
    {
        // Normaliza (trim + UF em maiúscula) igual ao fluxo do perfil, para não rejeitar entradas
        // válidas por causa de espaço ou caixa (ex.: "df" → "DF").
        $oabNumero = trim((string) ($criador->getOabNumero() ?? $input->oabNumero ?? ''));
        $oabUf     = strtoupper(trim((string) ($criador->getOabUf() ?? $input->oabUf ?? '')));

        if ($oabNumero === '' || $oabUf === '') {
            throw new \InvalidArgumentException('Informe a OAB (número e UF) para criar um escritório.');
        }

        $this->validadorOab->validarFormato($oabNumero, $oabUf);

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

        // Verifica a OAB e grava o status — sem sobrescrever quem já é confirmada (dono).
        if ($criador->isOabConfirmada() === false) {
            $resultado = $this->validadorOab->verificar($oabNumero, $oabUf, (string) $criador->getFullName());
            $criador->setOabStatus($resultado->status);
            $criador->setOabNomeOficial($resultado->nomeOficial);

            if ($resultado->status === StatusOab::Confirmada) {
                $criador->setOabVerificadaEm(new \DateTimeImmutable());
            }
        }

        $tenant = new Tenant();
        $tenant->setName($input->nome);
        $this->em->persist($tenant);

        // bootstrap seta criadoPor, cria perfil admin + vínculo dono + seed, e faz flush().
        $this->bootstrap->bootstrap($tenant, $criador);

        return $tenant;
    }
}
