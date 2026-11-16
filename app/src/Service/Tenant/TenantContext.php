<?php
declare(strict_types=1);

namespace App\Service\Tenant;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Service\ResetInterface;

final class TenantContext implements ResetInterface
{
    private const SESSION_KEY = 'current_tenant_id';

    /**
     * Escritório fixado sem sessão pelo conector de I.A. (spec mcp-remoto-oauth 4.1; revisão de 16/11/2026, achado 3),
     * só para a requisição: vale antes da sessão em {@see getCurrentTenant()}, {@see getCurrentUserTenant()} e
     * {@see hasCurrentTenant()}, e zera no {@see reset()}. Guarda os ids e relê pelo EntityManager, como o caminho da
     * sessão: a entidade devolvida é sempre a gerenciada, mesmo depois de um `clear()`.
     */
    private ?int $tenantFixoId = null;
    private ?int $vinculoFixoId = null;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly BloqueioPorPedidoDeExclusao $bloqueioPorPedido,
    ) {}

    public function getCurrentTenant(): ?Tenant
    {
        if ($this->tenantFixoId !== null) {
            return $this->em->find(Tenant::class, $this->tenantFixoId);
        }

        $tenantId = $this->tenantIdFromSession();
        if ($tenantId === null) {
            return null;
        }

        return $this->em->find(Tenant::class, $tenantId);
    }

    public function getCurrentUserTenant(): ?UserTenant
    {
        if ($this->vinculoFixoId !== null) {
            return $this->em->find(UserTenant::class, $this->vinculoFixoId);
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $tenantId = $this->tenantIdFromSession();
        if ($tenantId === null) {
            return null;
        }

        return $this->em->getRepository(UserTenant::class)->findOneBy([
            'user'   => $user,
            'tenant' => $this->em->getReference(Tenant::class, $tenantId),
        ]);
    }

    public function setCurrentTenant(int $tenantId): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('Usuário não autenticado.');
        }

        $userTenant = $this->em->getRepository(UserTenant::class)->findOneBy([
            'user'   => $user,
            'tenant' => $this->em->getReference(Tenant::class, $tenantId),
        ]);

        if ($userTenant === null || !$userTenant->isActive() || $userTenant->getTenant()->isActive() !== true) {
            throw new AccessDeniedException('Sem vínculo ativo com este escritório.');
        }
        // "Suspensa a pedido" (PR 5): só o primário entra. Fecha a seleção por POST e o EscritorioController.
        if ($this->bloqueioPorPedido->bloqueia($userTenant)) {
            throw new AccessDeniedException(BloqueioPorPedidoDeExclusao::MENSAGEM);
        }

        $this->requestStack->getSession()->set(self::SESSION_KEY, $tenantId);
    }

    public function hasCurrentTenant(): bool
    {
        return $this->tenantFixoId !== null || $this->tenantIdFromSession() !== null;
    }

    public function clearCurrentTenant(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
    }

    /**
     * Fixa o escritório da requisição SEM sessão: o do token do conector de I.A. Só o PortaoDoConector chama, depois de
     * conferir pessoa, vínculo, escritório e barreiras (`PortaoDoConectorArquiteturaTest`). Assim os serviços que leem
     * o escritório daqui (auditoria, cadeado, notificações) veem o escritório do token, e não nulo. Vale até o
     * {@see reset()} do fim da requisição; a sessão não é tocada.
     */
    public function fixarSemSessao(Tenant $tenant, UserTenant $vinculo): void
    {
        $tenantId = $tenant->getId();
        $vinculoId = $vinculo->getId();
        if ($tenantId === null || $vinculoId === null || $vinculo->getTenant()->getId() !== $tenantId) {
            throw new \LogicException('O escritório e o vínculo fixados têm de estar gravados, e o vínculo tem de ser do escritório.');
        }

        $this->tenantFixoId = $tenantId;
        $this->vinculoFixoId = $vinculoId;
    }

    /** Já há escritório fixado sem sessão nesta requisição (o portão recusa abrir de novo). */
    public function estaFixadoSemSessao(): bool
    {
        return $this->tenantFixoId !== null;
    }

    public function reset(): void
    {
        $this->tenantFixoId = null;
        $this->vinculoFixoId = null;
    }

    private function tenantIdFromSession(): ?int
    {
        try {
            $value = $this->requestStack->getSession()->get(self::SESSION_KEY);
        } catch (SessionNotFoundException) {
            return null;
        }

        return is_int($value) ? $value : null;
    }
}
