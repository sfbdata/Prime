<?php

declare(strict_types=1);

namespace App\Tenant\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use App\Tenant\Enum\MotivoDaSaida;
use App\Tenant\Repository\SaidaDaEquipeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Registro de que uma pessoa deixou o escritório (spec docs/specs/saida-da-equipe.md). A remoção apaga o vínculo
 * (`user_tenant`), então a data da saída mora aqui, gravada na mesma transação. Só inclusão: nada atualiza nem apaga
 * uma saída, a não ser a purga do escritório. Suspender ou mandar para análise não é saída (o vínculo continua).
 *
 * Lido pelo painel do Master (frente b5: saídas por UF nos últimos 30 dias, com o filtro "Só primários").
 *
 * Anatomia:
 *   - índice (tenant_id, saiu_em): saídas de um escritório por período;
 *   - índice (saiu_em): a contagem global do painel do Master, sem tenant;
 *   - `user_id` SET NULL: a saída sobrevive ao usuário (o painel cai na UF do escritório);
 *   - `tenant_id` NOT NULL sem ação: a purga apaga explicitamente (`PurgarEscritorioUseCase::ORDEM_DELECAO`).
 */
#[ORM\Entity(repositoryClass: SaidaDaEquipeRepository::class)]
#[ORM\Table(name: 'saida_da_equipe')]
#[ORM\Index(name: 'idx_saida_da_equipe_tenant_saiu_em', columns: ['tenant_id', 'saiu_em'])]
#[ORM\Index(name: 'idx_saida_da_equipe_saiu_em', columns: ['saiu_em'])]
class SaidaDaEquipe implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'saiu_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $saiuEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $usuario,
        #[ORM\Column(type: 'string', length: 20, enumType: MotivoDaSaida::class)]
        private MotivoDaSaida $motivo,
        #[ORM\Column(name: 'era_primario', type: 'boolean')]
        private bool $eraPrimario,
    ) {
        $this->saiuEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getUsuario(): ?User
    {
        return $this->usuario;
    }

    public function getMotivo(): MotivoDaSaida
    {
        return $this->motivo;
    }

    public function eraPrimario(): bool
    {
        return $this->eraPrimario;
    }

    public function getSaiuEm(): \DateTimeImmutable
    {
        return $this->saiuEm;
    }
}
