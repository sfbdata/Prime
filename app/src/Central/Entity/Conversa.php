<?php

declare(strict_types=1);

namespace App\Central\Entity;

use App\Central\Repository\ConversaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Conversa da Central de Comunicação (A1: só individual — `docs/specs/central-comunicacao-a1.md`).
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

    private function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\Column(length: 20, enumType: TipoDeConversa::class)]
        private TipoDeConversa $tipo,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_menor_id', nullable: false)]
        private User $usuarioMenor,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_maior_id', nullable: false)]
        private User $usuarioMaior,
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

    /** O participante que não é `$eu` (conversa individual). */
    public function outroAlemDe(User $eu): User
    {
        return $this->usuarioMenor->getId() === $eu->getId() ? $this->usuarioMaior : $this->usuarioMenor;
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

    public function getUsuarioMenor(): User
    {
        return $this->usuarioMenor;
    }

    public function getUsuarioMaior(): User
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
