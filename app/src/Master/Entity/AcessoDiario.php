<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Master\Repository\AcessoDiarioRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um acesso ao sistema: a pessoa entrou (login bem-sucedido) naquele dia. UMA linha por pessoa por dia, por
 * mais que ela entre; é a base da linha "Acessos ao sistema" do gráfico Crescimento do Painel do Master
 * (desenho 05 - Perfis 1.2, VS 03.11.26). Sem histórico anterior: a linha começa no dia em que isto entrou
 * no ar (decisão do Samuel, 04/11/2026).
 *
 * Dado pessoal mínimo: só quem e o dia (sem hora, IP ou navegador). Sai junto com a conta (CASCADE). Quem
 * grava é o {@see \App\Master\EventListener\AcessoDiarioNoLoginListener}, que nunca derruba o login.
 */
#[ORM\Entity(repositoryClass: AcessoDiarioRepository::class)]
#[ORM\Table(name: 'master_acesso_diario')]
#[ORM\UniqueConstraint(name: 'uniq_master_acesso_diario_usuario_dia', columns: ['usuario_id', 'dia'])]
#[ORM\Index(name: 'idx_master_acesso_diario_dia', columns: ['dia'])]
class AcessoDiario
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $dia,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getDia(): \DateTimeImmutable
    {
        return $this->dia;
    }
}
