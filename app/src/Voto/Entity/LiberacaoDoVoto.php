<?php

declare(strict_types=1);

namespace App\Voto\Entity;

use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * O Master liberou (ou não) o BlueJus Voto para UM escritório (spec docs/specs/bluejus-voto.md, D5 e D7, fase A1b; molde
 * da liberação da I.A. por escritório). Uma linha por escritório: a unicidade é uma constraint NOMEADA em `tenant_id`
 * (e não `OneToOne`, cujo índice único nasceria com nome de hash, fora do controle da migration escrita à mão). Sem
 * linha, o escritório não está liberado.
 *
 * Mora no schema `voto` (D1-B). É a única entidade do Voto que é auditável: quem liberou e quem bloqueou fica no
 * `audit_log` do escritório alterado. As entidades da votação não serão (o sigilo do voto vem antes da trilha).
 *
 * A purga definitiva (PurgarEscritorioUseCase) apaga a linha pela ordem de deleção, antes do tenant, e a
 * conferência dela enxerga o schema `voto` (spec docs/specs/voto-redes-do-schema.md). A chave estrangeira
 * para `tenant` com ON DELETE CASCADE fica como segunda rede: é a exceção da D1-B registrada na spec do Voto.
 */
#[ORM\Entity(repositoryClass: LiberacaoDoVotoRepository::class)]
#[ORM\Table(name: 'liberacao', schema: 'voto')]
#[ORM\UniqueConstraint(name: 'uniq_voto_liberacao_tenant', columns: ['tenant_id'])]
#[ORM\HasLifecycleCallbacks]
class LiberacaoDoVoto implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $liberado = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadaEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
    ) {
        $agora = new \DateTimeImmutable();
        $this->criadaEm = $agora;
        $this->atualizadaEm = $agora;
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadaEm = new \DateTimeImmutable();
    }

    public function liberar(): void
    {
        $this->liberado = true;
    }

    /** Bloquear tira o Voto do escritório; o que ele já tiver no Voto fica guardado e volta se o Master liberar de novo. */
    public function bloquear(): void
    {
        $this->liberado = false;
    }

    public function isLiberado(): bool
    {
        return $this->liberado;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getAtualizadaEm(): \DateTimeImmutable
    {
        return $this->atualizadaEm;
    }
}
