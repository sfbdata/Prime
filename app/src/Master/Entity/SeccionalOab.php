<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Master\Enum\StatusDaDivulgacao;
use App\Master\Repository\SeccionalOabRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma seccional da OAB (uma por UF) no mapa "Onde estamos atuando" do Painel do Master
 * (spec docs/specs/master-seccionais-oab.md; desenho 05, Perfis 1.2, VS 21.10.26, L116-127).
 *
 * GLOBAL, sem tenant, de propósito: é dado da PLATAFORMA (as 27 sedes), lido e editado só pelo Master
 * (ROLE_SUPER_ADMIN). Não há dado de escritório nem de cliente; por isso não entra no TenantFilter nem na
 * purga de escritório. A carga da migration grava só a UF e o nome: endereço, telefone e site nascem vazios
 * e o Master os preenche pelo "Editar sede" (nada é inventado). Auditável: o AuditLogSubscriber grava cada
 * edição, dentro do EscopoDeAuditoriaDePlataforma (trilha do Master, sem escritório).
 *
 * Desenho 05 inteiro (conferência de 21/10/2026, Version20261020149500): a linha guarda também se a seccional
 * aparece no mapa (`visivel`, filtro compartilhado entre os Masters) e a marca da divulgação da UF (status, cor da
 * paleta e data), como o protótipo guarda {cor, st, d} por UF. Só o estado atual: o histórico não aparece no desenho.
 */
#[ORM\Entity(repositoryClass: SeccionalOabRepository::class)]
#[ORM\Table(name: 'master_seccional_oab')]
#[ORM\UniqueConstraint(name: 'uniq_master_seccional_oab_uf', columns: ['uf'])]
#[ORM\Index(name: 'idx_master_seccional_oab_atualizado_por', columns: ['atualizado_por_id'])]
class SeccionalOab implements Auditavel
{
    public const ENDERECO_MAXIMO = 255;
    public const TELEFONE_MAXIMO = 40;
    public const SITE_MAXIMO = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: self::ENDERECO_MAXIMO, nullable: true)]
    private ?string $endereco = null;

    #[ORM\Column(length: self::TELEFONE_MAXIMO, nullable: true)]
    private ?string $telefone = null;

    #[ORM\Column(length: self::SITE_MAXIMO, nullable: true)]
    private ?string $site = null;

    /** Nulo enquanto a sede nunca foi editada (linha da carga da migration). */
    #[ORM\Column(name: 'atualizado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'atualizado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $atualizadoPor = null;

    /** Aparece no mapa e no chip (botão direito alterna; "Mostrar todas" volta todas para verdadeiro). */
    #[ORM\Column(options: ['default' => true])]
    private bool $visivel = true;

    /** Cor da paleta escolhida na marca da UF (#rrggbb); nula enquanto não há divulgação. */
    #[ORM\Column(name: 'marca_cor', length: 7, nullable: true)]
    private ?string $marcaCor = null;

    #[ORM\Column(name: 'marca_status', length: 20, nullable: true, enumType: StatusDaDivulgacao::class)]
    private ?StatusDaDivulgacao $marcaStatus = null;

    #[ORM\Column(name: 'marca_em', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $marcaEm = null;

    public function __construct(
        #[ORM\Column(length: 2)]
        private string $uf,
        #[ORM\Column(length: 120)]
        private string $nome,
    ) {
    }

    /** Troca a sede inteira: campo vazio vira nulo (o cartão mostra "Endereço da sede: consulte a página oficial"). */
    public function editarSede(?string $endereco, ?string $telefone, ?string $site, ?User $por, \DateTimeImmutable $em): void
    {
        $this->endereco = $endereco;
        $this->telefone = $telefone;
        $this->site = $site;
        $this->atualizadoPor = $por;
        $this->atualizadoEm = $em;
    }

    /** Botão direito no chip: mostra a oculta e oculta a visível. */
    public function definirVisibilidade(bool $visivel): void
    {
        $this->visivel = $visivel;
    }

    public function mostrar(): void
    {
        $this->visivel = true;
    }

    /** A divulgação da UF: troca a marca inteira (status, cor e data), como o protótipo. */
    public function marcar(StatusDaDivulgacao $status, string $cor, \DateTimeImmutable $em): void
    {
        $this->marcaStatus = $status;
        $this->marcaCor = $cor;
        $this->marcaEm = $em;
    }

    public function isVisivel(): bool
    {
        return $this->visivel;
    }

    public function getMarcaCor(): ?string
    {
        return $this->marcaCor;
    }

    public function getMarcaStatus(): ?StatusDaDivulgacao
    {
        return $this->marcaStatus;
    }

    public function getMarcaEm(): ?\DateTimeImmutable
    {
        return $this->marcaEm;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUf(): string
    {
        return $this->uf;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getEndereco(): ?string
    {
        return $this->endereco;
    }

    public function getTelefone(): ?string
    {
        return $this->telefone;
    }

    public function getSite(): ?string
    {
        return $this->site;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    public function getAtualizadoPor(): ?User
    {
        return $this->atualizadoPor;
    }
}
