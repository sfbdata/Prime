<?php

declare(strict_types=1);

namespace App\Central\Entity;

use App\Central\Repository\PresencaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Presença na Central (F2, `docs/specs/central-comunicacao-painel-presenca.md`): o último batimento de cada pessoa
 * NESTE escritório e o status que ela escolheu. Uma linha por vínculo (usuário × escritório): quem trabalha em dois
 * escritórios tem presença separada em cada um.
 *
 * Gravada só pelo batimento (`PresencaRepository::registrar`, upsert); on-line = batimento há menos de
 * `PresencaRepository::ONLINE_SEGUNDOS`. Não é `Auditavel`: é atividade, não dado de negócio.
 */
#[ORM\Entity(repositoryClass: PresencaRepository::class)]
#[ORM\Table(name: 'central_presenca')]
#[ORM\Index(name: 'idx_central_presenca_usuario', columns: ['usuario_id'])]
#[ORM\UniqueConstraint(name: 'uniq_central_presenca', columns: ['tenant_id', 'usuario_id'])]
class Presenca implements TenantAware
{
    public const DISPONIVEL = 'disponivel';
    public const OCUPADO = 'ocupado';
    public const AUSENTE = 'ausente';
    /**
     * Ausente por falta de atividade (spec `presenca-e-ponto-no-cabecalho.md` §2): quem manda é o navegador da pessoa,
     * depois de `INATIVO_APOS_MINUTOS` sem mexer em nada. Separado do `ausente` escolhido para o servidor saber
     * dizer "sem atividade há N min".
     */
    public const INATIVO = 'inativo';
    public const STATUS = [self::DISPONIVEL, self::OCUPADO, self::AUSENTE, self::INATIVO];
    /**
     * Limite de inatividade padrão (desenho 03, 60 min). Desde a fase 3, o primário escolhe o do escritório entre as
     * opções do desenho ({@see \App\Central\Entity\ConfiguracaoDePresenca}); sem escolha, vale este.
     */
    public const INATIVO_APOS_MINUTOS = 60;
    /** As opções do seletor "Ausente após sem atividade" (desenho 03 L.685). */
    public const OPCOES_DE_AUSENCIA = [15, 30, 60, 120];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\Column(name: 'ultima_atividade', type: 'datetime_immutable')]
        private \DateTimeImmutable $ultimaAtividade,
        #[ORM\Column(type: 'string', length: 10, options: ['default' => self::DISPONIVEL])]
        private string $status = self::DISPONIVEL,
        #[ORM\Column(name: 'status_desde', type: 'datetime_immutable')]
        private ?\DateTimeImmutable $statusDesde = null,
    ) {
        $this->statusDesde ??= $ultimaAtividade;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getUltimaAtividade(): \DateTimeImmutable
    {
        return $this->ultimaAtividade;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStatusDesde(): \DateTimeImmutable
    {
        return $this->statusDesde ?? $this->ultimaAtividade;
    }
}
