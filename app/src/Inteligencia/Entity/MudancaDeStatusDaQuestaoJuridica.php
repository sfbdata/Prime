<?php

declare(strict_types=1);

namespace App\Inteligencia\Entity;

use App\Entity\Auth\User;
use App\Inteligencia\Enum\StatusDaQuestaoJuridica;
use App\Inteligencia\Repository\MudancaDeStatusDaQuestaoJuridicaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma linha do histórico de status de uma questão jurídica: de qual para qual, quem mudou e quando. Só é criada, nunca
 * alterada. O nome de quem mudou fica gravado junto: o usuário pode ser apagado depois (a FK vira NULL), e o
 * histórico continua dizendo quem foi.
 *
 * PLATAFORMA, sem tenant, como {@see EstadoDaQuestaoJuridica}.
 */
#[ORM\Entity(repositoryClass: MudancaDeStatusDaQuestaoJuridicaRepository::class)]
#[ORM\Table(name: 'inteligencia_questao_juridica_historico')]
#[ORM\Index(name: 'idx_inteligencia_questao_juridica_historico_chave', columns: ['chave', 'mudada_em'])]
#[ORM\Index(name: 'idx_inteligencia_questao_juridica_historico_por', columns: ['mudada_por_id'])]
class MudancaDeStatusDaQuestaoJuridica
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $mudadaPorNome;

    public function __construct(
        #[ORM\Column(length: 80)]
        private string $chave,
        #[ORM\Column(name: 'status_anterior', length: 20, enumType: StatusDaQuestaoJuridica::class)]
        private StatusDaQuestaoJuridica $de,
        #[ORM\Column(name: 'status_novo', length: 20, enumType: StatusDaQuestaoJuridica::class)]
        private StatusDaQuestaoJuridica $para,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'mudada_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $mudadaPor,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $mudadaEm,
    ) {
        $this->mudadaPorNome = self::nomeDe($mudadaPor);
    }

    public static function nomeDe(?User $usuario): string
    {
        $nome = trim((string) $usuario?->getFullName());

        return mb_substr($nome !== '' ? $nome : (string) $usuario?->getEmail(), 0, 180);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChave(): string
    {
        return $this->chave;
    }

    public function getDe(): StatusDaQuestaoJuridica
    {
        return $this->de;
    }

    public function getPara(): StatusDaQuestaoJuridica
    {
        return $this->para;
    }

    public function getMudadaPor(): ?User
    {
        return $this->mudadaPor;
    }

    public function getMudadaPorNome(): string
    {
        return $this->mudadaPorNome;
    }

    public function getMudadaEm(): \DateTimeImmutable
    {
        return $this->mudadaEm;
    }
}
