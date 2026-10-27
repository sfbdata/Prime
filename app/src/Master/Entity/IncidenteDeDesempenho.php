<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Master\Enum\GravidadeDeIncidente;
use App\Master\Repository\IncidenteDeDesempenhoRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Incidente aberto à mão pelo Master na seção Desempenho (spec docs/specs/master-desempenho.md §2, D3).
 * GLOBAL, sem tenant. Auditável: abrir e resolver passam pela trilha.
 */
#[ORM\Entity(repositoryClass: IncidenteDeDesempenhoRepository::class)]
#[ORM\Table(name: 'desempenho_incidente')]
#[ORM\Index(name: 'idx_desempenho_incidente_aberto_por', columns: ['aberto_por_id'])]
class IncidenteDeDesempenho implements Auditavel
{
    public const TITULO_MAXIMO = 160;
    public const CATEGORIA_MAXIMA = 40;
    public const SOLUCAO_MAXIMA = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvidoEm = null;

    #[ORM\Column(length: self::SOLUCAO_MAXIMA, nullable: true)]
    private ?string $solucao = null;

    public function __construct(
        #[ORM\Column(length: self::TITULO_MAXIMO)]
        private string $titulo,
        #[ORM\Column(length: self::CATEGORIA_MAXIMA)]
        private string $categoria,
        #[ORM\Column(length: 5, enumType: GravidadeDeIncidente::class)]
        private GravidadeDeIncidente $gravidade,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $abertoEm,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'aberto_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $abertoPor = null,
    ) {
    }

    public function resolver(?string $solucao, \DateTimeImmutable $em): void
    {
        $solucao = trim((string) $solucao);
        $this->solucao = $solucao === '' ? null : mb_substr($solucao, 0, self::SOLUCAO_MAXIMA);
        $this->resolvidoEm = $em;
    }

    public function estaAberto(): bool
    {
        return $this->resolvidoEm === null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function getCategoria(): string
    {
        return $this->categoria;
    }

    public function getGravidade(): GravidadeDeIncidente
    {
        return $this->gravidade;
    }

    public function getAbertoEm(): \DateTimeImmutable
    {
        return $this->abertoEm;
    }

    public function getAbertoPor(): ?User
    {
        return $this->abertoPor;
    }

    public function getResolvidoEm(): ?\DateTimeImmutable
    {
        return $this->resolvidoEm;
    }

    public function getSolucao(): ?string
    {
        return $this->solucao;
    }
}
