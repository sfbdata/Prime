<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Enum\CategoriaDocumentoCarteira;
use App\Cobranca\Repository\CarteiraDocumentoRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Documento anexado à Carteira de Cobrança (Ajuste #5): atas de assembleia, contratos e outros
 * arquivos relevantes no nível da carteira — NÃO do caso (a Carteira agrupa vários objetos/casos,
 * e alguns documentos pertencem à operação como um todo, não a um caso específico). Lista simples
 * e cronológica (sem drill-down), exibida logo abaixo da configuração da carteira.
 *
 * A mecânica de arquivo é 100% reusada de `EnviarDocumentoUseCase`/`ArquivoStorageInterface`: o
 * arquivo físico mora no MESMO diretório flat dos documentos de caso — `<cobrancasUploadsDir>/
 * <tenantId>/<hash>` (decisão deliberada: a purga (`PurgarEscritorioUseCase::removerDiretorioDeTenant`)
 * só varre esse diretório flat e não é recursiva; um diretório novo deixaria PII órfã em disco).
 * `caminhoArquivo` guarda só o hash. ON DELETE CASCADE derruba o documento se a carteira for apagada.
 * Não-final: proxies do Doctrine.
 */
#[ORM\Entity(repositoryClass: CarteiraDocumentoRepository::class)]
#[ORM\Table(name: 'cobranca_carteira_documento')]
#[ORM\Index(name: 'idx_cobranca_carteira_documento_carteira', columns: ['carteira_id'])]
#[ORM\Index(name: 'idx_cobranca_carteira_documento_tenant', columns: ['tenant_id'])]
class CarteiraDocumento implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Carteira::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Carteira $carteira = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $titulo;

    #[ORM\Column(enumType: CategoriaDocumentoCarteira::class)]
    private CategoriaDocumentoCarteira $categoria = CategoriaDocumentoCarteira::Outro;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observacao = null;

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

    public function __construct()
    {
        $this->carregadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCarteira(): ?Carteira
    {
        return $this->carteira;
    }

    public function setCarteira(Carteira $carteira): self
    {
        $this->carteira = $carteira;

        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function setTitulo(string $titulo): self
    {
        $this->titulo = trim($titulo);

        return $this;
    }

    public function getCategoria(): CategoriaDocumentoCarteira
    {
        return $this->categoria;
    }

    public function setCategoria(CategoriaDocumentoCarteira $categoria): self
    {
        $this->categoria = $categoria;

        return $this;
    }

    public function getObservacao(): ?string
    {
        return $this->observacao;
    }

    public function setObservacao(?string $observacao): self
    {
        $this->observacao = $observacao;

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
}
