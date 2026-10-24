<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaAcompanhamentoDesligadoRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um usuário que DEIXOU de acompanhar as alterações de uma pasta (menu ⋮ → "Acompanhar
 * alterações", desenho "02 - EXPEDIENTES - VS 18.10.26", `prefPasta('seguir')`).
 *
 * O padrão do desenho é LIGADO (`seguir: r.seguir !== undefined ? r.seguir : true`): quem vê a
 * pasta acompanha. Por isso a tabela guarda a EXCEÇÃO: sem linha = acompanhando; com linha =
 * desligado. Religar apaga a linha. Não há backfill: toda pasta nasce acompanhada por todos.
 *
 * Anatomia (a mesma do {@see PastaFavorita}):
 *   - UNIQUE (user_id, pasta_id): uma linha por usuário e pasta (desligar duas vezes não duplica);
 *   - índice (tenant_id, user_id): "o que eu desliguei neste escritório";
 *   - índice (pasta_id): "quem desligou esta pasta", a pergunta de quem avisa;
 *   - `pasta_id` CASCADE e `user_id` CASCADE: a preferência some com a pasta ou com o usuário;
 *   - `tenant_id` NOT NULL: entidade TenantAware, passa pelo TenantFilter e pela purga.
 *
 * Os nomes de índice são explícitos no mapeamento para o `doctrine:schema:validate` bater com
 * a migration.
 */
#[ORM\Entity(repositoryClass: PastaAcompanhamentoDesligadoRepository::class)]
#[ORM\Table(name: 'pasta_acompanhamento_desligado')]
#[ORM\Index(name: 'idx_pasta_acomp_desligado_tenant_user', columns: ['tenant_id', 'user_id'])]
#[ORM\Index(name: 'idx_pasta_acomp_desligado_pasta', columns: ['pasta_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pasta_acomp_desligado_user_pasta', columns: ['user_id', 'pasta_id'])]
class PastaAcompanhamentoDesligado implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'desligado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $desligadoEm;

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
        $this->desligadoEm = new \DateTimeImmutable();
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

    public function getDesligadoEm(): \DateTimeImmutable
    {
        return $this->desligadoEm;
    }
}
