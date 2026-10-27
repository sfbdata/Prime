<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Master\Repository\CentralMasterEstadoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * O que UM Master marcou numa mensagem da Central de Notificações (spec master-central-de-notificacoes.md, D1):
 * lida, com estrela, arquivada. A mensagem em si continua na tabela de origem (`origem`, `id_origem`).
 *
 * Uma linha por (Master, origem, id_origem), criada na primeira marcação. Sem linha = não lida, sem estrela, na
 * caixa. O estado é POR Master: o que um marca não muda a caixa do outro.
 *
 * NÃO é TenantAware (D3): o Master lê todos os escritórios. `tenant_id` guarda o escritório da origem (nulo para
 * suporte e alerta) só para a purga do escritório levar a linha junto. Fora da auditoria: é marca pessoal de
 * leitura, sem dado de escritório.
 */
#[ORM\Entity(repositoryClass: CentralMasterEstadoRepository::class)]
#[ORM\Table(name: 'central_master_estado')]
#[ORM\UniqueConstraint(name: 'uniq_central_master_estado', columns: ['master_id', 'origem', 'id_origem'])]
#[ORM\Index(name: 'idx_central_master_estado_origem', columns: ['origem', 'id_origem'])]
#[ORM\Index(name: 'idx_central_master_estado_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_central_master_estado_master', columns: ['master_id'])]
class CentralMasterEstado
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lidaEm = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $estrela = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $arquivadaEm = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'master_id', nullable: false, onDelete: 'CASCADE')]
        private User $master,
        #[ORM\Column(length: 20)]
        private string $origem,
        #[ORM\Column(name: 'id_origem', type: 'integer')]
        private int $idOrigem,
        #[ORM\Column(name: 'tenant_id', type: 'integer', nullable: true)]
        private ?int $tenantId = null,
    ) {
    }

    public function marcarLida(\DateTimeImmutable $agora): void
    {
        $this->lidaEm ??= $agora;
    }

    public function marcarNaoLida(): void
    {
        $this->lidaEm = null;
    }

    public function definirEstrela(bool $estrela): void
    {
        $this->estrela = $estrela;
    }

    public function arquivar(\DateTimeImmutable $agora): void
    {
        $this->arquivadaEm ??= $agora;
    }

    public function desarquivar(): void
    {
        $this->arquivadaEm = null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMaster(): User
    {
        return $this->master;
    }

    public function getOrigem(): string
    {
        return $this->origem;
    }

    public function getIdOrigem(): int
    {
        return $this->idOrigem;
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function isLida(): bool
    {
        return $this->lidaEm !== null;
    }

    public function getLidaEm(): ?\DateTimeImmutable
    {
        return $this->lidaEm;
    }

    public function temEstrela(): bool
    {
        return $this->estrela;
    }

    public function isArquivada(): bool
    {
        return $this->arquivadaEm !== null;
    }

    public function getArquivadaEm(): ?\DateTimeImmutable
    {
        return $this->arquivadaEm;
    }
}
