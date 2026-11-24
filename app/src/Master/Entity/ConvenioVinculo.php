<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Enum\MotivoDoFimDoVinculo;
use App\Master\Enum\OrigemDoVinculo;
use App\Master\Exception\ConvenioRecusado;
use App\Master\Repository\ConvenioVinculoRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um escritório dentro de um {@see Convenio} (spec docs/specs/convenios.md, §3.2). Sair e voltar cria linha nova: a
 * aberta (`fim` nulo) é única por escritório e convênio (índice parcial).
 *
 * NÃO é `TenantAware`, pelo mesmo motivo do `BonusDeEspaco` (D12): o Master no escritório X lê e encerra o vínculo de
 * Y, e a sincronização roda no worker. Só o `ConvenioVinculoRepository` consulta, sempre por tenant explícito, e
 * `ConvenioVinculoSoNoRepositorioArquiteturaTest` impede consulta em outro lugar. A purga do escritório apaga as linhas.
 *
 * Auditável: entrada e saída ficam na Trilha.
 */
#[ORM\Entity(repositoryClass: ConvenioVinculoRepository::class)]
#[ORM\Table(name: 'convenio_vinculo')]
#[ORM\UniqueConstraint(name: 'uniq_convenio_vinculo_aberto', columns: ['tenant_id', 'convenio_id'], options: ['where' => '(fim IS NULL)'])]
#[ORM\Index(name: 'idx_convenio_vinculo_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_convenio_vinculo_convenio_aberto', columns: ['convenio_id'], options: ['where' => '(fim IS NULL)'])]
#[ORM\Index(name: 'idx_convenio_vinculo_criado_por', columns: ['criado_por_id'])]
#[ORM\Index(name: 'idx_convenio_vinculo_encerrado_por', columns: ['encerrado_por_id'])]
class ConvenioVinculo implements Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $fim = null;

    /** O dia em que o benefício termina, marcado no aviso prévio (§6.2). */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $fimPrevisto = null;

    #[ORM\Column(length: 20, nullable: true, enumType: MotivoDoFimDoVinculo::class)]
    private ?MotivoDoFimDoVinculo $motivoFim = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $avisoEnviadoEm = null;

    /** OAB divergente: desde quando falta a prova (30 dias antes de fechar, §5.2). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $provaPendenteDesde = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'encerrado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $encerradoPor = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Convenio::class)]
        #[ORM\JoinColumn(name: 'convenio_id', nullable: false, onDelete: 'RESTRICT')]
        private Convenio $convenio,
        #[ORM\Column(length: 10, enumType: OrigemDoVinculo::class)]
        private OrigemDoVinculo $origem,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $inicio,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'criado_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $criadoPor = null,
    ) {
    }

    /** Fecha o vínculo. Nada é apagado: a linha fica como histórico. */
    public function encerrar(MotivoDoFimDoVinculo $motivo, \DateTimeImmutable $em, ?User $por = null): void
    {
        if ($this->fim !== null) {
            throw new ConvenioRecusado('Este vínculo já foi encerrado.');
        }
        if ($em < $this->inicio) {
            throw new ConvenioRecusado('O fim do vínculo não pode ser antes do início.');
        }
        $this->fim = $em;
        $this->motivoFim = $motivo;
        $this->encerradoPor = $por;
    }

    /**
     * Falta a prova (a OAB do criador do escritório está divergente ou sumiu, §5.2): marca o começo da espera de 30 dias.
     * Devolve se mudou.
     */
    public function marcarProvaPendente(\DateTimeImmutable $desde): bool
    {
        if ($this->provaPendenteDesde !== null) {
            return false;
        }
        $this->provaPendenteDesde = $desde;

        return true;
    }

    /** A prova voltou antes do prazo: a marca some. Devolve se mudou. */
    public function limparProvaPendente(): bool
    {
        if ($this->provaPendenteDesde === null) {
            return false;
        }
        $this->provaPendenteDesde = null;

        return true;
    }

    /** O convênio vai terminar (§6.2): grava o dia previsto e quando o aviso saiu. Devolve se o dia mudou. */
    public function avisarFim(\DateTimeImmutable $fimPrevisto, \DateTimeImmutable $avisoEm): bool
    {
        if ($this->fimPrevisto?->format('Y-m-d') === $fimPrevisto->format('Y-m-d')) {
            return false;
        }
        $this->fimPrevisto = $fimPrevisto;
        $this->avisoEnviadoEm = $avisoEm;

        return true;
    }

    /** O fim foi cancelado (§6.2, item 5). Devolve se havia fim previsto (quem foi avisado recebe o "continua"). */
    public function cancelarFimPrevisto(): bool
    {
        if ($this->fimPrevisto === null) {
            return false;
        }
        $this->fimPrevisto = null;

        return true;
    }

    public function estaAberto(): bool
    {
        return $this->fim === null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getConvenio(): Convenio
    {
        return $this->convenio;
    }

    public function getOrigem(): OrigemDoVinculo
    {
        return $this->origem;
    }

    public function getInicio(): \DateTimeImmutable
    {
        return $this->inicio;
    }

    public function getFim(): ?\DateTimeImmutable
    {
        return $this->fim;
    }

    public function getFimPrevisto(): ?\DateTimeImmutable
    {
        return $this->fimPrevisto;
    }

    public function getMotivoFim(): ?MotivoDoFimDoVinculo
    {
        return $this->motivoFim;
    }

    public function getAvisoEnviadoEm(): ?\DateTimeImmutable
    {
        return $this->avisoEnviadoEm;
    }

    public function getProvaPendenteDesde(): ?\DateTimeImmutable
    {
        return $this->provaPendenteDesde;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function getEncerradoPor(): ?User
    {
        return $this->encerradoPor;
    }
}
