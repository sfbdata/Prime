<?php

declare(strict_types=1);

namespace App\Pagamento\Entity;

use App\Entity\Tenant\Tenant;
use App\Pagamento\Enum\MetodoDePagamento;
use App\Pagamento\Enum\OrigemDoPagamento;
use App\Pagamento\Enum\ResultadoDaConfirmacao;
use App\Pagamento\Enum\StatusDoPagamento;
use App\Pagamento\Repository\PagamentoRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * O registro único de "quem pagou, quando e quanto" (spec `gateway-pagamentos.md` §4). Os consumidores (Trabalho
 * Externo, Service Desk, Contribua) ligam a ele por `origem` + `origemId`; nenhum guarda o dinheiro por conta própria.
 *
 * O valor é congelado na criação: editar a proposta depois não muda uma cobrança que já existe.
 * A confirmação é decidida aqui, no domínio, e devolve um {@see ResultadoDaConfirmacao} em vez de lançar.
 */
#[ORM\Entity(repositoryClass: PagamentoRepository::class)]
#[ORM\Table(name: 'pagamento')]
#[ORM\UniqueConstraint(name: 'uniq_pagamento_referencia', columns: ['referencia'])]
#[ORM\UniqueConstraint(name: 'uniq_pagamento_end_to_end', columns: ['end_to_end_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pagamento_idempotency_key', columns: ['idempotency_key'])]
#[ORM\Index(name: 'idx_pagamento_origem', columns: ['tenant_id', 'origem', 'origem_id'])]
#[ORM\Index(name: 'idx_pagamento_status_expira', columns: ['status', 'expira_em'])]
#[ORM\Index(name: 'idx_pagamento_provedor_id_externo', columns: ['provedor', 'id_externo'])]
class Pagamento implements TenantAware, Auditavel
{
    /** R$ 1.000.000,00: acima disso é engano (e passaria do inteiro da coluna). */
    public const VALOR_MAXIMO_CENTAVOS = 100_000_000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 20, enumType: StatusDoPagamento::class)]
    private StatusDoPagamento $status = StatusDoPagamento::Aguardando;

    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $idExterno = null;

    /** PIX copia e cola da cobrança no banco: guardado para a tela não reconsultar o banco a cada abertura. */
    #[ORM\Column(name: 'br_code', type: 'text', nullable: true)]
    private ?string $brCode = null;

    #[ORM\Column(name: 'end_to_end_id', type: 'string', length: 40, nullable: true)]
    private ?string $endToEndId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $valorPagoCentavos = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $pagoEm = null;

    /** `banco` quando o aviso foi reconsultado no banco; `manual:<id do usuário>` quando uma pessoa decidiu. */
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $confirmadoPor = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $motivoManual = null;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $pagadorNome = null;

    /** Só os 4 últimos dígitos do CPF/CNPJ (spec §6.8): o documento inteiro não fica aqui. */
    #[ORM\Column(type: 'string', length: 4, nullable: true)]
    private ?string $pagadorDocumentoFinal = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $idempotencyKey;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\Column(type: 'string', length: 30, enumType: OrigemDoPagamento::class)]
        private OrigemDoPagamento $origem,
        #[ORM\Column(type: 'integer')]
        private int $origemId,
        #[ORM\Column(type: 'string', length: 60)]
        private string $referencia,
        #[ORM\Column(type: 'integer')]
        private int $valorCentavos,
        #[ORM\Column(type: 'string', length: 10, enumType: MetodoDePagamento::class)]
        private MetodoDePagamento $metodo,
        #[ORM\Column(type: 'string', length: 20)]
        private string $provedor,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        private ?\DateTimeImmutable $expiraEm = null,
    ) {
        if ($valorCentavos <= 0 || $valorCentavos > self::VALOR_MAXIMO_CENTAVOS) {
            throw new \DomainException('Informe um valor de cobrança válido.');
        }
        if ($referencia === '') {
            throw new \DomainException('A cobrança precisa de uma referência.');
        }
        $this->idempotencyKey = bin2hex(random_bytes(16));
        $this->criadoEm = new \DateTimeImmutable();
    }

    /**
     * Um pagamento chegou. Só `aguardando` libera o consumidor; o resto é registrado e, se houver dinheiro que não
     * cabe em lugar nenhum, vira `divergente` para o Master. Idempotente: a segunda chamada não muda nada.
     */
    public function confirmar(int $valorPagoCentavos, ?string $endToEndId, \DateTimeImmutable $pagoEm, string $confirmadoPor): ResultadoDaConfirmacao
    {
        if ($this->status === StatusDoPagamento::Paga) {
            return ResultadoDaConfirmacao::JaConfirmado;
        }
        if (!$this->status->aceitaPagamento()) {
            return $this->receberFora($valorPagoCentavos, $endToEndId, $pagoEm);
        }
        if ($valorPagoCentavos < $this->valorCentavos) {
            $this->registrarRecebimento($valorPagoCentavos, $endToEndId, $pagoEm);
            $this->status = StatusDoPagamento::Divergente;

            return ResultadoDaConfirmacao::ValorMenor;
        }
        $this->registrarRecebimento($valorPagoCentavos, $endToEndId, $pagoEm);
        $this->status = StatusDoPagamento::Paga;
        $this->confirmadoPor = $confirmadoPor;

        return $valorPagoCentavos > $this->valorCentavos ? ResultadoDaConfirmacao::ConfirmadoComDiferenca : ResultadoDaConfirmacao::Confirmado;
    }

    /** O Master resolve uma divergência: aceita o que chegou e libera, com o motivo gravado. */
    public function aceitarDivergencia(string $motivo, int $usuarioId): void
    {
        if ($this->status !== StatusDoPagamento::Divergente) {
            throw new \DomainException('Só um pagamento divergente pode ser aceito à mão.');
        }
        if (trim($motivo) === '') {
            throw new \DomainException('Informe o motivo.');
        }
        $this->status = StatusDoPagamento::Paga;
        $this->confirmadoPor = 'manual:' . $usuarioId;
        $this->motivoManual = mb_substr(trim($motivo), 0, 500);
    }

    /** Defeito de dados (dois pagamentos com o mesmo id no banco): nada se confirma sozinho, o Master decide. */
    public function marcarDivergente(): bool
    {
        if (!in_array($this->status, [StatusDoPagamento::Aguardando, StatusDoPagamento::Expirada, StatusDoPagamento::Cancelada], true)) {
            return false;
        }
        $this->status = StatusDoPagamento::Divergente;

        return true;
    }

    public function cancelar(): void
    {
        if ($this->status !== StatusDoPagamento::Aguardando) {
            throw new \DomainException('Só uma cobrança aguardando pode ser cancelada.');
        }
        $this->status = StatusDoPagamento::Cancelada;
    }

    public function expirar(\DateTimeImmutable $agora): bool
    {
        if ($this->status !== StatusDoPagamento::Aguardando || $this->expiraEm === null || $this->expiraEm > $agora) {
            return false;
        }
        $this->status = StatusDoPagamento::Expirada;

        return true;
    }

    public function registrarIdExterno(string $idExterno): void
    {
        $this->idExterno = $idExterno;
    }

    /** A cobrança foi criada no banco: o id dele e o PIX copia e cola. */
    public function registrarCobrancaNoBanco(string $idExterno, string $brCode): void
    {
        $this->idExterno = $idExterno;
        $this->brCode = $brCode;
    }

    public function registrarPagador(?string $nome, ?string $documento): void
    {
        $this->pagadorNome = $nome === null ? null : mb_substr(trim($nome), 0, 120);
        $digitos = preg_replace('/\D+/', '', (string) $documento);
        $this->pagadorDocumentoFinal = $digitos === '' ? null : substr($digitos, -4);
    }

    /** Cobrança cancelada, expirada ou devolvida que recebeu dinheiro: o dinheiro entrou, então o Master decide. */
    private function receberFora(int $valorPagoCentavos, ?string $endToEndId, \DateTimeImmutable $pagoEm): ResultadoDaConfirmacao
    {
        if ($this->status === StatusDoPagamento::Divergente) {
            return ResultadoDaConfirmacao::ValorMenor;
        }
        $this->registrarRecebimento($valorPagoCentavos, $endToEndId, $pagoEm);
        $this->status = StatusDoPagamento::Divergente;

        return ResultadoDaConfirmacao::CobrancaInativa;
    }

    private function registrarRecebimento(int $valorPagoCentavos, ?string $endToEndId, \DateTimeImmutable $pagoEm): void
    {
        $this->valorPagoCentavos = $valorPagoCentavos;
        $this->endToEndId = $endToEndId;
        $this->pagoEm = $pagoEm;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getOrigem(): OrigemDoPagamento
    {
        return $this->origem;
    }

    public function getOrigemId(): int
    {
        return $this->origemId;
    }

    public function getReferencia(): string
    {
        return $this->referencia;
    }

    public function getValorCentavos(): int
    {
        return $this->valorCentavos;
    }

    public function getMetodo(): MetodoDePagamento
    {
        return $this->metodo;
    }

    public function getProvedor(): string
    {
        return $this->provedor;
    }

    public function getStatus(): StatusDoPagamento
    {
        return $this->status;
    }

    public function getIdExterno(): ?string
    {
        return $this->idExterno;
    }

    public function getBrCode(): ?string
    {
        return $this->brCode;
    }

    public function getEndToEndId(): ?string
    {
        return $this->endToEndId;
    }

    public function getValorPagoCentavos(): ?int
    {
        return $this->valorPagoCentavos;
    }

    public function getPagoEm(): ?\DateTimeImmutable
    {
        return $this->pagoEm;
    }

    public function getExpiraEm(): ?\DateTimeImmutable
    {
        return $this->expiraEm;
    }

    public function getConfirmadoPor(): ?string
    {
        return $this->confirmadoPor;
    }

    public function getMotivoManual(): ?string
    {
        return $this->motivoManual;
    }

    public function getPagadorNome(): ?string
    {
        return $this->pagadorNome;
    }

    public function getPagadorDocumentoFinal(): ?string
    {
        return $this->pagadorDocumentoFinal;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}
