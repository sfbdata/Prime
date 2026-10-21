<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Master\Enum\TipoDeDivulgacao;
use App\Master\Repository\DivulgacaoNaSeccionalRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma divulgação do BlueJus numa seccional da OAB, registrada pelo Master no botão direito do mapa
 * (spec docs/specs/master-seccionais-oab.md; desenho 05, Perfis 1.2, VS 21.10.26: "material enviado,
 * reunião, parceria"). A mais recente dá a cor do ponto do chip ("seccional já trabalhada").
 *
 * GLOBAL, sem tenant, como a {@see SeccionalOab}: é trabalho comercial da PLATAFORMA, só do Master.
 * Só inclusão (não há editar nem apagar nesta entrega). O autor fica nulo se a conta dele for apagada
 * (SET NULL): o registro continua valendo. Auditável, dentro do EscopoDeAuditoriaDePlataforma.
 *
 * OBSOLETO desde a Version20261020149500 (desenho 05 inteiro): a divulgação virou a marca da UF na própria
 * SeccionalOab (status, cor e data). Nada mais lê nem grava aqui; a tabela `master_divulgacao_seccional` fica no
 * banco até a limpeza combinada com o dono (o DROP vai nessa limpeza, não agora).
 */
#[ORM\Entity(repositoryClass: DivulgacaoNaSeccionalRepository::class)]
#[ORM\Table(name: 'master_divulgacao_seccional')]
#[ORM\Index(name: 'idx_master_divulgacao_seccional_data', columns: ['seccional_id', 'data'])]
#[ORM\Index(name: 'idx_master_divulgacao_seccional_autor', columns: ['autor_id'])]
class DivulgacaoNaSeccional implements Auditavel
{
    public const NOTA_MAXIMA = 500;

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
        #[ORM\Column(length: 20, enumType: TipoDeDivulgacao::class)]
        private TipoDeDivulgacao $tipo,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $data,
        #[ORM\Column(length: self::NOTA_MAXIMA, nullable: true)]
        private ?string $nota,
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

    public function getTipo(): TipoDeDivulgacao
    {
        return $this->tipo;
    }

    public function getData(): \DateTimeImmutable
    {
        return $this->data;
    }

    public function getNota(): ?string
    {
        return $this->nota;
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
