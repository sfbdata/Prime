<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\Enum\FaseWhatsapp;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mensagem pronta para enviar pelo WhatsApp. Sem `enviadoEm` está em "MENSAGENS PRONTAS PARA
 * ENVIAR"; com, em "ENVIADAS". O texto guardado não traz o link da demanda: o endereço absoluto é
 * do servidor, e o apresentador o acrescenta na hora de montar a mensagem e o `wa.me`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'trabalho_externo_aviso_whatsapp')]
class AvisoWhatsapp
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $enviadoEm = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomeEnviadoPor = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: DemandaExterna::class, inversedBy: 'avisosWhatsapp')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DemandaExterna $demanda,
        /** Null na mensagem de boas-vindas, que não é de fase. */
        #[ORM\Column(enumType: FaseWhatsapp::class, length: 20, nullable: true)]
        private ?FaseWhatsapp $fase,
        #[ORM\Column(length: 120)]
        private string $nomeContato,
        #[ORM\Column(length: 15)]
        private string $telefone,
        #[ORM\Column(type: 'text')]
        private string $texto,
    ) {
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function marcarEnviado(Autor $autor): void
    {
        if ($this->enviadoEm === null) {
            $this->enviadoEm = new \DateTimeImmutable();
            $this->nomeEnviadoPor = $autor->nome;
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFase(): ?FaseWhatsapp
    {
        return $this->fase;
    }

    public function getNomeContato(): string
    {
        return $this->nomeContato;
    }

    public function getTelefone(): string
    {
        return $this->telefone;
    }

    public function getTexto(): string
    {
        return $this->texto;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getEnviadoEm(): ?\DateTimeImmutable
    {
        return $this->enviadoEm;
    }
}
