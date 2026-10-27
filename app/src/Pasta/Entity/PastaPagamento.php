<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaPagamentoRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * O que o cliente combinou pagar por esta pasta: honorário, parcela de
 * honorário, reembolso de custas.
 *
 * Não confundir com a Cobrança: lá o dinheiro é a dívida de um devedor numa
 * carteira, aqui é o que o escritório tem a receber pelo caso. São coisas
 * diferentes, com donos diferentes, e nunca se somam.
 *
 * O ESTADO NÃO É GRAVADO, é derivado de `pagoEm` + `vencimento`. Uma coluna de
 * status abriria a porta para ela divergir da data — que é exatamente o defeito
 * que este repositório já pagou caro em outros módulos.
 *
 * ÊXITO E SUCUMBÊNCIA (spec pasta-exito-sucumbencia): o lançamento condicionado ao
 * resultado nasce com `percentual` e SEM vencimento. Enquanto não é pago ele é PREVISTO
 * (`estaPrevisto`), também derivado: tipo + `pagoEm`. O valor em reais de quem tem
 * percentual é calculado na leitura (`BaseDoPercentual`); `valor` guarda só o retrato do
 * lançamento. Ele congela (`congelarValor`) ao ser recebido ou corrigido.
 */
#[ORM\Entity(repositoryClass: PastaPagamentoRepository::class)]
#[ORM\Table(name: 'pasta_pagamento')]
#[ORM\Index(name: 'idx_pasta_pagamento_pasta_id', columns: ['pasta_id'])]
#[ORM\Index(name: 'idx_pasta_pagamento_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_pasta_pagamento_vencimento', columns: ['vencimento'])]
class PastaPagamento implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Pasta::class, inversedBy: 'pagamentos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Pasta $pasta = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    /** Quem lançou. Some junto com o usuário; o pagamento não. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $autor = null;

    #[ORM\Column(length: 120)]
    private string $descricao = '';

    /** Decimal em string, como o resto do dinheiro do sistema. Nunca float. */
    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $valor = '0.00';

    /** Nulo só no êxito e na sucumbência (CHECK `ck_pasta_pagamento_vencimento`). */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $vencimento;

    #[ORM\Column(length: 20, enumType: TipoDePagamento::class, options: ['default' => 'contrato'])]
    private TipoDePagamento $tipo = TipoDePagamento::Contrato;

    /**
     * Percentual do êxito ou da sucumbência ("20.00"), sobre o Total contratado. Preenchido
     * = o valor é calculado na leitura; nulo = vale o `valor` gravado. Só existe em êxito ou
     * sucumbência ainda não pagos (CHECK `ck_pasta_pagamento_percentual`).
     */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $percentual = null;

    /** Preenchido = pago. É esta coluna, e só ela, que define o estado. */
    #[ORM\Column(name: 'pago_em', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $pagoEm = null;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    /**
     * A taxa de juros ao mês, em percentual ("1.5000" = 1,5% a.m.), da parcela que nasceu de um
     * parcelamento COM juros (Price) — FPN-009, o sufixo "· juros N% a.m." da linha.
     *
     * É só REGISTRO do que foi combinado: nenhuma conta lê esta coluna. O valor da parcela já
     * nasce calculado (`CalculadoraDeParcelamento`) e é ele, e só ele, que soma no previsto e no
     * recebido. Nulo na entrada, no lançamento avulso, no parcelamento sem juros e em todo
     * lançamento anterior a esta coluna (sem backfill: a taxa de antes não foi guardada).
     */
    #[ORM\Column(name: 'juros_mensal', type: 'decimal', precision: 6, scale: 4, nullable: true)]
    private ?string $jurosMensal = null;

    public function __construct()
    {
        $this->criadoEm   = new \DateTimeImmutable();
        $this->vencimento = new \DateTimeImmutable('today');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPasta(): ?Pasta
    {
        return $this->pasta;
    }

    public function setPasta(?Pasta $pasta): self
    {
        $this->pasta = $pasta;

        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
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

    public function getDescricao(): string
    {
        return $this->descricao;
    }

    public function setDescricao(string $descricao): self
    {
        $this->descricao = $descricao;

        return $this;
    }

    public function getValor(): string
    {
        return $this->valor;
    }

    public function setValor(string $valor): self
    {
        $this->valor = $valor;

        return $this;
    }

    /** Nulo no êxito e na sucumbência: o previsto não vence. */
    public function getVencimento(): ?\DateTimeImmutable
    {
        return $this->vencimento;
    }

    public function setVencimento(?\DateTimeImmutable $vencimento): self
    {
        $this->vencimento = $vencimento;

        return $this;
    }

    public function getTipo(): TipoDePagamento
    {
        return $this->tipo;
    }

    public function setTipo(TipoDePagamento $tipo): self
    {
        $this->tipo = $tipo;

        return $this;
    }

    public function getPercentual(): ?string
    {
        return $this->percentual;
    }

    /**
     * Lança o êxito ou a sucumbência: percentual, sem vencimento, e o retrato do valor
     * calculado neste momento (o que o histórico mostra como "era" se ele for corrigido).
     *
     * @param string $percentual decimal com ponto ("20", "12.5")
     */
    public function preverPorPercentual(TipoDePagamento $tipo, string $percentual, string $retratoDoValor): self
    {
        if (!$tipo->ehCondicionado()) {
            throw new \LogicException('Só êxito e sucumbência são lançados por percentual.');
        }

        $this->tipo       = $tipo;
        $this->percentual = bcadd($percentual, '0', 2);
        $this->vencimento = null;
        $this->valor      = $retratoDoValor;

        return $this;
    }

    /** Êxito ou sucumbência ainda não recebidos: fora do contratado, do recebido e dos vencidos. */
    public function estaPrevisto(): bool
    {
        return $this->tipo->ehCondicionado() && $this->pagoEm === null;
    }

    /**
     * Grava o valor em reais e larga o percentual: a partir daqui vale o número, não a conta.
     * Só "Marcar como recebido" e "Corrigir valor" chegam aqui (decisão 3 do dono).
     */
    public function congelarValor(string $valor): self
    {
        $this->valor      = $valor;
        $this->percentual = null;

        return $this;
    }

    public function getPagoEm(): ?\DateTimeImmutable
    {
        return $this->pagoEm;
    }

    public function estaPago(): bool
    {
        return $this->pagoEm !== null;
    }

    /**
     * Vencido é pendente com data no passado — nunca um pagamento já quitado,
     * por mais atrasada que a quitação tenha sido, nem um previsto.
     */
    public function estaVencido(?\DateTimeImmutable $hoje = null): bool
    {
        // O previsto nunca vence (desenho 02, `tabPendencias`): nem tem vencimento.
        if ($this->pagoEm !== null || $this->estaPrevisto() || $this->vencimento === null) {
            return false;
        }

        return $this->vencimento < ($hoje ?? new \DateTimeImmutable('today'));
    }

    /**
     * Marca como pago na data informada (hoje, por padrão) ou desfaz a
     * quitação. Uma única porta para os dois sentidos: é o mesmo gesto na tela.
     */
    public function alternarQuitacao(?\DateTimeImmutable $quando = null): self
    {
        // Recebido não tem percentual: quem quita congela antes (AlternarQuitacaoDoPagamentoUseCase).
        if ($this->pagoEm === null && $this->percentual !== null) {
            throw new \LogicException('Congele o valor do previsto antes de marcá-lo como recebido.');
        }

        $this->pagoEm = $this->pagoEm === null
            ? ($quando ?? new \DateTimeImmutable('today'))
            : null;

        return $this;
    }

    public function getJurosMensal(): ?string
    {
        return $this->jurosMensal;
    }

    /** Percentual ao mês, decimal com ponto ("1.5000"); nulo = sem juros registrados. */
    public function setJurosMensal(?string $jurosMensal): self
    {
        $this->jurosMensal = $jurosMensal;

        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}
