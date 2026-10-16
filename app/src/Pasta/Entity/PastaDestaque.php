<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaDestaqueRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cor com que um usuário destacou a linha de uma pasta na Lista do Expediente (botão direito →
 * "Destacar linha", desenho "03 - EXPEDIENTE Lista 1.1", E12). Preferência pessoal, como o favorito:
 * só quem destacou vê, e tirar o destaque apaga a linha.
 *
 * Anatomia igual à do PastaFavorita: UNIQUE (user_id, pasta_id), índice (tenant_id, user_id) para a
 * listagem, CASCADE de pasta e usuário, tenant NOT NULL (TenantAware, TenantFilter e purga).
 */
#[ORM\Entity(repositoryClass: PastaDestaqueRepository::class)]
#[ORM\Table(name: 'pasta_destaque')]
#[ORM\Index(name: 'idx_pasta_destaque_tenant_user', columns: ['tenant_id', 'user_id'])]
#[ORM\Index(name: 'idx_pasta_destaque_pasta', columns: ['pasta_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pasta_destaque_user_pasta', columns: ['user_id', 'pasta_id'])]
class PastaDestaque implements TenantAware
{
    /** As 6 cores do desenho (dc `DCORES`), pela chave gravada. */
    public const CORES = ['amarelo', 'verde', 'azul', 'rosa', 'laranja', 'lilas'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $cor;

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
        string $cor,
    ) {
        $this->definirCor($cor);
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function definirCor(string $cor): void
    {
        if (!in_array($cor, self::CORES, true)) {
            throw new \DomainException('Cor de destaque inválida.');
        }
        $this->cor = $cor;
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

    public function getCor(): string
    {
        return $this->cor;
    }
}
