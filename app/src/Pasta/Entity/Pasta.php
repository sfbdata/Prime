<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Cliente\Entity\Cliente;
use App\Expediente\Entity\Marcador;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
use App\Entity\Tarefa\Tarefa;
use App\Pasta\Repository\PastaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PastaRepository::class)]
#[ORM\Table(name: 'pasta')]
#[ORM\Index(name: 'idx_pasta_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_pasta_tenant_nup', columns: ['tenant_id', 'nup'])]
#[ORM\Index(name: 'idx_pasta_cliente_principal', columns: ['cliente_principal_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pasta_drive_folder_id', columns: ['drive_folder_id'])]
#[ORM\HasLifecycleCallbacks]
class Pasta implements Auditavel, TenantAware
{
    public const SITUACAO_ATIVA     = 'ativo';
    public const SITUACAO_ARQUIVADA = 'arquivado';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    // NUP NÃO é mais único: pode repetir (o acervo do Drive tem NUPs repetidos).
    // Identidade de sincronização é o driveFolderId; o índice (tenant_id, nup) é só para busca.
    #[ORM\Column(length: 255)]
    private ?string $nup = null;

    #[ORM\Column(name: 'drive_folder_id', length: 255, nullable: true)]
    private ?string $driveFolderId = null;

    #[ORM\Column(name: 'drive_synced_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $driveSyncedAt = null;

    #[Assert\Choice(choices: [self::SITUACAO_ATIVA, self::SITUACAO_ARQUIVADA])]
    #[ORM\Column(length: 20, options: ['default' => self::SITUACAO_ATIVA])]
    private string $situacao = self::SITUACAO_ATIVA;

    #[ORM\Column(enumType: PrioridadePasta::class, options: ['default' => 'normal'])]
    private PrioridadePasta $prioridade = PrioridadePasta::Normal;

    #[Assert\NotNull]
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dataAbertura;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomeCliente = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomeAcao = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $modificadoEm = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $criadoPor = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $responsavel = null;

    /** @var Collection<int, PastaProcesso> */
    #[ORM\OneToMany(mappedBy: 'pasta', targetEntity: PastaProcesso::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['principal' => 'DESC', 'vinculadoEm' => 'ASC', 'id' => 'ASC'])]
    private Collection $pastaProcessos;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToMany(targetEntity: Cliente::class)]
    #[ORM\JoinTable(name: 'pasta_cliente')]
    private Collection $clientes;

    /**
     * O cliente que representa a pasta nos indicadores — hoje, a "Média por CPF" da aba
     * Financeiro.
     *
     * É uma coluna na própria pasta, e não uma flag na tabela de vínculo (como em
     * PastaProcesso), por um motivo que vale registrar: aqui a unicidade sai de graça e é
     * garantida pelo BANCO. Uma coluna só aponta para um cliente. O precedente dos processos
     * mantém o invariante em memória e não tem trava nenhuma no banco — este caminho é mais
     * forte, não mais fraco.
     *
     * Anulável porque pasta SEM cliente nenhum não tem principal, e porque a FK é
     * `ON DELETE SET NULL` — excluir o cliente do sistema zera a coluna pelo banco. Fora esses
     * dois casos, pasta com cliente sempre tem principal gravado: quem garante é `addCliente()`
     * (grava no primeiro vínculo) e `removeCliente()` (promove outro se o principal sair).
     */
    #[ORM\ManyToOne(targetEntity: Cliente::class)]
    #[ORM\JoinColumn(name: 'cliente_principal_id', nullable: true, onDelete: 'SET NULL')]
    private ?Cliente $clientePrincipal = null;

