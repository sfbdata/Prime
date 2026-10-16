<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Repository\LeituraDemandaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Até que mensagem cada usuário leu o chat de uma demanda. O desenho guarda isso por perfil
 * (`d.lido[perfil]`); aqui é por usuário, porque cada pessoa da Equipe lê no seu ritmo.
 */
#[ORM\Entity(repositoryClass: LeituraDemandaRepository::class)]
#[ORM\Table(name: 'trabalho_externo_leitura')]
#[ORM\UniqueConstraint(name: 'uniq_te_leitura_demanda_usuario', columns: ['demanda_id', 'usuario_id'])]
class LeituraDemanda
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column]
    private int $ultimaMensagemLida = 0;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: DemandaExterna::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DemandaExterna $demanda,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
    ) {
    }

    public function marcarAte(int $mensagemId): void
    {
        $this->ultimaMensagemLida = max($this->ultimaMensagemLida, $mensagemId);
    }

    public function getUltimaMensagemLida(): int
    {
        return $this->ultimaMensagemLida;
    }

    public function getDemanda(): DemandaExterna
    {
        return $this->demanda;
    }
}
