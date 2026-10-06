<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaFavoritaRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma pasta que um usuário fixou nos favoritos (menu ⋮ → "Fixar nos favoritos", desenho
 * "02 - EXPEDIENTES 1.2.3", `prefPasta('fav')`). Favoritas sobem para o topo da listagem
 * do Expediente — de QUEM marcou, e de mais ninguém.
 *
 * É preferência pessoal: pende do usuário E da pasta, e só existe enquanto a linha existe
 * (desmarcar apaga). Não há "favorito = false" gravado.
 *
 * Anatomia:
 *   - UNIQUE (user_id, pasta_id): um usuário marca a mesma pasta uma vez só;
 *   - índice (tenant_id, user_id): é por ele que a listagem e a tela perguntam "quais são as
 *     minhas favoritas neste escritório";
 *   - `pasta_id` CASCADE: a preferência some com a pasta; `user_id` CASCADE pelo mesmo motivo;
 *   - `tenant_id` NOT NULL: entidade TenantAware, passa pelo TenantFilter e pela purga.
 *
 * Os nomes de índice são explícitos no mapeamento para o `doctrine:schema:validate` bater com
 * a migration escrita à mão.
 */
#[ORM\Entity(repositoryClass: PastaFavoritaRepository::class)]
#[ORM\Table(name: 'pasta_favorita')]
#[ORM\Index(name: 'idx_pasta_favorita_tenant_user', columns: ['tenant_id', 'user_id'])]
#[ORM\Index(name: 'idx_pasta_favorita_pasta', columns: ['pasta_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pasta_favorita_user_pasta', columns: ['user_id', 'pasta_id'])]
class PastaFavorita implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

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
    ) {
        $this->criadoEm = new \DateTimeImmutable();
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

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}
