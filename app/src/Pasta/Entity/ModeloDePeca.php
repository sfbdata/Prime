<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Enum\MateriaDeModelo;
use App\Pasta\Enum\TipoDePeca;
use App\Pasta\Repository\ModeloDePecaRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um modelo de petição do banco de modelos do escritório (desenho 02 de 27/10, aba Modelos da pasta).
 *
 * Pende do ESCRITÓRIO, não de uma pasta: o modelo vale em todas as pastas. O corpo é HTML rico já
 * sanitizado ({@see \App\Pasta\Service\SanitizadorDeModeloDePeca}); por ser grande, a entidade NÃO é
 * `Auditavel` (o corpo inteiro iria para o audit_log a cada edição).
 *
 * `usos` existe para o "Mais usados": no M1 fica em 0, quem incrementa é o M2 ("Usar nesta pasta").
 * `atualizadoEm` mexe a cada edição e alimenta o "Mais recentes".
 */
#[ORM\Entity(repositoryClass: ModeloDePecaRepository::class)]
#[ORM\Table(name: 'modelo_de_peca')]
#[ORM\Index(name: 'idx_modelo_de_peca_tenant_materia', columns: ['tenant_id', 'materia'])]
class ModeloDePeca implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Quem escreveu. Some junto com o usuário; o modelo, não: ele é do escritório. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $autor = null;

    #[ORM\Column(length: 255)]
    private string $titulo = '';

    #[ORM\Column(length: 30, enumType: MateriaDeModelo::class)]
    private MateriaDeModelo $materia;

    #[ORM\Column(length: 40, enumType: TipoDePeca::class)]
    private TipoDePeca $tipo;

    #[ORM\Column(type: 'text')]
    private string $corpo = '';

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $usos = 0;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(name: 'atualizado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
    ) {
        $this->materia      = MateriaDeModelo::Civel;
        $this->tipo         = TipoDePeca::PeticaoInicial;
        $this->criadoEm     = new \DateTimeImmutable();
        $this->atualizadoEm = $this->criadoEm;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getAutor(): ?User
    {
        return $this->autor;
    }

    public function setAutor(?User $autor): self
    {
        $this->autor = $autor;

        return $this;
    }

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function getMateria(): MateriaDeModelo
    {
        return $this->materia;
    }

    public function getTipo(): TipoDePeca
    {
        return $this->tipo;
    }

    public function getCorpo(): string
    {
        return $this->corpo;
    }

    public function getUsos(): int
    {
        return $this->usos;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAtualizadoEm(): \DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    /** Grava o conteúdo editável e marca a edição (o corpo já chega sanitizado). */
    public function atualizar(string $titulo, MateriaDeModelo $materia, TipoDePeca $tipo, string $corpo): self
    {
        $this->titulo       = trim($titulo);
        $this->materia      = $materia;
        $this->tipo         = $tipo;
        $this->corpo        = $corpo;
        $this->atualizadoEm = new \DateTimeImmutable();

        return $this;
    }
}
