<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Enum\CategoriaDocumentoAcordo;
use App\Cobranca\Repository\AcordoDocumentoRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Documento anexado a um Acordo (Ajuste #4): termo de acordo, contrato e outros arquivos
 * relevantes no nível do acordo. Lista simples e cronológica (sem drill-down), exibida na aba
 * "Documentos" da tela do acordo.
 *
 * A mecânica de arquivo é 100% reusada de `EnviarDocumentoUseCase`/`ArquivoStorageInterface`: o
 * arquivo físico mora no MESMO diretório flat dos documentos de caso — `<cobrancasUploadsDir>/
 * <tenantId>/<hash>` (decisão deliberada: a purga (`PurgarEscritorioUseCase::removerDiretorioDeTenant`)
 * só varre esse diretório flat e não é recursiva; um diretório novo deixaria PII órfã em disco).
 * `caminhoArquivo` guarda só o hash. ON DELETE CASCADE derruba o documento se o acordo for apagado.
 * Não-final: proxies do Doctrine.
 */
#[ORM\Entity(repositoryClass: AcordoDocumentoRepository::class)]
#[ORM\Table(name: 'cobranca_acordo_documento')]
#[ORM\Index(name: 'idx_cobranca_acordo_documento_acordo', columns: ['acordo_id'])]
#[ORM\Index(name: 'idx_cobranca_acordo_documento_tenant', columns: ['tenant_id'])]
class AcordoDocumento implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Acordo::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Acordo $acordo = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $titulo;

    #[ORM\Column(enumType: CategoriaDocumentoAcordo::class)]
    private CategoriaDocumentoAcordo $categoria = CategoriaDocumentoAcordo::Outro;

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

    public function getAcordo(): ?Acordo
    {
        return $this->acordo;
    }

    public function setAcordo(Acordo $acordo): self
    {
        $this->acordo = $acordo;

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

    public function getCategoria(): CategoriaDocumentoAcordo
    {
        return $this->categoria;
    }

    public function setCategoria(CategoriaDocumentoAcordo $categoria): self
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
