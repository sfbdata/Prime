<?php

declare(strict_types=1);

namespace App\Agenda\Entity;

use App\Agenda\Enum\TipoDeAjuste;
use App\Agenda\Repository\AjusteDeDataRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ajuste do escritório numa data que a Agenda mostra sem cadastro (feriado, recesso, tribunal,
 * comemorativa, aniversário). Vale só para a Agenda: a tabela `feriado` do Ponto não é tocada, e
 * ocultar um feriado aqui não o tira do cálculo da jornada (spec agenda-vs-18-10-26, seções 5 e 13).
 *
 * `dataId` é o id estável da data do sistema (ex. `fer-2026-02-17-carnaval`); nas datas acrescentadas
 * é nulo, e a Agenda as mostra com o id `x-<id>`.
 */
#[ORM\Entity(repositoryClass: AjusteDeDataRepository::class)]
#[ORM\Table(name: 'agenda_data_ajuste')]
#[ORM\Index(name: 'idx_agenda_data_ajuste_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_agenda_data_ajuste_data', columns: ['tenant_id', 'tipo', 'data_id'])]
class AjusteDeData implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\Column(length: 20, enumType: TipoDeAjuste::class)]
        private TipoDeAjuste $tipo,
        #[ORM\Column(name: 'data_id', length: 120, nullable: true)]
        private ?string $dataId,
        #[ORM\Column(type: 'date_immutable', nullable: true)]
        private ?\DateTimeImmutable $data,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $titulo,
        #[ORM\Column(length: 10, nullable: true)]
        private ?string $calendario,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $fonte,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'criado_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $criadoPor,
    ) {
        $this->criadoEm = new \DateTimeImmutable();
    }

    public static function acrescentar(Tenant $tenant, \DateTimeImmutable $data, string $titulo, string $calendario, ?string $fonte, User $por): self
    {
        return new self($tenant, TipoDeAjuste::Acrescentar, null, $data, $titulo, $calendario, $fonte, $por);
    }

    public static function renomear(Tenant $tenant, string $dataId, string $titulo, User $por): self
    {
        return new self($tenant, TipoDeAjuste::Renomear, $dataId, null, $titulo, null, null, $por);
    }

    public static function ocultar(Tenant $tenant, string $dataId, User $por): self
    {
        return new self($tenant, TipoDeAjuste::Ocultar, $dataId, null, null, null, null, $por);
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getTenant(): Tenant
    {
        return $this->tenant;
    }
    public function getTipo(): TipoDeAjuste
    {
        return $this->tipo;
    }
    public function getDataId(): ?string
    {
        return $this->dataId;
    }
    public function getData(): ?\DateTimeImmutable
    {
        return $this->data;
    }
    public function getTitulo(): ?string
    {
        return $this->titulo;
    }
    public function getCalendario(): ?string
    {
        return $this->calendario;
    }
    public function getFonte(): ?string
    {
        return $this->fonte;
    }
    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function mudarTitulo(string $titulo): void
    {
        $this->titulo = $titulo;
    }
}
