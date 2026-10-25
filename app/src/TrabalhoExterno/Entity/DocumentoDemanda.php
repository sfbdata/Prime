<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Auth\User;
use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\Enum\Perfil;
use Doctrine\ORM\Mapping as ORM;

/**
 * Documento anexado à demanda. `nomeArmazenado` é só o NOME cunhado pelo storage (D8); o escopo
 * do arquivo é o escritório solicitante, dono da demanda (ver `ChavesDeTrabalhoExterno`). Com `despacho`,
 * é anexo de um despacho da Central: a mesma guarda de download e a mesma purga, fora da lista DOCUMENTOS.
 */
#[ORM\Entity]
#[ORM\Table(name: 'trabalho_externo_documento')]
class DocumentoDemanda
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $enviadoPor;

    #[ORM\Column(length: 255)]
    private string $nomeAutor;

    #[ORM\Column(enumType: Perfil::class, length: 20)]
    private Perfil $perfilAutor;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $em;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: DemandaExterna::class, inversedBy: 'documentos')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DemandaExterna $demanda,
        #[ORM\Column(length: 255)]
        private string $nomeOriginal,
        #[ORM\Column(length: 255)]
        private string $nomeArmazenado,
        #[ORM\Column(type: 'bigint')]
        private int $tamanho,
        #[ORM\Column(length: 120)]
        private string $mimeType,
        Autor $autor,
        /** Anexo de um despacho da Central (desenho 06 de 24/10, l.516): aparece no despacho, não em DOCUMENTOS. */
        #[ORM\ManyToOne(targetEntity: DespachoDemanda::class, inversedBy: 'documentos')]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?DespachoDemanda $despacho = null,
    ) {
        $this->enviadoPor = $autor->usuario;
        $this->nomeAutor = $autor->nome;
        $this->perfilAutor = $autor->perfil;
        $this->em = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDemanda(): DemandaExterna
    {
        return $this->demanda;
    }

    public function getNomeOriginal(): string
    {
        return $this->nomeOriginal;
    }

    public function getNomeArmazenado(): string
    {
        return $this->nomeArmazenado;
    }

    public function getTamanho(): int
    {
        return $this->tamanho;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getNomeAutor(): string
    {
        return $this->nomeAutor;
    }

    public function getPerfilAutor(): Perfil
    {
        return $this->perfilAutor;
    }

    public function getEm(): \DateTimeImmutable
    {
        return $this->em;
    }

    public function getDespacho(): ?DespachoDemanda
    {
        return $this->despacho;
    }
}
