<?php

declare(strict_types=1);

namespace App\Auth\Entity;

use App\Auth\Repository\AcessoConhecidoRepository;
use App\Entity\Auth\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um jeito conhecido de a pessoa entrar (spec `login-aviso-novo.md`): a impressão HMAC do navegador mais a faixa do
 * IP, nunca o IP cru. Serve para avisar por e-mail quando surge um acesso novo.
 *
 * NÃO é multi-tenant: a identidade do User é global, anterior a qualquer escritório. As gravações passam pelo
 * `AcessoConhecidoRepository::registrar()` (upsert atômico); a entidade é o mapeamento da tabela e a base da purga.
 */
#[ORM\Entity(repositoryClass: AcessoConhecidoRepository::class)]
#[ORM\Table(name: 'acesso_conhecido')]
#[ORM\UniqueConstraint(name: 'uq_acesso_conhecido_user_impressao', columns: ['user_id', 'impressao'])]
#[ORM\Index(name: 'idx_acesso_conhecido_ultimo_em', columns: ['ultimo_em'])]
class AcessoConhecido
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 64)]
        private string $impressao,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $primeiroEm,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $ultimoEm,
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

    public function getImpressao(): string
    {
        return $this->impressao;
    }

    public function getPrimeiroEm(): \DateTimeImmutable
    {
        return $this->primeiroEm;
    }

    public function getUltimoEm(): \DateTimeImmutable
    {
        return $this->ultimoEm;
    }
}
