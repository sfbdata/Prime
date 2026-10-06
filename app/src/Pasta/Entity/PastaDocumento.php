<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\Descartavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PastaDocumentoRepository::class)]
#[ORM\Table(name: 'pasta_documento')]
#[ORM\Index(name: 'idx_pasta_documento_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_pasta_documento_tenant_sha256', columns: ['tenant_id', 'sha256'])]
// Parcial: só as linhas na lixeira entram — é a fila da purga, pequena por natureza. O `where` vai
// entre parênteses porque é assim que o PostgreSQL devolve a expressão ao `schema:validate`.
#[ORM\Index(name: 'idx_pasta_documento_lixeira', columns: ['excluido_em'], options: ['where' => '(excluido_em IS NOT NULL)'])]
#[ORM\UniqueConstraint(name: 'uniq_pasta_documento_drive_file_id', columns: ['drive_file_id'])]
class PastaDocumento implements Auditavel, TenantAware, Descartavel
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

    #[ORM\Column(name: 'drive_file_id', length: 255, nullable: true)]
    private ?string $driveFileId = null;

    /**
     * SHA-256 (hex minúsculo, 64 chars) dos bytes que ESTÃO no armazenamento — depois da
     * compressão, quando houve. É a base dos "arquivos duplicados": dois documentos com o mesmo
     * hash no mesmo escritório têm conteúdo idêntico. NULL = ainda não calculado (acervo anterior
     * à coluna, preenchido por `app:documentos:calcular-hash`), nunca "sem integridade".
     */
    #[ORM\Column(name: 'sha256', length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $sha256 = null;

    /**
     * Quem enviou o arquivo (D1). NULL no acervo anterior à coluna e nos caminhos que não têm
     * usuário (importação do acervo, reconciliação do Drive). `ON DELETE SET NULL`: o usuário sair
     * do sistema não apaga o documento nem o registro de que ele existiu.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'enviado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $enviadoPor = null;

    /**
     * Última edição dos METADADOS (nome, categoria, número, descrição). NULL = nunca editado desde
     * o upload; a tela mostra então `carregadoEm`. Mover de pasta não conta como modificação.
     */
    #[ORM\Column(name: 'modificado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $modificadoEm = null;

    /**
     * Páginas do PDF, contadas pelo Ghostscript no upload (D1). NULL = não é PDF, ou a contagem
     * falhou/ainda não foi feita (`app:documentos:calcular-hash --paginas` preenche o acervo).
     * Nunca "zero páginas": um PDF sem página não é contado, é NULL.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $paginas = null;

    /**
     * Lixeira (D7, L7): preenchido = o documento foi "excluído" pela tela e está na lixeira — a
     * linha e o arquivo físico FICAM até a purga (`app:documentos:purgar-lixeira`). NULL = vivo.
     * O `LixeiraFilter` esconde as linhas preenchidas de toda leitura comum. Sem setter de
     * propósito: entra pela `marcarExcluido()` e sai pela `restaurar()` — o desfazer da auditoria
     * (que grava por `set<Campo>`) não ressuscita nem apaga por fora da lixeira.
     */
    #[ORM\Column(name: 'excluido_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $excluidoEm = null;

    /** Quem mandou para a lixeira. `SET NULL`: o usuário sair não apaga a lápide. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'excluido_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $excluidoPor = null;

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

    public function getDriveFileId(): ?string
    {
        return $this->driveFileId;
    }

    public function setDriveFileId(?string $driveFileId): self
    {
        $this->driveFileId = $driveFileId;

        return $this;
    }

    public function getSha256(): ?string
    {
        return $this->sha256;
    }

    /**
     * Aceita só o formato que `hash('sha256', …)` produz. Um hash fora do formato (maiúsculo,
     * truncado, com espaço) nunca casaria com outro e viraria "sem duplicado" silencioso — por
     * isso é recusado, não normalizado.
     */
    public function setSha256(?string $sha256): self
    {
        if ($sha256 !== null && preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw new \InvalidArgumentException('sha256 inválido: esperado hex minúsculo de 64 caracteres.');
        }

        $this->sha256 = $sha256;

        return $this;
    }

    public function getEnviadoPor(): ?User
    {
        return $this->enviadoPor;
    }

    public function setEnviadoPor(?User $enviadoPor): self
    {
        $this->enviadoPor = $enviadoPor;

        return $this;
    }

    public function getModificadoEm(): ?\DateTimeImmutable
    {
        return $this->modificadoEm;
    }

    /** Registra uma edição de metadados; quem chama decide o instante (relógio injetável). */
    public function marcarModificadoEm(\DateTimeImmutable $instante): self
    {
        $this->modificadoEm = $instante;

        return $this;
    }

    public function getPaginas(): ?int
    {
        return $this->paginas;
    }

    /**
     * Só inteiro positivo ou NULL. Zero ou negativo é contagem que não aconteceu — e gravá-la
     * faria a tela mostrar "0 páginas" num PDF de verdade. Recusado, não normalizado (como o sha256).
     */
    public function setPaginas(?int $paginas): self
    {
        if ($paginas !== null && $paginas < 1) {
            throw new \InvalidArgumentException('paginas inválido: esperado inteiro positivo ou NULL.');
        }

        $this->paginas = $paginas;

        return $this;
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

    // ── Lixeira (D7) ────────────────────────────────────────────────────────────

    public function estaNaLixeira(): bool
    {
        return $this->excluidoEm !== null;
    }

    public function getExcluidoEm(): ?\DateTimeImmutable
    {
        return $this->excluidoEm;
    }

    public function getExcluidoPor(): ?User
    {
        return $this->excluidoPor;
    }

    /**
     * Manda para a lixeira: guarda quem e quando. O instante vem de fora porque uma seção excluída
     * carimba toda a subárvore com o MESMO valor — é por ele que o restaurar devolve o bloco inteiro.
     *
     * Recusa a segunda marcação: sobrescrever apagaria o autor e a data da exclusão de verdade
     * (e reiniciaria a contagem dos 30 dias da purga).
     */
    public function marcarExcluido(User $por, \DateTimeImmutable $em): self
    {
        if ($this->estaNaLixeira()) {
            throw new \LogicException('Este documento já está na lixeira.');
        }

        $this->excluidoEm  = $em;
        $this->excluidoPor = $por;

        return $this;
    }

    /** Tira da lixeira. Quem chama decide se a seção de origem ainda existe (senão, vai para a raiz). */
    public function restaurar(): self
    {
        if (!$this->estaNaLixeira()) {
            throw new \LogicException('Este documento não está na lixeira.');
        }

        $this->excluidoEm  = null;
        $this->excluidoPor = null;

        return $this;
    }
}
