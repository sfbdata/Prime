<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\TrabalhoExterno\Repository\MetricaPublicaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Os contadores anônimos da página pública de vagas (spec VS 18.10.26 §4.9): visitas pelo link e
 * cliques em "Criar conta", por dia (horário de Brasília) e por link. Nenhum dado pessoal: nem IP,
 * nem navegador, nem usuário. Um registro por dia e link, somado com UPSERT no repositório
 * ({@see MetricaPublicaRepository::somar()}).
 *
 * `chaveDoLink` é o id do link (0 sem link) e é ela que entra na unicidade: o PostgreSQL trata NULL
 * como distinto, e apagar o link (SET NULL) não pode colidir com a linha "sem link" do mesmo dia.
 */
#[ORM\Entity(repositoryClass: MetricaPublicaRepository::class)]
#[ORM\Table(name: 'trabalho_externo_metrica_publica')]
#[ORM\UniqueConstraint(name: 'uniq_te_metrica_dia_link', columns: ['dia', 'chave_do_link'])]
class MetricaPublica
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'chave_do_link', type: 'integer')]
    private int $chaveDoLink;

    #[ORM\Column(type: 'integer')]
    private int $visitas = 0;

    #[ORM\Column(type: 'integer')]
    private int $cliques = 0;

    public function __construct(
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $dia,
        #[ORM\ManyToOne(targetEntity: LinkDeVagas::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?LinkDeVagas $link = null,
    ) {
        $this->chaveDoLink = (int) $link?->getId();
    }

    public function getDia(): \DateTimeImmutable
    {
        return $this->dia;
    }

    public function getLink(): ?LinkDeVagas
    {
        return $this->link;
    }

    public function getVisitas(): int
    {
        return $this->visitas;
    }

    public function getCliques(): int
    {
        return $this->cliques;
    }
}
