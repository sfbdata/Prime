<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma linha da grade "Colaborador · Ocultar / Visualizar / Editar" do modal do cadeado (desenho L522-535),
 * só para o modo `PorPessoa` e só para quem vê (`Visualizar` ou `Editar`). Quem não tem linha fica `Oculto`.
 *
 * Anatomia: UNIQUE (cadeado_id, user_id); cadeado e pessoa CASCADE (a linha some com o cadeado ou com a
 * conta); tenant NOT NULL (TenantAware, passa pelo TenantFilter e pela purga).
 */
#[ORM\Entity]
#[ORM\Table(name: 'pasta_cadeado_acesso')]
#[ORM\Index(name: 'idx_pasta_cadeado_acesso_tenant_user', columns: ['tenant_id', 'user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pasta_cadeado_acesso_pessoa', columns: ['cadeado_id', 'user_id'])]
class AcessoAPastaTrancada implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: CadeadoDaPasta::class, inversedBy: 'acessos')]
        #[ORM\JoinColumn(name: 'cadeado_id', nullable: false, onDelete: 'CASCADE')]
        private CadeadoDaPasta $cadeado,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private User $pessoa,
        #[ORM\Column(length: 20, enumType: NivelDeAcesso::class)]
        private NivelDeAcesso $nivel,
    ) {
        if (!$nivel->podeVer()) {
            throw new \InvalidArgumentException('A lista do cadeado só guarda quem vê a pasta.');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getCadeado(): CadeadoDaPasta
    {
        return $this->cadeado;
    }

    /** A pasta, para a auditoria e para os listeners que procuram a pasta pela filha. */
    public function getPasta(): Pasta
    {
        return $this->cadeado->getPasta();
    }

    public function getPessoa(): User
    {
        return $this->pessoa;
    }

    public function getNivel(): NivelDeAcesso
    {
        return $this->nivel;
    }

    public function mudarNivel(NivelDeAcesso $nivel): void
    {
        if (!$nivel->podeVer()) {
            throw new \InvalidArgumentException('A lista do cadeado só guarda quem vê a pasta.');
        }
        $this->nivel = $nivel;
    }
}
