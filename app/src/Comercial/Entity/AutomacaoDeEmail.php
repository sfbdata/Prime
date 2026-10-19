<?php

declare(strict_types=1);

namespace App\Comercial\Entity;

use App\Entity\Auth\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * O estado de uma automação (spec §3.4): uma linha por chave de {@see \App\Comercial\Enum\Automacao}, criada pela
 * migration com o padrão do dc. `ligada_em` é o corte: as varreduras só olham fatos posteriores (nada para o
 * passado).
 */
#[ORM\Entity]
#[ORM\Table(name: 'comercial_automacao')]
class AutomacaoDeEmail
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
}
