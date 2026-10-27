<?php

declare(strict_types=1);

namespace App\Contribua\Entity;

use App\Contribua\Repository\CobrancaPixDaContaRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cobrança PIX aberta no banco para uma conta e um valor (QR dinâmico, C4). Reaproveitada enquanto não
 * vence, para trocar de valor não criar uma cobrança por clique. Vira `Apoio` só quando o banco confirma.
 */
#[ORM\Entity(repositoryClass: CobrancaPixDaContaRepository::class)]
#[ORM\Table(name: 'contribua_cobranca_pix')]
#[ORM\Index(name: 'idx_contribua_cobranca_pix_conta', columns: ['tenant_id', 'valor_centavos', 'situacao'])]
#[ORM\UniqueConstraint(name: 'uniq_contribua_cobranca_pix_banco', columns: ['banco', 'id_no_banco'])]
class CobrancaPixDaConta implements TenantAware
{
    public const ABERTA = 'aberta';
    public const PAGA = 'paga';
    public const CANCELADA = 'cancelada';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'id_no_banco', length: 120, nullable: true)]
    private ?string $idNoBanco = null;

    #[ORM\Column(name: 'copia_e_cola', type: 'text', nullable: true)]
    private ?string $copiaECola = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $codigo = null;

    #[ORM\Column(length: 12)]
    private string $situacao = self::ABERTA;

    #[ORM\Column(name: 'criada_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\Column(length: 20)]
        private string $banco,
        /** Ambiente em que nasceu (sandbox ou producao): só é servida e consultada nele. */
        #[ORM\Column(length: 20)]
        private string $ambiente,
        #[ORM\Column(name: 'valor_centavos', type: 'integer')]
        private int $valorCentavos,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $vencimento,
        /** Gravada antes de chamar o banco: repetir a chamada não cria outra cobrança. */
        #[ORM\Column(name: 'chave_de_idempotencia', length: 64)]
        private string $chaveDeIdempotencia,
    ) {
        $this->criadaEm = new \DateTimeImmutable();
    }

    /** O nosso código (`BJ<conta>-<id>`), que o banco devolve em toda consulta. */
    public function definirCodigo(string $numeroDaConta): string
    {
        return $this->codigo ??= 'BJ' . $numeroDaConta . '-' . $this->id;
    }

    public function registrarNoBanco(string $idNoBanco, string $copiaECola): void
    {
        $this->idNoBanco = $idNoBanco;
        $this->copiaECola = $copiaECola;
    }

    public function marcarPaga(): void
    {
        $this->situacao = self::PAGA;
    }

    public function marcarCancelada(): void
    {
        $this->situacao = self::CANCELADA;
    }

    /** Aberta e não vencida (pode ainda não ter o copia e cola: o banco falhou e a criação será repetida). */
    public function serveEm(\DateTimeImmutable $hoje): bool
    {
        return self::ABERTA === $this->situacao && $this->vencimento >= $hoje->setTime(0, 0);
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getAmbiente(): string
    {
        return $this->ambiente;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getBanco(): string
    {
        return $this->banco;
    }

    public function getValorCentavos(): int
    {
        return $this->valorCentavos;
    }

    public function getVencimento(): \DateTimeImmutable
    {
        return $this->vencimento;
    }

    public function getChaveDeIdempotencia(): string
    {
        return $this->chaveDeIdempotencia;
    }

    public function getIdNoBanco(): ?string
    {
        return $this->idNoBanco;
    }

    public function getCopiaECola(): ?string
    {
        return $this->copiaECola;
    }

    public function getCodigo(): ?string
    {
        return $this->codigo;
    }

    public function getSituacao(): string
    {
        return $this->situacao;
    }
}
