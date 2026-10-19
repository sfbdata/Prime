<?php

declare(strict_types=1);

namespace App\Agenda\Entity;

use App\Agenda\Enum\TipoDeCompromisso;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Repository\EventoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\ConteudoParticular;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EventoRepository::class)]
#[ORM\Index(name: 'idx_evento_google_id', columns: ['google_id'])]
#[ORM\HasLifecycleCallbacks]
class Evento implements Auditavel, TenantAware, ConteudoParticular
{
    public const STATUS_AGENDADO = 'agendado';
    public const STATUS_CONCLUIDO = 'concluido';
    public const STATUS_CANCELADO = 'cancelado';

    public const VISIBILIDADE_TODOS = 'todos';
    public const VISIBILIDADE_SOMENTE_EU = 'somente_eu';

    public const COR_AZUL = '#0073b7';
    public const COR_VERDE = '#00a65a';
    public const COR_AMARELO = '#f39c12';
    public const COR_VERMELHO = '#f56954';
    public const COR_ROXO = '#605ca8';
    public const COR_CIANO = '#00c0ef';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $titulo = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descricao = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $dataInicio = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $dataFim = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $local = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_AGENDADO;

    #[ORM\Column(length: 20)]
    private string $cor = self::COR_AZUL;

    #[ORM\Column(type: 'boolean')]
    private bool $diaInteiro = false;

    #[ORM\Column(type: 'boolean')]
    private bool $recorrente = false;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $tipoRecorrencia = null; // diario, semanal, mensal, anual

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $fimRecorrencia = null;

