<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Tenant\Tenant;
use App\TrabalhoExterno\Enum\Disponibilidade;
use App\TrabalhoExterno\Enum\Visibilidade;
use App\TrabalhoExterno\Repository\ConfiguracaoCentralRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Configuração ÚNICA da Central (há um só Master global, D1). Os padrões são os do desenho
 * (`Component.CFG`). A sede (D2) começa vazia: até o Master defini-la, ninguém é Equipe.
 */
#[ORM\Entity(repositoryClass: ConfiguracaoCentralRepository::class)]
#[ORM\Table(name: 'trabalho_externo_configuracao')]
class ConfiguracaoCentral
{
    /** O que a Equipe pode fazer ("EQUIPE DO MASTER (SECUNDÁRIOS)"). */
    public const EQUIPE_PADRAO = ['ver' => true, 'chat' => true, 'proposta' => false, 'convidar' => false, 'situacao' => false];

    /** "PRIVACIDADE PARA CONTAS EXTERNAS". */
    public const PRIVACIDADE_PADRAO = ['nomeResp' => false, 'contatoConv' => false];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(enumType: Visibilidade::class, length: 10)]
    private Visibilidade $visibilidade = Visibilidade::Todos;

    #[ORM\Column(enumType: Disponibilidade::class, length: 10)]
    private Disponibilidade $disponibilidade = Disponibilidade::Disponivel;

    #[ORM\Column]
    private bool $bloquearSuspenso = true;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motivo = null;

    /** @var array<string, bool> */
    #[ORM\Column(type: 'json')]
    private array $equipe = self::EQUIPE_PADRAO;

    /** @var array<string, bool> */
    #[ORM\Column(type: 'json')]
    private array $privacidade = self::PRIVACIDADE_PADRAO;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Tenant $sede = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVisibilidade(): Visibilidade
    {
        return $this->visibilidade;
    }

    public function definirVisibilidade(Visibilidade $v): void
    {
        $this->visibilidade = $v;
        $this->tocar();
    }

    public function getDisponibilidade(): Disponibilidade
    {
        return $this->disponibilidade;
    }

    public function definirDisponibilidade(Disponibilidade $d): void
    {
        $this->disponibilidade = $d;
        $this->tocar();
    }

    public function isBloquearSuspenso(): bool
    {
        return $this->bloquearSuspenso;
    }

    public function definirBloquearSuspenso(bool $b): void
    {
        $this->bloquearSuspenso = $b;
        $this->tocar();
    }

    /** A central está suspensa E configurada para aceitar só rascunhos. */
    public function envioBloqueado(): bool
    {
        return $this->disponibilidade === Disponibilidade::Suspensa && $this->bloquearSuspenso;
    }

    public function getMotivo(): ?string
    {
        return $this->motivo;
    }

    public function definirMotivo(?string $motivo): void
    {
        $motivo = $motivo !== null ? trim($motivo) : null;
        $this->motivo = $motivo === '' ? null : mb_substr((string) $motivo, 0, 255);
        $this->tocar();
    }

    public function equipePode(string $chave): bool
    {
        return (bool) ($this->getEquipe()[$chave] ?? false);
    }

    /** @return array<string, bool> */
    public function getEquipe(): array
    {
        return array_merge(self::EQUIPE_PADRAO, array_intersect_key($this->equipe, self::EQUIPE_PADRAO));
    }

    public function definirEquipe(string $chave, bool $valor): void
    {
        if (!array_key_exists($chave, self::EQUIPE_PADRAO)) {
            throw new \InvalidArgumentException('Opção de equipe desconhecida.');
        }
        $equipe = $this->getEquipe();
        $equipe[$chave] = $valor;
        $this->equipe = $equipe;
        $this->tocar();
    }

    public function privacidade(string $chave): bool
    {
        return (bool) ($this->getPrivacidade()[$chave] ?? false);
    }

    /** @return array<string, bool> */
    public function getPrivacidade(): array
    {
        return array_merge(self::PRIVACIDADE_PADRAO, array_intersect_key($this->privacidade, self::PRIVACIDADE_PADRAO));
    }

    public function definirPrivacidade(string $chave, bool $valor): void
    {
        if (!array_key_exists($chave, self::PRIVACIDADE_PADRAO)) {
            throw new \InvalidArgumentException('Opção de privacidade desconhecida.');
        }
        $priv = $this->getPrivacidade();
        $priv[$chave] = $valor;
        $this->privacidade = $priv;
        $this->tocar();
    }

    public function getSede(): ?Tenant
    {
        return $this->sede;
    }

    public function definirSede(?Tenant $sede): void
    {
        $this->sede = $sede;
        $this->tocar();
    }

    private function tocar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }
}
