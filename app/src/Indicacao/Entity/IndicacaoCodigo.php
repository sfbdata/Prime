<?php

declare(strict_types=1);

namespace App\Indicacao\Entity;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoCodigoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * O código do link curto `/i/{codigo}` de cada pessoa (spec `indicar-bluejus.md`). Aleatório, nunca derivado do id:
 * um hash do id seria adivinhável. Nasce no primeiro acesso à janela (por SQL com ON CONFLICT, ver o repositório).
 */
#[ORM\Entity(repositoryClass: IndicacaoCodigoRepository::class)]
#[ORM\Table(name: 'indicacao_codigo')]
#[ORM\UniqueConstraint(name: 'uniq_indicacao_codigo', columns: ['codigo'])]
class IndicacaoCodigo
{
    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 12)]
        private string $codigo,
    ) {
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCodigo(): string
    {
        return $this->codigo;
    }
}
