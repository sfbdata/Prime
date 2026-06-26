<?php

namespace App\Entity\Ponto;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Enum\TipoJustificativa;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\Ponto\JustificativaPontoRepository::class)]
#[ORM\Index(fields: ['batchId'], name: 'IDX_JUST_BATCH')]
class JustificativaPonto implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $data = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $anexoPath = null; // Caminho para o arquivo (PDF/Imagem)

    #[ORM\Column(length: 20)]
    private ?string $status = 'pendente'; // pendente, abonado, rejeitado

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $dataAnalise = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $analisadoPor = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observacaoAnalise = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $batchId = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $tipo = null;

    #[ORM\Column]
    private bool $abonoParcial = false;

    #[ORM\Column(type: 'time', nullable: true)]
    private ?\DateTimeInterface $horaInicioAbono = null;

    #[ORM\Column(type: 'time', nullable: true)]
    private ?\DateTimeInterface $horaFimAbono = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $tipoRegistroEsquecido = null;

    #[ORM\Column(type: 'time', nullable: true)]
    private ?\DateTimeInterface $horaRegistroEsquecido = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getData(): ?\DateTimeInterface
    {
        return $this->data;
    }

    public function setData(\DateTimeInterface $data): static
    {
        $this->data = $data;
        return $this;
    }

    public function getAnexoPath(): ?string
    {
        return $this->anexoPath;
    }

    public function setAnexoPath(?string $anexoPath): static
    {
        $this->anexoPath = $anexoPath;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getDataAnalise(): ?\DateTimeInterface
    {
        return $this->dataAnalise;
    }

    public function setDataAnalise(?\DateTimeInterface $dataAnalise): static
    {
        $this->dataAnalise = $dataAnalise;
        return $this;
    }

    public function getAnalisadoPor(): ?User
    {
        return $this->analisadoPor;
    }

    public function setAnalisadoPor(?User $analisadoPor): static
    {
        $this->analisadoPor = $analisadoPor;
        return $this;
    }

    public function getObservacaoAnalise(): ?string
    {
        return $this->observacaoAnalise;
    }

    public function setObservacaoAnalise(?string $observacaoAnalise): static
    {
        $this->observacaoAnalise = $observacaoAnalise;
        return $this;
    }

    public function getBatchId(): ?string
    {
        return $this->batchId;
    }

    public function setBatchId(?string $batchId): static
    {
        $this->batchId = $batchId;
        return $this;
    }

    public function getTipo(): ?string
    {
        return $this->tipo;
    }

    public function setTipo(?string $tipo): static
    {
        $this->tipo = $tipo;
        return $this;
    }

    public function getLabelTipo(): ?string
    {
        if ($this->tipo === null) {
            return null;
        }

        return TipoJustificativa::tryFrom($this->tipo)?->label() ?? $this->tipo;
    }

    public function isAbonoParcial(): bool
    {
        return $this->abonoParcial;
    }

    public function setAbonoParcial(bool $abonoParcial): static
    {
        $this->abonoParcial = $abonoParcial;
        return $this;
    }

    public function getHoraInicioAbono(): ?\DateTimeInterface
    {
        return $this->horaInicioAbono;
    }

    public function setHoraInicioAbono(?\DateTimeInterface $horaInicioAbono): static
    {
        $this->horaInicioAbono = $horaInicioAbono;
        return $this;
    }

    public function getHoraFimAbono(): ?\DateTimeInterface
    {
        return $this->horaFimAbono;
    }

    public function setHoraFimAbono(?\DateTimeInterface $horaFimAbono): static
    {
        $this->horaFimAbono = $horaFimAbono;
        return $this;
    }

    public function getTipoRegistroEsquecido(): ?string
    {
        return $this->tipoRegistroEsquecido;
    }

    public function setTipoRegistroEsquecido(?string $tipoRegistroEsquecido): static
    {
        $this->tipoRegistroEsquecido = $tipoRegistroEsquecido;

        return $this;
    }

    public function getHoraRegistroEsquecido(): ?\DateTimeInterface
    {
        return $this->horaRegistroEsquecido;
    }

    public function setHoraRegistroEsquecido(?\DateTimeInterface $horaRegistroEsquecido): static
    {
        $this->horaRegistroEsquecido = $horaRegistroEsquecido;

        return $this;
    }

    public function getMinutosAbonados(): int
    {
        if (!$this->abonoParcial || $this->horaInicioAbono === null || $this->horaFimAbono === null) {
            return 0;
        }
        $diff = $this->horaInicioAbono->diff($this->horaFimAbono);
        return max(0, ($diff->h * 60) + $diff->i);
    }
}
