<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\ArquivoArmazenadoRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * O arquivo físico por trás de um ou mais documentos da pasta (spec central-documentos, fatia C).
 *
 * Um documento aponta para ele por `pasta_documento.arquivo_id`. O código `DOC-XXXX-XXXX-XXXX` é o
 * nome que o escritório vê; `chave_fisica` é o nome que o storage cunhou. Na C1 a relação é 1:1
 * (cada upload cria o seu); a C2 passa a juntar duplicatas do MESMO escritório sob a mesma linha.
 */
#[ORM\Entity(repositoryClass: ArquivoArmazenadoRepository::class)]
#[ORM\Table(name: 'arquivo_armazenado')]
#[ORM\Index(name: 'idx_arquivo_armazenado_tenant_sha256', columns: ['tenant_id', 'sha256'])]
#[ORM\UniqueConstraint(name: 'uniq_arquivo_armazenado_codigo', columns: ['tenant_id', 'codigo'])]
#[ORM\UniqueConstraint(name: 'uniq_arquivo_armazenado_chave', columns: ['tenant_id', 'chave_fisica'])]
class ArquivoArmazenado implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Tenant $tenant;

    #[ORM\Column(length: 20)]
    private string $codigo;

    #[ORM\Column(length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $sha256 = null;

    #[ORM\Column(type: 'bigint')]
    private int $tamanhoBytes;

    #[ORM\Column(length: 255)]
    private string $chaveFisica;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct(Tenant $tenant, string $codigo, string $chaveFisica, int $tamanhoBytes, ?string $sha256)
    {
        $this->tenant       = $tenant;
        $this->codigo       = $codigo;
        $this->chaveFisica  = $chaveFisica;
        $this->tamanhoBytes = $tamanhoBytes;
        $this->sha256       = $sha256;
        $this->criadoEm     = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getCodigo(): string
    {
        return $this->codigo;
    }

    public function getSha256(): ?string
    {
        return $this->sha256;
    }

    public function getTamanhoBytes(): int
    {
        return $this->tamanhoBytes;
    }

    public function getChaveFisica(): string
    {
        return $this->chaveFisica;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}
