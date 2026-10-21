<?php

declare(strict_types=1);

namespace App\Termo\Entity;

use App\Entity\Auth\User;
use App\Termo\Enum\OrigemDoAceite;
use App\Termo\Repository\CienciaPoliticaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Registro de que a pessoa declarou ter lido a Política de Privacidade (versão e data), na caixa única do cadastro do
 * desenho 10. Não é aceite: a Política não entra no fluxo de reaceite (ver PoliticaPrivacidadeVigente). Fica ao lado
 * do AceiteTermo porque nasce no mesmo clique.
 */
#[ORM\Entity(repositoryClass: CienciaPoliticaRepository::class)]
#[ORM\Table(name: 'ciencia_politica')]
#[ORM\UniqueConstraint(name: 'uq_ciencia_politica_user_versao_origem', columns: ['user_id', 'versao', 'origem'])]
class CienciaPolitica
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 40)]
        private string $versao,
        #[ORM\Column(length: 45)]
        private string $ip,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $userAgent = null,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $cienteEm = new \DateTimeImmutable(),
        #[ORM\Column(length: 20, enumType: OrigemDoAceite::class, options: ['default' => 'cadastro'])]
        private OrigemDoAceite $origem = OrigemDoAceite::Cadastro,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getVersao(): string
    {
        return $this->versao;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getCienteEm(): \DateTimeImmutable
    {
        return $this->cienteEm;
    }

    public function getOrigem(): OrigemDoAceite
    {
        return $this->origem;
    }
}
