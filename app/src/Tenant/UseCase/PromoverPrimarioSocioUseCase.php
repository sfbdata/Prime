<?php
declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Tenant\Tenant;
use App\Repository\TenantRoleRepository;
use App\Repository\UserTenantRepository;
use App\Tenant\DTO\PromoverPrimarioSocioInput;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Promove um colaborador secundário a primário sócio do escritório (spec perfis-master D4).
 *
 * Quem dispara: o primário do escritório (ou o Master), na edição do perfil primário. A
 * autorização é do controller; aqui moram as regras de negócio.
 * Objetivo: dar a um colaborador as mesmas competências de gestão do titular (secundários,
 * perfis, permissões, módulos), sem que ele possa rebaixar o titular.
 * Pré-condições: o alvo tem vínculo ATIVO com ESTE escritório, a conta dele está ativa, ele
 * ainda não é primário e o escritório tem o perfil de sistema.
 * Fluxo principal: busca o vínculo pelo par (alvo, escritório), troca o perfil do vínculo
 * pelo perfil de sistema do mesmo escritório e grava.
 * Fluxos alternativos: vínculo de outro escritório, inexistente ou inativo; conta desativada;
 * já primário; escritório sem perfil de sistema. Todos recusados com mensagem.
 * Pós-condições: o vínculo passa a ter o perfil isSystem; o PermissionChecker dá acesso total.
 * Auditoria: UserTenant é Auditavel, o AuditLogSubscriber grava o "update" do tenantRole no
 * flush, com o tenant da sessão, que é o da rota (o TenantRoleController exige os dois iguais).
 * Regra não óbvia: a busca é SEMPRE pelo par (usuário, escritório). Um id de usuário de outro
 * escritório não encontra vínculo e para aqui, mesmo que o controller deixe passar.
 */
final class PromoverPrimarioSocioUseCase
{
    public function __construct(
        private readonly UserTenantRepository $userTenantRepository,
        private readonly TenantRoleRepository $tenantRoleRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(PromoverPrimarioSocioInput $input): void
    {
        $vinculo = $this->userTenantRepository->findAtivoPorUserETenant($input->alvo, $input->tenant);
        if ($vinculo === null || !$vinculo->isActive() || !$this->mesmoEscritorio($vinculo->getTenant(), $input->tenant)) {
            throw new OperacaoDePrimarioRecusada(
                'Só usuários ativos desta conta podem ser promovidos a primário.'
            );
        }

        if (!$input->alvo->isActive()) {
            throw new OperacaoDePrimarioRecusada('A conta deste usuário está desativada.');
        }

        if ($vinculo->getTenantRole()?->isSystem() === true) {
            throw new OperacaoDePrimarioRecusada('Este usuário já é primário do escritório.');
        }

        $perfilDoPrimario = $this->tenantRoleRepository->findPerfilDoPrimario($input->tenant);
        if ($perfilDoPrimario === null || !$this->mesmoEscritorio($perfilDoPrimario->getTenant(), $input->tenant)) {
            throw new OperacaoDePrimarioRecusada('O escritório não tem o perfil de usuário primário.');
        }

        $vinculo->setTenantRole($perfilDoPrimario);
        $vinculo->setUpdatedAt(new \DateTimeImmutable());

        $this->em->flush();
    }

    /** Mesma conta: identidade, ou id igual quando os dois têm id (proxy x entidade carregada). */
    private function mesmoEscritorio(?Tenant $a, Tenant $b): bool
    {
        return $a === $b || ($a !== null && $a->getId() !== null && $a->getId() === $b->getId());
    }
}
