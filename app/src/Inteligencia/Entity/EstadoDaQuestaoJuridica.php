<?php

declare(strict_types=1);

namespace App\Inteligencia\Entity;

use App\Entity\Auth\User;
use App\Inteligencia\Enum\StatusDaQuestaoJuridica;
use App\Inteligencia\Repository\EstadoDaQuestaoJuridicaRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * O status de uma questão jurídica do catálogo ({@see \App\Inteligencia\Juridico\CatalogoDeQuestoesJuridicas}), pela
 * chave. Uma linha por chave, criada na primeira mudança: questão sem linha está pendente.
 *
 * PLATAFORMA, sem tenant: as questões são do BlueJus (contrato com a Anthropic, Política de Privacidade, Termos de
 * Uso), lidas e alteradas só pelo Master. Quem alterou vira NULL se o usuário for apagado (o nome fica no histórico).
 *
 * Auditável: o AuditLogSubscriber grava cada mudança, no escopo de plataforma.
 */
#[ORM\Entity(repositoryClass: EstadoDaQuestaoJuridicaRepository::class)]
#[ORM\Table(name: 'inteligencia_questao_juridica')]
#[ORM\UniqueConstraint(name: 'uniq_inteligencia_questao_juridica_chave', columns: ['chave'])]
#[ORM\Index(name: 'idx_inteligencia_questao_juridica_alterada_por', columns: ['alterada_por_id'])]
class EstadoDaQuestaoJuridica implements Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: StatusDaQuestaoJuridica::class, options: ['default' => 'pendente'])]
    private StatusDaQuestaoJuridica $status = StatusDaQuestaoJuridica::Pendente;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterada_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradaPor = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $alteradaEm;

    public function __construct(
        #[ORM\Column(length: 80)]
        private string $chave,
        \DateTimeImmutable $agora,
    ) {
        $this->alteradaEm = $agora;
    }

    /**
     * Passa a questão para o status novo e devolve a linha do histórico; o mesmo status não muda nada (null).
     */
    public function mudarPara(StatusDaQuestaoJuridica $novo, User $por, \DateTimeImmutable $agora): ?MudancaDeStatusDaQuestaoJuridica
    {
        if ($novo === $this->status) {
            return null;
        }
        $mudanca = new MudancaDeStatusDaQuestaoJuridica($this->chave, $this->status, $novo, $por, $agora);
        $this->status = $novo;
        $this->alteradaPor = $por;
        $this->alteradaEm = $agora;

        return $mudanca;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChave(): string
    {
        return $this->chave;
    }

    public function getStatus(): StatusDaQuestaoJuridica
    {
        return $this->status;
    }

    public function getAlteradaPor(): ?User
    {
        return $this->alteradaPor;
    }

    public function getAlteradaEm(): \DateTimeImmutable
    {
        return $this->alteradaEm;
    }
}
