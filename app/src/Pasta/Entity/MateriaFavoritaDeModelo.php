<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Enum\MateriaDeModelo;
use App\Pasta\Repository\MateriaFavoritaDeModeloRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/** A estrela de uma matéria na coluna esquerda do banco de modelos, por usuário. Desmarcar apaga a linha. */
#[ORM\Entity(repositoryClass: MateriaFavoritaDeModeloRepository::class)]
#[ORM\Table(name: 'materia_favorita_de_modelo')]
#[ORM\Index(name: 'idx_materia_favorita_de_modelo_tenant_user', columns: ['tenant_id', 'user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_materia_favorita_de_modelo_user_materia', columns: ['user_id', 'materia'])]
class MateriaFavoritaDeModelo implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\Column(length: 30, enumType: MateriaDeModelo::class)]
        private MateriaDeModelo $materia,
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

    public function getMateria(): MateriaDeModelo
    {
        return $this->materia;
    }
}
