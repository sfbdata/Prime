<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * D1 da spec meu-escritorio.md: a tentativa de quem não é primário (nem Master) de salvar, enviar a logo, removê-la ou
 * conferir a exclusão fica no `audit_log` com ação própria (`negado`), o usuário, o escritório e a rota. NUNCA o
 * conteúdo enviado.
 */
final class RegistroDeAcessoNegadoAoEscritorio
{
    /** A coluna `action` é VARCHAR(10). */
    public const ACAO = 'negado';

    public function __construct(
        private readonly AuditLogRepository $auditoria,
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function registrar(User $quem, Tenant $tenant, string $rota): void
    {
        $requisicao = $this->requestStack->getCurrentRequest();
        $this->auditoria->registrar((new AuditLog())
            ->setAction(self::ACAO)
            ->setEntityClass(Tenant::class)
            ->setEntityId((string) $tenant->getId())
            ->setTenantId($tenant->getId())
            ->setActorUserId($quem->getId())
            ->setActorEmail($quem->getEmail())
            ->setIpAddress($requisicao?->getClientIp())
            ->setRoute($rota)
            ->setChanges(['rota' => $rota, 'motivo' => 'nao_e_primario_nem_master']));
        $this->em->flush();
    }
}
