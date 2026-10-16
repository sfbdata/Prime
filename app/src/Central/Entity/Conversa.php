<?php

declare(strict_types=1);

namespace App\Central\Entity;

use App\Central\Repository\ConversaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Conversa da Central de Comunicação: individual (A1 — `docs/specs/central-comunicacao-a1.md`) ou grupo (B —
 * `docs/specs/central-comunicacao-b.md`).
 *
 * Grupo tem nome (1–60) e um **administrador do grupo** — coluna da conversa, não papel do sistema (D3): só ele
 * adiciona e remove participantes. Não tem par: `usuario_menor`/`usuario_maior` ficam nulos, e o índice único do par
 * não o alcança (no PostgreSQL NULL não colide).
 *
 * O par de pessoas é guardado ORDENADO (`usuario_menor` < `usuario_maior` por id) com UNIQUE no escritório:
 * "abrir conversa com a Mariana" de qualquer um dos dois lados chega sempre à mesma linha, e uma corrida entre
 * os dois cliques esbarra no índice em vez de criar duas conversas.
 *
 * Fora da auditoria (`Auditavel`) de propósito: o `audit_log` é lido por quem administra o escritório, e a
 * conversa é das duas pessoas (ver `AuditavelCoberturaTest`).
 */
#[ORM\Entity(repositoryClass: ConversaRepository::class)]
#[ORM\Table(name: 'central_conversa')]
#[ORM\Index(name: 'idx_central_conversa_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_central_conversa_par', columns: ['tenant_id', 'usuario_menor_id', 'usuario_maior_id'])]
class Conversa implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'ultima_mensagem_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ultimaMensagemEm = null;

    public const TAMANHO_MAXIMO_DO_NOME = 60;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $nome = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'administrador_id', nullable: true)]
    private ?User $administrador = null;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\Column(length: 20, enumType: TipoDeConversa::class)]
        private TipoDeConversa $tipo,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_menor_id', nullable: true)]
        private ?User $usuarioMenor,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_maior_id', nullable: true)]
        private ?User $usuarioMaior,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'criada_por_id', nullable: false)]
        private User $criadaPor,
        #[ORM\Column(name: 'criada_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $criadaEm,
    ) {
    }

    /**
     * Conversa entre quem abre e o destinatário. Consigo mesmo não existe — não há com quem conversar, e a
     * linha quebraria a regra "o outro" da lista.
     */
    public static function individual(Tenant $tenant, User $quemAbre, User $destinatario, \DateTimeImmutable $em): self
    {
        if ($quemAbre->getId() === null || $destinatario->getId() === null) {
            throw new \LogicException('Conversa exige usuários persistidos.');
        }
        if ($quemAbre->getId() === $destinatario->getId()) {
            throw new \DomainException('Não há conversa consigo mesmo.');
        }

        [$menor, $maior] = $quemAbre->getId() < $destinatario->getId() ? [$quemAbre, $destinatario] : [$destinatario, $quemAbre];

        return new self($tenant, TipoDeConversa::Individual, $menor, $maior, $quemAbre, $em);
    }

    /** Grupo novo: quem cria é o administrador. O nome é obrigatório (1–60, sem espaço sobrando). */
    public static function grupo(Tenant $tenant, User $criador, string $nome, \DateTimeImmutable $em): self
    {
        $conversa = new self($tenant, TipoDeConversa::Grupo, null, null, $criador, $em);
        $conversa->renomear($nome);
        $conversa->administrador = $criador;

        return $conversa;
    }

    public function isGrupo(): bool
    {
        return $this->tipo === TipoDeConversa::Grupo;
    }

    /** O participante que não é `$eu` — só existe em conversa individual. */
    public function outroAlemDe(User $eu): User
    {
        if ($this->usuarioMenor === null || $this->usuarioMaior === null) {
            throw new \LogicException('Conversa em grupo não tem "o outro".');
        }

        return $this->usuarioMenor->getId() === $eu->getId() ? $this->usuarioMaior : $this->usuarioMenor;
    }

    public function renomear(string $nome): void
    {
        if (!$this->isGrupo()) {
            throw new \DomainException('Só grupo tem nome.');
        }
        $nome = trim(preg_replace('/\s+/u', ' ', $nome) ?? '');
        if ($nome === '') {
            throw new \DomainException('Dê um nome ao grupo.');
        }
        if (mb_strlen($nome) > self::TAMANHO_MAXIMO_DO_NOME) {
            throw new \DomainException(sprintf('O nome do grupo passa de %d caracteres.', self::TAMANHO_MAXIMO_DO_NOME));
        }

        $this->nome = $nome;
    }

    public function isAdministradaPor(User $usuario): bool
    {
        return $this->isGrupo() && $this->administrador !== null && $this->administrador->getId() === $usuario->getId();
    }

    /** Troca o administrador do grupo (quem sai passa a administração adiante); `null` = grupo sem ninguém. */
    public function passarAdministracaoPara(?User $usuario): void
    {
        if (!$this->isGrupo()) {
            throw new \DomainException('Só grupo tem administrador.');
        }

        $this->administrador = $usuario;
    }

    public function getNome(): ?string
    {
        return $this->nome;
    }

    public function getAdministrador(): ?User
    {
        return $this->administrador;
    }

    public function registrarMensagem(\DateTimeImmutable $em): void
    {
        if ($this->ultimaMensagemEm === null || $em > $this->ultimaMensagemEm) {
            $this->ultimaMensagemEm = $em;
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getTipo(): TipoDeConversa
    {
        return $this->tipo;
    }

    public function getUsuarioMenor(): ?User
    {
        return $this->usuarioMenor;
    }

    public function getUsuarioMaior(): ?User
    {
        return $this->usuarioMaior;
    }

    public function getCriadaPor(): User
    {
        return $this->criadaPor;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getUltimaMensagemEm(): ?\DateTimeImmutable
    {
        return $this->ultimaMensagemEm;
    }
}
