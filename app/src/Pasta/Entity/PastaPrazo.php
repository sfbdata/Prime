<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Prazo ou audiência registrado à mão na pasta: o "+ Adicionar prazo ou audiência" do trilho da aba Dados
 * (desenho 1.2.3, `bj-pz-manual-v1`). Aparece em "Próximos prazos" junto das metas com prazo e alimenta os
 * filtros EXPEDIENTES da Lista (Audiência, Com prazo, Prazo findou).
 *
 * Cumprido não sai do banco: some da lista e fica a data e quem cumpriu. Excluir apaga de verdade, como o
 * desenho; o cartão de sistema no Registro e o audit_log guardam o que foi.
 * Responsável é texto livre, como o desenho (não é vínculo com colaborador).
 */
#[ORM\Entity(repositoryClass: PastaPrazoRepository::class)]
#[ORM\Table(name: 'pasta_prazo')]
#[ORM\Index(name: 'idx_pasta_prazo_tenant_data', columns: ['tenant_id', 'data'])]
#[ORM\Index(name: 'idx_pasta_prazo_pasta', columns: ['pasta_id'])]
class PastaPrazo implements TenantAware, Auditavel
{
    public const TIPO_PRAZO     = 'prazo';
    public const TIPO_AUDIENCIA = 'audiencia';
    public const TIPOS = [self::TIPO_PRAZO, self::TIPO_AUDIENCIA];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $tipo = self::TIPO_PRAZO;

    #[ORM\Column(length: 255)]
    private string $titulo = '';

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $data;

    /** "HH:MM" ou null (a hora é opcional, também na audiência, como o desenho). */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $hora = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $responsavel = null;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(name: 'cumprido_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cumpridoEm = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'cumprido_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $cumpridoPor = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Pasta::class)]
        #[ORM\JoinColumn(name: 'pasta_id', nullable: false, onDelete: 'CASCADE')]
        private Pasta $pasta,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'criado_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $criadoPor,
    ) {
        $this->criadoEm = new \DateTimeImmutable();
        $this->data = new \DateTimeImmutable('today');
    }

    public function definir(string $tipo, string $titulo, \DateTimeImmutable $data, ?string $hora, ?string $responsavel): void
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new \DomainException('Tipo de prazo inválido.');
        }
        $titulo = trim($titulo);
        if ($titulo === '') {
            throw new \DomainException('Informe a descrição e a data');
        }
        if ($hora !== null && $hora !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
            throw new \DomainException('Hora inválida.');
        }
        $this->tipo = $tipo;
        $this->titulo = mb_substr($titulo, 0, 255);
        $this->data = $data;
        $this->hora = ($hora === null || $hora === '') ? null : $hora;
        $responsavel = $responsavel === null ? '' : trim($responsavel);
        $this->responsavel = $responsavel === '' ? null : mb_substr($responsavel, 0, 120);
    }

    public function cumprir(User $por): void
    {
        if ($this->cumpridoEm !== null) {
            throw new \DomainException('Este prazo já foi cumprido.');
        }
        $this->cumpridoEm = new \DateTimeImmutable();
        $this->cumpridoPor = $por;
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getPasta(): Pasta { return $this->pasta; }
    public function getTipo(): string { return $this->tipo; }
    public function isAudiencia(): bool { return $this->tipo === self::TIPO_AUDIENCIA; }
    public function getTitulo(): string { return $this->titulo; }
    public function getData(): \DateTimeImmutable { return $this->data; }
    public function getHora(): ?string { return $this->hora; }
    public function getResponsavel(): ?string { return $this->responsavel; }
    public function getCriadoEm(): \DateTimeImmutable { return $this->criadoEm; }
    public function getCriadoPor(): ?User { return $this->criadoPor; }
    public function getCumpridoEm(): ?\DateTimeImmutable { return $this->cumpridoEm; }
    public function getCumpridoPor(): ?User { return $this->cumpridoPor; }
    public function estaCumprido(): bool { return $this->cumpridoEm !== null; }
}
