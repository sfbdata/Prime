<?php

namespace App\Ponto\Entity;

use App\Ponto\Repository\BlocoJornadaRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BlocoJornadaRepository::class)]
#[ORM\HasLifecycleCallbacks]
class BlocoJornada implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'blocos')]
    #[ORM\JoinColumn(nullable: false)]
    private ?JornadaTenant $jornadaTenant = null;

    /**
     * Escritório dono, denormalizado do pai (escritório (JornadaTenant)) para o TenantFilter alcançar o bloco
     * (fatia 6 de docs/specs/isolamento-multitenant-cobertura.md). Nunca recebido de fora: sem setter,
     * copiado do pai ao ligar o bloco a ele e conferido ao gravar.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Tenant $tenant; // sem valor até o pai ter escritório; o PrePersist garante antes de gravar

    #[ORM\Column(type: 'json')]
    private array $diasSemana = []; // [1-7], 1=Segunda ... 7=Domingo

    #[ORM\Column(length: 5)]
    private string $entrada = '09:00'; // HH:mm

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $repouso = null; // HH:mm

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $retorno = null; // HH:mm

    #[ORM\Column(length: 5)]
    private string $saida = '18:00'; // HH:mm

    #[ORM\Column]
    private int $minutosBloco = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJornadaTenant(): ?JornadaTenant
    {
        return $this->jornadaTenant;
    }

    public function setJornadaTenant(?JornadaTenant $jornadaTenant): static
    {
        $this->jornadaTenant = $jornadaTenant;
        // Ligado a um pai com escritório, o bloco passa a ser desse escritório. Desligado (null, a caminho do
        // orphanRemoval), fica com o que tinha.
        if ($jornadaTenant?->getTenant() !== null) {
            $this->tenant = $jornadaTenant->getTenant();
        }

        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant ?? null;
    }

    /**
     * Ao gravar, o escritório é sempre o do pai: o pai pode ter ganhado o escritório depois de receber o bloco
     * (addBloco antes de setTenant). Pai sem escritório é erro de montagem, e falhar aqui é melhor que gravar um
     * bloco que o filtro não sabe a quem pertence.
     */
    #[ORM\PrePersist]
    public function herdarEscritorioDoPai(): void
    {
        $dono = $this->jornadaTenant?->getTenant();
        if ($dono === null) {
            throw new \LogicException('BlocoJornada sem escritório: o pai precisa ter escritório antes de gravar.');
        }
        $this->tenant = $dono;
    }

    public function getDiasSemana(): array
    {
        return $this->diasSemana;
    }

    public function setDiasSemana(array $diasSemana): static
    {
        $this->diasSemana = $diasSemana;
        return $this;
    }

    public function getEntrada(): string
    {
        return $this->entrada;
    }

    public function setEntrada(string $entrada): static
    {
        $this->entrada = $entrada;
        return $this;
    }

    public function getRepouso(): ?string
    {
        return $this->repouso;
    }

    public function setRepouso(?string $repouso): static
    {
        $this->repouso = $repouso;
        return $this;
    }

    public function getRetorno(): ?string
    {
        return $this->retorno;
    }

    public function setRetorno(?string $retorno): static
    {
        $this->retorno = $retorno;
        return $this;
    }

    public function getSaida(): string
    {
        return $this->saida;
    }

    public function setSaida(string $saida): static
    {
        $this->saida = $saida;
        return $this;
    }

    public function getMinutosBloco(): int
    {
        return $this->minutosBloco;
    }

    public function setMinutosBloco(int $minutosBloco): static
    {
        $this->minutosBloco = $minutosBloco;
        return $this;
    }
}
