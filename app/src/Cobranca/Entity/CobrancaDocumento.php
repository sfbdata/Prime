<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Enum\CategoriaDocumentoCobranca;
use App\Cobranca\Repository\CobrancaDocumentoRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Documento de um Caso de Cobrança (SPEC §15, invariável 25): termo de acordo, boleto, comprovante,
 * notificação, documento de negociação ou outro arquivo relevante. Existe vinculado ao CasoCobranca
 * (FK obrigatória) e NUNCA depende de Pasta — o caso pode ter documentos antes de qualquer
 * judicialização. Ao judicializar, os documentos PERMANECEM no caso (não migram nem duplicam, §16).
 *
 * A mecânica de arquivo (armazenar/servir/excluir) é 100% reusada do App\Shared\Service; esta
 * entidade existe apenas porque a FK aponta para o Caso (a PastaDocumento aponta para Pasta). O
 * arquivo físico mora em `cobrancas/<tenantId>/<hash>` (isolamento por tenant no disco, padrão M5);
 * `caminhoArquivo` guarda só o hash. Vínculo com o Caso é unidirecional (navegação pelo repositório);
 * ON DELETE CASCADE derruba o documento se o caso/seção for apagado. Não-final: proxies do Doctrine.
 */
#[ORM\Entity(repositoryClass: CobrancaDocumentoRepository::class)]
#[ORM\Table(name: 'cobranca_documento')]
#[ORM\Index(name: 'idx_cobranca_documento_caso', columns: ['caso_id'])]
#[ORM\Index(name: 'idx_cobranca_documento_tenant', columns: ['tenant_id'])]
class CobrancaDocumento implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CasoCobranca::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CasoCobranca $caso = null;

    #[ORM\ManyToOne(targetEntity: CobrancaSecao::class, inversedBy: 'documentos')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?CobrancaSecao $secao = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $titulo;

    #[ORM\Column(enumType: CategoriaDocumentoCobranca::class)]
    private CategoriaDocumentoCobranca $categoria = CategoriaDocumentoCobranca::Outro;

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

    #[ORM\Column(options: ['default' => 0])]
    private int $ordem = 0;

    public function __construct()
    {
        $this->carregadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCaso(): ?CasoCobranca
    {
        return $this->caso;
    }

    public function setCaso(CasoCobranca $caso): self
    {
        $this->caso = $caso;

        return $this;
    }

    public function getSecao(): ?CobrancaSecao
    {
        return $this->secao;
    }

    public function setSecao(?CobrancaSecao $secao): self
    {
        $this->secao = $secao;

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
        $this->titulo = mb_strtoupper(trim($titulo));

        return $this;
    }

    public function getCategoria(): CategoriaDocumentoCobranca
    {
        return $this->categoria;
    }

    public function setCategoria(CategoriaDocumentoCobranca $categoria): self
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

    public function getOrdem(): int
    {
        return $this->ordem;
    }

    public function setOrdem(int $ordem): self
    {
        $this->ordem = $ordem;

        return $this;
    }
}
