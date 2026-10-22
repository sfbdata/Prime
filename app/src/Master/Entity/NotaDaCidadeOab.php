<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Master\Repository\NotaDaCidadeOabRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * A nota explicativa de uma cidade (a capital do modo "Capitais" dos estados que faltam), escrita pelo Master na
 * janela da OAB da cidade (spec docs/specs/master-oab-estados-que-faltam.md, "NOTA EXPLICATIVA"; desenho 05,
 * Perfis 1.2, VS 21.10.26, L491 e L896: a chave do protótipo é `c:<UF>:<cidade>`).
 *
 * Mesmo modelo da {@see NotaDaSeccional} (que segue sendo a nota do estado): uma por cidade da seccional (única por
 * seccional e cidade), compartilhada entre os Masters, GLOBAL e sem tenant. Texto vazio apaga a linha. Quem gravou por
 * último fica nulo se a conta dele for apagada (SET NULL). Auditável, dentro do EscopoDeAuditoriaDePlataforma.
 */
#[ORM\Entity(repositoryClass: NotaDaCidadeOabRepository::class)]
#[ORM\Table(name: 'master_nota_cidade_oab')]
#[ORM\UniqueConstraint(name: 'uniq_master_nota_cidade_oab', columns: ['seccional_id', 'cidade'])]
#[ORM\Index(name: 'idx_master_nota_cidade_oab_atualizada_por', columns: ['atualizada_por_id'])]
class NotaDaCidadeOab implements Auditavel
{
    public const CIDADE_MAXIMA = 120;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: NotaDaSeccional::TEXTO_MAXIMO)]
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
        #[ORM\Column(length: self::CIDADE_MAXIMA)]
        private string $cidade,
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

    public function getCidade(): string
    {
        return $this->cidade;
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
