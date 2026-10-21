<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Master\Repository\SubsecaoOabRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Endereço de uma subseção da OAB, cadastrado pelo Master na janela da OAB do mapa "Onde estamos atuando"
 * (spec docs/specs/master-oab-estados-que-faltam.md, "SUBSEÇÕES"; desenho 05, Perfis 1.2, VS 21.10.26, L487-489).
 *
 * GLOBAL, sem tenant, como a {@see SeccionalOab} de que faz parte: é dado da PLATAFORMA (trabalho comercial do
 * BlueJus), lido e gravado só pelo Master (ROLE_SUPER_ADMIN). Só adicionar e remover (o desenho não mostra editar;
 * DONO). O autor fica nulo se a conta dele for apagada (SET NULL). Auditável, dentro do EscopoDeAuditoriaDePlataforma.
 *
 * Desenho 05 inteiro (Version20261020149500): a subseção é de uma cidade da UF. A coluna `cidade` nasceu nula para
 * não mexer nas linhas que já existiam; o UseCase exige a cidade em toda subseção nova.
 */
#[ORM\Entity(repositoryClass: SubsecaoOabRepository::class)]
#[ORM\Table(name: 'master_subsecao_oab')]
#[ORM\Index(name: 'idx_master_subsecao_oab_seccional', columns: ['seccional_id'])]
#[ORM\Index(name: 'idx_master_subsecao_oab_autor', columns: ['autor_id'])]
class SubsecaoOab implements Auditavel
{
    public const NOME_MAXIMO = 120;
    public const CIDADE_MAXIMA = 120;
    public const ENDERECO_MAXIMO = 255;
    public const TELEFONE_MAXIMO = 40;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: SeccionalOab::class)]
        #[ORM\JoinColumn(name: 'seccional_id', nullable: false, onDelete: 'CASCADE')]
        private SeccionalOab $seccional,
        #[ORM\Column(length: self::CIDADE_MAXIMA, nullable: true)]
        private ?string $cidade,
        #[ORM\Column(length: self::NOME_MAXIMO)]
        private string $nome,
        #[ORM\Column(length: self::ENDERECO_MAXIMO)]
        private string $endereco,
        #[ORM\Column(length: self::TELEFONE_MAXIMO, nullable: true)]
        private ?string $telefone,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'autor_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $autor,
        \DateTimeImmutable $criadoEm,
    ) {
        $this->criadoEm = $criadoEm;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeccional(): SeccionalOab
    {
        return $this->seccional;
    }

    public function getCidade(): ?string
    {
        return $this->cidade;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getEndereco(): string
    {
        return $this->endereco;
    }

    public function getTelefone(): ?string
    {
        return $this->telefone;
    }

    public function getAutor(): ?User
    {
        return $this->autor;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}
