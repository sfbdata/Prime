<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Enum\ChaveDoAvisoDoPush;
use App\Pasta\Repository\AvisoDoPushDaPastaRepository;
use App\Pasta\Service\PadroesDoAvisoDoPush;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * As chaves do "Avisar quando chegar" de UMA pasta (spec push-avisar-quando-chegar §1): avisar o
 * responsável no sino, mandar também por e-mail, e pedir a análise da BlueJus IA. Valem por pasta.
 *
 * A linha só nasce quando alguém mexe numa chave: sem linha, valem os padrões de
 * {@see PadroesDoAvisoDoPush} (e a primeira alteração parte deles). A criação é um INSERT
 * idempotente no repositório (`garantirDaPasta`): duas abas gravando ao mesmo tempo não estouram o UNIQUE. UNIQUE (pasta_id): uma linha
 * por pasta; a pasta apagada leva a linha (CASCADE). Quem mexeu por último fica gravado (SET NULL
 * se o usuário sair do banco).
 *
 * Auditável: ligar ou desligar um aviso é ato de alguém sobre a pasta.
 */
#[ORM\Entity(repositoryClass: AvisoDoPushDaPastaRepository::class)]
#[ORM\Table(name: 'pasta_aviso_push')]
#[ORM\Index(name: 'idx_pasta_aviso_push_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pasta_aviso_push_pasta', columns: ['pasta_id'])]
class AvisoDoPushDaPasta implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'avisar_responsavel', type: 'boolean')]
    private bool $avisarResponsavel = PadroesDoAvisoDoPush::RESPONSAVEL;

    #[ORM\Column(name: 'por_email', type: 'boolean')]
    private bool $porEmail = PadroesDoAvisoDoPush::EMAIL;

    #[ORM\Column(name: 'analise_ia', type: 'boolean')]
    private bool $analiseIa = PadroesDoAvisoDoPush::IA;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Pasta::class)]
        #[ORM\JoinColumn(name: 'pasta_id', nullable: false, onDelete: 'CASCADE')]
        private Pasta $pasta,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'atualizado_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $atualizadoPor,
        #[ORM\Column(name: 'atualizado_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $atualizadoEm,
    ) {
    }

    /** Põe a chave no estado pedido e registra quem mexeu. */
    public function definir(ChaveDoAvisoDoPush $chave, bool $ligada, User $por, \DateTimeImmutable $em): void
    {
        match ($chave) {
            ChaveDoAvisoDoPush::Responsavel => $this->avisarResponsavel = $ligada,
            ChaveDoAvisoDoPush::Email => $this->porEmail = $ligada,
            ChaveDoAvisoDoPush::Ia => $this->analiseIa = $ligada,
        };
        $this->atualizadoPor = $por;
        $this->atualizadoEm = $em;
    }

    public function ligada(ChaveDoAvisoDoPush $chave): bool
    {
        return match ($chave) {
            ChaveDoAvisoDoPush::Responsavel => $this->avisarResponsavel,
            ChaveDoAvisoDoPush::Email => $this->porEmail,
            ChaveDoAvisoDoPush::Ia => $this->analiseIa,
        };
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getPasta(): Pasta
    {
        return $this->pasta;
    }

    public function getAtualizadoPor(): ?User
    {
        return $this->atualizadoPor;
    }

    public function getAtualizadoEm(): \DateTimeImmutable
    {
        return $this->atualizadoEm;
    }
}
