<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\DecisaoSemProcessoDaPastaRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Decisão da equipe sobre uma pasta sem processo cujo conteúdo indicava atuação judicial
 * (desenho 1.2.3, `spMarcar` L4507-4512, chave `bj-semproc-dec-v1`).
 *
 * Nasce quando alguém marca ou desmarca "Administrativo sem processo" e a análise da pasta achou
 * termos judiciais: marcar grava `administrativo`, desmarcar grava `precisa`. Uma por pasta
 * (a última decisão sobrescreve a anterior). Alimenta o aprendizado do relatório: termo que aparece
 * em 2 ou mais decisões `administrativo` do escritório pesa 40% (L4518-4520).
 *
 * Fora do audit_log: é gravada por upsert SQL (contra a corrida no UNIQUE), e o ato da pessoa (a troca
 * de `administrativa` na Pasta) já é auditado; `por` e `em` ficam na própria linha.
 */
#[ORM\Entity(repositoryClass: DecisaoSemProcessoDaPastaRepository::class)]
#[ORM\Table(name: 'pasta_decisao_sem_processo')]
#[ORM\Index(name: 'idx_pasta_decisao_sem_processo_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pasta_decisao_sem_processo_pasta', columns: ['pasta_id'])]
class DecisaoSemProcessoDaPasta implements TenantAware
{
    public const ADMINISTRATIVO = 'administrativo';
    public const PRECISA        = 'precisa';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $decisao;

    /** @var list<string> os rótulos dos termos judiciais que a análise achou (SP_JUD, L4504) */
    #[ORM\Column(type: 'json')]
    private array $termos;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $por;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $em;

    /**
     * @param list<string> $termos
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Pasta::class)]
        #[ORM\JoinColumn(name: 'pasta_id', nullable: false, onDelete: 'CASCADE')]
        private Pasta $pasta,
        string $decisao,
        array $termos,
        ?User $por,
        \DateTimeImmutable $em,
    ) {
        $this->decisao = self::exigirDecisaoValida($decisao);
        $this->termos  = $termos;
        $this->por     = $por;
        $this->em      = $em;
    }

    /**
     * A decisão mais nova substitui a anterior (uma por pasta, como a chave por pasta do desenho).
     *
     * @param list<string> $termos
     */
    public function redefinir(string $decisao, array $termos, ?User $por, \DateTimeImmutable $em): void
    {
        $this->decisao = self::exigirDecisaoValida($decisao);
        $this->termos  = $termos;
        $this->por     = $por;
        $this->em      = $em;
    }

    private static function exigirDecisaoValida(string $decisao): string
    {
        if (!\in_array($decisao, [self::ADMINISTRATIVO, self::PRECISA], true)) {
            throw new \DomainException('Decisão inválida para pasta sem processo.');
        }

        return $decisao;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getPasta(): Pasta
    {
        return $this->pasta;
    }

    public function getDecisao(): string
    {
        return $this->decisao;
    }

    /** @return list<string> */
    public function getTermos(): array
    {
        return $this->termos;
    }

    public function getPor(): ?User
    {
        return $this->por;
    }

    public function getEm(): \DateTimeImmutable
    {
        return $this->em;
    }
}
