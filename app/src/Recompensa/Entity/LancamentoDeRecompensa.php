<?php

declare(strict_types=1);

namespace App\Recompensa\Entity;

use App\Entity\Auth\User;
use App\Recompensa\Enum\FormaDePagamento;
use App\Recompensa\Exception\RecompensaRecusada;
use App\Recompensa\Repository\LancamentoDeRecompensaRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma linha do extrato de recompensas (spec perfis-1.2-vs-25-10-recompensas-e-planos, F2). É um LIVRO: só nasce, nunca
 * muda nem some. Sem setter e mapeada como somente leitura; lançamento errado se corrige com outro de sinal oposto.
 *
 * `valorCentavos` tem sinal no `bonus`: positivo é crédito, negativo é estorno; nunca zero. O `pago` (F4) é a baixa manual
 * de um pagamento feito FORA do sistema: valor sempre positivo, com a forma e, se houver, o comprovante (arquivo global).
 * `liberado` e `mensal` entram nas fatias seguintes. A chave de idempotência é
 * única: o navegador manda um UUID por abertura do diálogo e repetir a chave não lança de novo.
 *
 * GLOBAL, sem tenant. O usuário (o indicador) vira nulo se a pessoa for apagada (LGPD) e a linha fica no extrato.
 */
#[ORM\Entity(repositoryClass: LancamentoDeRecompensaRepository::class, readOnly: true)]
#[ORM\Table(name: 'lancamento_de_recompensa')]
#[ORM\UniqueConstraint(name: 'uniq_lancamento_de_recompensa_chave', columns: ['chave_de_idempotencia'])]
#[ORM\Index(name: 'idx_lancamento_de_recompensa_user_criado', columns: ['user_id', 'criado_em'])]
#[ORM\Index(name: 'idx_lancamento_de_recompensa_criado_por', columns: ['criado_por_id'])]
class LancamentoDeRecompensa implements Auditavel
{
    public const TIPO_BONUS = 'bonus';
    public const TIPO_PAGO = 'pago';
    /** R$ 100.000,00, o mesmo teto dos valores do programa. */
    public const VALOR_MAXIMO = 10000000;
    public const OBSERVACAO_MAXIMA = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $tipo;

    #[ORM\Column(type: 'integer')]
    private int $valorCentavos;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $observacao;

    #[ORM\Column(length: 30, nullable: true, enumType: FormaDePagamento::class)]
    private ?FormaDePagamento $forma = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $comprovanteChave = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $comprovanteNome = null;

    #[ORM\Column(length: 64)]
    private string $chaveDeIdempotencia;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'criado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $criadoPor;

    /** @throws RecompensaRecusada valor zero ou acima do teto, observação longa */
    public function __construct(User $user, string $tipo, int $valorCentavos, ?string $observacao, string $chaveDeIdempotencia, ?User $criadoPor, \DateTimeImmutable $criadoEm)
    {
        if ($valorCentavos === 0 || abs($valorCentavos) > self::VALOR_MAXIMO) {
            throw new RecompensaRecusada('O valor deve ser diferente de zero e ter no máximo R$ 100.000,00 (use negativo para estornar).');
        }
        if (!in_array($tipo, [self::TIPO_BONUS, self::TIPO_PAGO], true)) {
            throw new RecompensaRecusada('Tipo de lançamento inválido.');
        }
        if ($tipo === self::TIPO_PAGO && $valorCentavos < 0) {
            throw new RecompensaRecusada('O valor pago deve ser maior que zero.');
        }
        $observacao = $observacao === null ? null : trim($observacao);
        if ($observacao !== null && mb_strlen($observacao) > self::OBSERVACAO_MAXIMA) {
            throw new RecompensaRecusada('A observação deve ter até 255 caracteres.');
        }

        $this->user = $user;
        $this->tipo = $tipo;
        $this->valorCentavos = $valorCentavos;
        $this->observacao = $observacao === '' ? null : $observacao;
        $this->chaveDeIdempotencia = $chaveDeIdempotencia;
        $this->criadoPor = $criadoPor;
        $this->criadoEm = $criadoEm;
    }

    /**
     * A baixa manual de um pagamento (F4): valor positivo, a forma e o comprovante opcional (chave e nome original).
     *
     * @throws RecompensaRecusada valor fora do limite ou observação longa
     */
    public static function pagamento(User $user, int $valorCentavos, FormaDePagamento $forma, ?string $observacao, ?string $comprovanteChave, ?string $comprovanteNome, string $chaveDeIdempotencia, ?User $criadoPor, \DateTimeImmutable $criadoEm): self
    {
        $pagamento = new self($user, self::TIPO_PAGO, $valorCentavos, $observacao, $chaveDeIdempotencia, $criadoPor, $criadoEm);
        $pagamento->forma = $forma;
        $pagamento->comprovanteChave = $comprovanteChave;
        $pagamento->comprovanteNome = $comprovanteChave === null ? null : mb_substr((string) $comprovanteNome, 0, 255);

        return $pagamento;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function getValorCentavos(): int
    {
        return $this->valorCentavos;
    }

    public function getObservacao(): ?string
    {
        return $this->observacao;
    }

    public function getChaveDeIdempotencia(): string
    {
        return $this->chaveDeIdempotencia;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function getForma(): ?FormaDePagamento
    {
        return $this->forma;
    }

    public function getComprovanteChave(): ?string
    {
        return $this->comprovanteChave;
    }

    public function getComprovanteNome(): ?string
    {
        return $this->comprovanteNome;
    }

    public function temComprovante(): bool
    {
        return $this->comprovanteChave !== null;
    }

    public function ehEstorno(): bool
    {
        return $this->valorCentavos < 0;
    }
}
