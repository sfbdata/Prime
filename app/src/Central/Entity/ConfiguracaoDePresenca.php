<?php

declare(strict_types=1);

namespace App\Central\Entity;

use App\Central\Repository\ConfiguracaoDePresencaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * A regra de presença do escritório (spec `presenca-e-ponto-no-cabecalho.md` §4, fase 3): depois de quantos minutos sem
 * atividade a pessoa aparece "ausente" (desenho 03 L.306 e L.685: 15, 30, 60 ou 120). Uma linha por escritório, criada na
 * primeira escolha do primário; sem linha vale o padrão (60). Auditável: é a regra do escritório, escolhida por alguém.
 */
#[ORM\Entity(repositoryClass: ConfiguracaoDePresencaRepository::class)]
#[ORM\Table(name: 'central_configuracao_presenca')]
#[ORM\UniqueConstraint(name: 'uniq_central_configuracao_presenca', columns: ['tenant_id'])]
class ConfiguracaoDePresenca implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'minutos_ausente', type: 'integer')]
    private int $minutosAusente = Presenca::INATIVO_APOS_MINUTOS;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradoPor = null;

    #[ORM\Column(name: 'alterado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $alteradoEm = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
    ) {
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getMinutosAusente(): int
    {
        return $this->minutosAusente;
    }

    public function alterar(int $minutos, User $por, \DateTimeImmutable $agora): void
    {
        $this->minutosAusente = $minutos;
        $this->alteradoPor = $por;
        $this->alteradoEm = $agora;
    }
}
