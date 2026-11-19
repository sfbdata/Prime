<?php

declare(strict_types=1);

namespace App\Contribua\Entity;

use App\Contribua\Repository\CampanhaDevolucaoRepository;
use App\Entity\Auth\User;
use App\Shared\Attribute\SemIsolamentoDeTenant;
use Doctrine\ORM\Mapping as ORM;

/**
 * O pedido de devolução de uma inscrição. A chave Pix de quem vai receber fica cifrada (só o Master a vê, no painel).
 */
#[ORM\Entity(repositoryClass: CampanhaDevolucaoRepository::class)]
#[ORM\Table(name: 'contribua_campanha_devolucao')]
#[SemIsolamentoDeTenant('Devolução da campanha comercial do BlueJus: pertence à inscrição da pessoa, não a um escritório; só o Master a vê por inteiro')]
class CampanhaDevolucao
{
    public const MAXIMO_DO_MOTIVO = 400;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $devolvidaEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $devolvidaPor = null;

    public function __construct(
        #[ORM\OneToOne(targetEntity: CampanhaParticipacao::class)]
        #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
        private CampanhaParticipacao $participacao,
        #[ORM\Column(type: 'integer')]
        private int $valorCentavos,
        #[ORM\Column(type: 'text')]
        private string $chavePixCifrada,
        #[ORM\Column(length: 400, nullable: true)]
        private ?string $motivo,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $pedidaEm,
    ) {
        $motivo = trim((string) $motivo);
        $this->motivo = $motivo === '' ? null : mb_substr($motivo, 0, self::MAXIMO_DO_MOTIVO);
    }

    public function marcarDevolvida(User $master, \DateTimeImmutable $em): void
    {
        if ($this->devolvidaEm !== null) {
            throw new \DomainException('Esta devolução já foi marcada como paga.');
        }
        $this->participacao->marcarDevolvida();
        $this->devolvidaEm = $em;
        $this->devolvidaPor = $master;
    }

    public function isPendente(): bool
    {
        return $this->devolvidaEm === null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParticipacao(): CampanhaParticipacao
    {
        return $this->participacao;
    }

    public function getValorCentavos(): int
    {
        return $this->valorCentavos;
    }

    public function getChavePixCifrada(): string
    {
        return $this->chavePixCifrada;
    }

    public function getMotivo(): ?string
    {
        return $this->motivo;
    }

    public function getPedidaEm(): \DateTimeImmutable
    {
        return $this->pedidaEm;
    }

    public function getDevolvidaEm(): ?\DateTimeImmutable
    {
        return $this->devolvidaEm;
    }
}
