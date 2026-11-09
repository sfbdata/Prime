<?php

declare(strict_types=1);

namespace App\Ponto\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma VERSÃO da lotação de uma pessoa NUM escritório (spec `docs/specs/ponto-desenho-0811.md`, E6: lotação com
 * vigência, decisão do Coordenador de 09/11/2026, no molde da jornada com vigência). O feriado de uma lotação vale no
 * dia para quem tinha aquela lotação NAQUELE dia (`FeriadosDaPessoa`): trocar a lotação no cadastro não muda a folha
 * nem o banco do passado.
 *
 * - A versão inicial (`valeAPartirDe` nulo) vale "desde sempre": é a lotação que a pessoa tinha antes da primeira troca.
 * - Cada troca grava uma versão que vale a partir do dia da troca (`RegistrarTrocaDeLotacaoUseCase`); a troca no mesmo
 *   dia substitui a versão daquele dia.
 * - `user_tenant.lotacao_id` continua sendo a lotação de HOJE; sem nenhuma versão, ela vale para todos os dias.
 *
 * Lotação nula: a pessoa estava sem lotação (só os feriados de "Todas as sedes"). Apagar a lotação deixa a versão sem
 * lotação (SET NULL, como em `user_tenant`); os feriados só dela já saem junto (CASCADE em `feriado`).
 */
#[ORM\Entity(repositoryClass: \App\Ponto\Repository\LotacaoComVigenciaRepository::class)]
#[ORM\Table(name: 'lotacao_vigencia')]
#[ORM\Index(name: 'idx_lotacao_vigencia_vinculo', columns: ['user_id', 'tenant_id'])]
#[ORM\UniqueConstraint(name: 'uq_lotacao_vigencia_dia', columns: ['user_id', 'tenant_id', 'vale_a_partir_de'], options: ['where' => '(vale_a_partir_de IS NOT NULL)'])]
#[ORM\UniqueConstraint(name: 'uq_lotacao_vigencia_inicial', columns: ['user_id', 'tenant_id'], options: ['where' => '(vale_a_partir_de IS NULL)'])]
class LotacaoComVigencia implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Lotacao::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Lotacao $lotacao;

    /** O dia em que esta versão passa a valer (só a data); nulo na versão inicial, que vale desde sempre. */
    #[ORM\Column(name: 'vale_a_partir_de', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $valeAPartirDe;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        ?Lotacao $lotacao,
        ?\DateTimeImmutable $valeAPartirDe,
    ) {
        $this->lotacao = $lotacao;
        $this->valeAPartirDe = $valeAPartirDe?->setTime(0, 0);
    }

    /** A lotação que a pessoa tinha antes da primeira troca: vale para todos os dias antes da versão seguinte. */
    public static function inicial(User $pessoa, Tenant $escritorio, ?Lotacao $lotacao): self
    {
        return new self($pessoa, $escritorio, $lotacao, null);
    }

    public static function aPartirDe(User $pessoa, Tenant $escritorio, ?Lotacao $lotacao, \DateTimeImmutable $dia): self
    {
        return new self($pessoa, $escritorio, $lotacao, $dia);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getLotacao(): ?Lotacao
    {
        return $this->lotacao;
    }

    /** Só a troca no mesmo dia muda a lotação de uma versão (`RegistrarTrocaDeLotacaoUseCase`). */
    public function setLotacao(?Lotacao $lotacao): static
    {
        $this->lotacao = $lotacao;

        return $this;
    }

    public function getValeAPartirDe(): ?\DateTimeImmutable
    {
        return $this->valeAPartirDe;
    }

    public function ehDoDia(\DateTimeInterface $dia): bool
    {
        return $this->valeAPartirDe?->format('Y-m-d') === $dia->format('Y-m-d');
    }
}
