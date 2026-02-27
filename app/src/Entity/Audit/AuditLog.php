<?php

namespace App\Entity\Audit;

use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_entity', columns: ['entity_class', 'entity_id'])]
#[ORM\Index(name: 'idx_audit_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_audit_actor_user_id', columns: ['actor_user_id'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private string $action;

    #[ORM\Column(length: 255)]
    private string $entityClass;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $entityId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $changes = null;

    #[ORM\Column(nullable: true)]
    private ?int $actorUserId = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $actorEmail = null;

    #[ORM\Column(nullable: true)]
    private ?int $tenantId = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $route = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): self
    {
        $this->action = $action;
        return $this;
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function setEntityClass(string $entityClass): self
    {
        $this->entityClass = $entityClass;
        return $this;
    }

    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    public function setEntityId(?string $entityId): self
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getChanges(): ?array
    {
        return $this->changes;
    }

    public function setChanges(?array $changes): self
    {
        $this->changes = $changes;
        return $this;
    }

    public function getActorUserId(): ?int
    {
        return $this->actorUserId;
    }

    public function setActorUserId(?int $actorUserId): self
    {
        $this->actorUserId = $actorUserId;
        return $this;
    }

    public function getActorEmail(): ?string
    {
        return $this->actorEmail;
    }

    public function setActorEmail(?string $actorEmail): self
    {
        $this->actorEmail = $actorEmail;
        return $this;
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function setTenantId(?int $tenantId): self
    {
        $this->tenantId = $tenantId;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getRoute(): ?string
    {
        return $this->route;
    }

    public function setRoute(?string $route): self
    {
        $this->route = $route;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}