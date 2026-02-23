<?php

namespace App\Entity\Tarefa;

use App\Entity\Auth\User;
use App\Repository\TarefaMensagemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TarefaMensagemRepository::class)]
class TarefaMensagem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tarefa::class, inversedBy: 'mensagens')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tarefa $tarefa = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $usuario = null;

    #[ORM\Column(type: 'text')]
    private string $mensagem;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $arquivoAnexo = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTarefa(): ?Tarefa
    {
        return $this->tarefa;
    }

    public function setTarefa(?Tarefa $tarefa): self
    {
        $this->tarefa = $tarefa;
        return $this;
    }

    public function getUsuario(): ?User
    {
        return $this->usuario;
    }

    public function setUsuario(?User $usuario): self
    {
        $this->usuario = $usuario;
        return $this;
    }

    public function getMensagem(): string
    {
        return $this->mensagem;
    }

    public function setMensagem(string $mensagem): self
    {
        $this->mensagem = $mensagem;
        return $this;
    }

    public function getArquivoAnexo(): ?string
    {
        return $this->arquivoAnexo;
    }

    public function setArquivoAnexo(?string $arquivoAnexo): self
    {
        $this->arquivoAnexo = $arquivoAnexo;
        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function setCriadoEm(\DateTimeImmutable $criadoEm): self
    {
        $this->criadoEm = $criadoEm;
        return $this;
    }
}
