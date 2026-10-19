<?php

declare(strict_types=1);

namespace App\Contribua\Entity;

use App\Contribua\Repository\PlanoRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um plano de espaço do BlueJus (desenho 08: Free, Essencial, Profissional, Avançado).
 *
 * Catálogo GLOBAL, sem tenant: o mesmo plano vale para todos os escritórios e só o Master o edita
 * (desenho 05, "Planos e limites"). Os valores iniciais vêm da migration e seguem o 08; o 05
 * mostra preços diferentes e se declara "Preços de exemplo".
 *
 * `limiteUsuarios` existe porque o 05 mostra usuários por plano; nulo = sem limite. Quem aplica o
 * limite no convite é o domínio Perfis, não este.
 */
#[ORM\Entity(repositoryClass: PlanoRepository::class)]
#[ORM\Table(name: 'contribua_plano')]
#[ORM\UniqueConstraint(name: 'uniq_contribua_plano_codigo', columns: ['codigo'])]
#[ORM\HasLifecycleCallbacks]
class Plano implements Auditavel
{
    public const CODIGO_FREE = 'free';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    public function __construct(
        #[ORM\Column(length: 30)]
        private string $codigo,
        #[ORM\Column(length: 60)]
        private string $nome,
        #[ORM\Column(type: 'integer')]
        private int $cotaGb,
        #[ORM\Column(type: 'integer')]
        private int $precoCentavos,
        #[ORM\Column(length: 120)]
        private string $descricao,
        #[ORM\Column(type: 'smallint')]
        private int $ordem,
        #[ORM\Column(type: 'integer', nullable: true)]
        private ?int $limiteUsuarios = null,
        #[ORM\Column(type: 'boolean', options: ['default' => true])]
        private bool $ativo = true,
    ) {
        $this->criadoEm = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodigo(): string
    {
        return $this->codigo;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getCotaGb(): int
    {
        return $this->cotaGb;
    }

    public function getPrecoCentavos(): int
    {
        return $this->precoCentavos;
    }

    public function getDescricao(): string
    {
        return $this->descricao;
    }

    public function getOrdem(): int
    {
        return $this->ordem;
    }

    public function getLimiteUsuarios(): ?int
    {
        return $this->limiteUsuarios;
    }

    public function isAtivo(): bool
    {
        return $this->ativo;
    }

    public function ehGratuito(): bool
    {
        return $this->precoCentavos === 0;
    }
}
