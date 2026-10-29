<?php

declare(strict_types=1);

namespace App\ChatIa\Entity;

use App\ChatIa\Enum\EstadoDaProposta;
use App\ChatIa\Enum\TipoDaProposta;
use App\ChatIa\Repository\PropostaDeCadastroRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma PROPOSTA de cadastro (cliente ou pasta) que a I.A fez no Chat (`docs/specs/chat-ia-cadastro.md`). A I.A nunca
 * grava: ela só propõe, e quem grava é o clique em Confirmar de quem a recebeu. A proposta é de quem perguntou
 * (`usuario`), no escritório dele.
 *
 * `dados` é o JSON do que SERÁ gravado e leva dado pessoal (CPF, nome, endereço): fica fora do diff do `audit_log`
 * (`AuditLogSubscriber::IGNORED_FIELDS_POR_CLASSE`), expira em 24 h e é ZERADO ao confirmar, cancelar ou vencer. Sobra a
 * ação, as datas e o `resultado` (tipo e id do que foi criado). Forma de `dados`:
 *
 * - cliente: `{tipoPessoa: 'pf'|'pj', campos: {chave: valor}}`;
 * - pasta: `{nomeCliente, nomeAcao, nup?, cliente?: {tipoPessoa, campos}}`.
 *
 * Transição única: `pendente` para `confirmada`, `cancelada` ou `expirada`.
 */
#[ORM\Entity(repositoryClass: PropostaDeCadastroRepository::class)]
#[ORM\Table(name: 'chat_ia_proposta')]
#[ORM\Index(name: 'idx_chat_ia_proposta_conversa', columns: ['conversa_id'])]
#[ORM\Index(name: 'idx_chat_ia_proposta_mensagem', columns: ['mensagem_id'])]
#[ORM\Index(name: 'idx_chat_ia_proposta_dono', columns: ['tenant_id', 'usuario_id', 'estado'])]
class PropostaDeCadastro implements TenantAware, Auditavel
{
    /** A proposta vale 24 h a partir de quando foi feita. */
    public const VALIDADE = 'PT24H';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MensagemDeChat::class)]
    #[ORM\JoinColumn(name: 'mensagem_id', nullable: true, onDelete: 'CASCADE')]
    private ?MensagemDeChat $mensagem = null;

    #[ORM\Column(length: 12, enumType: EstadoDaProposta::class)]
    private EstadoDaProposta $estado = EstadoDaProposta::Pendente;

    /** @var array<string, mixed>|null o que será gravado; nulo depois de decidida ou vencida */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dados;

    #[ORM\Column(name: 'expira_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiraEm;

    #[ORM\Column(name: 'decidida_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decididaEm = null;

    #[ORM\Column(name: 'resultado_tipo', length: 12, nullable: true)]
    private ?string $resultadoTipo = null;

    #[ORM\Column(name: 'resultado_id', type: 'integer', nullable: true)]
    private ?int $resultadoId = null;

    /** @param array<string, mixed> $dados */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_id', nullable: false)]
        private User $usuario,
        #[ORM\ManyToOne(targetEntity: ConversaDeChat::class)]
        #[ORM\JoinColumn(name: 'conversa_id', nullable: false, onDelete: 'CASCADE')]
        private ConversaDeChat $conversa,
        #[ORM\Column(length: 12, enumType: TipoDaProposta::class)]
        private TipoDaProposta $tipo,
        array $dados,
        #[ORM\Column(name: 'criada_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $criadaEm,
    ) {
        if ($conversa->getTenant()?->getId() !== $tenant->getId() || $conversa->getUsuario()->getId() !== $usuario->getId()) {
            throw new \LogicException('A proposta precisa ser do mesmo escritório e da mesma pessoa da conversa.');
        }
        $this->dados = $dados;
        $this->expiraEm = $criadaEm->add(new \DateInterval(self::VALIDADE));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getConversa(): ConversaDeChat
    {
        return $this->conversa;
    }

    public function getMensagem(): ?MensagemDeChat
    {
        return $this->mensagem;
    }

    public function getTipo(): TipoDaProposta
    {
        return $this->tipo;
    }

    public function getEstado(): EstadoDaProposta
    {
        return $this->estado;
    }

    /** @return array<string, mixed>|null nulo quando a proposta já não guarda dado pessoal */
    public function getDados(): ?array
    {
        return $this->dados;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getExpiraEm(): \DateTimeImmutable
    {
        return $this->expiraEm;
    }

    public function getDecididaEm(): ?\DateTimeImmutable
    {
        return $this->decididaEm;
    }

    public function getResultadoTipo(): ?string
    {
        return $this->resultadoTipo;
    }

    public function getResultadoId(): ?int
    {
        return $this->resultadoId;
    }

    /** A resposta do assistente que trouxe esta proposta. */
    public function associarAMensagem(MensagemDeChat $mensagem): void
    {
        $this->mensagem = $mensagem;
    }

    public function estaPendente(): bool
    {
        return $this->estado === EstadoDaProposta::Pendente;
    }

    /** Pendente, mas já passou do prazo: ainda não foi marcada `expirada`. */
    public function estaVencida(\DateTimeImmutable $agora): bool
    {
        return $this->estado === EstadoDaProposta::Pendente && $agora >= $this->expiraEm;
    }

    /** O estado que a tela mostra: a pendente vencida já aparece como `expirada`. */
    public function estadoEm(\DateTimeImmutable $agora): EstadoDaProposta
    {
        return $this->estaVencida($agora) ? EstadoDaProposta::Expirada : $this->estado;
    }

    public function confirmar(string $resultadoTipo, int $resultadoId, \DateTimeImmutable $em): void
    {
        $this->exigirPendente();
        $this->encerrar(EstadoDaProposta::Confirmada, $em);
        $this->resultadoTipo = $resultadoTipo;
        $this->resultadoId = $resultadoId;
    }

    public function cancelar(\DateTimeImmutable $em): void
    {
        $this->exigirPendente();
        $this->encerrar(EstadoDaProposta::Cancelada, $em);
    }

    public function expirar(\DateTimeImmutable $em): void
    {
        $this->exigirPendente();
        $this->encerrar(EstadoDaProposta::Expirada, $em);
    }

    private function encerrar(EstadoDaProposta $estado, \DateTimeImmutable $em): void
    {
        $this->estado = $estado;
        $this->decididaEm = $em;
        $this->dados = null;
    }

    private function exigirPendente(): void
    {
        if ($this->estado !== EstadoDaProposta::Pendente) {
            throw new \LogicException('A proposta já foi decidida.');
        }
    }
}
