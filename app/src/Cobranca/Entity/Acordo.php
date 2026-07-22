<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Repository\AcordoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Acordo de um Caso de Cobrança (SPEC §12): substitui uma ou várias obrigações do MESMO caso
 * (invariável 13) por novas obrigações (parcelas). As obrigações substituídas nunca são apagadas
 * (invariável 14) — só marcadas via `Obrigacao.acordoSubstituto` —; enquanto o acordo é VIGENTE
 * (`ativo`/`cumprido`) elas ficam fora do saldo exigível e as parcelas entram (invariável 15).
 * Rompimento e cancelamento são MANUAIS, registram motivo (§12.9) e — por derivação (invariável 20)
 * — restauram os originais e descartam as parcelas no saldo, sem reversão imperativa. Parcela
 * vencida NÃO rompe o acordo automaticamente (§12.7).
 */
#[ORM\Entity(repositoryClass: AcordoRepository::class)]
#[ORM\Table(name: 'cobranca_acordo')]
#[ORM\Index(name: 'idx_cobranca_acordo_tenant_caso', columns: ['tenant_id', 'caso_id'])]
#[ORM\Index(name: 'idx_cobranca_acordo_tenant_numero_externo', columns: ['tenant_id', 'numero_externo'])]
#[ORM\HasLifecycleCallbacks]
class Acordo implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: CasoCobranca::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?CasoCobranca $caso = null;

    #[ORM\Column(enumType: StatusAcordo::class)]
    private StatusAcordo $status = StatusAcordo::Ativo;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dataAcordo;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motivoRompimento = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motivoCancelamento = null;

    /**
     * Snapshot do total negociado (Ajuste 7), em CENTAVOS. DESCRITIVO e NÃO-autoritativo para saldo:
     * o saldo exigível segue derivado das obrigações (invariável 20). Null em acordos antigos (pré-Ajuste 7).
     */
    #[ORM\Column(name: 'valor_total_negociado', type: 'integer', nullable: true)]
    private ?int $valorTotalNegociado = null;

    /** Snapshot da entrada do acordo (Ajuste 7), em CENTAVOS; 0 = sem entrada. Descritivo (ver acima). */
    #[ORM\Column(name: 'valor_entrada', type: 'integer', options: ['default' => 0])]
    private int $valorEntrada = 0;

    /**
     * Identidade externa (spec `cobranca-importar-linhas-acordo.md` §4): número do acordo na origem
     * (relatório TOPLIFE). Dedup por (carteira + número) na (re)importação — a carteira é derivada
     * via `caso → objeto → carteira`, não há coluna direta. Nulo para acordos criados manualmente.
     */
    #[ORM\Column(name: 'numero_externo', type: 'integer', nullable: true)]
    private ?int $numeroExterno = null;

    /**
     * Total de parcelas esperado, lido do relatório de origem ("Acordo N - Parc. p/t", §3.3). Nulo
     * para acordos manuais. Quando presente e maior que `count(parcelas)`, o acordo está incompleto —
     * as parcelas faltantes entram por importação futura (quando vencerem) ou à mão (§3.3). NOTA de
     * implementação (tarefa #7-A): a spec §4 nomeia só `numero_externo`; esta coluna foi acrescentada
     * porque §3.3 exige indicar "faltam parcelas" e não há onde derivar o total esperado sem persistir.
     */
    #[ORM\Column(name: 'numero_parcelas_total', type: 'integer', nullable: true)]
    private ?int $numeroParcelasTotal = null;

    /**
     * Obrigações originais que este acordo substituiu (inverso; nunca apagadas — invariável 14).
     *
     * @var Collection<int, Obrigacao>
     */
    #[ORM\OneToMany(mappedBy: 'acordoSubstituto', targetEntity: Obrigacao::class)]
    #[ORM\OrderBy(['vencimentoOriginal' => 'ASC', 'id' => 'ASC'])]
    private Collection $obrigacoesSubstituidas;

    /**
     * Parcelas geradas por este acordo (inverso).
     *
     * Ordem EXPLÍCITA (como nas queries do ObrigacaoRepository): sem `OrderBy` o SELECT da coleção sai
     * sem `ORDER BY` e o Postgres devolve na ordem física da heap — e um UPDATE (reconhecer encargos,
     * editar a parcela) reescreve a tupla no fim, jogando "Parcela 3/6" na frente da "1/6" na tela.
     * `id` desempata parcelas de mesmo vencimento, mantendo a listagem estável.
     *
     * @var Collection<int, Obrigacao>
     */
    #[ORM\OneToMany(mappedBy: 'acordoOrigem', targetEntity: Obrigacao::class)]
    #[ORM\OrderBy(['vencimentoOriginal' => 'ASC', 'id' => 'ASC'])]
    private Collection $parcelas;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $criadoPor = null;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
        $this->dataAcordo = new \DateTimeImmutable();
        $this->obrigacoesSubstituidas = new ArrayCollection();
        $this->parcelas = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function estaAtivo(): bool
    {
        return $this->status === StatusAcordo::Ativo;
    }

    /** Rompimento manual (§12.9): só de acordo ativo; registra o motivo. */
    public function romper(string $motivo): self
    {
        $this->status = StatusAcordo::Rompido;
        $this->motivoRompimento = $motivo;

        return $this;
    }

    /** Cancelamento manual: só de acordo ativo; motivo opcional. */
    public function cancelar(?string $motivo): self
    {
        $this->status = StatusAcordo::Cancelado;
        $this->motivoCancelamento = $motivo;

        return $this;
    }

    /** Marca o acordo como cumprido (parcelas quitadas) — decisão manual (§28). */
    public function marcarCumprido(): self
    {
        $this->status = StatusAcordo::Cumprido;

        return $this;
    }

    /**
     * Total esperado (relatório de origem) maior que as parcelas já cadastradas — faltam parcelas
     * (§3.3). Acordo manual (sem `numeroParcelasTotal`) nunca está incompleto.
     */
    public function estaIncompleto(): bool
    {
        return $this->numeroParcelasTotal !== null && $this->parcelas->count() < $this->numeroParcelasTotal;
    }

    /** Quantas parcelas faltam para completar o total esperado; 0 se completo ou acordo manual. */
    public function parcelasFaltantes(): int
    {
        if ($this->numeroParcelasTotal === null) {
            return 0;
        }

        return max(0, $this->numeroParcelasTotal - $this->parcelas->count());
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getCaso(): ?CasoCobranca
    {
        return $this->caso;
    }

    public function setCaso(CasoCobranca $caso): self
    {
        $this->caso = $caso;

        return $this;
    }

    public function getStatus(): StatusAcordo
    {
        return $this->status;
    }

    public function setStatus(StatusAcordo $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getDataAcordo(): \DateTimeImmutable
    {
        return $this->dataAcordo;
    }

    public function setDataAcordo(\DateTimeImmutable $dataAcordo): self
    {
        $this->dataAcordo = $dataAcordo;

        return $this;
    }

    public function getMotivoRompimento(): ?string
    {
        return $this->motivoRompimento;
    }

    public function setMotivoRompimento(?string $motivoRompimento): self
    {
        $this->motivoRompimento = $motivoRompimento;

        return $this;
    }

    public function getMotivoCancelamento(): ?string
    {
        return $this->motivoCancelamento;
    }

    public function setMotivoCancelamento(?string $motivoCancelamento): self
    {
        $this->motivoCancelamento = $motivoCancelamento;

        return $this;
    }

    /**
     * @return Collection<int, Obrigacao>
     */
    public function getObrigacoesSubstituidas(): Collection
    {
        return $this->obrigacoesSubstituidas;
    }

    /**
     * @return Collection<int, Obrigacao>
     */
    public function getParcelas(): Collection
    {
        return $this->parcelas;
    }

    public function getValorTotalNegociado(): ?int
    {
        return $this->valorTotalNegociado;
    }

    public function setValorTotalNegociado(?int $valorTotalNegociado): self
    {
        $this->valorTotalNegociado = $valorTotalNegociado;

        return $this;
    }

    public function getValorEntrada(): int
    {
        return $this->valorEntrada;
    }

    public function setValorEntrada(int $valorEntrada): self
    {
        $this->valorEntrada = $valorEntrada;

        return $this;
    }

    public function getNumeroExterno(): ?int
    {
        return $this->numeroExterno;
    }

    public function setNumeroExterno(?int $numeroExterno): self
    {
        $this->numeroExterno = $numeroExterno;

        return $this;
    }

    public function getNumeroParcelasTotal(): ?int
    {
        return $this->numeroParcelasTotal;
    }

    public function setNumeroParcelasTotal(?int $numeroParcelasTotal): self
    {
        $this->numeroParcelasTotal = $numeroParcelasTotal;

        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function setCriadoPor(?User $criadoPor): self
    {
        $this->criadoPor = $criadoPor;

        return $this;
    }
}
