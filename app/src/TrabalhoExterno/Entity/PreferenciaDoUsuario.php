<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Repository\PreferenciaDoUsuarioRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * O que o Trabalho Externo lembra de UM usuário entre as visitas (desenho 06): a última visita à aba
 * Vagas, que define o selo "NOVA" (`bj-te-visto-<perfil>`, l.1008–1009), e o último "QUEM ESTÁ
 * OFERECENDO", que vira o padrão das próximas vagas (`ofPad`, l.1005), e o aviso de vaga nova na área
 * (`bj-te-alerta-<perfil>`, l.1107, guardado no servidor pela spec §4.7). Uma linha por usuário e chave.
 */
#[ORM\Entity(repositoryClass: PreferenciaDoUsuarioRepository::class)]
#[ORM\Table(name: 'trabalho_externo_preferencia')]
#[ORM\UniqueConstraint(name: 'uniq_te_preferencia_usuario_chave', columns: ['usuario_id', 'chave'])]
class PreferenciaDoUsuario
{
    public const ULTIMA_VISITA_VAGAS = 'vagas.ultimaVisita';
    /** Visitas mais próximas que isso contam como a mesma: "desde a última visita" não zera ao recarregar. */
    private const MINUTOS_DA_MESMA_VISITA = 30;
    public const OFERTANTE_PADRAO = 'vagas.ofertantePadrao';
    /** "Avisar quando sair vaga da minha área" (l.1107): `{ligado, escritorioId}`, o escritório do contexto. */
    public const ALERTA_DE_VAGAS = 'vagas.alerta';

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

    /**
     * A visita à aba Vagas (`{em, anterior}`): `em` é a de agora; `anterior` só avança quando a visita
     * guardada tem mais de 30 min, para "NOVA" e "desde a sua última visita" sobreviverem ao recarregar e
     * ao aviso de visita que a tela manda depois de listar.
     */
    public function registrarVisita(\DateTimeImmutable $agora): void
    {
        $em = $this->valor['em'] ?? null;
        $anterior = $this->valor['anterior'] ?? null;
        if (is_string($em) && new \DateTimeImmutable($em) < $agora->modify(sprintf('-%d minutes', self::MINUTOS_DA_MESMA_VISITA))) {
            $anterior = $em;
        }
        $this->definir(['em' => $agora->format(\DATE_ATOM), 'anterior' => is_string($anterior) ? $anterior : null], $agora);
    }

    /** A referência de "nova" (a visita anterior); null quando ainda não houve: nada é novo. */
    public function visitaAnterior(): ?\DateTimeImmutable
    {
        $anterior = $this->valor['anterior'] ?? null;

        return is_string($anterior) ? new \DateTimeImmutable($anterior) : null;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
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
