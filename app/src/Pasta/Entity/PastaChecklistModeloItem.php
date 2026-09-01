<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaChecklistModeloItemRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma linha do modelo: o título do documento a conferir.
 *
 * Não guarda "concluído" — o modelo é a lista a fazer, não o estado de nenhuma pasta.
 * Ao aplicar, todo item nasce pendente na pasta de destino.
 *
 * O título é gravado em maiúsculas igual ao PastaChecklistItem faz. É essa
 * normalização compartilhada que faz a comparação de "já existe na pasta" bater.
 */
#[ORM\Entity(repositoryClass: PastaChecklistModeloItemRepository::class)]
#[ORM\Table(name: 'pasta_checklist_modelo_item')]
#[ORM\Index(name: 'idx_pasta_checklist_modelo_item_modelo', columns: ['modelo_id'])]
#[ORM\Index(name: 'idx_pasta_checklist_modelo_item_tenant', columns: ['tenant_id'])]
class PastaChecklistModeloItem implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PastaChecklistModelo::class, inversedBy: 'itens')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PastaChecklistModelo $modelo = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255)]
    private string $titulo = '';

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $ordem = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getModelo(): ?PastaChecklistModelo
    {
        return $this->modelo;
    }

    public function setModelo(?PastaChecklistModelo $modelo): self
    {
        $this->modelo = $modelo;

        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function setTitulo(string $titulo): self
    {
        $this->titulo = mb_strtoupper(trim($titulo));

        return $this;
    }

    public function getOrdem(): int
    {
        return $this->ordem;
    }

    public function setOrdem(int $ordem): self
    {
        $this->ordem = $ordem;

        return $this;
    }
}
