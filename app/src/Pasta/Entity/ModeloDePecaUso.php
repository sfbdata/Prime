<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\ModeloDePecaUsoRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um "Usar na pasta": qual modelo virou minuta em qual pasta, quando e por quem (desenho 02 de 27/10,
 * `bj-modelos-uso`). O documento gerado fica ligado para consulta; se ele sair, o registro de uso
 * continua (SET NULL). Excluir o modelo ou a pasta apaga o registro (CASCADE). Não é auditada: o uso
 * já fica no histórico do documento da pasta.
 */
#[ORM\Entity(repositoryClass: ModeloDePecaUsoRepository::class)]
#[ORM\Table(name: 'modelo_de_peca_uso')]
#[ORM\Index(name: 'idx_modelo_de_peca_uso_tenant_modelo', columns: ['tenant_id', 'modelo_id'])]
class ModeloDePecaUso implements TenantAware
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
        #[ORM\ManyToOne(targetEntity: ModeloDePeca::class)]
        #[ORM\JoinColumn(name: 'modelo_id', nullable: false, onDelete: 'CASCADE')]
        private ModeloDePeca $modelo,
        #[ORM\ManyToOne(targetEntity: Pasta::class)]
        #[ORM\JoinColumn(name: 'pasta_id', nullable: false, onDelete: 'CASCADE')]
        private Pasta $pasta,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $usuario,
        #[ORM\ManyToOne(targetEntity: PastaDocumento::class)]
        #[ORM\JoinColumn(name: 'documento_id', nullable: true, onDelete: 'SET NULL')]
        private ?PastaDocumento $documento,
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

    public function getModelo(): ModeloDePeca
    {
        return $this->modelo;
    }

    public function getPasta(): Pasta
    {
        return $this->pasta;
    }

    public function getUsuario(): ?User
    {
        return $this->usuario;
    }

    public function getDocumento(): ?PastaDocumento
    {
        return $this->documento;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}
