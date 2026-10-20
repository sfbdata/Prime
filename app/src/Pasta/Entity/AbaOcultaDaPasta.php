<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\AbaOcultaDaPastaRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma aba da pasta escondida de parte da equipe (spec pasta-ocultar-aba §1, D1: vale só NESTA pasta).
 *
 * Sem linha = a aba é visível para todos. Com linha: `paraTodos` esconde de toda a equipe; senão,
 * `usuarios` lista os ids de quem não vê. Quem decide se a regra vale para uma pessoa é o
 * `VisibilidadeDasAbasDaPasta` (Master e primário nunca são ocultados); a entidade só guarda.
 *
 * Auditável: quem escondeu o quê de quem é dado de governança.
 */
#[ORM\Entity(repositoryClass: AbaOcultaDaPastaRepository::class)]
#[ORM\Table(name: 'pasta_aba_oculta')]
#[ORM\UniqueConstraint(name: 'uniq_pasta_aba_oculta_pasta_aba', columns: ['pasta_id', 'aba'])]
#[ORM\Index(name: 'idx_pasta_aba_oculta_tenant', columns: ['tenant_id'])]
class AbaOcultaDaPasta implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * @param list<int> $usuarios
     */
    private function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Pasta::class)]
        #[ORM\JoinColumn(name: 'pasta_id', nullable: false, onDelete: 'CASCADE')]
        private Pasta $pasta,
        #[ORM\Column(name: 'aba', type: 'string', length: 20, enumType: AbaDaPasta::class)]
        private AbaDaPasta $aba,
        #[ORM\Column(name: 'para_todos', type: 'boolean')]
        private bool $paraTodos,
        #[ORM\Column(name: 'usuarios', type: 'json')]
        private array $usuarios,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'atualizado_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $atualizadoPor,
        #[ORM\Column(name: 'atualizado_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $atualizadoEm,
    ) {
    }

    public static function paraTodaAEquipe(Tenant $tenant, Pasta $pasta, AbaDaPasta $aba, User $quem, \DateTimeImmutable $agora): self
    {
        return new self($tenant, $pasta, $aba, true, [], $quem, $agora);
    }

    /**
     * @param list<int> $usuarios
     */
    public static function paraAlguns(Tenant $tenant, Pasta $pasta, AbaDaPasta $aba, array $usuarios, User $quem, \DateTimeImmutable $agora): self
    {
        $regra = new self($tenant, $pasta, $aba, false, [], $quem, $agora);
        $regra->ocultarPara($usuarios, $quem, $agora);

        return $regra;
    }

    public function ocultarParaTodos(User $quem, \DateTimeImmutable $agora): void
    {
        $this->paraTodos = true;
        $this->usuarios  = [];
        $this->registrarAutoria($quem, $agora);
    }

    /**
     * @param list<int> $usuarios ids de usuário; repetidos e a ordem não importam
     */
    public function ocultarPara(array $usuarios, User $quem, \DateTimeImmutable $agora): void
    {
        $ids = array_values(array_unique(array_map('intval', $usuarios)));
        sort($ids);

        if ($ids === []) {
            throw new \DomainException('A regra precisa de ao menos uma pessoa: sem ninguém, a aba volta a ser visível.');
        }

        $this->paraTodos = false;
        $this->usuarios  = $ids;
        $this->registrarAutoria($quem, $agora);
    }

    /** A regra, sozinha, sem as exceções de Master e primário (que são do serviço). */
    public function alcanca(int $userId): bool
    {
        return $this->paraTodos || \in_array($userId, $this->usuarios, true);
    }

    private function registrarAutoria(User $quem, \DateTimeImmutable $agora): void
    {
        $this->atualizadoPor = $quem;
        $this->atualizadoEm  = $agora;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getPasta(): Pasta
    {
        return $this->pasta;
    }

    public function getAba(): AbaDaPasta
    {
        return $this->aba;
    }

    public function isParaTodos(): bool
    {
        return $this->paraTodos;
    }

    /** @return list<int> */
    public function getUsuarios(): array
    {
        return array_map('intval', $this->usuarios);
    }

    public function getAtualizadoPor(): ?User
    {
        return $this->atualizadoPor;
    }

    public function getAtualizadoEm(): \DateTimeImmutable
    {
        return $this->atualizadoEm;
    }
}
