<?php

namespace App\Ponto\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Ponto\Repository\RegistroPontoRepository::class)]
// O mesmo envio (E13) não entra duas vezes para a mesma pessoa; as batidas sem identificador (antigas, do gestor e do
// esquecimento) ficam com NULL, que o PostgreSQL deixa repetir.
#[ORM\UniqueConstraint(name: self::INDICE_DO_ENVIO, columns: ['user_id', 'id_envio'])]
class RegistroPonto implements Auditavel, TenantAware
{
    /** O índice único do envio: a batida reconhece pela mensagem do banco a violação que é dele. */
    public const INDICE_DO_ENVIO = 'uniq_registro_ponto_user_envio';

    public const TIPO_ENTRADA = 'entrada';
    public const TIPO_REPOUSO = 'repouso';
    public const TIPO_RETORNO = 'retorno';
    public const TIPO_SAIDA = 'saida';
    public const TIPOS_VALIDOS = [
        self::TIPO_ENTRADA,
        self::TIPO_REPOUSO,
        self::TIPO_RETORNO,
        self::TIPO_SAIDA,
    ];

    /**
     * O `sedeNomeSnapshot` da batida lançada ou editada pelo admin (`TenantController::pontoAdd` e
     * `pontoEdit`). `EscolhaDasBatidasDoDia` lê este mesmo valor para saber que a batida não é do
     * colaborador — por isso é constante, e não texto repetido nos dois lados.
     */
    public const SNAPSHOT_LANCAMENTO_MANUAL = 'Lançamento manual';

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

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $dataHora = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 8, nullable: true)]
    private ?string $latitude = null;

    #[ORM\Column(type: 'decimal', precision: 11, scale: 8, nullable: true)]
    private ?string $longitude = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $precisaoGps = null; // em metros

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Sede $sede = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sedeNomeSnapshot = null;

    #[ORM\Column(length: 20)]
    private ?string $tipo = self::TIPO_ENTRADA;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observacao = null;

    // Batida registrada em home office (fora do raio das sedes, sem GPS). sede/lat/lng ficam nulos.
    #[ORM\Column(options: ['default' => false])]
    private bool $homeOffice = false;

    // E13 (spec ponto-desenho-0811): a hora do relógio do aparelho no toque. Na batida normal é só informação; na
    // guardada sem internet (E14) é ela que vale. Nula nas batidas antigas, nas do gestor e quando o aparelho não mandou.
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $horaAparelho = null;

    // A hora oficial (relógio do servidor) em que a batida chegou. Nula nas batidas anteriores à E13.
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $horaChegada = null;

    // A batida veio da fila do aparelho, guardada sem internet (E14): a folha marca o dia "Sem internet".
    #[ORM\Column(options: ['default' => false])]
    private bool $enviadaSemInternet = false;

    // O identificador do envio feito pelo navegador no toque (UUID v4), o mesmo em toda nova tentativa da batida.
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $idEnvio = null;

    public function __construct()
    {
        $this->dataHora = new \DateTime();
    }

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

    public function getDataHora(): ?\DateTimeInterface
    {
        return $this->dataHora;
    }

    public function setDataHora(\DateTimeInterface $dataHora): static
    {
        $this->dataHora = $dataHora;
        return $this;
    }

    public function getLatitude(): ?string
    {
        return $this->latitude;
    }

    public function setLatitude(?string $latitude): static
    {
        $this->latitude = $latitude;
        return $this;
    }

    public function getLongitude(): ?string
    {
        return $this->longitude;
    }

    public function setLongitude(?string $longitude): static
    {
        $this->longitude = $longitude;
        return $this;
    }

    public function getPrecisaoGps(): ?string
    {
        return $this->precisaoGps;
    }

    public function setPrecisaoGps(?string $precisaoGps): static
    {
        $this->precisaoGps = $precisaoGps;
        return $this;
    }

    public function getSede(): ?Sede
    {
        return $this->sede;
    }

    public function setSede(?Sede $sede): static
    {
        $this->sede = $sede;
        return $this;
    }

    public function getSedeNomeSnapshot(): ?string
    {
        return $this->sedeNomeSnapshot;
    }

    public function setSedeNomeSnapshot(?string $sedeNomeSnapshot): static
    {
        $this->sedeNomeSnapshot = $sedeNomeSnapshot;
        return $this;
    }

    public function getTipo(): ?string
    {
        return $this->tipo;
    }

    public function setTipo(string $tipo): static
    {
        if (!in_array($tipo, self::TIPOS_VALIDOS, true)) {
            throw new \InvalidArgumentException('Tipo de registro de ponto invalido.');
        }

        $this->tipo = $tipo;
        return $this;
    }

    public function getObservacao(): ?string
    {
        return $this->observacao;
    }

    public function setObservacao(?string $observacao): static
    {
        $this->observacao = $observacao;
        return $this;
    }

    public function isHomeOffice(): bool
    {
        return $this->homeOffice;
    }

    public function setHomeOffice(bool $homeOffice): static
    {
        $this->homeOffice = $homeOffice;
        return $this;
    }

    public function getHoraAparelho(): ?\DateTimeImmutable
    {
        return $this->horaAparelho;
    }

    public function setHoraAparelho(?\DateTimeImmutable $horaAparelho): static
    {
        $this->horaAparelho = $horaAparelho;
        return $this;
    }

    public function getHoraChegada(): ?\DateTimeImmutable
    {
        return $this->horaChegada;
    }

    public function setHoraChegada(?\DateTimeImmutable $horaChegada): static
    {
        $this->horaChegada = $horaChegada;
        return $this;
    }

    public function isEnviadaSemInternet(): bool
    {
        return $this->enviadaSemInternet;
    }

    public function setEnviadaSemInternet(bool $enviadaSemInternet): static
    {
        $this->enviadaSemInternet = $enviadaSemInternet;
        return $this;
    }

    public function getIdEnvio(): ?string
    {
        return $this->idEnvio;
    }

    public function setIdEnvio(?string $idEnvio): static
    {
        $this->idEnvio = $idEnvio;
        return $this;
    }
}
