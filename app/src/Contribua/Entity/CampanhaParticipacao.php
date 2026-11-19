<?php

declare(strict_types=1);

namespace App\Contribua\Entity;

use App\Contribua\Enum\SituacaoDaParticipacao;
use App\Contribua\Repository\CampanhaParticipacaoRepository;
use App\Entity\Auth\User;
use App\Contribua\Service\FormatoDaCampanha;
use App\Entity\Tenant\Tenant;
use App\Shared\Attribute\SemIsolamentoDeTenant;
use Doctrine\ORM\Mapping as ORM;

/**
 * A inscrição de uma pessoa no Desafio. É da pessoa, não do escritório (o escritório fica só como referência de
 * onde ela se inscreveu). Nasce "aguardando pagamento": o Master confere o Pix no extrato e confirma. O CPF
 * nunca é guardado: só o hash e os dois últimos dígitos. O que foi prometido (valor, multiplicador e prazos)
 * fica congelado aqui, de modo que mudar a campanha depois não tira nada de quem já entrou (regra 3).
 */
#[ORM\Entity(repositoryClass: CampanhaParticipacaoRepository::class)]
#[ORM\Table(name: 'contribua_campanha_participacao')]
#[ORM\UniqueConstraint(name: 'uniq_contribua_campanha_part_usuario', columns: ['campanha_id', 'usuario_id'])]
#[ORM\UniqueConstraint(name: 'uniq_contribua_campanha_part_cpf', columns: ['campanha_id', 'cpf_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_contribua_campanha_part_oab', columns: ['campanha_id', 'oab'])]
#[SemIsolamentoDeTenant('Inscrição na campanha comercial do BlueJus: pertence à pessoa e à campanha global; o escritório é só referência de onde se inscreveu')]
class CampanhaParticipacao
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private int $valorCentavos;

    #[ORM\Column(type: 'smallint')]
    private int $multiplicador;

    #[ORM\Column(type: 'smallint')]
    private int $prazoPedidoDias;

    #[ORM\Column(type: 'smallint')]
    private int $prazoPagamentoDiasUteis;

    #[ORM\Column(type: 'integer')]
    private int $versaoRegras;

    #[ORM\Column(length: 30, enumType: SituacaoDaParticipacao::class)]
    private SituacaoDaParticipacao $situacao = SituacaoDaParticipacao::AguardandoPagamento;

    #[ORM\Column(length: 25, nullable: true)]
    private ?string $txid = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $pagoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $pagamentoConfirmadoPor = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Campanha::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Campanha $campanha,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Tenant $tenant,
        #[ORM\Column(length: 120)]
        private string $nome,
        #[ORM\Column(type: 'string', length: 64, options: ['fixed' => true])]
        private string $cpfHash,
        #[ORM\Column(type: 'string', length: 2, options: ['fixed' => true])]
        private string $cpfFinal,
        #[ORM\Column(length: 20)]
        private string $oab,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $aceiteRegrasEm,
        #[ORM\Column(length: 45, nullable: true)]
        private ?string $ip,
        \DateTimeImmutable $criadaEm,
    ) {
        $this->criadaEm = $criadaEm;
        $this->valorCentavos = $campanha->getPrecoCentavos();
        $this->multiplicador = $campanha->getMultiplicador();
        $this->prazoPedidoDias = $campanha->getPrazoPedidoDias();
        $this->prazoPagamentoDiasUteis = $campanha->getPrazoPagamentoDiasUteis();
        $this->versaoRegras = $campanha->getVersaoRegras();
    }

    /** O txid do Pix da inscrição: "BJB" + id (até 25 caracteres, só letras e números). Chamar depois do flush. */
    public function gerarTxid(): void
    {
        $this->txid = 'BJB' . $this->id;
    }

    public function confirmarPagamento(User $master, \DateTimeImmutable $em): void
    {
        if ($this->situacao !== SituacaoDaParticipacao::AguardandoPagamento) {
            throw new \DomainException('Esta inscrição não está aguardando pagamento.');
        }
        $this->situacao = SituacaoDaParticipacao::Ativa;
        $this->pagoEm = $em;
        $this->pagamentoConfirmadoPor = $master;
    }

    public function registrarPedidoDeDevolucao(): void
    {
        $this->situacao = SituacaoDaParticipacao::DevolucaoPedida;
    }

    public function marcarDevolvida(): void
    {
        if ($this->situacao !== SituacaoDaParticipacao::DevolucaoPedida) {
            throw new \DomainException('Esta inscrição não tem devolução pendente.');
        }
        $this->situacao = SituacaoDaParticipacao::Devolvida;
    }

    /** Dias de calendário (São Paulo) desde o pagamento, ou desde a inscrição se ainda não pagou. `$hoje` = meia-noite de São Paulo. */
    public function diasDesde(\DateTimeImmutable $hoje): int
    {
        $desde = FormatoDaCampanha::diaDoInstante($this->pagoEm ?? $this->criadaEm);

        return (int) $desde->diff($hoje)->format('%r%a');
    }

    /** Dias que ainda restam para pedir a devolução (nunca negativo). */
    public function diasRestantes(\DateTimeImmutable $hoje): int
    {
        return max(0, $this->prazoPedidoDias - $this->diasDesde($hoje));
    }

    /** Só a inscrição ativa, até `pago_em + prazo_pedido_dias` (D9). */
    public function podeDevolver(\DateTimeImmutable $hoje): bool
    {
        return $this->situacao === SituacaoDaParticipacao::Ativa && $this->diasDesde($hoje) <= $this->prazoPedidoDias;
    }

    /** Valor da devolução: o preço pago vezes o multiplicador, ambos congelados na inscrição. */
    public function valorDaDevolucaoCentavos(): int
    {
        return $this->valorCentavos * $this->multiplicador;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampanha(): Campanha
    {
        return $this->campanha;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getCpfHash(): string
    {
        return $this->cpfHash;
    }

    public function getCpfFinal(): string
    {
        return $this->cpfFinal;
    }

    public function getOab(): string
    {
        return $this->oab;
    }

    public function getValorCentavos(): int
    {
        return $this->valorCentavos;
    }

    public function getMultiplicador(): int
    {
        return $this->multiplicador;
    }

    public function getPrazoPedidoDias(): int
    {
        return $this->prazoPedidoDias;
    }

    public function getPrazoPagamentoDiasUteis(): int
    {
        return $this->prazoPagamentoDiasUteis;
    }

    public function getVersaoRegras(): int
    {
        return $this->versaoRegras;
    }

    public function getSituacao(): SituacaoDaParticipacao
    {
        return $this->situacao;
    }

    public function getTxid(): ?string
    {
        return $this->txid;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getPagoEm(): ?\DateTimeImmutable
    {
        return $this->pagoEm;
    }

    public function getPagamentoConfirmadoPor(): ?User
    {
        return $this->pagamentoConfirmadoPor;
    }
}
