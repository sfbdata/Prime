<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\ResumoSemProcesso;
use App\Pasta\Repository\EnvioDoRelatorioSemProcessoRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Histórico do envio AUTOMÁTICO do relatório de pastas sem processo (desenho 1.2.3, `spEnviar`
 * L4566-4567, chave `bj-semproc-hist-v1`): a fotografia das contagens no dia do envio mensal.
 * Alimenta a tabela "Evolução" do relatório (os últimos 6 meses + Hoje).
 *
 * O envio manual ("Enviar à Controladoria agora") não grava aqui, como no desenho (L4567). Por isso
 * o UNIQUE (tenant, mes) basta para garantir um envio automático por mês e escritório.
 *
 * Fora do audit_log: é dado derivado das pastas, gravado pelo comando agendado, não ato de uma
 * pessoa (mesma natureza da foto diária do Dashboard).
 */
#[ORM\Entity(repositoryClass: EnvioDoRelatorioSemProcessoRepository::class)]
#[ORM\Table(name: 'relatorio_sem_processo_envio')]
#[ORM\UniqueConstraint(name: 'uniq_relatorio_sem_processo_envio_mes', columns: ['tenant_id', 'mes'])]
class EnvioDoRelatorioSemProcesso implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** "MM/AAAA", como o `mes` do desenho. */
    #[ORM\Column(length: 7)]
    private string $mes;

    #[ORM\Column(type: 'integer')]
    private int $sem;

    #[ORM\Column(type: 'integer')]
    private int $deveria;

    #[ORM\Column(type: 'integer')]
    private int $rever;

    #[ORM\Column(type: 'integer')]
    private int $classificar;

    #[ORM\Column(type: 'integer')]
    private int $admin;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $em;

    #[ORM\Column(options: ['default' => true])]
    private bool $automatico;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        ResumoSemProcesso $resumo,
        \DateTimeImmutable $em,
        bool $automatico,
    ) {
        $this->mes         = $em->format('m/Y');
        $this->sem         = $resumo->sem;
        $this->deveria     = $resumo->deveria;
        $this->rever       = $resumo->rever;
        $this->classificar = $resumo->classificar;
        $this->admin       = $resumo->admin;
        $this->em          = $em;
        $this->automatico  = $automatico;
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

    public function getEm(): \DateTimeImmutable
    {
        return $this->em;
    }

    public function isAutomatico(): bool
    {
        return $this->automatico;
    }

    public function resumo(): ResumoSemProcesso
    {
        return new ResumoSemProcesso($this->sem, $this->deveria, $this->rever, $this->classificar, $this->admin);
    }
}
