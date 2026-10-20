<?php

declare(strict_types=1);

namespace App\Comercial\Entity;

use App\Entity\Auth\User;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * O estado de uma automação (spec §3.4): uma linha por chave de {@see \App\Comercial\Enum\Automacao}, criada pela
 * migration com o padrão do dc. `ligada_em` é o corte: as varreduras só olham fatos posteriores (nada para o
 * passado). Auditável: a mudança do Master fica na trilha (o AuditLogSubscriber grava no flush).
 */
#[ORM\Entity]
#[ORM\Table(name: 'comercial_automacao')]
class AutomacaoDeEmail implements Auditavel
{
    #[ORM\Id]
    #[ORM\Column(length: 10)]
    private string $chave = '';

    #[ORM\Column(type: 'boolean')]
    private bool $ligada = false;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $atraso = null;

    #[ORM\Column(name: 'ligada_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ligadaEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterada_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradaPor = null;

    #[ORM\Column(name: 'alterada_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $alteradaEm = null;

    public function getChave(): string
    {
        return $this->chave;
    }

    public function isLigada(): bool
    {
        return $this->ligada;
    }

    public function getAtraso(): ?string
    {
        return $this->atraso;
    }

    /** Ligar renova o corte (`ligada_em`): o que aconteceu enquanto esteve desligada não dispara. */
    public function alterar(bool $ligada, ?string $atraso, User $por, \DateTimeImmutable $agora): void
    {
        if ($ligada && !$this->ligada) {
            $this->ligadaEm = $agora;
        }
        $this->ligada = $ligada;
        $this->atraso = $atraso;
        $this->alteradaPor = $por;
        $this->alteradaEm = $agora;
    }
}
