<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Enum\OperacaoDeMensagem;
use App\TrabalhoExterno\Repository\MarcacaoDeMensagemRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * O que UM usuário fez com uma mensagem do chat: favorita, lixeira (30 dias) ou excluída da
 * conversa dele. A mensagem continua para os outros (spec VS 18.10.26 §3.4).
 */
#[ORM\Entity(repositoryClass: MarcacaoDeMensagemRepository::class)]
#[ORM\Table(name: 'trabalho_externo_marcacao_mensagem')]
#[ORM\UniqueConstraint(name: 'uniq_te_marcacao_mensagem_usuario', columns: ['mensagem_id', 'usuario_id'])]
class MarcacaoDeMensagem
{
    /** A lixeira do chat esvazia sozinha depois deste prazo (`_chatLixeira()` do desenho). */
    public const DIAS_NA_LIXEIRA = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $favorita = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lixeiraEm = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $excluidaEm = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: MensagemDemanda::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private MensagemDemanda $mensagem,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
    ) {
    }

    public function aplicar(OperacaoDeMensagem $op, \DateTimeImmutable $agora): void
    {
        match ($op) {
            OperacaoDeMensagem::Lixeira => $this->lixeiraEm = $agora,
            OperacaoDeMensagem::Restaurar => $this->lixeiraEm = null,
            OperacaoDeMensagem::Definitivo => $this->excluirDaConversa($agora),
            OperacaoDeMensagem::Favoritar => $this->favorita = true,
            OperacaoDeMensagem::Desfavoritar => $this->favorita = false,
        };
    }

    private function excluirDaConversa(\DateTimeImmutable $agora): void
    {
        $this->lixeiraEm = null;
        $this->excluidaEm = $agora;
    }

    public function getMensagem(): MensagemDemanda
    {
        return $this->mensagem;
    }

    public function isFavorita(): bool
    {
        return $this->favorita;
    }

    public function naLixeira(\DateTimeImmutable $agora): bool
    {
        return $this->lixeiraEm !== null && !$this->excluida($agora);
    }

    /** Excluída de vez, ou na lixeira há mais de 30 dias (sem cron: o prazo é lido aqui). */
    public function excluida(\DateTimeImmutable $agora): bool
    {
        return $this->excluidaEm !== null
            || ($this->lixeiraEm !== null && $this->lixeiraEm < $agora->modify('-' . self::DIAS_NA_LIXEIRA . ' days'));
    }
}
