<?php

declare(strict_types=1);

namespace App\Voto\Entity;

use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\String\u;

/**
 * Unidade da assembleia de condomínio (spec docs/specs/voto-assembleia-condominio.md, seções 1, 3 e 4): quem vota, pelo
 * link pessoal `/votar/{token}`.
 *
 * Apta se a situação for vazia ou apto, apta, adimplente, regular, quite (sem acento e sem caixa; regra da importação
 * do desenho, N97). Inapta fica na lista e na ata e não vota.
 *
 * O link: o banco guarda a semente e o hash do token, nunca o token (App\Voto\Service\TokenDaUnidade). "Gerar novo
 * link" troca a semente ({@see trocarSemente()}) e o link anterior deixa de valer.
 *
 * Não é `Auditavel`: o cadastro da unidade (responsável, e-mail, semente) não vai para o audit_log. O tenant vem da
 * assembleia (nunca de fora).
 */
#[ORM\Entity(repositoryClass: UnidadeDaAssembleiaRepository::class)]
#[ORM\Table(name: 'assembleia_unidade', schema: 'voto')]
#[ORM\UniqueConstraint(name: 'uniq_voto_assembleia_unidade_token', columns: ['token_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_voto_assembleia_unidade_identificacao', columns: ['assembleia_id', 'bloco', 'identificacao'])]
class UnidadeDaAssembleia implements TenantAware
{
    /** Situações que permitem votar, já sem acento e em minúsculas. Vazia também permite. */
    private const SITUACOES_APTAS = ['apto', 'apta', 'adimplente', 'regular', 'quite'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(type: 'boolean')]
    private bool $apta;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $linkEnviadoEm = null;

    /**
     * @param string      $semente   32 hex (TokenDaUnidade::novaSemente())
     * @param string      $tokenHash 64 hex (TokenDaUnidade::hash(TokenDaUnidade::token($semente)))
     * @param string|null $fracao    decimal com ponto ("0.025000"); obrigatória quando o peso é a fração ideal
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Assembleia::class, inversedBy: 'unidades')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Assembleia $assembleia,
        #[ORM\Column(length: 80)]
        private string $identificacao,
        #[ORM\Column(length: 32, options: ['fixed' => true])]
        private string $semente,
        #[ORM\Column(length: 64, options: ['fixed' => true])]
        private string $tokenHash,
        #[ORM\Column(length: 40, options: ['default' => ''])]
        private string $bloco = '',
        #[ORM\Column(length: 40, nullable: true)]
        private ?string $situacao = null,
        #[ORM\Column(type: 'decimal', precision: 12, scale: 6, nullable: true)]
        private ?string $fracao = null,
        #[ORM\Column(length: 160, nullable: true)]
        private ?string $responsavel = null,
        #[ORM\Column(length: 180, nullable: true)]
        private ?string $email = null,
    ) {
        $this->tenant = $assembleia->getTenant();
        $this->apta = self::situacaoPermiteVotar($situacao);
        $assembleia->registrarUnidade($this);
    }

    /** Vazia ou apto, apta, adimplente, regular, quite, sem acento e sem caixa. */
    public static function situacaoPermiteVotar(?string $situacao): bool
    {
        $normalizada = u($situacao ?? '')->ascii()->lower()->trim()->toString();

        return '' === $normalizada || \in_array($normalizada, self::SITUACOES_APTAS, true);
    }

    /** "Gerar novo link": o anterior deixa de valer e a unidade volta a "ainda não recebeu" o link por e-mail. */
    public function trocarSemente(string $semente, string $tokenHash): void
    {
        $this->semente = $semente;
        $this->tokenHash = $tokenHash;
        $this->linkEnviadoEm = null;
    }

    public function registrarEnvioDoLink(?\DateTimeImmutable $em = null): void
    {
        $this->linkEnviadoEm = $em ?? new \DateTimeImmutable();
    }

    /**
     * Peso do voto desta unidade, decimal com 6 casas em string: a fração (0 se faltar) quando a assembleia vota por
     * fração ideal; 1 quando é uma unidade, um voto.
     */
    public function pesoDoVoto(): string
    {
        if (PesoDoVoto::Unidade === $this->assembleia->getPeso()) {
            return '1.000000';
        }

        return bcadd($this->fracao ?? '0', '0', 6);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getAssembleia(): Assembleia
    {
        return $this->assembleia;
    }

    public function getIdentificacao(): string
    {
        return $this->identificacao;
    }

    public function getBloco(): string
    {
        return $this->bloco;
    }

    public function getSituacao(): ?string
    {
        return $this->situacao;
    }

    public function isApta(): bool
    {
        return $this->apta;
    }

    public function getFracao(): ?string
    {
        return $this->fracao;
    }

    public function getResponsavel(): ?string
    {
        return $this->responsavel;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getSemente(): string
    {
        return $this->semente;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getLinkEnviadoEm(): ?\DateTimeImmutable
    {
        return $this->linkEnviadoEm;
    }
}
