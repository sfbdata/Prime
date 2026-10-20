<?php

declare(strict_types=1);

namespace App\Contribua\Entity;

use App\Contribua\Repository\MotivoDeNaoApoiarRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Ainda não é o momento de contribuir?" (desenho 08): o motivo, em um clique e **anônimo**.
 *
 * Guarda só o escritório (para não perguntar de novo por 30 dias) e o motivo. Não guarda quem
 * respondeu: o desenho promete anonimato, e o Master recebe só "Conta Nº … : motivo".
 */
#[ORM\Entity(repositoryClass: MotivoDeNaoApoiarRepository::class)]
#[ORM\Table(name: 'contribua_motivo_de_nao_apoiar')]
#[ORM\Index(name: 'idx_contribua_motivo_tenant_criado', columns: ['tenant_id', 'criado_em'])]
class MotivoDeNaoApoiar implements TenantAware
{
    public const MOTIVOS = [
        'Ainda estou conhecendo o sistema',
        'Sem orçamento agora',
        'Prefiro comprar espaço',
        'Não sabia que podia contribuir',
        'Outro motivo',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\Column(length: 200)]
        private string $motivo,
    ) {
        $this->motivo = mb_substr(trim($motivo), 0, 200);
        if ($this->motivo === '') {
            throw new \DomainException('Conte o motivo em poucas palavras.');
        }
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getMotivo(): string
    {
        return $this->motivo;
    }
}
