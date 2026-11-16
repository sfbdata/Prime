<?php

declare(strict_types=1);

namespace App\Conector\Entity;

use App\Conector\Repository\ConsentimentoDoConectorRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * A "conexão" que a pessoa vê e revoga no Meu Perfil (`docs/specs/mcp-remoto-oauth.md`, seção 4 e 13.2): a pessoa
 * autorizou um app de I.A. a agir em UM escritório, com estes escopos. Uma conexão = um escritório; para outro, outra
 * autorização.
 *
 * Uma ativa por pessoa, escritório e cliente: índice único parcial (`revogado_em IS NULL`). Cada autorização nova no
 * mesmo consentimento abre uma concessão nova (13.2). Revogar não apaga: a linha fica como histórico e os tokens que
 * nasceram dela param na requisição seguinte (regra 3 da seção 4). Auditável: conceder e revogar ficam no `audit_log`.
 * FKs em cascata para escritório, pessoa e app (9.9).
 */
#[ORM\Entity(repositoryClass: ConsentimentoDoConectorRepository::class)]
#[ORM\Table(name: 'conector_ia_consentimento')]
#[ORM\UniqueConstraint(name: 'uniq_conector_ia_consentimento_ativo', columns: ['user_id', 'tenant_id', 'cliente_id'], options: ['where' => '(revogado_em IS NULL)'])]
#[ORM\Index(name: 'idx_conector_ia_consentimento_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_conector_ia_consentimento_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_conector_ia_consentimento_cliente', columns: ['cliente_id'])]
class ConsentimentoDoConector implements TenantAware, Auditavel
{
    /** Segundos mínimos entre duas gravações do último uso. */
    public const INTERVALO_DO_ULTIMO_USO = 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'revogado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revogadoEm = null;

    #[ORM\Column(name: 'ultimo_uso_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ultimoUsoEm = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $escopos;

    /**
     * @param list<string> $escopos
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: ClienteDoConector::class)]
        #[ORM\JoinColumn(name: 'cliente_id', nullable: false, onDelete: 'CASCADE')]
        private ClienteDoConector $cliente,
        array $escopos,
        #[ORM\Column(name: 'concedido_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $concedidoEm,
    ) {
        $this->escopos = Escopos::exigir($escopos);
    }

    /** Revogar de novo não muda a data da primeira revogação. */
    public function revogar(\DateTimeImmutable $agora): void
    {
        if ($this->revogadoEm !== null) {
            return;
        }

        $this->revogadoEm = $agora;
    }

    public function isAtivo(): bool
    {
        return $this->revogadoEm === null;
    }

    /** Grava o último uso no máximo uma vez por minuto (13.2): não vira um UPDATE a cada chamada. */
    public function registrarUso(\DateTimeImmutable $agora): void
    {
        if ($this->ultimoUsoEm !== null && $agora->getTimestamp() - $this->ultimoUsoEm->getTimestamp() < self::INTERVALO_DO_ULTIMO_USO) {
            return;
        }

        $this->ultimoUsoEm = $agora;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getCliente(): ClienteDoConector
    {
        return $this->cliente;
    }

    /** @return list<string> */
    public function getEscopos(): array
    {
        return $this->escopos;
    }

    public function getConcedidoEm(): \DateTimeImmutable
    {
        return $this->concedidoEm;
    }

    public function getRevogadoEm(): ?\DateTimeImmutable
    {
        return $this->revogadoEm;
    }

    public function getUltimoUsoEm(): ?\DateTimeImmutable
    {
        return $this->ultimoUsoEm;
    }
}
