<?php

declare(strict_types=1);

namespace App\Contribua\Entity;

use App\Contribua\Enum\TipoDeSugestao;
use App\Contribua\Repository\SugestaoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma sugestão de melhoria enviada por alguém do escritório (desenho 08, "Sugestões e melhorias").
 *
 * O escritório vê as próprias; o Master vê as de todos e as marca como atendidas, com resposta
 * opcional para quem enviou. O nome de quem enviou fica gravado no momento do envio: a pessoa pode
 * sair do escritório e a sugestão continua dizendo de quem veio.
 */
#[ORM\Entity(repositoryClass: SugestaoRepository::class)]
#[ORM\Table(name: 'contribua_sugestao')]
#[ORM\Index(name: 'idx_contribua_sugestao_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_contribua_sugestao_autor', columns: ['autor_id'])]
#[ORM\Index(name: 'idx_contribua_sugestao_respondida_por', columns: ['respondida_por_id'])]
class Sugestao implements TenantAware
{
    public const MINIMO_DE_CARACTERES = 8;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $atendida = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $resposta = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $respondidaPor = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $respondidaEm = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $autor,
        #[ORM\Column(length: 180)]
        private string $autorNome,
        #[ORM\Column(length: 10, enumType: TipoDeSugestao::class)]
        private TipoDeSugestao $tipo,
        #[ORM\Column(type: 'text')]
        private string $texto,
    ) {
        $this->texto = trim($texto);
        if (mb_strlen($this->texto) < self::MINIMO_DE_CARACTERES) {
            throw new \DomainException('Escreva um pouco mais para entendermos a sua sugestão.');
        }
        $this->criadaEm = new \DateTimeImmutable();
    }

    /** "Marcar atendida" (com resposta opcional) ou "Reabrir", que apaga a resposta. */
    public function alternarAtendimento(?string $resposta, User $master): void
    {
        $this->atendida = !$this->atendida;
        $resposta = trim((string) $resposta);
        $this->resposta = $this->atendida && $resposta !== '' ? $resposta : null;
        $this->respondidaPor = $this->atendida ? $master : null;
        $this->respondidaEm = $this->atendida ? new \DateTimeImmutable() : null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getAutorNome(): string
    {
        return $this->autorNome;
    }

    public function getTipo(): TipoDeSugestao
    {
        return $this->tipo;
    }

    public function getTexto(): string
    {
        return $this->texto;
    }

    public function isAtendida(): bool
    {
        return $this->atendida;
    }

    public function getResposta(): ?string
    {
        return $this->resposta;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }
}
