<?php

namespace App\Ponto\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Ponto\Model\AjusteDaMarcacao;
use App\Ponto\Model\MarcacaoDoPonto;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Ponto\Repository\RegistroPontoRepository::class)]
// O mesmo envio (E13) não entra duas vezes para a mesma pessoa; as batidas sem identificador (antigas, do gestor e do
// esquecimento) ficam com NULL, que o PostgreSQL deixa repetir.
#[ORM\UniqueConstraint(name: self::INDICE_DO_ENVIO, columns: ['user_id', 'id_envio'])]
// NSR único por escritório (D2). As batidas sem NSR (antigas, do gestor, do abono) ficam nulas: no PostgreSQL o
// nulo não colide no índice único, o mesmo que um índice parcial `WHERE nsr IS NOT NULL`.
#[ORM\UniqueConstraint(name: 'uniq_registro_ponto_tenant_nsr', columns: ['tenant_id', 'nsr'])]
// E10: a batida gravada só se insere (gatilho registro_ponto_so_insercao). Data e hora, tipo, pessoa e escritório não
// mudam depois do id: a correção vai para ponto_ajuste (PontoAjuste).
class RegistroPonto implements Auditavel, TenantAware, MarcacaoDoPonto
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

    // E12 (spec ponto-desenho-0811, P18 e P74): a batida feita fora do raio das sedes e registrada como trabalho
    // externo. Fica sem sede, com a posição e o local e motivo que a pessoa escreveu, e vai para a análise do gestor
    // por uma justificativa `trabalho_externo` ligada a ela.
    #[ORM\Column(options: ['default' => false])]
    private bool $trabalhoExterno = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $motivoTrabalhoExterno = null;

    // NSR, número sequencial do registro no escritório (spec ponto-desenho-0811 D2 e E15): dado pelo servidor
    // (`NumeroSequencialDoRegistro`) só à batida que a própria pessoa registra. Nulo nas batidas antigas, nas lançadas
    // pelo gestor e nas criadas pelo abono de esquecimento (ajuste, não marcação).
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $nsr = null;

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

    public function getNsr(): ?int
    {
        return $this->nsr;
    }

    public function setNsr(int $nsr): static
    {
        $this->nsr = $nsr;

        return $this;
    }

    public function isTrabalhoExterno(): bool
    {
        return $this->trabalhoExterno;
    }

    public function setTrabalhoExterno(bool $trabalhoExterno): static
    {
        $this->trabalhoExterno = $trabalhoExterno;
        return $this;
    }

    public function getMotivoTrabalhoExterno(): ?string
    {
        return $this->motivoTrabalhoExterno;
    }

    public function setMotivoTrabalhoExterno(?string $motivoTrabalhoExterno): static
    {
        $this->motivoTrabalhoExterno = $motivoTrabalhoExterno;
        return $this;
    }

    /**
     * Batida feita pelo próprio colaborador: sem observação e sem a marca de lançamento manual. A mesma regra de
     * `EscolhaDasBatidasDoDia::ehDoColaborador` (a aprovação de esquecimento grava observação fixa; o lançamento e a
     * edição do admin gravam o snapshot `Lançamento manual`).
     */
    public function feitaPeloColaborador(): bool
    {
        return $this->observacao === null
            && $this->sedeNomeSnapshot !== self::SNAPSHOT_LANCAMENTO_MANUAL;
    }

    public function pessoaId(): int
    {
        return $this->user?->getId() ?? throw new \LogicException('A batida ainda não tem a pessoa gravada.');
    }

    /** O alvo dos ajustes desta batida: 'b{id}'. */
    public function alvo(): string
    {
        return 'b' . ($this->id ?? throw new \LogicException('A batida ainda não foi gravada: não tem alvo de ajuste.'));
    }

    /** A batida original, sem ajuste: não tem cabeça de linhagem. */
    public function cabeca(): ?int
    {
        return null;
    }

    public function ajuste(): ?AjusteDaMarcacao
    {
        return null;
    }
}
