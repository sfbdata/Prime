<?php

declare(strict_types=1);

namespace App\Processo\Entity;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Processo\Repository\PrevisaoRegistradaRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma previsão da "Situação atual" do Push (spec push-painel-monitoramento §3), guardada para a
 * taxa de acertos. É o porte, no servidor, do `bj-push-previsao-v1` que o desenho guardava no
 * navegador (dc L.5104-5109):
 *   - nasce quando a ÚLTIMA movimentação do processo muda (chave = "dd/mm/aaaa Tipo"), com o
 *     próximo ato que a regra previu (`previsto`, vazio quando nenhuma regra casou);
 *   - é conferida UMA vez, quando chega a movimentação seguinte: `acertou` diz se alguma palavra
 *     longa do previsto aparece no texto do que realmente aconteceu (`PrevisaoProcessual::acertou`).
 *
 * Anatomia:
 *   - UNIQUE (pasta_id, chave): uma previsão por movimentação DA PASTA, árbitro do INSERT
 *     idempotente. É por pasta, e não por processo, porque a lista lida é a da pasta (todos os
 *     processos dela) e o mesmo processo pode estar em outra pasta, com outra lista: por processo,
 *     uma pasta conferiria a previsão da outra;
 *   - `pasta_id` e `processo_id` (o principal da pasta quando a previsão nasceu) CASCADE;
 *   - `tenant_id` NOT NULL: TenantAware, passa pelo TenantFilter e pela purga.
 *
 * Dado derivado, gravado pelo sistema na montagem da aba: fora da auditoria (AuditavelCoberturaTest).
 */
#[ORM\Entity(repositoryClass: PrevisaoRegistradaRepository::class)]
#[ORM\Table(name: 'previsao_processual')]
#[ORM\Index(name: 'idx_previsao_processual_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_previsao_processual_pasta_chave', columns: ['pasta_id', 'chave'])]
class PrevisaoRegistrada implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $conferida = false;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $acertou = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Pasta::class)]
        #[ORM\JoinColumn(name: 'pasta_id', nullable: false, onDelete: 'CASCADE')]
        private Pasta $pasta,
        #[ORM\ManyToOne(targetEntity: Processo::class)]
        #[ORM\JoinColumn(name: 'processo_id', nullable: false, onDelete: 'CASCADE')]
        private Processo $processo,
        #[ORM\Column(length: 255)]
        private string $chave,
        #[ORM\Column(type: 'text')]
        private string $previsto,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $em,
    ) {
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

    public function getProcesso(): Processo
    {
        return $this->processo;
    }

    public function getChave(): string
    {
        return $this->chave;
    }

    public function getPrevisto(): string
    {
        return $this->previsto;
    }

    public function getEm(): \DateTimeImmutable
    {
        return $this->em;
    }

    public function isConferida(): bool
    {
        return $this->conferida;
    }

    public function getAcertou(): ?bool
    {
        return $this->acertou;
    }
}