#[ORM\OneToMany(mappedBy: 'pasta', targetEntity: PastaDocumento::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordem' => 'ASC'])]
    private Collection $documentos;

    #[ORM\OneToMany(mappedBy: 'pasta', targetEntity: Tarefa::class)]
    private Collection $tarefas;

    #[ORM\ManyToMany(targetEntity: Marcador::class)]
    #[ORM\JoinTable(name: 'pasta_marcador')]
    private Collection $marcadores;

    #[ORM\Column(length: 20, options: ['default' => 'PENDENTE'])]
    private string $situacaoContrato = 'PENDENTE';

    #[ORM\Column(options: ['default' => false])]
    private bool $proBono = false;

    /**
     * Valor da causa, em reais. Nulo significa "ninguém preencheu" — que é
     * diferente de R$ 0,00 (causa sem valor econômico). A tela e a média por CPF
     * dependem dessa distinção: nulo fica de fora da média, zero entra nela.
     *
     * Fica em string porque o Doctrine mapeia decimal assim: dinheiro não passa
     * por float em lugar nenhum do caminho.
     */
    #[ORM\Column(name: 'valor_causa', type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $valorCausa = null;

    #[ORM\OneToMany(mappedBy: 'pasta', targetEntity: PastaObservacaoFinanceira::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['criadaEm' => 'ASC'])]
    private Collection $observacoesFinanceiras;

    #[ORM\OneToMany(mappedBy: 'pasta', targetEntity: PastaObservacaoDetalhes::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['criadaEm' => 'ASC'])]
    private Collection $observacoesDetalhes;

    #[ORM\OneToMany(mappedBy: 'pasta', targetEntity: PastaChecklistItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordem' => 'ASC'])]
    private Collection $checklistItens;

    #[ORM\OneToMany(mappedBy: 'pasta', targetEntity: PastaSecao::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordem' => 'ASC'])]
    private Collection $secoes;

    #[ORM\OneToMany(mappedBy: 'pasta', targetEntity: PastaMensagem::class)]
    private Collection $mensagens;

    public function __construct()
    {
        $this->dataAbertura = new \DateTimeImmutable();
        $this->criadoEm = new \DateTimeImmutable();
        $this->modificadoEm = new \DateTimeImmutable();
        $this->clientes = new ArrayCollection();
        $this->pastaProcessos = new ArrayCollection();
$this->documentos = new ArrayCollection();
        $this->tarefas = new ArrayCollection();
        $this->marcadores = new ArrayCollection();
        $this->observacoesFinanceiras = new ArrayCollection();
        $this->observacoesDetalhes = new ArrayCollection();
        $this->checklistItens = new ArrayCollection();
        $this->secoes = new ArrayCollection();
        $this->mensagens = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNup(): ?string
    {
        return $this->nup;
    }

    public function setNup(string $nup): self
    {
        $this->nup = mb_strtoupper(trim($nup));
        return $this;
    }

    public function getSituacao(): string
    {
        return $this->situacao;
    }

    public function setSituacao(string $situacao): self
    {
        $this->situacao = $situacao;

        return $this;
    }

    public function getPrioridade(): PrioridadePasta
    {
        return $this->prioridade;
    }

    public function setPrioridade(PrioridadePasta $prioridade): self
    {
        $this->prioridade = $prioridade;

        return $this;
    }

    public function getPrioridadeBadgeClass(): string
    {
        return match ($this->prioridade) {
            PrioridadePasta::Normal     => 'text-bg-secondary',
            PrioridadePasta::Prioridade => 'text-bg-warning',
            PrioridadePasta::Urgente    => 'text-bg-danger',
        };
    }

    public function getPrioridadeLabel(): string
    {
        return $this->prioridade->label();
    }

    public function getDataAbertura(): \DateTimeImmutable
    {
        return $this->dataAbertura;
    }

    public function setDataAbertura(\DateTimeImmutable $dataAbertura): self
    {
        $this->dataAbertura = $dataAbertura;
        return $this;
    }

    public function getNomeCliente(): ?string
    {
        return $this->nomeCliente;
    }

    public function setNomeCliente(?string $nomeCliente): self
    {
        $this->nomeCliente = $nomeCliente !== null ? mb_strtoupper(trim($nomeCliente)) : null;
        return $this;
    }

    public function getNomeAcao(): ?string
    {
        return $this->nomeAcao;
    }

    public function setNomeAcao(?string $nomeAcao): self
    {
        $this->nomeAcao = $nomeAcao !== null ? mb_strtoupper(trim($nomeAcao)) : null;
        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    #[ORM\PreUpdate]
    public function setModificadoEmValue(): void
    {
        $this->modificadoEm = new \DateTimeImmutable();
    }

    public function getModificadoEm(): ?\DateTimeImmutable
    {
        return $this->modificadoEm;
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

    public function getResponsavel(): ?User
    {
        return $this->responsavel;
    }

    public function setResponsavel(?User $responsavel): self
    {
        $this->responsavel = $responsavel;
        return $this;
    }

    /**
     * @return Collection<int, PastaProcesso>
     */
    public function getPastaProcessos(): Collection
    {
        return $this->pastaProcessos;
    }

    /**
     * @return list<Processo>
     */
    public function getProcessos(): array
    {
        return array_map(
            static fn (PastaProcesso $vinculo): Processo => $vinculo->getProcesso(),
            $this->pastaProcessos->toArray(),
        );
    }

    public function getVinculoPrincipal(): ?PastaProcesso
    {
        foreach ($this->pastaProcessos as $vinculo) {
            if ($vinculo->isPrincipal()) {
                return $vinculo;
            }
        }

        return $this->pastaProcessos->first() ?: null;
    }

    /**
     * Processo "principal" da pasta — representante nos resumos (barra lateral,
     * listagem, timeline, peticionar). Substitui o antigo getProcesso().
     */
    public function getProcessoPrincipal(): ?Processo
    {
        return $this->getVinculoPrincipal()?->getProcesso();
    }

    public function temProcesso(Processo $processo): bool
    {
        return $this->getVinculoPorProcesso($processo) !== null;
    }

    public function getVinculoPorProcesso(Processo $processo): ?PastaProcesso
    {
        foreach ($this->pastaProcessos as $vinculo) {
            if ($this->mesmoProcesso($vinculo->getProcesso(), $processo)) {
                return $vinculo;
            }
        }

        return null;
    }

    /**
     * Vincula um processo à pasta. No-op (retorna o vínculo existente) se já vinculado.
     * O primeiro processo vinculado vira automaticamente o principal.
     */
    public function vincularProcesso(Processo $processo, ?User $vinculadoPor = null): PastaProcesso
    {
        $existente = $this->getVinculoPorProcesso($processo);
        if ($existente !== null) {
            return $existente;
        }

        $vinculo = new PastaProcesso($this, $processo, $vinculadoPor);
        if ($this->pastaProcessos->isEmpty()) {
            $vinculo->setPrincipal(true);
        }
        $this->pastaProcessos->add($vinculo);

        return $vinculo;
    }

    /**
     * Desvincula um processo. Se ele era o principal e ainda restam vínculos,
     * promove o primeiro remanescente a principal.
     */
    public function desvincularProcesso(Processo $processo): void
    {
        $vinculo = $this->getVinculoPorProcesso($processo);
        if ($vinculo === null) {
            return;
        }

        $eraPrincipal = $vinculo->isPrincipal();
        $this->pastaProcessos->removeElement($vinculo);

        if ($eraPrincipal) {
            $novoPrincipal = $this->pastaProcessos->first() ?: null;
            $novoPrincipal?->setPrincipal(true);
        }
    }

    /**
     * Define um processo já vinculado como principal, zerando o anterior.
     */
    public function definirProcessoPrincipal(Processo $processo): void
    {
        $alvo = $this->getVinculoPorProcesso($processo);
        if ($alvo === null) {
            throw new \DomainException('O processo não está vinculado a esta pasta.');
        }

        foreach ($this->pastaProcessos as $vinculo) {
            $vinculo->setPrincipal($vinculo === $alvo);
        }
    }

    private function mesmoProcesso(Processo $a, Processo $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return $a->getId() !== null && $a->getId() === $b->getId();
    }

    /**
     * @return Collection<int, Cliente>
     */
    public function getClientes(): Collection
    {
        return $this->clientes;
    }

    /**
     * Vincular o PRIMEIRO cliente já o grava como principal; do segundo em diante, nada muda.
     *
     * É aqui que mora a correção do defeito de origem. Antes, "quem é o principal" era
     * RECALCULADO a cada leitura pelo cliente de cadastro mais antigo — então vincular depois
     * alguém cadastrado há mais tempo trocava o número na tela, sem ninguém ter pedido. Agora a
     * resposta é GRAVADA uma vez: vincular mais dez clientes não mexe nela, porque só se grava
     * quando o campo está vazio.
     *
     * A guarda `=== null` é o "e depois nunca mais automático". Sem ela isto viraria outra forma
     * do mesmo defeito.
     */
    public function addCliente(Cliente $cliente): self
    {
        if (!$this->clientes->contains($cliente)) {
            $this->clientes->add($cliente);
        }

        if ($this->clientePrincipal === null) {
            $this->clientePrincipal = $cliente;
        }

        return $this;
    }

    /**
     * Quem manda nos indicadores da pasta (a "Média por CPF" da aba Financeiro).
     *
     * INVARIANTE DA CASA: pasta com cliente NUNCA fica sem principal. Ele é o primeiro cliente
     * vinculado, ou quem o dono marcou depois pela estrela — nunca um critério recalculado a cada
     * leitura, que era o que fazia o número trocar sozinho.
     *
     * O caminho normal é a primeira linha: `addCliente()` grava na hora do primeiro vínculo e
     * `removeCliente()` promove outro se o principal sair. Sobram DOIS caminhos que ainda deixam a
     * coluna nula com clientes na pasta, e é só por eles que o fallback existe:
     *
     * - o cliente marcado ser EXCLUÍDO do sistema — a FK é `ON DELETE SET NULL`, então o banco
     *   zera a coluna sozinho, sem passar por método nenhum daqui;
     * - pasta gravada antes desta regra por um caminho que não use `addCliente()`.
     *
     * Nos dois, o fallback determinístico segura o invariante em vez de a tela ficar sem média.
     */
    public function getClientePrincipal(): ?Cliente
    {
        if ($this->clientePrincipal !== null && $this->clientes->contains($this->clientePrincipal)) {
            return $this->clientePrincipal;
        }

        return $this->clienteMaisAntigo();
    }

    /**
     * Marca o cliente principal. Ele precisa já estar vinculado à pasta — marcar alguém de fora
     * seria mostrar, num indicador, a média de quem não é parte deste caso.
     *
     * Regra na ENTIDADE e não no UseCase, como em definirProcessoPrincipal(): assim ela vale por
     * qualquer porta que grave a pasta, não só pela que passa pelo UseCase.
     */
    public function definirClientePrincipal(Cliente $cliente): void
    {
        if (!$this->clientes->contains($cliente)) {
            throw new \DomainException('O cliente não está vinculado a esta pasta.');
        }

        $this->clientePrincipal = $cliente;
    }

    /**
     * O critério automático: o cliente de cadastro mais antigo do escritório.
     *
     * Privado de propósito. Era público (`getPrimeiroCliente`) e virou a única resposta para
     * "de quem é a média" — o que deixava um ato sem relação com dinheiro (vincular um cliente
     * mais antigo) TROCAR o número na tela. Agora é só o que vale quando ninguém marcou nada.
     *
     * `pasta_cliente` não tem coluna de data, então não dá para saber qual vínculo veio primeiro;
     * o que se ordena aqui é o id do cliente. Determinístico, mas não estável — e é exatamente
     * por não ser estável que existe a marcação explícita.
     */
    private function clienteMaisAntigo(): ?Cliente
    {
        $clientes = $this->clientes->toArray();

        if ($clientes === []) {
            return null;
        }

        usort($clientes, static fn (Cliente $a, Cliente $b): int => $a->getId() <=> $b->getId());

        return $clientes[0];
    }

    /**
     * Desvincular o principal PROMOVE outro, em vez de só apagar a marcação.
     *
     * Apagar deixaria a pasta com clientes e sem principal — o estado que o invariante proíbe, e
     * que faria a média sumir da tela sem ninguém ter pedido. Promover mantém um número lá; ele
     * fica gravado, então não muda mais sozinho, e o dono troca pela estrela se não gostou.
     *
     * Mesma ideia do "promover o próximo" que `desvincularProcesso()` já faz. Qual promover é
     * decisão determinística de UM momento (o de menor id entre os que sobraram), não um critério
     * recalculado a cada leitura — é essa diferença que separa isto do defeito de origem.
     */
    public function removeCliente(Cliente $cliente): self
    {
        $this->clientes->removeElement($cliente);

        if ($this->clientePrincipal !== null && $this->clientePrincipal === $cliente) {
            $this->clientePrincipal = $this->clienteMaisAntigo();
        }

        return $this;
    }

/**
     * @return Collection<int, PastaDocumento>
     */
    public function getDocumentos(): Collection
    {
        return $this->documentos;
    }

    /**
     * @return Collection<int, Tarefa>
     */
    public function getTarefas(): Collection
    {
        return $this->tarefas;
    }

    public function addDocumento(PastaDocumento $documento): self
    {
        if (!$this->documentos->contains($documento)) {
            $this->documentos->add($documento);
            $documento->setPasta($this);
        }
        return $this;
    }

    public function removeDocumento(PastaDocumento $documento): self
    {
        if ($this->documentos->removeElement($documento)) {
            if ($documento->getPasta() === $this) {
                $documento->setPasta(null);
            }
        }
        return $this;
    }

    /** @return Collection<int, Marcador> */
    public function getMarcadores(): Collection
    {
        return $this->marcadores;
    }

    public function addMarcador(Marcador $marcador): self
    {
        foreach ($this->marcadores as $existente) {
            if ($existente->getId() === $marcador->getId()) {
                return $this;
            }
        }
        $this->marcadores->add($marcador);
        return $this;
    }

    public function removeMarcador(Marcador $marcador): self
    {
        foreach ($this->marcadores as $existente) {
            if ($existente->getId() === $marcador->getId()) {
                $this->marcadores->removeElement($existente);
                return $this;
            }
        }
        return $this;
    }

    public function hasMarcador(Marcador $marcador): bool
    {
        foreach ($this->marcadores as $existente) {
            if ($existente->getId() === $marcador->getId()) {
                return true;
            }
        }

        return false;
    }

    public function getSituacaoContrato(): string
    {
        return $this->situacaoContrato;
    }

    public function setSituacaoContrato(string $situacao): self
    {
        $this->situacaoContrato = $situacao;

        return $this;
    }

    public function isProBono(): bool
    {
        return $this->proBono;
    }

    public function setProBono(bool $proBono): self
    {
        $this->proBono = $proBono;

        return $this;
    }

    public function getValorCausa(): ?string
    {
        return $this->valorCausa;
    }

    public function setValorCausa(?string $valorCausa): self
    {
        $this->valorCausa = $valorCausa;

        return $this;
    }

    /** @return Collection<int, PastaObservacaoFinanceira> */
    public function getObservacoesFinanceiras(): Collection
    {
        return $this->observacoesFinanceiras;
    }

    /** @return Collection<int, PastaObservacaoDetalhes> */
    public function getObservacoesDetalhes(): Collection
    {
        return $this->observacoesDetalhes;
    }

    /** @return Collection<int, PastaChecklistItem> */
    public function getChecklistItens(): Collection
    {
        return $this->checklistItens;
    }

    /** @return Collection<int, PastaSecao> */
    public function getSecoes(): Collection
    {
        return $this->secoes;
    }

    /** @return Collection<int, PastaMensagem> */
    public function getMensagens(): Collection
    {
        return $this->mensagens;
    }

    public function addSecao(PastaSecao $secao): self
    {
        if (!$this->secoes->contains($secao)) {
            $this->secoes->add($secao);
            $secao->setPasta($this);
        }

        return $this;
    }

    public function removeSecao(PastaSecao $secao): self
    {
        if ($this->secoes->removeElement($secao)) {
            if ($secao->getPasta() === $this) {
                $secao->setPasta(null);
            }
        }

        return $this;
    }

    public function getDriveFolderId(): ?string
    {
        return $this->driveFolderId;
    }

    public function setDriveFolderId(?string $driveFolderId): self
    {
        $this->driveFolderId = $driveFolderId;

        return $this;
    }

    public function getDriveSyncedAt(): ?\DateTimeImmutable
    {
        return $this->driveSyncedAt;
    }

    public function setDriveSyncedAt(?\DateTimeImmutable $driveSyncedAt): self
    {
        $this->driveSyncedAt = $driveSyncedAt;

        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }
}
