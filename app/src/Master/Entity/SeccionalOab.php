<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
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

    public function __construct(
        #[ORM\Column(length: 2)]
        private string $uf,
        #[ORM\Column(length: 120)]
        private string $nome,
    ) {
    }

    /** Troca a sede inteira: campo vazio vira nulo (o cartão mostra "Endereço não cadastrado"). */
    public function editarSede(?string $endereco, ?string $telefone, ?string $site, ?User $por, \DateTimeImmutable $em): void
    {
        $this->endereco = $endereco;
        $this->telefone = $telefone;
        $this->site = $site;
        $this->atualizadoPor = $por;
        $this->atualizadoEm = $em;
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
