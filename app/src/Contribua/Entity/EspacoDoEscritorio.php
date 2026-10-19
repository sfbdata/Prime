<?php

declare(strict_types=1);

namespace App\Contribua\Entity;

use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * O espaço de UM escritório: em que plano ele está e se o Master lhe deu um limite próprio.
 *
 * Uma linha por tenant (constraint nomeada, no molde de `inteligencia_configuracao`). Sem linha, o
 * escritório está no plano Free: é o "toda conta nova entra no Plano Free (10 GB)" do desenho 05.
 *
 * O limite efetivo, em ordem:
 *  1. `semLimite` (convenção, sem desenho): o Master libera o escritório de qualquer limite;
 *  2. `cotaGb`, o "Editar limite de armazenamento" do Master (desenho 05);
 *  3. a cota do plano.
 *
 * Auditável: cada mudança de limite fica no `audit_log` (o 05 exige justificativa e auditoria).
 *
 * NÃO é `TenantAware`, de propósito (decisão da revisão da C1): todo leitor pede o espaço por um
 * tenantId explícito, e o filtro da sessão só atrapalharia. O decorador lê o escritório da CHAVE do
 * arquivo (no Trabalho Externo, o solicitante, mesmo quando quem anexa é o Master) e o Painel do
 * Master lê qualquer escritório. Com o filtro, o Master no escritório X veria o Y como Free. O
 * isolamento é estrutural: só `EspacoDoEscritorioRepository::doTenant()` consulta esta entidade, e
 * `EspacoDoEscritorioSoNoContribuaArquiteturaTest` impede consulta em outro lugar.
 */
#[ORM\Entity(repositoryClass: EspacoDoEscritorioRepository::class)]
#[ORM\Table(name: 'contribua_espaco_do_escritorio')]
#[ORM\UniqueConstraint(name: 'uniq_contribua_espaco_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_contribua_espaco_plano', columns: ['plano_id'])]
#[ORM\Index(name: 'idx_contribua_espaco_alterado_por', columns: ['alterado_por_id'])]
#[ORM\HasLifecycleCallbacks]
class EspacoDoEscritorio implements Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $cotaGb = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $semLimite = false;

    /** "Definir limite de usuários" do Master (desenho 05); nulo = vale o do plano. Aplicado pelo Perfis. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $limiteUsuarios = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $justificativa = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradoPor = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Plano::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Plano $plano,
    ) {
        $this->criadoEm = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    /** O "Editar limite de armazenamento" do Master: GB próprio, ou sem limite nenhum. */
    public function definirLimite(?int $cotaGb, bool $semLimite, string $justificativa, ?User $por): void
    {
        if (!$semLimite && ($cotaGb === null || $cotaGb <= 0)) {
            throw new \DomainException('Informe um limite em GB maior que zero.');
        }

        $this->semLimite = $semLimite;
        $this->cotaGb = $semLimite ? null : $cotaGb;
        $this->justificativa = $justificativa;
        $this->alteradoPor = $por;
    }

    public function definirLimiteDeUsuarios(?int $limite, string $justificativa, ?User $por): void
    {
        if ($limite !== null && $limite <= 0) {
            throw new \DomainException('Informe um limite de usuários maior que zero.');
        }

        $this->limiteUsuarios = $limite;
        $this->justificativa = $justificativa;
        $this->alteradoPor = $por;
    }

    public function getLimiteUsuarios(): ?int
    {
        return $this->limiteUsuarios;
    }

    public function mudarDePlano(Plano $plano): void
    {
        $this->plano = $plano;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getPlano(): Plano
    {
        return $this->plano;
    }

    public function getCotaGb(): ?int
    {
        return $this->cotaGb;
    }

    public function isSemLimite(): bool
    {
        return $this->semLimite;
    }

    public function getJustificativa(): ?string
    {
        return $this->justificativa;
    }
}
