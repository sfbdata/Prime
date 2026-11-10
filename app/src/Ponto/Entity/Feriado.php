<?php

namespace App\Ponto\Entity;

use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Ponto\Repository\FeriadoRepository::class)]
class Feriado implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nome = null;

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $data = null;

    #[ORM\Column]
    private ?bool $recorrente = true; // Se repete todo ano

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    /**
     * Abrangência (spec `ponto-desenho-0811.md`, P71 com o desvio da E6): vazia, o feriado vale para todo o escritório;
     * com uma lotação, só para quem tem aquela lotação no escritório do feriado (`FeriadosDaPessoa`). A lotação com
     * feriado não é apagada (`ApagarLotacoesQueSairamUseCase`; no banco, ON DELETE NO ACTION): CASCADE apagaria o
     * feriado e mudaria os meses passados, SET NULL o faria valer para todos.
     */
    #[ORM\ManyToOne(targetEntity: Lotacao::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Lotacao $lotacao = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNome(): ?string
    {
        return $this->nome;
    }

    public function setNome(string $nome): static
    {
        $this->nome = $nome;
        return $this;
    }

    public function getData(): ?\DateTimeInterface
    {
        return $this->data;
    }

    public function setData(\DateTimeInterface $data): static
    {
        $this->data = $data;
        return $this;
    }

    public function isRecorrente(): ?bool
    {
        return $this->recorrente;
    }

    public function setRecorrente(bool $recorrente): static
    {
        $this->recorrente = $recorrente;
        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getLotacao(): ?Lotacao
    {
        return $this->lotacao;
    }

    public function setLotacao(?Lotacao $lotacao): static
    {
        $this->lotacao = $lotacao;
        return $this;
    }
}
