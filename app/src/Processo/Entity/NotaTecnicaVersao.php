<?php

declare(strict_types=1);

namespace App\Processo\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\Repository\NotaTecnicaVersaoRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma versão ANTERIOR de uma nota técnica (PMC-020; desenho 1.2.3, `notasGravar`: a cada edição,
 * `versoes.concat([{ txt: anterior, em: editado || em }])`). Cada edição que muda o texto guarda
 * aqui o texto de antes, INTEIRO — o `audit_log` corta texto em 500 caracteres e a nota vai até
 * 5000, então a versão anterior se perderia.
 *
 * `escrita_em` é o `em` do desenho: quando AQUELE texto foi escrito (a criação da nota ou a edição
 * anterior), não quando foi substituído. `autor` é quem o escreveu — a edição é só do autor da
 * nota, então é o autor dela naquele momento; SET NULL pelo mesmo motivo da nota (desvincular o
 * colaborador não apaga o que ele escreveu).
 *
 * Só se GRAVA. O desenho guarda as versões e não as mostra em lugar nenhum; uma tela de "versões"
 * (quem vê, por quanto tempo ficam) é decisão do dono e está PENDENTE — não foi inventada aqui.
 *
 * A versão some com a nota (CASCADE) e com o escritório (purga, `ORDEM_DELECAO`).
 */
#[ORM\Entity(repositoryClass: NotaTecnicaVersaoRepository::class)]
#[ORM\Table(name: 'nota_tecnica_versao')]
#[ORM\Index(name: 'idx_nota_tecnica_versao_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_nota_tecnica_versao_nota', columns: ['nota_tecnica_id'])]
class NotaTecnicaVersao implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: NotaTecnica::class)]
        #[ORM\JoinColumn(name: 'nota_tecnica_id', nullable: false, onDelete: 'CASCADE')]
        private NotaTecnica $nota,
        #[ORM\Column(type: 'text')]
        private string $conteudo,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'autor_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $autor,
        #[ORM\Column(name: 'escrita_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $escritaEm,
    ) {
    }

    /** A versão do texto ATUAL da nota, antes de ele ser substituído pela edição. */
    public static function daNotaAntesDeEditar(NotaTecnica $nota, Tenant $tenant): self
    {
        return new self($tenant, $nota, $nota->getConteudo(), $nota->getAutor(), $nota->getEditadaEm() ?? $nota->getCriadaEm());
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getNota(): NotaTecnica
    {
        return $this->nota;
    }

    public function getConteudo(): string
    {
        return $this->conteudo;
    }

    public function getAutor(): ?User
    {
        return $this->autor;
    }

    public function getEscritaEm(): \DateTimeImmutable
    {
        return $this->escritaEm;
    }
}
