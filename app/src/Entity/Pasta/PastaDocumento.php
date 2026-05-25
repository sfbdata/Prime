<?php

declare(strict_types=1);

namespace App\Entity\Pasta;

use App\Entity\Tenant\Tenant;
use App\Repository\PastaDocumentoRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PastaDocumentoRepository::class)]
#[ORM\Table(name: 'pasta_documento')]
#[ORM\Index(name: 'idx_pasta_documento_tenant', columns: ['tenant_id'])]
class PastaDocumento
{
    public const CATEGORIA_PECA = 'PECA';
    public const CATEGORIA_PROCURACAO = 'PROCURACAO';
    public const CATEGORIA_IDENTIFICACAO = 'IDENTIFICACAO';
    public const CATEGORIA_COMPROVANTE_RESIDENCIA = 'COMPROVANTE_RESIDENCIA';
    public const CATEGORIA_GRATUIDADE_JUSTICA = 'GRATUIDADE_JUSTICA';
    public const CATEGORIA_DEMAIS = 'DEMAIS';
    public const CATEGORIA_CONTRATO = 'CONTRATO';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $titulo;

    #[Assert\Choice(choices: ['PECA', 'PROCURACAO', 'IDENTIFICACAO', 'COMPROVANTE_RESIDENCIA', 'GRATUIDADE_JUSTICA', 'DEMAIS', 'CONTRATO'])]
    #[ORM\Column(length: 40)]
    private string $categoria;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descricao = null;

    #[ORM\Column(length: 255)]
    private string $caminhoArquivo;

    #[ORM\Column(length: 255)]
    private string $nomeOriginal;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column(type: 'integer')]
    private int $tamanhoBytes;

    #[ORM\Column(name: 'uploaded_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $carregadoEm;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $numero = null;

    #[ORM\ManyToOne(targetEntity: Pasta::class, inversedBy: 'documentos')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Pasta $pasta = null;

    #[ORM\ManyToOne(targetEntity: PastaSecao::class, inversedBy: 'documentos')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?PastaSecao $secao = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $ordem = 0;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    public function __construct()
    {
        $this->carregadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCategoria(): string
    {
        return $this->categoria;
    }

    public function setCategoria(string $categoria): self
    {
        $this->categoria = $categoria;

        return $this;
    }

    public function getDescricao(): ?string
    {
        return $this->descricao;
    }

    public function setDescricao(?string $descricao): self
    {
        $this->descricao = $descricao;

        return $this;
    }

    public function getCaminhoArquivo(): string
    {
        return $this->caminhoArquivo;
    }

    public function setCaminhoArquivo(string $caminhoArquivo): self
    {
        $this->caminhoArquivo = $caminhoArquivo;

        return $this;
    }

    public function getNomeOriginal(): string
    {
        return $this->nomeOriginal;
    }

    public function setNomeOriginal(string $nomeOriginal): self
    {
        $this->nomeOriginal = $nomeOriginal;

        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getTamanhoBytes(): int
    {
        return $this->tamanhoBytes;
    }

    public function setTamanhoBytes(int $tamanhoBytes): self
    {
        $this->tamanhoBytes = $tamanhoBytes;

        return $this;
    }

    public function getCarregadoEm(): \DateTimeImmutable
    {
        return $this->carregadoEm;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(?string $numero): self
    {
        $this->numero = $numero !== null ? mb_strtoupper(trim($numero)) : null;

        return $this;
    }

    public function getPasta(): ?Pasta
    {
        return $this->pasta;
    }

    public function setPasta(?Pasta $pasta): self
    {
        $this->pasta = $pasta;

        return $this;
    }

    public function getSecao(): ?PastaSecao
    {
        return $this->secao;
    }

    public function setSecao(?PastaSecao $secao): self
    {
        $this->secao = $secao;

        return $this;
    }

    public function getOrdem(): int
    {
        return $this->ordem;
    }

    public function setOrdem(int $ordem): void
    {
        $this->ordem = $ordem;
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
}
