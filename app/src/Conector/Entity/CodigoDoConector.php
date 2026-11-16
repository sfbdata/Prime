<?php

declare(strict_types=1);

namespace App\Conector\Entity;

use App\Conector\Repository\CodigoDoConectorRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Código de autorização (`docs/specs/mcp-remoto-oauth.md`, 9.6 e 13.2): nasce no `/oauth/authorize` junto com a
 * concessão e é trocado pelo primeiro par de tokens no `/oauth/token`. Guarda só o hash do código.
 *
 * Preso à concessão (escritório, escopos, `resource`), ao `redirect_uri` e ao `code_challenge`, que é só S256: 43
 * caracteres base64url, sem `=`. Vale até 10 minutos e é de uso único: {@see usar()} recusa o vencido e o já usado.
 */
#[ORM\Entity(repositoryClass: CodigoDoConectorRepository::class)]
#[ORM\Table(name: 'conector_ia_codigo')]
#[ORM\UniqueConstraint(name: 'uniq_conector_ia_codigo_identificador', columns: ['identificador_hash'])]
#[ORM\Index(name: 'idx_conector_ia_codigo_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_conector_ia_codigo_concessao', columns: ['concessao_id'])]
class CodigoDoConector implements TenantAware
{
    use ConfereHash;

    /** O código vale 10 minutos a partir de quando foi emitido. */
    public const VALIDADE = 'PT10M';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'identificador_hash', length: 64)]
    private string $identificadorHash;

    /** Copiado da concessão. */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(name: 'code_challenge', length: 43)]
    private string $codeChallenge;

    #[ORM\Column(name: 'expira_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiraEm;

    #[ORM\Column(name: 'usado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usadoEm = null;

    public function __construct(
        string $identificadorHash,
        #[ORM\ManyToOne(targetEntity: ConcessaoDoConector::class)]
        #[ORM\JoinColumn(name: 'concessao_id', nullable: false, onDelete: 'CASCADE')]
        private ConcessaoDoConector $concessao,
        #[ORM\Column(name: 'redirect_uri', type: 'text')]
        private string $redirectUri,
        string $codeChallenge,
        #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $criadoEm,
    ) {
        if (!$concessao->estaValidaEm($criadoEm)) {
            throw new \DomainException('Concessão revogada ou vencida não emite código.');
        }
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $codeChallenge) !== 1) {
            throw new \InvalidArgumentException('O code_challenge precisa ser S256 (43 caracteres base64url).');
        }
        $this->identificadorHash = self::exigirHash($identificadorHash, 'identificador_hash');
        $this->tenant = $concessao->getTenant() ?? throw new \LogicException('Concessão sem escritório.');
        $this->codeChallenge = $codeChallenge;
        $this->expiraEm = $criadoEm->add(new \DateInterval(self::VALIDADE));
    }

    /** Marca o código como usado. Usado ou vencido: recusa, sem mudar nada. */
    public function usar(\DateTimeImmutable $agora): void
    {
        if ($this->usadoEm !== null) {
            throw new \DomainException('O código de autorização já foi usado.');
        }
        if ($this->venceuEm($agora)) {
            throw new \DomainException('O código de autorização venceu.');
        }

        $this->usadoEm = $agora;
    }

    public function venceuEm(\DateTimeImmutable $agora): bool
    {
        return $agora >= $this->expiraEm;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdentificadorHash(): string
    {
        return $this->identificadorHash;
    }

    public function getConcessao(): ConcessaoDoConector
    {
        return $this->concessao;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    public function getCodeChallenge(): string
    {
        return $this->codeChallenge;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getExpiraEm(): \DateTimeImmutable
    {
        return $this->expiraEm;
    }

    public function getUsadoEm(): ?\DateTimeImmutable
    {
        return $this->usadoEm;
    }
}
