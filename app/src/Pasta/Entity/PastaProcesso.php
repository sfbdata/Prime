<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Processo\Entity\Processo;
use Doctrine\ORM\Mapping as ORM;

/**
 * Vínculo entre uma Pasta e um Processo.
 *
 * Entidade de associação: uma Pasta pode ter vários processos vinculados e, dentre
 * eles, exatamente um é o principal (a "cara" da pasta nos resumos). O invariante de
 * unicidade do principal é mantido pela própria Pasta (ver Pasta::vincularProcesso,
 * desvincularProcesso e definirProcessoPrincipal). Não é TenantAware: o isolamento de
 * tenant vem da Pasta dona do vínculo.
 *
 * Apensado (P4f): todo vínculo que não é o principal. `tipoApenso` guarda o tipo escolhido ao vincular
 * pelo "Vincular apensado"; vínculo antigo (ou vindo do "Vincular processo") fica sem tipo e é lido
 * como "Outro". Não há processo pai: o vínculo é pela pasta, como no desenho.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pasta_processo')]
#[ORM\UniqueConstraint(name: 'uniq_pasta_processo', columns: ['pasta_id', 'processo_id'])]
class PastaProcesso
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Pasta::class, inversedBy: 'pastaProcessos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Pasta $pasta;

    #[ORM\ManyToOne(targetEntity: Processo::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Processo $processo;

    #[ORM\Column(options: ['default' => false])]
    private bool $principal = false;

    #[ORM\Column(name: 'tipo_apenso', length: 40, nullable: true, enumType: TipoDeApenso::class)]
    private ?TipoDeApenso $tipoApenso = null;

    #[ORM\Column(name: 'vinculado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $vinculadoEm;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'vinculado_por_id', nullable: true)]
    private ?User $vinculadoPor = null;

    public function __construct(Pasta $pasta, Processo $processo, ?User $vinculadoPor = null)
    {
        $this->pasta = $pasta;
        $this->processo = $processo;
        $this->vinculadoPor = $vinculadoPor;
        $this->vinculadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPasta(): Pasta
    {
        return $this->pasta;
    }

    public function getProcesso(): Processo
    {
        return $this->processo;
    }

    public function isPrincipal(): bool
    {
        return $this->principal;
    }

    public function setPrincipal(bool $principal): self
    {
        $this->principal = $principal;

        return $this;
    }

    public function getTipoApenso(): ?TipoDeApenso
    {
        return $this->tipoApenso;
    }

    public function setTipoApenso(?TipoDeApenso $tipoApenso): self
    {
        $this->tipoApenso = $tipoApenso;

        return $this;
    }

    /** Rótulo do tipo do apensado; sem tipo gravado (vínculo antigo) é "Outro" (spec P4f §1). */
    public function getTipoApensoRotulo(): string
    {
        return ($this->tipoApenso ?? TipoDeApenso::Outro)->rotulo();
    }

    public function getVinculadoEm(): \DateTimeImmutable
    {
        return $this->vinculadoEm;
    }

    public function getVinculadoPor(): ?User
    {
        return $this->vinculadoPor;
    }
}
