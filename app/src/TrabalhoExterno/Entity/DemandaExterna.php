<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\DTO\DadosDaSolicitacao;
use App\TrabalhoExterno\Enum\AcaoDemanda;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Enum\TipoMensagem;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma demanda de trabalho externo: o pedido de um escritório (Solicitante) para a Central executar
 * um serviço em outra localidade.
 *
 * **Não implementa `TenantAware` de propósito.** É a única entidade do sistema lida entre
 * escritórios: o Master e a Equipe veem as demandas de todos os escritórios. O `TenantFilter` a
 * esconderia deles. O isolamento é feito pelo `DemandaExternaRepository`, que só tem consultas
 * escopadas, e pela `PoliticaDaDemanda` (spec `docs/specs/trabalho-externo.md` §3). A coluna
 * continua se chamando `tenant_id` para a purga de escritório e o `PurgaCoberturaSchemaTest`
 * a enxergarem.
 */
#[ORM\Entity(repositoryClass: DemandaExternaRepository::class)]
#[ORM\Table(name: 'trabalho_externo_demanda')]
#[ORM\Index(name: 'idx_te_demanda_tenant_situacao', columns: ['tenant_id', 'situacao'])]
#[ORM\HasLifecycleCallbacks]
class DemandaExterna
{
    /** Checklist fixo do desenho (`Component.CHECK`). */
    public const CHECKLIST = [
        'Conferir documentos e instruções',
        'Executar a atividade solicitada',
        'Anexar comprovantes da diligência',
        'Registrar relatório de execução',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(enumType: Situacao::class, length: 20)]
    private Situacao $situacao = Situacao::Rascunho;

    #[ORM\Column(length: 255)]
    private string $escritorio = '';

    #[ORM\Column(length: 180)]
    private string $email = '';

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telefone = null;

    #[ORM\Column(length: 2)]
    private string $ufOrigem = 'DF';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $cidadeOrigem = null;

    #[ORM\Column]
    private bool $emNomeDeOutro = false;

    #[ORM\Column(length: 255)]
    private string $advogado = '';

    #[ORM\Column(length: 20)]
    private string $oab = '';

    #[ORM\Column(length: 2)]
    private string $oabUf = 'DF';

    #[ORM\Column(length: 2)]
    private string $ufExecucao = 'DF';

    #[ORM\Column(length: 120)]
    private string $cidadeExecucao = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $orgao = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $processo = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $partes = null;

    #[ORM\Column(length: 120)]
    private string $tipoServico = '';

    #[ORM\Column(length: 10)]
    private string $prioridade = 'Normal';

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $prazoFinal = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataPretendida = null;

    #[ORM\Column(type: 'text')]
    private string $descricao = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $objetivo = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $instrucoes = null;

    #[ORM\Column]
    private bool $urgente = false;

    /** Quem executa: o profissional convidado ativo (F3). Vazio = "A definir". */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $responsavel = null;

    /** @var list<array{t: string, ok: bool}> */
    #[ORM\Column(type: 'json')]
    private array $checklist = [];

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $pagoEm = null;

    /** Momento do ENVIO (o rascunho ainda não foi enviado). É o que o filtro de período usa. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $enviadaEm = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    /** @var Collection<int, HistoricoDemanda> */
    #[ORM\OneToMany(targetEntity: HistoricoDemanda::class, mappedBy: 'demanda', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['em' => 'ASC', 'id' => 'ASC'])]
    private Collection $historico;

    /** @var Collection<int, MensagemDemanda> */
    #[ORM\OneToMany(targetEntity: MensagemDemanda::class, mappedBy: 'demanda', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['em' => 'ASC', 'id' => 'ASC'])]
    private Collection $mensagens;

    /** @var Collection<int, PropostaDemanda> */
    #[ORM\OneToMany(targetEntity: PropostaDemanda::class, mappedBy: 'demanda', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['versao' => 'ASC'])]
    private Collection $propostas;

    /** @var Collection<int, DocumentoDemanda> */
    #[ORM\OneToMany(targetEntity: DocumentoDemanda::class, mappedBy: 'demanda', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['em' => 'ASC', 'id' => 'ASC'])]
    private Collection $documentos;

