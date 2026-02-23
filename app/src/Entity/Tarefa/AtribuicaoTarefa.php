<?php

namespace App\Entity\Tarefa;

use App\Entity\Auth\User;
use App\Repository\AtribuicaoTarefaRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AtribuicaoTarefaRepository::class)]
class AtribuicaoTarefa
{
    public const STATUS_PENDENTE = 'pendente';
    public const STATUS_EM_REVISAO = 'em_revisao';
    public const STATUS_CONCLUIDA = 'concluida';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tarefa::class, inversedBy: 'atribuicoes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tarefa $tarefa = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $usuario = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDENTE;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dataAtribuicao;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataEnvioRevisao = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $arquivosUsuario = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descricaoResposta = null;

    public function __construct()
    {
        $this->dataAtribuicao = new \DateTimeImmutable();
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getDataAtribuicao(): \DateTimeImmutable
    {
        return $this->dataAtribuicao;
    }

    public function setDataAtribuicao(\DateTimeImmutable $dataAtribuicao): self
    {
        $this->dataAtribuicao = $dataAtribuicao;
        return $this;
    }

    public function getDataEnvioRevisao(): ?\DateTimeImmutable
    {
        return $this->dataEnvioRevisao;
    }

    public function setDataEnvioRevisao(?\DateTimeImmutable $dataEnvioRevisao): self
    {
        $this->dataEnvioRevisao = $dataEnvioRevisao;
        return $this;
    }

    public function getArquivosUsuario(): array
    {
        return $this->arquivosUsuario ?? [];
    }

    public function setArquivosUsuario(?array $arquivosUsuario): self
    {
        $this->arquivosUsuario = $arquivosUsuario ?? [];
        return $this;
    }

    public function addArquivoUsuario(string $arquivo): self
    {
        $arquivos = $this->getArquivosUsuario();
        $arquivos[] = $arquivo;
        $this->arquivosUsuario = $arquivos;

        return $this;
    }

    public function getDescricaoResposta(): ?string
    {
        return $this->descricaoResposta;
    }

    public function setDescricaoResposta(?string $descricaoResposta): self
    {
        $this->descricaoResposta = $descricaoResposta;
        return $this;
    }
}
