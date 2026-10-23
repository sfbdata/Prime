<?php

declare(strict_types=1);

namespace App\Dashboard\Entity;

use App\Dashboard\Repository\DashboardZeramentoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um "Zerar" de coluna da tabela Desempenho (DASH-014; desenho "01 - Dashboard 1.2.2", `dzCorte`):
 * a coluna passa a contar só o que veio DEPOIS de `contarDesde`, para UMA pessoa (`usuario`) ou
 * para todos (`usuario` nulo). Desfazer não apaga a linha: grava quem e quando, e a coluna volta a
 * contar tudo. Quem pode zerar e desfazer (só o Master) é regra do caso de uso, não daqui.
 *
 * Anatomia:
 *   - índice (tenant_id, coluna): a leitura do painel busca os zeramentos do escritório por coluna;
 *   - `user_id` CASCADE: o zeramento de uma pessoa some com ela;
 *   - `por_id` NOT NULL sem ação e `desfeito_por_id` SET NULL: o autor é obrigatório e o
 *     sistema não apaga usuário (só desativa);
 *   - `tenant_id` NOT NULL sem ação: TenantAware, apagada pela purga do escritório.
 */
#[ORM\Entity(repositoryClass: DashboardZeramentoRepository::class)]
#[ORM\Table(name: 'dashboard_zeramento')]
#[ORM\Index(name: 'idx_dashboard_zeramento_tenant_coluna', columns: ['tenant_id', 'coluna'])]
#[ORM\Index(name: 'idx_dashboard_zeramento_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_dashboard_zeramento_por', columns: ['por_id'])]
#[ORM\Index(name: 'idx_dashboard_zeramento_desfeito_por', columns: ['desfeito_por_id'])]
class DashboardZeramento implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(name: 'desfeito_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $desfeitoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'desfeito_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $desfeitoPor = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\Column(name: 'coluna', type: 'string', length: 40)]
        private string $coluna,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'CASCADE')]
        private ?User $usuario,
        #[ORM\Column(name: 'contar_desde', type: 'datetime_immutable')]
        private \DateTimeImmutable $contarDesde,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'por_id', nullable: false)]
        private User $por,
        ?\DateTimeImmutable $criadoEm = null,
    ) {
        $this->criadoEm = $criadoEm ?? new \DateTimeImmutable();
    }

    /** Desfaz uma vez só: desfazer de novo apagaria quem desfez primeiro. */
    public function desfazer(User $por, \DateTimeImmutable $em): void
    {
        $this->exigirAtivo();
        $this->desfeitoEm  = $em;
        $this->desfeitoPor = $por;
    }

    public function estaAtivo(): bool
    {
        return $this->desfeitoEm === null;
    }

    public function valeParaTodos(): bool
    {
        return $this->usuario === null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getColuna(): string
    {
        return $this->coluna;
    }

    public function getUsuario(): ?User
    {
        return $this->usuario;
    }

    public function getContarDesde(): \DateTimeImmutable
    {
        return $this->contarDesde;
    }

    public function getPor(): User
    {
        return $this->por;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getDesfeitoEm(): ?\DateTimeImmutable
    {
        return $this->desfeitoEm;
    }

    public function getDesfeitoPor(): ?User
    {
        return $this->desfeitoPor;
    }

    private function exigirAtivo(): void
    {
        if (!$this->estaAtivo()) {
            throw new \LogicException('Este zeramento já foi desfeito.');
        }
    }
}
