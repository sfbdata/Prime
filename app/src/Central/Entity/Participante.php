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
