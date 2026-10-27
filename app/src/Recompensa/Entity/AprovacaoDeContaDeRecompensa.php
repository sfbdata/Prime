<?php

declare(strict_types=1);

namespace App\Recompensa\Entity;

use App\Entity\Auth\User;
use App\Recompensa\Repository\AprovacaoDeContaDeRecompensaRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * O Master aprovou uma conta suspeita de um indicador: a partir daí ela conta para a recompensa dele (spec
 * perfis-1.2-vs-25-10-recompensas-e-planos, F3). A aprovação é por par (indicador, conta) e só nasce: não se desfaz nesta
 * fatia. Some junto com o indicador ou com a conta (CASCADE).
 *
 * GLOBAL, sem tenant: são dois usuários e quem aprovou.
 */
#[ORM\Entity(repositoryClass: AprovacaoDeContaDeRecompensaRepository::class)]
#[ORM\Table(name: 'aprovacao_de_conta_de_recompensa')]
#[ORM\UniqueConstraint(name: 'uniq_aprovacao_de_conta_de_recompensa', columns: ['indicador_id', 'conta_id'])]
#[ORM\Index(name: 'idx_aprovacao_de_conta_de_recompensa_conta', columns: ['conta_id'])]
#[ORM\Index(name: 'idx_aprovacao_de_conta_de_recompensa_aprovada_por', columns: ['aprovada_por_id'])]
class AprovacaoDeContaDeRecompensa implements Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'indicador_id', nullable: false, onDelete: 'CASCADE')]
    private User $indicador;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'conta_id', nullable: false, onDelete: 'CASCADE')]
    private User $conta;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'aprovada_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $aprovadaPor;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $aprovadaEm;

    public function __construct(User $indicador, User $conta, ?User $aprovadaPor, \DateTimeImmutable $aprovadaEm)
    {
        $this->indicador = $indicador;
        $this->conta = $conta;
        $this->aprovadaPor = $aprovadaPor;
        $this->aprovadaEm = $aprovadaEm;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIndicador(): User
    {
        return $this->indicador;
    }

    public function getConta(): User
    {
        return $this->conta;
    }

    public function getAprovadaPor(): ?User
    {
        return $this->aprovadaPor;
    }

    public function getAprovadaEm(): \DateTimeImmutable
    {
        return $this->aprovadaEm;
    }
}
