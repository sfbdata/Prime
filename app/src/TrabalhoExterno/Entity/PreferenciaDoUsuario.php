<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Repository\PreferenciaDoUsuarioRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * O que o Trabalho Externo lembra de UM usuário entre as visitas (desenho 06): a última visita à aba
 * Vagas, que define o selo "NOVA" (`bj-te-visto-<perfil>`, l.1008–1009), e o último "QUEM ESTÁ
 * OFERECENDO", que vira o padrão das próximas vagas (`ofPad`, l.1005). Uma linha por usuário e chave.
 */
#[ORM\Entity(repositoryClass: PreferenciaDoUsuarioRepository::class)]
#[ORM\Table(name: 'trabalho_externo_preferencia')]
#[ORM\UniqueConstraint(name: 'uniq_te_preferencia_usuario_chave', columns: ['usuario_id', 'chave'])]
class PreferenciaDoUsuario
{
    public const ULTIMA_VISITA_VAGAS = 'vagas.ultimaVisita';
    public const OFERTANTE_PADRAO = 'vagas.ofertantePadrao';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $valor = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $em;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\Column(length: 40)]
        private string $chave,
    ) {
        $this->em = new \DateTimeImmutable();
    }

    /** @param array<string, mixed> $valor */
    public function definir(array $valor, \DateTimeImmutable $agora): void
    {
        $this->valor = $valor;
        $this->em = $agora;
    }

    /** @return array<string, mixed> */
    public function getValor(): array
    {
        return $this->valor;
    }

    public function getEm(): \DateTimeImmutable
    {
        return $this->em;
    }
}
