<?php

declare(strict_types=1);

namespace App\Djen\Entity;

use App\Djen\Enum\TipoDeAtoDoDiario;
use App\Djen\Repository\PublicacaoDoDiarioDaOabRepository;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Publicação do Diário Eletrônico da OAB (aba "Diário da OAB" do Push, desenho 11) registrada à mão.
 * Pertence a quem registrou: "só você vê esta aba" (a ético-disciplinar é sigilosa, art. 72 da Lei nº 8.906/1994).
 */
#[ORM\Entity(repositoryClass: PublicacaoDoDiarioDaOabRepository::class)]
#[ORM\Table(name: 'djen_diario_oab')]
#[ORM\Index(name: 'idx_djen_diario_oab_usuario', columns: ['tenant_id', 'usuario_id', 'excluida_em', 'data_publicacao'])]
#[ORM\Index(name: 'idx_djen_diario_oab_usuario_id', columns: ['usuario_id'])]
#[ORM\Index(name: 'idx_djen_diario_oab_processo', columns: ['processo_id'])]
#[ORM\Index(name: 'idx_djen_diario_oab_pasta', columns: ['pasta_id'])]
class PublicacaoDoDiarioDaOab implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Processo::class)]
    #[ORM\JoinColumn(name: 'processo_id', nullable: true, onDelete: 'SET NULL')]
    private ?Processo $processo = null;

    /** Pasta a que o usuário ligou a publicação. Null = avulsa. */
    #[ORM\ManyToOne(targetEntity: Pasta::class)]
    #[ORM\JoinColumn(name: 'pasta_id', nullable: true, onDelete: 'SET NULL')]
    private ?Pasta $pasta = null;

    /** Meta criada a partir do prazo (uma por publicação). */
    #[ORM\ManyToOne(targetEntity: Tarefa::class)]
    #[ORM\JoinColumn(name: 'tarefa_id', nullable: true, onDelete: 'SET NULL')]
    private ?Tarefa $tarefa = null;

    #[ORM\Column(name: 'lida_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lidaEm = null;

    #[ORM\Column(name: 'excluida_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $excluidaEm = null;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_id', nullable: false)]
        private User $usuario,
        #[ORM\Column(name: 'data_publicacao', type: 'date_immutable')]
        private \DateTimeImmutable $dataPublicacao,
        #[ORM\Column(length: 20)]
        private string $seccional,
        #[ORM\Column(length: 20, enumType: TipoDeAtoDoDiario::class)]
        private TipoDeAtoDoDiario $tipo,
        #[ORM\Column(length: 255)]
        private string $ato,
        #[ORM\Column(length: 255)]
        private string $orgao,
        #[ORM\Column(type: 'text')]
        private string $texto,
        #[ORM\Column(name: 'numero_processo', length: 50, nullable: true)]
        private ?string $numeroProcesso = null,
        #[ORM\Column(name: 'prazo_dias', type: 'smallint', nullable: true)]
        private ?int $prazoDias = null,
    ) {
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getDataPublicacao(): \DateTimeImmutable
    {
        return $this->dataPublicacao;
    }

    public function getSeccional(): string
    {
        return $this->seccional;
    }

    public function getTipo(): TipoDeAtoDoDiario
    {
        return $this->tipo;
    }

    public function getAto(): string
    {
        return $this->ato;
    }

    public function getOrgao(): string
    {
        return $this->orgao;
    }

    public function getTexto(): string
    {
        return $this->texto;
    }

    public function getNumeroProcesso(): ?string
    {
        return $this->numeroProcesso;
    }

    public function getPrazoDias(): ?int
    {
        return $this->prazoDias;
    }

    public function getProcesso(): ?Processo
    {
        return $this->processo;
    }

    public function getPasta(): ?Pasta
    {
        return $this->pasta;
    }

    public function vincularPasta(?Pasta $pasta): void
    {
        $this->pasta = $pasta;
    }

    public function getTarefa(): ?Tarefa
    {
        return $this->tarefa;
    }

    public function vincularTarefa(Tarefa $tarefa): void
    {
        $this->tarefa = $tarefa;
    }

    public function isLida(): bool
    {
        return $this->lidaEm !== null;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}
