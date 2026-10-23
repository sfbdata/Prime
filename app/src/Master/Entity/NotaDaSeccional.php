<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Master\Repository\NotaDaSeccionalRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * A nota explicativa de uma seccional da OAB, escrita pelo Master na janela da OAB do mapa "Onde estamos atuando"
 * (spec docs/specs/master-oab-estados-que-faltam.md, "NOTA EXPLICATIVA"; desenho 05, Perfis 1.2, VS 21.10.26, L491).
 *
 * Uma por UF (única por seccional), compartilhada entre os Masters: é anotação de trabalho da PLATAFORMA, como as
 * divulgações. GLOBAL, sem tenant. Texto vazio apaga a linha (o UseCase remove; não há nota vazia gravada). Quem
 * gravou por último fica nulo se a conta dele for apagada (SET NULL). Auditável, dentro do EscopoDeAuditoriaDePlataforma.
 */
#[ORM\Entity(repositoryClass: NotaDaSeccionalRepository::class)]
#[ORM\Table(name: 'master_nota_seccional')]
#[ORM\UniqueConstraint(name: 'uniq_master_nota_seccional_seccional', columns: ['seccional_id'])]
#[ORM\Index(name: 'idx_master_nota_seccional_atualizada_por', columns: ['atualizada_por_id'])]
class NotaDaSeccional implements Auditavel
{
    public const TEXTO_MAXIMO = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: self::TEXTO_MAXIMO)]
    private string $texto;

    #[ORM\Column(name: 'atualizada_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadaEm;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'atualizada_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $atualizadaPor;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: SeccionalOab::class)]
        #[ORM\JoinColumn(name: 'seccional_id', nullable: false, onDelete: 'CASCADE')]
        private SeccionalOab $seccional,
        string $texto,
        ?User $por,
        \DateTimeImmutable $em,
    ) {
        $this->texto = $texto;
        $this->atualizadaPor = $por;
        $this->atualizadaEm = $em;
    }

    /** Troca o texto inteiro e registra quem e quando. */
    public function reescrever(string $texto, ?User $por, \DateTimeImmutable $em): void
    {
        $this->texto = $texto;
        $this->atualizadaPor = $por;
        $this->atualizadaEm = $em;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeccional(): SeccionalOab
    {
        return $this->seccional;
    }

    public function getTexto(): string
    {
        return $this->texto;
    }

    public function getAtualizadaEm(): \DateTimeImmutable
    {
        return $this->atualizadaEm;
    }

    public function getAtualizadaPor(): ?User
    {
        return $this->atualizadaPor;
    }
}
