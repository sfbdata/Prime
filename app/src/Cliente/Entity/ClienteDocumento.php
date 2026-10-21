<?php

namespace App\Cliente\Entity;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaDocumento;
use App\Repository\ClienteDocumentoRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClienteDocumentoRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_cliente_documento_vinculo', columns: ['cliente_id', 'pasta_documento_id'])]
class ClienteDocumento implements TenantAware
{
    public const CATEGORIA_IDENTIFICACAO          = 'IDENTIFICACAO';
    public const CATEGORIA_PROCURACAO             = 'PROCURACAO';
    public const CATEGORIA_COMPROVANTE_RESIDENCIA = 'COMPROVANTE_RESIDENCIA';
    public const CATEGORIA_CONTRATO               = 'CONTRATO';
    public const CATEGORIA_DEMAIS                 = 'DEMAIS';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $titulo;

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

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $uploadedAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $numero = null;

    #[ORM\ManyToOne(targetEntity: Cliente::class, inversedBy: 'documentos')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Cliente $cliente = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    /**
     * Documento VINCULADO a partir do menu Documentos (Inteligência cadastral, F-A): aponta para o arquivo da pasta,
     * sem cópia. O vinculado não tem arquivo próprio (`caminhoArquivo` vazio, `tamanhoBytes` 0): abrir e baixar usam o
     * do {@see PastaDocumento}, com a permissão da pasta; remover o vínculo nunca apaga o arquivo da pasta. A purga do
     * documento da pasta derruba o vínculo (`ON DELETE CASCADE`); na lixeira, ele só some da tela ({@see estaDisponivel}).
     */
    #[ORM\ManyToOne(targetEntity: PastaDocumento::class)]
    #[ORM\JoinColumn(name: 'pasta_documento_id', nullable: true, onDelete: 'CASCADE')]
    private ?PastaDocumento $pastaDocumento = null;

    public function __construct()
    {
        $this->uploadedAt = new \DateTimeImmutable();
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
        $this->titulo = $titulo;

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

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(?string $numero): self
    {
        $this->numero = $numero;

        return $this;
    }

    public function getCliente(): ?Cliente
    {
        return $this->cliente;
    }

    public function setCliente(?Cliente $cliente): self
    {
        $this->cliente = $cliente;

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

    /** Liga ao documento da pasta, sem arquivo próprio (ver {@see $pastaDocumento}). */
    public static function vinculadoA(PastaDocumento $origem, Cliente $cliente, Tenant $tenant): self
    {
        $doc = (new self())->setCliente($cliente)->setTenant($tenant)->setTitulo($origem->getTitulo())
            ->setCategoria($origem->getCategoria())->setNomeOriginal($origem->getNomeOriginal())
            ->setMimeType($origem->getMimeType())->setCaminhoArquivo('')->setTamanhoBytes(0);
        $doc->pastaDocumento = $origem;

        return $doc;
    }

    public function getPastaDocumento(): ?PastaDocumento
    {
        return $this->pastaDocumento;
    }

    public function isVinculado(): bool
    {
        return $this->pastaDocumento !== null;
    }

    /**
     * O vinculado só aparece enquanto o documento da pasta está vivo. Na lixeira, o `LixeiraFilter` esconde a linha e
     * a carga preguiçosa dele falha (`EntityNotFoundException`): para a tela, é o mesmo que excluído.
     */
    public function estaDisponivel(): bool
    {
        if ($this->pastaDocumento === null) {
            return true;
        }
        try {
            return !$this->pastaDocumento->estaNaLixeira();
        } catch (EntityNotFoundException) {
            return false;
        }
    }
}
