<?php

declare(strict_types=1);

namespace App\Sync\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use App\Sync\Repository\TenantDriveConexaoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Conexão do Google Drive de UM escritório (tenant) — o pré-requisito "Conectar meu Drive"
 * da Fase 2 do sync. Uma linha por tenant (JoinColumn único): guarda o `refreshToken` OAuth
 * CIFRADO em repouso (ver {@see \App\Sync\Service\CifradorDeSegredo}) e o id da pasta-raiz do
 * acervo daquele escritório no Drive. Alimenta a fábrica de client por tenant
 * ({@see \App\Sync\Service\GoogleDriveClientFactory}).
 *
 * Auditável (quem conectou/desconectou e quando). O campo `refreshTokenCifrado` está na lista
 * de campos ignorados do {@see \App\Service\Audit\AuditLogSubscriber} — o blob do token, ainda
 * que cifrado, nunca é gravado no audit_log.
 */
#[ORM\Entity(repositoryClass: TenantDriveConexaoRepository::class)]
#[ORM\Table(name: 'sync_drive_conexao')]
#[ORM\HasLifecycleCallbacks]
class TenantDriveConexao implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Uma conexão por escritório (unique). Owning side — não toca a entidade Tenant (legado). */
    #[ORM\OneToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?Tenant $tenant = null;

    /** `base64(nonce || ciphertext)` do refresh_token OAuth. NUNCA texto puro. */
    #[ORM\Column(type: 'text')]
    private string $refreshTokenCifrado = '';

    /** Id da pasta-raiz do acervo do escritório no Drive. Nulo até o admin informar. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $rootFolderId = null;

    /** E-mail da conta Google conectada, só para exibir na tela. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contaEmail = null;

    /** Escopo OAuth concedido (ex.: `.../auth/drive`). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $escopo = null;

    /** Só fica ativa (usável pelo sync) quando token E pasta-raiz estão presentes. */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $ativo = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $conectadoEm;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $conectadoPor = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    public function __construct(Tenant $tenant)
    {
        $this->tenant = $tenant;
        $this->conectadoEm = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    /**
     * Registra/atualiza as credenciais OAuth vindas do callback. Não ativa por si só —
     * a conexão só vira usável quando a pasta-raiz também é definida ({@see definirRootFolder()}).
     */
    public function registrarCredenciais(string $refreshTokenCifrado, ?string $contaEmail, ?string $escopo, User $por): void
    {
        $this->refreshTokenCifrado = $refreshTokenCifrado;
        $this->contaEmail = $contaEmail;
        $this->escopo = $escopo;
        $this->conectadoPor = $por;
    }

    /** Define a pasta-raiz e ativa a conexão (token já presente é pré-condição). */
    public function definirRootFolder(string $rootFolderId): void
    {
        $this->rootFolderId = $rootFolderId;
        $this->ativo = $this->refreshTokenCifrado !== '' && $rootFolderId !== '';
    }

    /** Desconecta: some com o token e desativa. Mantém a linha para histórico de quem/quando. */
    public function desconectar(): void
    {
        $this->refreshTokenCifrado = '';
        $this->rootFolderId = null;
        $this->escopo = null;
        $this->ativo = false;
    }

    /** Pronta para o sync usar (token cifrado + pasta-raiz + ativa). */
    public function estaPronta(): bool
    {
        return $this->ativo && $this->refreshTokenCifrado !== '' && $this->rootFolderId !== null && $this->rootFolderId !== '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getRefreshTokenCifrado(): string
    {
        return $this->refreshTokenCifrado;
    }

    public function getRootFolderId(): ?string
    {
        return $this->rootFolderId;
    }

    public function getContaEmail(): ?string
    {
        return $this->contaEmail;
    }

    public function getEscopo(): ?string
    {
        return $this->escopo;
    }

    public function isAtivo(): bool
    {
        return $this->ativo;
    }

    public function getConectadoEm(): \DateTimeImmutable
    {
        return $this->conectadoEm;
    }

    public function getConectadoPor(): ?User
    {
        return $this->conectadoPor;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
    }
}
