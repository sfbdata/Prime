<?php

declare(strict_types=1);

namespace App\Termo\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Termo\Repository\AceiteTermoRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AceiteTermoRepository::class)]
#[ORM\Table(name: 'aceite_termo')]
#[ORM\UniqueConstraint(name: 'uq_aceite_termo_user_versao', columns: ['user_id', 'versao'])]
class AceiteTermo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 40)]
    private string $versao;

    #[ORM\Column]
    private \DateTimeImmutable $aceitoEm;

    #[ORM\Column(length: 45)]
    private string $ip;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant;

    public function __construct(
        User $user,
        string $versao,
        string $ip,
        ?string $userAgent = null,
        ?Tenant $tenant = null,
    ) {
        $this->user = $user;
        $this->versao = $versao;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
        $this->tenant = $tenant;
        $this->aceitoEm = new \DateTimeImmutable();
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

    public function getAceitoEm(): \DateTimeImmutable
    {
        return $this->aceitoEm;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }
}
