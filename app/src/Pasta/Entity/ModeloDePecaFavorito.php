<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\ModeloDePecaFavoritoRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * A estrela de um modelo do banco de modelos, para QUEM marcou e mais ninguém. Preferência pessoal,
 * irmã de {@see PastaDocumentoFavorito}: existe enquanto a linha existe (desmarcar apaga) e não é
 * auditada. Excluir o modelo ou o usuário apaga a estrela (CASCADE).
 */
#[ORM\Entity(repositoryClass: ModeloDePecaFavoritoRepository::class)]
#[ORM\Table(name: 'modelo_de_peca_favorito')]
#[ORM\Index(name: 'idx_modelo_de_peca_favorito_tenant_user', columns: ['tenant_id', 'user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_modelo_de_peca_favorito_user_modelo', columns: ['user_id', 'modelo_id'])]
class ModeloDePecaFavorito implements TenantAware
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
        #[ORM\ManyToOne(targetEntity: ModeloDePeca::class)]
        #[ORM\JoinColumn(name: 'modelo_id', nullable: false, onDelete: 'CASCADE')]
        private ModeloDePeca $modelo,
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

    public function getModelo(): ModeloDePeca
    {
        return $this->modelo;
    }
}
