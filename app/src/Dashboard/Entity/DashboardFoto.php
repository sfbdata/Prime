<?php

declare(strict_types=1);

namespace App\Dashboard\Entity;

use App\Dashboard\Repository\DashboardFotoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Foto diária das métricas de ESTOQUE do Dashboard, por colaborador: quantas metas ativas,
 * demandas ativas, metas vencidas e prazos próximos ele tinha no fim do dia `referencia`.
 *
 * Por que existe: essas quatro colunas medem o que está aberto NUM INSTANTE (status/situação
 * de hoje, prazo relativo a agora) e não dá para reconstruir o passado — a meta concluída
 * depois de uma data some da contagem antiga, e `dataConclusao` é nula no legado. A única
 * comparação honesta é fotografar daqui para frente (`app:dashboard:fotografar`) e comparar
 * com a foto da véspera do período (`data_de − 1 dia`). Sem foto, o painel não mostra
 * tendência nenhuma para essas métricas.
 *
 * É dado DERIVADO (recalculável a qualquer momento a partir de tarefa/pasta, só que no
 * presente): não é auditado e é regravado sem cerimônia (ON CONFLICT DO UPDATE).
 *
 * Anatomia:
 *   - UNIQUE (tenant_id, user_id, referencia): uma foto por pessoa por dia em cada escritório —
 *     é o árbitro do upsert e o índice da leitura do painel;
 *   - índice (user_id): o CASCADE do usuário não varre a tabela;
 *   - `user_id` CASCADE: a foto não sobrevive ao usuário;
 *   - `tenant_id` NOT NULL sem ação: a purga do escritório apaga explicitamente
 *     (`PurgarEscritorioUseCase::ORDEM_DELECAO`).
 *
 * Nomes de índice explícitos para o `doctrine:schema:validate` bater com a migration escrita à
 * mão (`Version20261006171500`).
 */
#[ORM\Entity(repositoryClass: DashboardFotoRepository::class)]
#[ORM\Table(name: 'dashboard_foto')]
#[ORM\Index(name: 'idx_dashboard_foto_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_dashboard_foto_tenant_user_ref', columns: ['tenant_id', 'user_id', 'referencia'])]
class DashboardFoto implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Momento em que os números foram tirados (regravado quando o dia é refotografado). */
    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $referencia,
        #[ORM\Column(name: 'metas_ativas', type: 'integer')]
        private int $metasAtivas,
        #[ORM\Column(name: 'demandas_ativas', type: 'integer')]
        private int $demandasAtivas,
        #[ORM\Column(name: 'metas_vencidas', type: 'integer')]
        private int $metasVencidas,
        #[ORM\Column(name: 'prazos_proximos', type: 'integer')]
        private int $prazosProximos,
    ) {
        $this->criadoEm = new \DateTimeImmutable();
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

    public function getReferencia(): \DateTimeImmutable
    {
        return $this->referencia;
    }

    public function getMetasAtivas(): int
    {
        return $this->metasAtivas;
    }

    public function getDemandasAtivas(): int
    {
        return $this->demandasAtivas;
    }

    public function getMetasVencidas(): int
    {
        return $this->metasVencidas;
    }

    public function getPrazosProximos(): int
    {
        return $this->prazosProximos;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}
