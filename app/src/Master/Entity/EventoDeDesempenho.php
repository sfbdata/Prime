<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Master\Enum\TipoDeEventoDeDesempenho;
use App\Master\Repository\EventoDeDesempenhoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um evento medido nas telas ou no login (seção Desempenho do Master; spec docs/specs/master-desempenho.md).
 *
 * GLOBAL, sem tenant, de propósito: é telemetria da PLATAFORMA. Dado pessoal mínimo (§2.1): só `usuario`
 * (nulo se anônimo ou excluído), nunca IP, user agent nem e-mail digitado. `pagina` é só o caminho.
 */
#[ORM\Entity(repositoryClass: EventoDeDesempenhoRepository::class)]
#[ORM\Table(name: 'desempenho_evento')]
#[ORM\Index(name: 'idx_desempenho_evento_tipo_em', columns: ['tipo', 'em'])]
#[ORM\Index(name: 'idx_desempenho_evento_usuario', columns: ['usuario_id'])]
class EventoDeDesempenho
{
    public const MENSAGEM_MAXIMA = 160;
    public const ORIGEM_MAXIMA = 60;
    public const PAGINA_MAXIMA = 120;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $mensagem = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $origem = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $pagina = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'usuario_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $usuario = null;

    public function __construct(
        #[ORM\Column(length: 10, enumType: TipoDeEventoDeDesempenho::class)]
        private TipoDeEventoDeDesempenho $tipo,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $em,
        #[ORM\Column(type: 'boolean', nullable: true)]
        private ?bool $ok = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        private ?int $ms = null,
    ) {
    }

    /** Texto vindo de fora: corta nos tamanhos da coluna; vazio vira nulo. */
    public function descrever(?string $mensagem, ?string $origem, ?string $pagina, ?User $usuario): void
    {
        $this->mensagem = self::cortar($mensagem, self::MENSAGEM_MAXIMA);
        $this->origem = self::cortar($origem, self::ORIGEM_MAXIMA);
        $this->pagina = self::cortar($pagina, self::PAGINA_MAXIMA);
        $this->usuario = $usuario;
    }

    private static function cortar(?string $texto, int $maximo): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : mb_substr($texto, 0, $maximo);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTipo(): TipoDeEventoDeDesempenho
    {
        return $this->tipo;
    }

    public function getEm(): \DateTimeImmutable
    {
        return $this->em;
    }

    public function getOk(): ?bool
    {
        return $this->ok;
    }

    public function getMs(): ?int
    {
        return $this->ms;
    }

    public function getMensagem(): ?string
    {
        return $this->mensagem;
    }

    public function getOrigem(): ?string
    {
        return $this->origem;
    }

    public function getPagina(): ?string
    {
        return $this->pagina;
    }

    public function getUsuario(): ?User
    {
        return $this->usuario;
    }
}
