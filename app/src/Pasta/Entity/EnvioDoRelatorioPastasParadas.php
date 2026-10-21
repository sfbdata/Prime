<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\RelatorioPastasParadasOutput;
use App\Pasta\Repository\EnvioDoRelatorioPastasParadasRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * O envio automático do relatório mensal de pastas paradas (spec docs/specs/pastas-paradas.md,
 * "Envio automático"; desenho 02, `paradasEnviar` com a chave `bj-paradas-envio-v1`): um por mês e
 * escritório, com as contagens dos quatro cartões no dia do envio.
 *
 * É a trava do "uma vez por mês": o UNIQUE (tenant, mes) e a gravação por `INSERT ... ON CONFLICT DO
 * NOTHING` ANTES dos avisos (ver o repositório) fazem dois crons sobrepostos enviarem uma vez só.
 *
 * Fora do audit_log: é dado derivado das pastas, gravado pelo comando agendado, não ato de uma
 * pessoa (como o histórico do relatório de pastas sem processo).
 */
#[ORM\Entity(repositoryClass: EnvioDoRelatorioPastasParadasRepository::class)]
#[ORM\Table(name: 'relatorio_pastas_paradas_envio')]
#[ORM\UniqueConstraint(name: 'uniq_relatorio_pastas_paradas_envio_mes', columns: ['tenant_id', 'mes'])]
class EnvioDoRelatorioPastasParadas implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** "MM/AAAA". */
    #[ORM\Column(length: 7)]
    private string $mes;

    #[ORM\Column(type: 'integer')]
    private int $paradas;

    #[ORM\Column(name: 'mais_de_90', type: 'integer')]
    private int $maisDe90;

    #[ORM\Column(name: 'sem_contrato', type: 'integer')]
    private int $semContrato;

    #[ORM\Column(name: 'sem_processo', type: 'integer')]
    private int $semProcesso;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $em;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        RelatorioPastasParadasOutput $relatorio,
        \DateTimeImmutable $em,
    ) {
        $this->mes         = self::mesDe($em);
        $this->paradas     = \count($relatorio->pastas);
        $this->maisDe90    = $relatorio->maisDe90();
        $this->semContrato = $relatorio->semContrato();
        $this->semProcesso = $relatorio->semProcesso();
        $this->em          = $em;
    }

    /** A chave do mês ("MM/AAAA"), a mesma da gravação por SQL. */
    public static function mesDe(\DateTimeImmutable $quando): string
    {
        return $quando->format('m/Y');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getMes(): string
    {
        return $this->mes;
    }

    public function getParadas(): int
    {
        return $this->paradas;
    }

    public function getMaisDe90(): int
    {
        return $this->maisDe90;
    }

    public function getSemContrato(): int
    {
        return $this->semContrato;
    }

    public function getSemProcesso(): int
    {
        return $this->semProcesso;
    }

    public function getEm(): \DateTimeImmutable
    {
        return $this->em;
    }
}
