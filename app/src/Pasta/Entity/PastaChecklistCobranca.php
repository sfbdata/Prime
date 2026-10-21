<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaChecklistCobrancaRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * O registro da cobrança automática de UM item do checklist (desenho 02, `bj-ck-cob-v1`, dc 4278-4372):
 * desde quando está pendente, as datas em que o responsável foi cobrado, a previsão que ele informou e
 * quantas vezes a quebrou; e, para item marcado sem o documento, a auditoria "sem anexo".
 *
 * Estado operacional, não auditável: o aviso que sai já fica na caixa de cada pessoa. Item concluído com
 * anexo, ou que volta a pendente, perde o registro e recomeça do zero, como no desenho.
 */
#[ORM\Entity(repositoryClass: PastaChecklistCobrancaRepository::class)]
#[ORM\Table(name: 'pasta_checklist_cobranca')]
#[ORM\UniqueConstraint(name: 'uniq_pasta_checklist_cobranca_item', columns: ['item_id'])]
#[ORM\Index(name: 'idx_pasta_checklist_cobranca_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_pasta_checklist_cobranca_auditoria_por', columns: ['auditoria_por_id'])]
class PastaChecklistCobranca implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** @var list<string> datas (Y-m-d) em que o responsável foi cobrado */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $envios = [];

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $previsao = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $previsaoInformadaEm = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $quebras = 0;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $auditoriaEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $auditoriaPor = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: PastaChecklistItem::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private PastaChecklistItem $item,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $pendenteDesde,
    ) {
    }

    public function registrarEnvio(\DateTimeImmutable $hoje): void
    {
        $dia = $hoje->format('Y-m-d');
        if (!\in_array($dia, $this->envios, true)) {
            $this->envios[] = $dia;
        }
    }

    /** Cobrou por previsão descumprida: a previsão cai e a quebra conta (dc 4364). */
    public function registrarQuebra(): void
    {
        $this->previsao = null;
        ++$this->quebras;
    }

    public function informarPrevisao(?\DateTimeImmutable $data, \DateTimeImmutable $agora): void
    {
        $this->previsao = $data;
        $this->previsaoInformadaEm = $agora;
    }

    public function registrarAuditoria(?User $por, \DateTimeImmutable $hoje): void
    {
        $this->auditoriaEm = $hoje;
        $this->auditoriaPor = $por;
    }

    public function limparAuditoria(): void
    {
        $this->auditoriaEm = null;
        $this->auditoriaPor = null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getItem(): PastaChecklistItem
    {
        return $this->item;
    }

    public function getPendenteDesde(): \DateTimeImmutable
    {
        return $this->pendenteDesde;
    }

    /** @return list<string> */
    public function getEnvios(): array
    {
        return $this->envios;
    }

    public function getPrevisao(): ?\DateTimeImmutable
    {
        return $this->previsao;
    }

    public function getQuebras(): int
    {
        return $this->quebras;
    }

    public function getAuditoriaEm(): ?\DateTimeImmutable
    {
        return $this->auditoriaEm;
    }

    public function getAuditoriaPor(): ?User
    {
        return $this->auditoriaPor;
    }
}