    #[ORM\Column(length: 20)]
    private string $visibilidade = self::VISIBILIDADE_TODOS;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $criador = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'evento_participante')]
    private Collection $participantes;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $criadoEm = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $modificadoEm = null;

    /** Nulo nos eventos da agenda antiga: o calendário sai da legenda de cor. */
    #[ORM\Column(length: 10, nullable: true, enumType: TipoDeCompromisso::class)]
    private ?TipoDeCompromisso $tipo = null;

    #[ORM\ManyToOne(targetEntity: Pasta::class)]
    #[ORM\JoinColumn(name: 'pasta_id', nullable: true, onDelete: 'SET NULL')]
    private ?Pasta $pasta = null;

    /** Minutos antes do início para o lembrete; 0 = sem lembrete; nulo = padrão do tipo. */
    #[ORM\Column(nullable: true)]
    private ?int $lembreteMinutos = null;

    /** @var array<mixed> ids dos colegas avisados no Chat (JSON do banco; getAvisados() normaliza) */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $avisados = [];

    /** Id do evento no Google Agenda da pessoa que criou (entrega 6); nulo se nunca foi sincronizado. */
    #[ORM\Column(name: 'google_id', length: 1024, nullable: true)]
    private ?string $googleId = null;

    /** Resumo do que foi sincronizado com o Google por último: muda o compromisso, muda o resumo, e ele vai. */
    #[ORM\Column(name: 'google_hash', length: 80, nullable: true)]
    private ?string $googleHash = null;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
        $this->participantes = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function setModificadoEmValue(): void
    {
        $this->modificadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function setTitulo(string $titulo): self
    {
        $this->titulo = $titulo;
        return $this;
    }

    public function getDescricao(): ?string
    {
        return $this->descricao;
    }

    public function setDescricao(?string $descricao): self
    {
        $this->descricao = $descricao;
        return $this;
    }

    public function getDataInicio(): ?\DateTimeImmutable
    {
        return $this->dataInicio;
    }

    public function setDataInicio(\DateTimeImmutable $dataInicio): self
    {
        $this->dataInicio = $dataInicio;
        return $this;
    }

    public function getDataFim(): ?\DateTimeImmutable
    {
        return $this->dataFim;
    }

    public function setDataFim(\DateTimeImmutable $dataFim): self
    {
        $this->dataFim = $dataFim;
        return $this;
    }

    public function getLocal(): ?string
    {
        return $this->local;
    }

    public function setLocal(?string $local): self
    {
        $this->local = $local;
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

    public function getVisibilidade(): string
    {
        return $this->visibilidade;
    }

    public function setVisibilidade(string $visibilidade): self
    {
        $this->visibilidade = $visibilidade;
        return $this;
    }

    public function getCor(): string
    {
        return $this->cor;
    }

    public function setCor(string $cor): self
    {
        $this->cor = $cor;
        return $this;
    }

    public function isDiaInteiro(): bool
    {
        return $this->diaInteiro;
    }

    public function setDiaInteiro(bool $diaInteiro): self
    {
        $this->diaInteiro = $diaInteiro;
        return $this;
    }

    public function isRecorrente(): bool
    {
        return $this->recorrente;
    }

    public function setRecorrente(bool $recorrente): self
    {
        $this->recorrente = $recorrente;
        return $this;
    }

    public function getTipoRecorrencia(): ?string
    {
        return $this->tipoRecorrencia;
    }

    public function setTipoRecorrencia(?string $tipoRecorrencia): self
    {
        $this->tipoRecorrencia = $tipoRecorrencia;
        return $this;
    }

    public function getFimRecorrencia(): ?\DateTimeImmutable
    {
        return $this->fimRecorrencia;
    }

    public function setFimRecorrencia(?\DateTimeImmutable $fimRecorrencia): self
    {
        $this->fimRecorrencia = $fimRecorrencia;
        return $this;
    }

    public function getCriador(): ?User
    {
        return $this->criador;
    }

    public function setCriador(User $criador): self
    {
        $this->criador = $criador;
        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;
        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getParticipantes(): Collection
    {
        return $this->participantes;
    }

    public function addParticipante(User $participante): self
    {
        if (!$this->participantes->contains($participante)) {
            $this->participantes->add($participante);
        }
        return $this;
    }

    public function removeParticipante(User $participante): self
    {
        $this->participantes->removeElement($participante);
        return $this;
    }

    public function getCriadoEm(): ?\DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getModificadoEm(): ?\DateTimeImmutable
    {
        return $this->modificadoEm;
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_AGENDADO => 'Agendado',
            self::STATUS_CONCLUIDO => 'Concluído',
            self::STATUS_CANCELADO => 'Cancelado',
            default => $this->status,
        };
    }

    public function getStatusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_AGENDADO => 'text-bg-primary',
            self::STATUS_CONCLUIDO => 'text-bg-success',
            self::STATUS_CANCELADO => 'text-bg-danger',
            default => 'text-bg-secondary',
        };
    }

    /**
     * Retorna o evento em formato para FullCalendar
     */
    public function toFullCalendarArray(): array
    {
        $data = [
            'id' => $this->id,
            'title' => $this->titulo,
            'start' => $this->dataInicio?->format('Y-m-d\TH:i:s'),
            'end' => $this->dataFim?->format('Y-m-d\TH:i:s'),
            'allDay' => $this->diaInteiro,
            'backgroundColor' => $this->cor,
            'borderColor' => $this->cor,
            'extendedProps' => [
                'descricao' => $this->descricao,
                'local' => $this->local,
                'status' => $this->status,
                'criador' => $this->criador?->getFullName(),
            ],
        ];

        // Adicionar classe CSS para eventos cancelados
        if ($this->status === self::STATUS_CANCELADO) {
            $data['classNames'] = ['evento-cancelado'];
        }

        return $data;
    }

    public function getTipo(): ?TipoDeCompromisso
    {
        return $this->tipo;
    }

    public function setTipo(?TipoDeCompromisso $tipo): self
    {
        $this->tipo = $tipo;
        if (null !== $tipo) {
            $this->cor = $tipo->cor();
        }

        return $this;
    }

    public function getPasta(): ?Pasta
    {
        return $this->pasta;
    }

    public function setPasta(?Pasta $pasta): self
    {
        $this->pasta = $pasta;

        return $this;
    }

    public function getLembreteMinutos(): ?int
    {
        return $this->lembreteMinutos;
    }

    public function setLembreteMinutos(?int $minutos): self
    {
        $this->lembreteMinutos = $minutos;

        return $this;
    }

    /** @return list<int> */
    public function getAvisados(): array
    {
        return array_map('intval', array_values($this->avisados));
    }

    /**
     * Acrescenta colegas avisados no Chat e devolve só os que ainda não tinham sido avisados.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function avisar(array $ids): array
    {
        $novos = array_values(array_diff(array_unique(array_map('intval', $ids)), $this->getAvisados()));
        $this->avisados = [...$this->getAvisados(), ...$novos];

        return $novos;
    }

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function getGoogleHash(): ?string
    {
        return $this->googleHash;
    }

    /** O Google recusou esta versão (resumo com prefixo): não tenta de novo até o compromisso mudar. */
    public function recusadoPeloGoogle(string $hashDaRecusa): self
    {
        $this->googleHash = $hashDaRecusa;

        return $this;
    }

    /** Marca o compromisso como igual ao do Google (depois de enviar ou de trazer). */
    public function sincronizadoComGoogle(string $googleId, string $hash): self
    {
        $this->googleId = $googleId;
        $this->googleHash = $hash;

        return $this;
    }

    /** "Só eu" agora ou antes desta mudança: nos dois casos o conteúdo não vai para a auditoria. */
    public function ehParticular(array $mudancas = []): bool
    {
        $antes = $mudancas['visibilidade'][0] ?? null;

        return self::VISIBILIDADE_SOMENTE_EU === $this->visibilidade || self::VISIBILIDADE_SOMENTE_EU === $antes;
    }

    public function camposParticulares(): array
    {
        return ['titulo', 'descricao', 'local'];
    }
}
