<?php

declare(strict_types=1);

namespace App\Central\Entity;

use App\Central\Repository\ParticipanteRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Quem está numa conversa, e até onde leu. "Não lida" é a mensagem de OUTRA pessoa com id acima de
 * `ultima_lida_id`; ler é mover o cursor, e o cursor nunca anda para trás — duas abas abertas não
 * "desleem" o que a outra já marcou.
 *
 * É a linha de participante, e não o par da conversa, que dá acesso: a regra de quem lê vale igual para a
 * conversa em grupo que vem na A2.
 */
#[ORM\Entity(repositoryClass: ParticipanteRepository::class)]
#[ORM\Table(name: 'central_participante')]
#[ORM\Index(name: 'idx_central_participante_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_central_participante_usuario', columns: ['usuario_id'])]
#[ORM\UniqueConstraint(name: 'uniq_central_participante', columns: ['conversa_id', 'usuario_id'])]
class Participante implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'ultima_lida_id', type: 'integer', options: ['default' => 0])]
    private int $ultimaLidaId = 0;

    /** "Excluir" a conversa (limpar para mim): mensagens até este id não aparecem a esta pessoa. 0 = nada limpo. */
    #[ORM\Column(name: 'limpa_ate_id', type: 'integer', options: ['default' => 0])]
    private int $limpaAteId = 0;

    /**
     * "Marcar como não lida" (A2): sinal do próprio participante, à parte do cursor — o cursor continua sem
     * recuar. Desliga quando a conversa é lida.
     */
    #[ORM\Column(name: 'marcada_nao_lida', type: 'boolean', options: ['default' => false])]
    private bool $marcadaNaoLida = false;

    /**
     * Saiu ou foi removido do grupo (B). Participação inativa não lê nada — toda consulta da Central filtra
     * `saiu_em IS NULL`. Readmitido, a mesma linha volta (o único `(conversa, usuario)` não deixa duplicar).
     */
    #[ORM\Column(name: 'saiu_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $saiuEm = null;

    /** Preferências SÓ deste participante (B): não mexem na de ninguém. */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $fixada = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $silenciada = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $arquivada = false;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Conversa::class)]
        #[ORM\JoinColumn(name: 'conversa_id', nullable: false, onDelete: 'CASCADE')]
        private Conversa $conversa,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_id', nullable: false)]
        private User $usuario,
        #[ORM\Column(name: 'entrou_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $entrouEm,
    ) {
    }

    /** Avança o cursor até `$mensagemId`; nunca recua. */
    public function marcarLidaAte(int $mensagemId): void
    {
        if ($mensagemId > $this->ultimaLidaId) {
            $this->ultimaLidaId = $mensagemId;
        }
    }

    /** Gravado só por `ParticipanteRepository::avancarLimpeza` (no banco, nunca recua). */
    public function getLimpaAteId(): int
    {
        return $this->limpaAteId;
    }

    public function sair(\DateTimeImmutable $em): void
    {
        $this->saiuEm = $em;
    }

    /**
     * Volta ao grupo. O histórico anterior fica visível, mas não chega como não lido: o cursor vai até a última
     * mensagem que já existe (e, como sempre, nunca recua). As preferências de antes não voltam — quem é readmitido
     * recomeça como quem entra pela primeira vez (sem arquivada escondendo o grupo, sem marca de não lida).
     */
    public function voltar(\DateTimeImmutable $em, int $ultimaMensagemId): void
    {
        $this->saiuEm = null;
        $this->entrouEm = $em;
        $this->marcarLidaAte($ultimaMensagemId);
        $this->fixada = false;
        $this->silenciada = false;
        $this->arquivada = false;
        $this->marcadaNaoLida = false;
    }

    public function isAtivo(): bool
    {
        return $this->saiuEm === null;
    }

    public function getSaiuEm(): ?\DateTimeImmutable
    {
        return $this->saiuEm;
    }

    public function fixar(bool $fixada): void
    {
        $this->fixada = $fixada;
    }

    public function silenciar(bool $silenciada): void
    {
        $this->silenciada = $silenciada;
    }

    public function arquivar(bool $arquivada): void
    {
        $this->arquivada = $arquivada;
    }

    public function isFixada(): bool
    {
        return $this->fixada;
    }

    public function isSilenciada(): bool
    {
        return $this->silenciada;
    }

    public function isArquivada(): bool
    {
        return $this->arquivada;
    }

    public function marcarComoNaoLida(): void
    {
        $this->marcadaNaoLida = true;
    }

    public function isMarcadaNaoLida(): bool
    {
        return $this->marcadaNaoLida;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getConversa(): Conversa
    {
        return $this->conversa;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getEntrouEm(): \DateTimeImmutable
    {
        return $this->entrouEm;
    }

    public function getUltimaLidaId(): int
    {
        return $this->ultimaLidaId;
    }
}
