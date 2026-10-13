<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaTimelineVisitaRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * A última vez que um usuário abriu a Timeline inteligente de uma pasta (PAS-043; desenho 1.2.3,
 * `bluejus-central.js` `tlVisto()`/`tlFechar`). É o lastro do "Enquanto você estava fora": sem
 * guardar a visita POR USUÁRIO no servidor não há como dizer o que é novo — e o navegador não
 * substitui persistência (regra do dono).
 *
 * Duas datas, e não uma, porque a visita é registrada AO ABRIR o painel:
 *   - `vista_em`: a abertura mais recente (a desta sessão de leitura);
 *   - `vista_anterior_em`: a abertura de antes — é a partir DELA que a tela conta o que é novo.
 * Assim a contagem não zera quando o usuário troca de chip ou de período (cada troca recarrega
 * a lista) e não depende de um "fechar" que o navegador pode não mandar (aba fechada, navegação).
 * `vista_anterior_em` nulo = primeira abertura: não há "enquanto você estava fora".
 *
 * Anatomia (mesma do `PastaFavorita`):
 *   - UNIQUE (user_id, pasta_id): uma linha por usuário e pasta — é o árbitro do upsert;
 *   - `pasta_id` e `user_id` CASCADE: a visita some com a pasta ou com o usuário;
 *   - `tenant_id` NOT NULL: TenantAware, passa pelo TenantFilter e pela purga.
 */
#[ORM\Entity(repositoryClass: PastaTimelineVisitaRepository::class)]
#[ORM\Table(name: 'pasta_timeline_visita')]
#[ORM\Index(name: 'idx_pasta_timeline_visita_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_pasta_timeline_visita_pasta', columns: ['pasta_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pasta_timeline_visita_user_pasta', columns: ['user_id', 'pasta_id'])]
class PastaTimelineVisita implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'vista_anterior_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $vistaAnteriorEm = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\ManyToOne(targetEntity: Pasta::class)]
        #[ORM\JoinColumn(name: 'pasta_id', nullable: false, onDelete: 'CASCADE')]
        private Pasta $pasta,
        #[ORM\Column(name: 'vista_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $vistaEm,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getPasta(): Pasta
    {
        return $this->pasta;
    }

    public function getVistaEm(): \DateTimeImmutable
    {
        return $this->vistaEm;
    }

    public function getVistaAnteriorEm(): ?\DateTimeImmutable
    {
        return $this->vistaAnteriorEm;
    }
}
