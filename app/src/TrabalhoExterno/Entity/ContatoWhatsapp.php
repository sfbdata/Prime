<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\Enum\FaseWhatsapp;
use App\TrabalhoExterno\Enum\Perfil;
use Doctrine\ORM\Mapping as ORM;

/**
 * Contato conectado à demanda para receber avisos pelo WhatsApp ("CONTATOS CONECTADOS"). Só entra
 * com o consentimento declarado por quem cadastra; o momento e o autor do consentimento ficam
 * gravados. Nenhuma mensagem sai sozinha: o BlueJus só prepara o texto e o link `wa.me`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'trabalho_externo_contato_whatsapp')]
class ContatoWhatsapp
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column]
    private bool $ativo = true;

    /** @var array<string, bool> */
    #[ORM\Column(type: 'json')]
    private array $fases;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $consentidoEm;

    #[ORM\Column(length: 255)]
    private string $nomeAutor;

    #[ORM\Column(enumType: Perfil::class, length: 20)]
    private Perfil $perfilAutor;

    /** @param string $telefone só dígitos, com DDD (sem o 55) */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: DemandaExterna::class, inversedBy: 'contatosWhatsapp')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DemandaExterna $demanda,
        #[ORM\Column(length: 120)]
        private string $nome,
        #[ORM\Column(length: 15)]
        private string $telefone,
        Autor $autor,
    ) {
        $this->fases = FaseWhatsapp::todasLigadas();
        $this->consentidoEm = new \DateTimeImmutable();
        $this->nomeAutor = $autor->nome;
        $this->perfilAutor = $autor->perfil;
    }

    public function querAviso(FaseWhatsapp $fase): bool
    {
        return $this->ativo && ($this->fases[$fase->value] ?? false);
    }

    public function alternarAtivo(): void
    {
        $this->ativo = !$this->ativo;
    }

    public function alternarFase(FaseWhatsapp $fase): void
    {
        $this->fases[$fase->value] = !($this->fases[$fase->value] ?? false);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getTelefone(): string
    {
        return $this->telefone;
    }

    public function isAtivo(): bool
    {
        return $this->ativo;
    }

    /** @return array<string, bool> */
    public function getFases(): array
    {
        return $this->fases;
    }

    public function getConsentidoEm(): \DateTimeImmutable
    {
        return $this->consentidoEm;
    }
}
