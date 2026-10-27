<?php

namespace App\Entity\Tarefa;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Repository\TarefaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use App\Shared\Prazo\RegraDoPrazo;
use App\Tarefa\Enum\EstadoDoPrazoJuridico;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\PrioridadeDaMeta;
use App\Tarefa\Enum\TipoDeDemanda;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TarefaRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_tarefa_tenant_numero', columns: ['tenant_id', 'numero'])]
class Tarefa implements Auditavel, TenantAware
{
    public const STATUS_PENDENTE    = 'pendente';
    public const STATUS_EM_REVISAO  = 'em_revisao';
    public const STATUS_CONCLUIDA   = 'concluida';

    public const STATUS_LABELS = [
        self::STATUS_PENDENTE   => 'Pendente',
        self::STATUS_EM_REVISAO => 'Para Revisão',
        self::STATUS_CONCLUIDA  => 'Concluída',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Número da meta na sequência do ESCRITÓRIO ("Nº 0001", desenho 02 L1559). Nunca o id, que é global
     * e expõe o volume dos outros escritórios (PMC-035). Atribuído ao criar pelo `GravarMetaNovaUseCase`;
     * nulo nas metas antigas até o `app:tarefa:numerar-metas` rodar.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $numero = null;

    #[ORM\Column(length: 255)]
    private string $titulo;

    #[ORM\Column(type: 'text')]
    private string $descricao;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $prazo = null;

    /** Prazo jurídico (fatal) da providência, informado, confirmado ou estimado na Nova meta (desenho 02). Nulo: sem prazo legal ou meta antiga. */
    #[ORM\Column(name: 'prazo_juridico', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $prazoJuridico = null;

    /** De onde veio o `prazoJuridico` (`AnaliseDoPrazoDaMeta`). Nulo nas metas antigas. */
    #[ORM\Column(name: 'estado_prazo_juridico', length: 30, nullable: true, enumType: EstadoDoPrazoJuridico::class)]
    private ?EstadoDoPrazoJuridico $estadoDoPrazoJuridico = null;

    /** Data da publicação informada na origem do prazo. */
    #[ORM\Column(name: 'data_publicacao', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataDaPublicacao = null;

    /** Data da ciência/intimação informada na origem do prazo. */
    #[ORM\Column(name: 'data_ciencia', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataDaCiencia = null;

    /** O prazo da meta foi o prazo interno sugerido pela análise (e não outra data escolhida). */
    #[ORM\Column(name: 'usou_prazo_sugerido', options: ['default' => false])]
    private bool $usouPrazoSugerido = false;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDENTE;

    /** Prioridade própria da meta (desenho 04); nasce herdada da pasta (`PrioridadeDaMeta::daPasta`). */
    #[ORM\Column(length: 20, enumType: PrioridadeDaMeta::class, options: ['default' => 'normal'])]
    private PrioridadeDaMeta $prioridade = PrioridadeDaMeta::Normal;

    /**
     * Caixa da esteira em que a meta está (desenho 04 de 22/10). Sem etapa escolhida, a meta nasce na etapa
     * deduzida do título (`EtapaDaPasta::deduzirDoTitulo`, no `definirEtapaPeloTitulo`).
     */
    #[ORM\Column(length: 20, enumType: EtapaDaPasta::class, options: ['default' => 'juridico'])]
    // Nula só entre o `new` e o PrePersist; no banco é NOT NULL, com Jurídico para INSERT feito por SQL.
    private ?EtapaDaPasta $etapa = null; // @phpstan-ignore doctrine.columnType

    /** Decide a sequência de caixas por onde a pasta passa (`TipoDeDemanda::sequencia`). */
    #[ORM\Column(name: 'tipo_de_demanda', length: 20, enumType: TipoDeDemanda::class, options: ['default' => 'judicial'])]
    private TipoDeDemanda $tipoDeDemanda = TipoDeDemanda::Judicial;

    /**
     * Etapas da esteira que a pasta saltou até chegar a esta meta (desenho 04B L44: riscadas no trilho, "N etapa(s)
     * saltada(s): X, Y"). Lista dos valores de `EtapaDaPasta`, na ordem da esteira; a meta da etapa seguinte herda a
     * lista e soma o que a passagem saltou (04B L175 `pul`).
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'etapas_saltadas', type: 'json', options: ['default' => '[]'])]
    private array $etapasSaltadas = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dataCriacao;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataAlteracao = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataConclusao = null;

    #[ORM\ManyToOne(targetEntity: Pasta::class, inversedBy: 'tarefas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Pasta $pasta;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $criadoPor = null;

    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'tarefa_responsaveis')]
    private Collection $responsaveis;

    /**
     * Quem marcou esta meta para acompanhar. É marcação PESSOAL: acompanhar não muda a meta
     * para os colegas nem dá qualquer permissão — só coloca a meta na aba "Em acompanhamento"
     * de quem marcou. Sem tenant próprio, como `tarefa_responsaveis`: o escopo vem da Tarefa,
     * que é TenantAware.
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'tarefa_acompanhamento')]
    private Collection $acompanhantes;

    #[ORM\OneToMany(mappedBy: 'tarefa', targetEntity: TarefaMensagem::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['criadoEm' => 'ASC', 'id' => 'ASC'])]
    private Collection $mensagens;

    public function __construct()
    {
        $this->dataCriacao = new \DateTimeImmutable();
        $this->mensagens   = new ArrayCollection();
        $this->responsaveis = new ArrayCollection();
        $this->acompanhantes = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function definirEtapaPeloTitulo(): void
    {
        $this->etapa ??= EtapaDaPasta::deduzirDoTitulo($this->titulo);
    }

    #[ORM\PreUpdate]
    public function atualizarDataAlteracao(): void
    {
        $this->dataAlteracao = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): ?int
    {
        return $this->numero;
    }

    /** O número é dado uma vez só: trocar o de uma meta já numerada a desligaria do que já foi citado. */
    public function numerar(int $numero): self
    {
        if ($this->numero !== null) {
            throw new \LogicException('Esta meta já tem número.');
        }
        if ($numero < 1) {
            throw new \InvalidArgumentException('O número da meta começa em 1.');
        }

        $this->numero = $numero;

        return $this;
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

    public function getDescricao(): string
    {
        return $this->descricao;
    }

    public function setDescricao(string $descricao): self
    {
        $this->descricao = $descricao;
        return $this;
    }

    public function getPrazo(): ?\DateTimeImmutable
    {
        return $this->prazo;
    }

    public function setPrazo(?\DateTimeImmutable $prazo): self
    {
        $this->prazo = $prazo;
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

    public function getPrioridade(): PrioridadeDaMeta
    {
        return $this->prioridade;
    }

    public function setPrioridade(PrioridadeDaMeta $prioridade): self
    {
        $this->prioridade = $prioridade;

        return $this;
    }

    public function getPrazoJuridico(): ?\DateTimeImmutable
    {
        return $this->prazoJuridico;
    }

    public function getEstadoDoPrazoJuridico(): ?EstadoDoPrazoJuridico
    {
        return $this->estadoDoPrazoJuridico;
    }

    public function getDataDaPublicacao(): ?\DateTimeImmutable
    {
        return $this->dataDaPublicacao;
    }

    public function getDataDaCiencia(): ?\DateTimeImmutable
    {
        return $this->dataDaCiencia;
    }

    public function usouPrazoSugerido(): bool
    {
        return $this->usouPrazoSugerido;
    }

    /** Guarda o que a análise do prazo da Nova meta concluiu, junto das datas de origem que a pessoa informou. */
    public function registrarAnaliseDoPrazo(?\DateTimeImmutable $prazoJuridico, EstadoDoPrazoJuridico $estado, ?\DateTimeImmutable $publicacao, ?\DateTimeImmutable $ciencia, bool $usouPrazoSugerido): self
    {
        $this->prazoJuridico = $prazoJuridico;
        $this->estadoDoPrazoJuridico = $estado;
        $this->dataDaPublicacao = $publicacao;
        $this->dataDaCiencia = $ciencia;
        $this->usouPrazoSugerido = $usouPrazoSugerido;

        return $this;
    }

    public function getEtapa(): EtapaDaPasta
    {
        return $this->etapa ?? EtapaDaPasta::deduzirDoTitulo($this->titulo);
    }

    public function setEtapa(EtapaDaPasta $etapa): self
    {
        $this->etapa = $etapa;

        return $this;
    }

    public function getTipoDeDemanda(): TipoDeDemanda
    {
        return $this->tipoDeDemanda;
    }

    public function setTipoDeDemanda(TipoDeDemanda $tipoDeDemanda): self
    {
        $this->tipoDeDemanda = $tipoDeDemanda;

        return $this;
    }

    /** @return list<EtapaDaPasta> na ordem da esteira, sem repetir; valor desconhecido no banco é ignorado */
    public function getEtapasSaltadas(): array
    {
        $etapas = array_values(array_filter(array_map(EtapaDaPasta::tryFrom(...), $this->etapasSaltadas)));
        usort($etapas, static fn (EtapaDaPasta $a, EtapaDaPasta $b): int => $a->posicao() <=> $b->posicao());

        return $etapas;
    }

    /** @param iterable<EtapaDaPasta> $etapas */
    public function setEtapasSaltadas(iterable $etapas): self
    {
        $valores = [];
        foreach ($etapas as $etapa) {
            $valores[$etapa->value] = $etapa->value;
        }
        $this->etapasSaltadas = array_values($valores);

        return $this;
    }

    public function getDataCriacao(): \DateTimeImmutable
    {
        return $this->dataCriacao;
    }

    public function getDataAlteracao(): ?\DateTimeImmutable
    {
        return $this->dataAlteracao;
    }

    public function getDataConclusao(): ?\DateTimeImmutable
    {
        return $this->dataConclusao;
    }

    public function setDataConclusao(?\DateTimeImmutable $dataConclusao): self
    {
        $this->dataConclusao = $dataConclusao;
        return $this;
    }

    public function getPasta(): Pasta
    {
        return $this->pasta;
    }

    public function setPasta(Pasta $pasta): self
    {
        $this->pasta = $pasta;
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

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function setCriadoPor(?User $criadoPor): self
    {
        $this->criadoPor = $criadoPor;
        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getResponsaveis(): Collection
    {
        return $this->responsaveis;
    }

    public function getResponsavel(): ?User
    {
        return $this->responsaveis->first() ?: null;
    }

    public function addResponsavel(User $responsavel): self
    {
        if (!$this->responsaveis->contains($responsavel)) {
            $this->responsaveis->add($responsavel);
        }
        return $this;
    }

    public function removeResponsavel(User $responsavel): self
    {
        $this->responsaveis->removeElement($responsavel);
        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getAcompanhantes(): Collection
    {
        return $this->acompanhantes;
    }

    public function ehAcompanhadaPor(User $usuario): bool
    {
        return $this->acompanhantes->contains($usuario);
    }

    /**
     * Liga/desliga o acompanhamento de UM usuário e devolve o estado final — o botão da tela
     * é um alternador, e devolver o estado evita que a view precise recontar a coleção.
     */
    public function alternarAcompanhamento(User $usuario): bool
    {
        if ($this->acompanhantes->contains($usuario)) {
            $this->acompanhantes->removeElement($usuario);

            return false;
        }

        $this->acompanhantes->add($usuario);

        return true;
    }

    /**
     * @return Collection<int, TarefaMensagem>
     */
    public function getMensagens(): Collection
    {
        return $this->mensagens;
    }

    public function addMensagem(TarefaMensagem $mensagem): self
    {
        if (!$this->mensagens->contains($mensagem)) {
            $this->mensagens->add($mensagem);
            $mensagem->setTarefa($this);
        }
        return $this;
    }

    public function removeMensagem(TarefaMensagem $mensagem): self
    {
        if ($this->mensagens->removeElement($mensagem)) {
            if ($mensagem->getTarefa() === $this) {
                $mensagem->setTarefa(null);
            }
        }
        return $this;
    }

    /**
     * A contagem de dias é a da RegraDoPrazo (dias de calendário; a hora gravada no prazo não conta), para a
     * meta dar o mesmo veredito aqui e na Pasta. $hoje existe para os testes; sem ele, é hoje.
     *
     * @return array{dias: int|null, cor: string, texto: string}
     */
    public function getPrazoInfo(?\DateTimeImmutable $hoje = null): array
    {
        if ($this->prazo === null || $this->status === self::STATUS_CONCLUIDA) {
            return ['dias' => null, 'cor' => '', 'texto' => '-'];
        }

        $dias = RegraDoPrazo::diasAte($this->prazo, $hoje);

        if ($dias > 3) {
            $cor = 'success';
        } elseif ($dias >= 1) {
            $cor = 'warning';
        } else {
            $cor = 'danger';
        }

        if ($dias < 0) {
            $texto = abs($dias) . ' dia(s) atrasado';
        } elseif ($dias === 0) {
            $texto = 'Vence hoje';
        } elseif ($dias === 1) {
            $texto = '1 dia';
        } else {
            $texto = $dias . ' dias';
        }

        return ['dias' => $dias, 'cor' => $cor, 'texto' => $texto];
    }
}