    public function __construct(
        /** Escritório solicitante, dono da demanda. */
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $criadoPor,
    ) {
        $this->criadoEm = new \DateTimeImmutable();
        $this->checklist = array_map(static fn (string $t): array => ['t' => $t, 'ok' => false], self::CHECKLIST);
        $this->historico = new ArrayCollection();
        $this->mensagens = new ArrayCollection();
        $this->propostas = new ArrayCollection();
        $this->documentos = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    /** Código exibido ("TE-0001"). Deriva do id: não há segunda sequência para divergir. */
    public function getCodigo(): string
    {
        return 'TE-' . str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    /** O inverso de {@see getCodigo()}; null se o texto não tem a forma de um código. */
    public static function idDoCodigo(string $codigo): ?int
    {
        if (preg_match('/^TE-(\d{1,9})$/', $codigo, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /** Grava o conteúdo do formulário. Só enquanto for rascunho (depois do envio, o pedido é o que foi enviado). */
    public function preencher(DadosDaSolicitacao $d): void
    {
        if ($this->situacao !== Situacao::Rascunho) {
            throw new TransicaoInvalida('Só o rascunho pode ser editado.');
        }

        $this->escritorio = $d->escritorio;
        $this->email = $d->email;
        $this->telefone = $d->telefone !== '' ? $d->telefone : null;
        $this->ufOrigem = $d->ufOrigem;
        $this->cidadeOrigem = $d->cidadeOrigem !== '' ? $d->cidadeOrigem : null;
        $this->emNomeDeOutro = $d->emNomeDeOutro;
        $this->advogado = $d->advogado;
        $this->oab = $d->oab;
        $this->oabUf = $d->oabUf;
        $this->ufExecucao = $d->ufExecucao;
        $this->cidadeExecucao = $d->cidadeExecucao;
        $this->orgao = $d->orgao !== '' ? $d->orgao : null;
        $this->processo = $d->processo !== '' ? $d->processo : null;
        $this->partes = $d->partes !== '' ? $d->partes : null;
        $this->tipoServico = $d->tipoServico;
        $this->prioridade = $d->prioridade;
        $this->prazoFinal = $d->prazoFinal;
        $this->dataPretendida = $d->dataPretendida;
        $this->descricao = $d->descricao;
        $this->objetivo = $d->objetivo !== '' ? $d->objetivo : null;
        $this->instrucoes = $d->instrucoes !== '' ? $d->instrucoes : null;
        $this->urgente = $d->urgente;
    }

    /** Rascunho → nova. A validação dos obrigatórios é do UseCase (grupo `envio` do DTO). */
    public function enviar(Autor $autor): void
    {
        if ($this->situacao !== Situacao::Rascunho) {
            throw new TransicaoInvalida('Esta solicitação já foi enviada.');
        }

        $this->mudarPara(Situacao::Nova, $autor, null);
        $this->enviadaEm = new \DateTimeImmutable();
    }

    /**
     * Aplica uma ação de fluxo. Confere só a REGRA DE ESTADO (de onde a ação parte); quem pode
     * executá-la é decidido antes, pela `PoliticaDaDemanda`. Ações confirmadas deixam o registro no
     * chat, como o `aplicarConf` do desenho; as de clique direto, só o histórico.
     */
    public function aplicar(AcaoDemanda $acao, Autor $autor, ?string $motivo): void
    {
        $motivo = $motivo !== null ? trim($motivo) : null;
        $motivo = $motivo === '' ? null : $motivo;

        if (!in_array($this->situacao, $acao->origens(), true)) {
            throw new TransicaoInvalida(sprintf('"%s" não se aplica a uma demanda %s.', $acao->rotulo($autor->perfil), mb_strtolower($this->situacao->rotulo())));
        }
        if ($acao->exigeMotivo() && $motivo === null) {
            throw new TransicaoInvalida('Descreva o motivo para continuar.');
        }
        if ($acao === AcaoDemanda::ExcluirRascunho) {
            throw new TransicaoInvalida('O rascunho é excluído pelo repositório, não por transição.');
        }

        $proposta = $this->propostaVigente();
        match ($acao) {
            AcaoDemanda::AceitarProposta => $proposta?->aceitar($autor),
            AcaoDemanda::SolicitarAjustes => $proposta?->pedirAjustes(),
            AcaoDemanda::RecusarProposta => $proposta?->recusar(),
            default => null,
        };

        if ($acao === AcaoDemanda::RegistrarPagamento) {
            if ($this->pagoEm !== null) {
                throw new TransicaoInvalida('O pagamento desta demanda já foi registrado.');
            }
            $this->pagoEm = new \DateTimeImmutable();
            $this->historico->add(new HistoricoDemanda($this, null, Situacao::MARCO_PAGO, $autor, null));
        }

        $destino = $acao->destino();
        if ($destino !== null) {
            $this->mudarPara($destino, $autor, $motivo);
        }

        if ($acao->confirma()) {
            $this->registrar($autor, $acao->registro() . ($motivo !== null ? ': ' . $motivo : '.'), TipoMensagem::Sistema);
        }
    }

    /** Nova versão da proposta: as anteriores ainda em aberto viram "substituída". */
    public function enviarProposta(PropostaDemanda $proposta, Autor $autor): void
    {
        if (!in_array($this->situacao, [Situacao::Analise, Situacao::Proposta], true)) {
            throw new TransicaoInvalida('A proposta só pode ser enviada com a demanda em análise ou aguardando aceite.');
        }

        foreach ($this->propostas as $anterior) {
            $anterior->substituirSeEmAberto();
        }
        $this->propostas->add($proposta);

        if ($this->situacao !== Situacao::Proposta) {
            $this->mudarPara(Situacao::Proposta, $autor, null);
        }
        $this->registrar($autor, sprintf('Proposta v%d enviada: %s.', $proposta->getVersao(), $proposta->valorFormatado()), TipoMensagem::Sistema);
    }

    public function proximaVersaoDeProposta(): int
    {
        return $this->propostas->count() + 1;
    }

    public function propostaVigente(): ?PropostaDemanda
    {
        $ultima = $this->propostas->last();

        return $ultima instanceof PropostaDemanda ? $ultima : null;
    }

    /** Mensagem do chat (comum, nota interna, andamento, arquivo ou registro). */
    public function registrar(Autor $autor, string $texto, TipoMensagem $tipo): MensagemDemanda
    {
        $mensagem = new MensagemDemanda($this, $autor, $tipo, $texto);
        $this->mensagens->add($mensagem);

        return $mensagem;
    }

    /** Liga/desliga um item do checklist e deixa o andamento no chat ("Etapa concluída: …"). */
    public function alternarItemDoChecklist(int $indice, Autor $autor): void
    {
        if ($this->situacao !== Situacao::Execucao) {
            throw new TransicaoInvalida('O checklist só pode ser alterado durante a execução.');
        }
        if (!isset($this->checklist[$indice])) {
            throw new TransicaoInvalida('Item do checklist inexistente.');
        }

        $item = $this->checklist[$indice];
        $item['ok'] = !$item['ok'];
        $this->checklist[$indice] = $item;
        $this->registrar($autor, ($item['ok'] ? 'Etapa concluída: ' : 'Etapa reaberta: ') . $item['t'], TipoMensagem::Andamento);
    }

    public function anexar(DocumentoDemanda $documento): void
    {
        $this->documentos->add($documento);
    }

    private function mudarPara(Situacao $para, Autor $autor, ?string $motivo): void
    {
        $this->historico->add(new HistoricoDemanda($this, $this->situacao->value, $para->value, $autor, $motivo));
        $this->situacao = $para;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function getSituacao(): Situacao
    {
        return $this->situacao;
    }

    public function getEscritorio(): string
    {
        return $this->escritorio;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getTelefone(): ?string
    {
        return $this->telefone;
    }

    public function getUfOrigem(): string
    {
        return $this->ufOrigem;
    }

    public function getCidadeOrigem(): ?string
    {
        return $this->cidadeOrigem;
    }

    public function isEmNomeDeOutro(): bool
    {
        return $this->emNomeDeOutro;
    }

    public function getAdvogado(): string
    {
        return $this->advogado;
    }

    public function getOab(): string
    {
        return $this->oab;
    }

    public function getOabUf(): string
    {
        return $this->oabUf;
    }

    public function getUfExecucao(): string
    {
        return $this->ufExecucao;
    }

    public function getCidadeExecucao(): string
    {
        return $this->cidadeExecucao;
    }

    public function getOrgao(): ?string
    {
        return $this->orgao;
    }

    public function getProcesso(): ?string
    {
        return $this->processo;
    }

    public function getPartes(): ?string
    {
        return $this->partes;
    }

    public function getTipoServico(): string
    {
        return $this->tipoServico;
    }

    public function getPrioridade(): string
    {
        return $this->prioridade;
    }

    public function getPrazoFinal(): ?\DateTimeImmutable
    {
        return $this->prazoFinal;
    }

    public function getDataPretendida(): ?\DateTimeImmutable
    {
        return $this->dataPretendida;
    }

    public function getDescricao(): string
    {
        return $this->descricao;
    }

    public function getObjetivo(): ?string
    {
        return $this->objetivo;
    }

    public function getInstrucoes(): ?string
    {
        return $this->instrucoes;
    }

    public function isUrgente(): bool
    {
        return $this->urgente;
    }

    public function getResponsavel(): ?string
    {
        return $this->responsavel;
    }

    public function definirResponsavel(?string $nome): void
    {
        $this->responsavel = $nome !== null && trim($nome) !== '' ? trim($nome) : null;
    }

    /** @return list<array{t: string, ok: bool}> */
    public function getChecklist(): array
    {
        return $this->checklist;
    }

    public function getPagoEm(): ?\DateTimeImmutable
    {
        return $this->pagoEm;
    }

    public function getEnviadaEm(): ?\DateTimeImmutable
    {
        return $this->enviadaEm;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    /** @return list<HistoricoDemanda> */
    public function getHistorico(): array
    {
        return array_values($this->historico->toArray());
    }

    /** @return list<MensagemDemanda> */
    public function getMensagens(): array
    {
        return array_values($this->mensagens->toArray());
    }

    /** @return list<PropostaDemanda> */
    public function getPropostas(): array
    {
        return array_values($this->propostas->toArray());
    }

    /** @return list<DocumentoDemanda> */
    public function getDocumentos(): array
    {
        return array_values($this->documentos->toArray());
    }
}
