<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\DTO\DadosDaSolicitacao;
use App\TrabalhoExterno\Enum\AcaoDemanda;
use App\TrabalhoExterno\Enum\FaseWhatsapp;
use App\TrabalhoExterno\Enum\NaturezaDoDespacho;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Enum\SituacaoConvite;
use App\TrabalhoExterno\Enum\SituacaoProposta;
use App\TrabalhoExterno\Enum\TipoDeDespacho;
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

    /**
     * Valor do pagamento ajustado pelo Master (aba Pagamento) enquanto não pago e sem comprovante. A proposta aceita
     * não muda; o valor efetivo é este, se houver, senão o da proposta ({@see valorEfetivoDoPagamento()}). Trocar a
     * proposta aceita zera o ajuste.
     */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $valorPagamentoAjustado = null;

    /** "Aceitar resposta" do DESPACHO (desenho 06 de 24/10, `dpResp`, l.1392): só o Master liga e desliga. */
    #[ORM\Column(options: ['default' => false])]
    private bool $respostasAoDespachoLiberadas = false;

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

    /** @var Collection<int, ConviteDemanda> */
    #[ORM\OneToMany(targetEntity: ConviteDemanda::class, mappedBy: 'demanda', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $convites;

    /** @var Collection<int, DespachoDemanda> */
    #[ORM\OneToMany(targetEntity: DespachoDemanda::class, mappedBy: 'demanda', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $despachos;

    /** @var Collection<int, ContatoWhatsapp> */
    #[ORM\OneToMany(targetEntity: ContatoWhatsapp::class, mappedBy: 'demanda', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $contatosWhatsapp;

    /** @var Collection<int, AvisoWhatsapp> */
    #[ORM\OneToMany(targetEntity: AvisoWhatsapp::class, mappedBy: 'demanda', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $avisosWhatsapp;

    /**
     * O que aconteceu NESTA requisição e avisa as partes (desenho 06 de 24/10, `mudar` e `msgSis`): cada
     * mudança de situação ("Andamento: {situação}{ · motivo}") e cada mensagem de sistema, arquivo ou
     * andamento, na ordem, com a mensagem quando há (o convidado só é avisado do que leria no chat). Não é
     * coluna: o caso de uso entrega ao `AvisosDoTrabalhoExterno`, que retira.
     *
     * @var list<array{texto: string, mensagem: ?MensagemDemanda}>
     */
    private array $andamentosParaAvisar = [];

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
        $this->convites = new ArrayCollection();
        $this->despachos = new ArrayCollection();
        $this->contatosWhatsapp = new ArrayCollection();
        $this->avisosWhatsapp = new ArrayCollection();
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
            AcaoDemanda::AceitarProposta => $this->aceitarProposta($proposta, $autor),
            AcaoDemanda::SolicitarAjustes => $proposta?->pedirAjustes(),
            AcaoDemanda::RecusarProposta => $proposta?->recusar(),
            default => null,
        };

        if ($acao === AcaoDemanda::RegistrarPagamento) {
            if ($this->pagoEm !== null) {
                throw new TransicaoInvalida('O pagamento desta demanda já foi registrado.');
            }
            $this->marcarComoPago($autor, null);
        }

        $destino = $acao->destino();
        if ($destino !== null) {
            $this->mudarPara($destino, $autor, $motivo);
        }

        if ($acao->confirma()) {
            $this->registrar($autor, $acao->registro() . ($motivo !== null ? ': ' . $motivo : '.'), TipoMensagem::Sistema);
        }
    }

    /** Aceitar uma proposta troca a base do pagamento: o ajuste do Master sobre a anterior deixa de valer. */
    private function aceitarProposta(?PropostaDemanda $proposta, Autor $autor): void
    {
        $proposta?->aceitar($autor);
        $this->valorPagamentoAjustado = null;
    }

    /** O recebimento: `pagoEm`, o marco `pago` no histórico (com o motivo, se houver) e o aviso de WhatsApp da fase Pago. */
    private function marcarComoPago(Autor $autor, ?string $motivo): void
    {
        $this->pagoEm = new \DateTimeImmutable();
        $this->historico->add(new HistoricoDemanda($this, null, Situacao::MARCO_PAGO, $autor, $motivo));
        $this->enfileirarAvisos(FaseWhatsapp::Pago);
    }

    /**
     * "Confirmar recebimento" da aba Pagamento (desenho 25/10, `pg.confirmar`): a mesma ação `RegistrarPagamento`
     * (mesmas origens, mesma recusa se já pago), com o histórico "PIX R$ x" e o registro do desenho no lugar do
     * "Pagamento registrado." genérico. `$valor` já vem formatado (R$ 1.500,00), da proposta aceita.
     */
    public function confirmarRecebimentoDoPix(Autor $autor, string $valor): void
    {
        $acao = AcaoDemanda::RegistrarPagamento;
        if (!in_array($this->situacao, $acao->origens(), true)) {
            throw new TransicaoInvalida(sprintf('"%s" não se aplica a uma demanda %s.', $acao->rotulo($autor->perfil), mb_strtolower($this->situacao->rotulo())));
        }
        if ($this->pagoEm !== null) {
            throw new TransicaoInvalida('O pagamento desta demanda já foi registrado.');
        }

        $this->marcarComoPago($autor, 'PIX ' . $valor);
        $this->registrar($autor, sprintf('✅ Pagamento de %s confirmado via PIX. A execução pode começar.', $valor), TipoMensagem::Pagamento);
    }

    /** A proposta aceita mais recente (`PA` do desenho): é dela que saem o valor e a forma do pagamento. Sem aceite, sem valor. */
    public function propostaAceita(): ?PropostaDemanda
    {
        $aceita = null;
        foreach ($this->propostas as $proposta) {
            if ($proposta->getSituacao() === SituacaoProposta::Aceita) {
                $aceita = $proposta;
            }
        }

        return $aceita;
    }

    /**
     * O valor a cobrar ("1500.00"): o ajustado pelo Master, se houver, senão o da proposta aceita. Nulo sem aceite ou
     * com valor zerado. É o ÚNICO ponto de leitura do valor do pagamento (tela, QR, txid, chat, histórico, confirmação).
     */
    public function valorEfetivoDoPagamento(): ?string
    {
        $valor = $this->valorPagamentoAjustado ?? $this->propostaAceita()?->getValor();

        return $valor !== null && (float) $valor > 0 ? $valor : null;
    }

    public function getValorPagamentoAjustado(): ?string
    {
        return $this->valorPagamentoAjustado;
    }

    /** O Master pode ajustar o valor: proposta aceita, nas situações do pagamento, sem pagar e sem comprovante enviado. */
    public function valorDoPagamentoEditavel(): bool
    {
        return $this->valorEfetivoDoPagamento() !== null
            && $this->pagoEm === null
            && $this->comprovante() === null
            && in_array($this->situacao, AcaoDemanda::RegistrarPagamento->origens(), true);
    }

    /**
     * Ajusta o valor do pagamento. `$novo` já vem validado e normalizado ("1800.00"); `$motivo` já aparado (10 a 300).
     * Recusas (409): fora das situações do pagamento, já pago, sem proposta aceita, comprovante enviado (o cliente pode
     * ter pago o valor velho) e valor igual ao efetivo. Grava o marco `valor` no histórico (antigo, novo e motivo) e a
     * mensagem de sistema do tipo Pagamento, sem o motivo.
     */
    public function ajustarValorDoPagamento(Autor $autor, string $novo, string $motivo): void
    {
        $acao = AcaoDemanda::RegistrarPagamento;
        if (!in_array($this->situacao, $acao->origens(), true)) {
            throw new TransicaoInvalida(sprintf('A demanda está %s: o valor do pagamento não pode mais ser alterado. Recarregue a tela.', mb_strtolower($this->situacao->rotulo())));
        }
        if ($this->pagoEm !== null) {
            throw new TransicaoInvalida('O pagamento desta demanda já foi registrado.');
        }
        $antigo = $this->valorEfetivoDoPagamento()
            ?? throw new TransicaoInvalida('O pagamento fica disponível quando uma proposta for aceita.');
        if ($this->comprovante() !== null) {
            throw new TransicaoInvalida('Já há comprovante enviado: o valor não pode mais ser alterado.');
        }
        if (round((float) $antigo * 100) === round((float) $novo * 100)) {
            throw new TransicaoInvalida('O novo valor é igual ao valor atual do pagamento.');
        }

        $de = PropostaDemanda::brl($antigo);
        $para = PropostaDemanda::brl($novo);
        $this->valorPagamentoAjustado = $novo;
        $this->historico->add(new HistoricoDemanda($this, null, Situacao::MARCO_VALOR, $autor, sprintf('De %s para %s. Motivo: %s', $de, $para, $motivo)));
        $this->registrar($autor, sprintf('Valor do pagamento alterado de %s para %s.', $de, $para), TipoMensagem::Pagamento);
    }

    /** O comprovante de pagamento enviado, se houver (no máximo um). */
    public function comprovante(): ?DocumentoDemanda
    {
        foreach ($this->documentos as $documento) {
            if ($documento->isComprovante()) {
                return $documento;
            }
        }

        return null;
    }

    /**
     * Guarda o comprovante do PIX (já gravado no storage) e deixa o aviso no chat (`pg.onComp`). Um só por demanda,
     * e só enquanto não pago ("aguardando"). Quem pode enviar é decidido antes, na política.
     */
    public function enviarComprovante(DocumentoDemanda $comprovante, Autor $autor): void
    {
        if ($this->pagoEm !== null) {
            throw new TransicaoInvalida('O pagamento desta demanda já foi confirmado.');
        }
        if ($this->comprovante() !== null) {
            throw new TransicaoInvalida('O comprovante desta demanda já foi enviado.');
        }

        $this->documentos->add($comprovante);
        $this->registrar($autor, sprintf('💳 Comprovante de pagamento PIX enviado (%s). Aguardando confirmação.', $comprovante->getNomeOriginal()), TipoMensagem::Pagamento);
    }

    /** O Master pode recusar o comprovante: enviado, sem pagar, nas situações do pagamento. */
    public function comprovanteRecusavel(): bool
    {
        return $this->comprovante() !== null
            && $this->pagoEm === null
            && in_array($this->situacao, AcaoDemanda::RegistrarPagamento->origens(), true);
    }

    /**
     * Recusa o comprovante: tira o documento da demanda (o registro sai no flush por orphanRemoval; o arquivo, o caso de
     * uso remove só depois do COMMIT), grava o marco `comprovante_recusado` (arquivo e motivo) e a mensagem de sistema
     * do tipo Pagamento, sem o motivo. O aviso de andamento leva o motivo. `$motivo` já vem aparado (10 a 300).
     * Recusas (409): fora das situações do pagamento, já pago ou sem comprovante.
     */
    public function recusarComprovante(Autor $autor, string $motivo): void
    {
        if (!in_array($this->situacao, AcaoDemanda::RegistrarPagamento->origens(), true)) {
            throw new TransicaoInvalida(sprintf('A demanda está %s: o comprovante não pode mais ser recusado. Recarregue a tela.', mb_strtolower($this->situacao->rotulo())));
        }
        if ($this->pagoEm !== null) {
            throw new TransicaoInvalida('O pagamento desta demanda já foi registrado.');
        }
        $comprovante = $this->comprovante()
            ?? throw new TransicaoInvalida('Esta demanda não tem comprovante para recusar.');

        $this->documentos->removeElement($comprovante);
        $this->historico->add(new HistoricoDemanda($this, null, Situacao::MARCO_COMPROVANTE_RECUSADO, $autor, sprintf('Arquivo: %s. Motivo: %s', $comprovante->getNomeOriginal(), $motivo)));
        $this->registrar($autor, 'Comprovante de pagamento recusado. Envie um novo comprovante na aba Pagamento.', TipoMensagem::Pagamento);
        $ultimo = array_key_last($this->andamentosParaAvisar);
        $this->andamentosParaAvisar[$ultimo]['texto'] = sprintf('Comprovante de pagamento recusado. Motivo: %s. Envie um novo comprovante na aba Pagamento.', $motivo);
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
        if (in_array($tipo, [TipoMensagem::Sistema, TipoMensagem::Pagamento, TipoMensagem::Arquivo, TipoMensagem::Andamento], true)) {
            $this->andamentosParaAvisar[] = ['texto' => $texto, 'mensagem' => $mensagem];
        }

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

    /**
     * Os andamentos ainda não avisados, e a lista volta a ficar vazia (um aviso por andamento, uma vez só).
     *
     * @return list<array{texto: string, mensagem: ?MensagemDemanda}>
     */
    public function retirarAndamentosParaAvisar(): array
    {
        $andamentos = $this->andamentosParaAvisar;
        $this->andamentosParaAvisar = [];

        return $andamentos;
    }

    public function anexar(DocumentoDemanda $documento): void
    {
        $this->documentos->add($documento);
    }

    /**
     * Publica um despacho da Central (`dpEnviar`, l.1394): o texto até 5.000, a natureza opcional, o prazo e o
     * lugar da assinatura (a cidade de execução, ou Brasília). O chat recebe "Novo despacho da Central
     * BlueJus{ · natureza}." como registro, que avisa as partes. Quem pode é decidido antes, na política.
     */
    public function despachar(Autor $autor, string $texto, ?NaturezaDoDespacho $natureza, \DateTimeImmutable $prazo): DespachoDemanda
    {
        $despacho = new DespachoDemanda($this, TipoDeDespacho::Despacho, $natureza, mb_substr(trim($texto), 0, DespachoDemanda::MAX_TEXTO), $prazo, $this->cidadeDaAssinatura(), $autor);
        $this->despachos->add($despacho);
        $this->registrar($autor, 'Novo despacho da Central BlueJus' . ($natureza !== null ? ' · ' . $natureza->rotulo() : '') . '.', TipoMensagem::Sistema);

        return $despacho;
    }

    /**
     * Resposta de uma parte (`dpResponder`, l.1392): só com "Aceitar resposta" ligado e pelo menos um despacho
     * publicado; o texto até 3.000. O chat recebe "Resposta ao despacho de {quem}.".
     */
    public function responderAoDespacho(Autor $autor, string $texto): DespachoDemanda
    {
        if (!$this->aceitaRespostaAoDespacho()) {
            throw new TransicaoInvalida('As respostas ao despacho não estão liberadas nesta demanda.');
        }
        $resposta = new DespachoDemanda($this, TipoDeDespacho::Resposta, null, mb_substr(trim($texto), 0, DespachoDemanda::MAX_RESPOSTA), null, $this->cidadeDaAssinatura(), $autor);
        $this->despachos->add($resposta);
        $this->registrar($autor, 'Resposta ao despacho de ' . $autor->nome . '.', TipoMensagem::Sistema);

        return $resposta;
    }

    public function liberarRespostasAoDespacho(bool $liberar): void
    {
        $this->respostasAoDespachoLiberadas = $liberar;
    }

    public function respostasAoDespachoLiberadas(): bool
    {
        return $this->respostasAoDespachoLiberadas;
    }

    /** `rOn && nD > 0` do desenho: a chave ligada e ao menos um despacho (resposta não conta). */
    public function aceitaRespostaAoDespacho(): bool
    {
        if (!$this->respostasAoDespachoLiberadas) {
            return false;
        }
        foreach ($this->despachos as $d) {
            if (!$d->ehResposta()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<DespachoDemanda> na ordem de publicação */
    public function getDespachos(): array
    {
        return array_values($this->despachos->toArray());
    }

    /** `((cur.form || {}).cidD) || 'Brasília'`: o lugar que assina o despacho. */
    private function cidadeDaAssinatura(): string
    {
        $cidade = trim($this->cidadeExecucao);

        return $cidade !== '' ? $cidade : 'Brasília';
    }

    private function mudarPara(Situacao $para, Autor $autor, ?string $motivo): void
    {
        $this->historico->add(new HistoricoDemanda($this, $this->situacao->value, $para->value, $autor, $motivo));
        $this->situacao = $para;
        $this->andamentosParaAvisar[] = ['texto' => 'Andamento: ' . $para->rotulo() . ($motivo !== null ? ' · ' . $motivo : ''), 'mensagem' => null];

        $fase = FaseWhatsapp::tryFrom($para->value);
        if ($fase !== null) {
            $this->enfileirarAvisos($fase);
        }
    }

    /**
     * Convida um profissional (spec §8). Um executor por vez, como no desenho: o convite que ainda
     * não foi revogado é revogado; o novo convidado passa a ser o responsável.
     */
    public function convidar(ConviteDemanda $convite, Autor $autor): void
    {
        if (!in_array($this->situacao, [Situacao::Aceita, Situacao::Execucao], true)) {
            throw new TransicaoInvalida('Só é possível convidar com a demanda aceita ou em execução.');
        }
        foreach ($this->convites as $anterior) {
            $anterior->revogar();
        }
        $this->convites->add($convite);
        $this->responsavel = $convite->getNome();
        // Sem o e-mail: o registro é visto por externos, e "convites nunca aparecem para solicitantes
        // ou convidados" (caixa "Sempre protegido" do desenho).
        $this->registrar($autor, sprintf('Convite enviado para %s.', $convite->getNome()), TipoMensagem::Sistema);
    }

    /** O convidado perde o acesso; o histórico e os arquivos dele ficam. */
    public function revogarConvite(ConviteDemanda $convite, Autor $autor): void
    {
        if ($convite->getSituacao() === SituacaoConvite::Revogado) {
            throw new TransicaoInvalida('Este acesso já foi revogado.');
        }
        $convite->revogar();
        if ($this->responsavel === $convite->getNome()) {
            $this->responsavel = null;
        }
        $this->registrar($autor, sprintf('Acesso de %s revogado.', $convite->getNome()), TipoMensagem::Sistema);
    }

    /**
     * Conecta um contato ao WhatsApp da demanda e já prepara a mensagem de boas-vindas
     * (`wa.adicionar` do desenho). O consentimento é pré-condição, conferida pelo UseCase.
     */
    public function conectarWhatsapp(string $nome, string $telefone, Autor $autor): ContatoWhatsapp
    {
        $contato = new ContatoWhatsapp($this, $nome, $telefone, $autor);
        $this->contatosWhatsapp->add($contato);
        $this->avisosWhatsapp->add(new AvisoWhatsapp(
            $this,
            null,
            $nome,
            $telefone,
            sprintf("BlueJus · %s\nOlá, %s! A partir de agora você recebe por aqui as atualizações de %s em %s/%s.", $this->getCodigo(), self::primeiroNome($nome), $this->tipoServico, $this->cidadeExecucao, $this->ufExecucao),
        ));

        return $contato;
    }

    public function desconectarWhatsapp(ContatoWhatsapp $contato): void
    {
        $this->contatosWhatsapp->removeElement($contato);
    }

    public function descartarAviso(AvisoWhatsapp $aviso): void
    {
        $this->avisosWhatsapp->removeElement($aviso);
    }

    /** "Avisar fase atual": refaz a mensagem da fase em que a demanda está, para os contatos ativos. */
    public function avisarFaseAtual(): void
    {
        $fase = $this->faseDeWhatsapp() ?? throw new TransicaoInvalida('A situação atual não tem aviso de fase.');

        foreach ($this->contatosWhatsapp as $contato) {
            if ($contato->isAtivo()) {
                $this->avisosWhatsapp->add($this->avisoDaFase($fase, $contato));
            }
        }
    }

    /** A fase que o WhatsApp anuncia agora (aceita e paga anuncia "Pagamento confirmado"). */
    public function faseDeWhatsapp(): ?FaseWhatsapp
    {
        if ($this->pagoEm !== null && $this->situacao === Situacao::Aceita) {
            return FaseWhatsapp::Pago;
        }

        return FaseWhatsapp::tryFrom($this->situacao->value);
    }

    private function enfileirarAvisos(FaseWhatsapp $fase): void
    {
        foreach ($this->contatosWhatsapp as $contato) {
            if ($contato->querAviso($fase)) {
                $this->avisosWhatsapp->add($this->avisoDaFase($fase, $contato));
            }
        }
    }

    private function avisoDaFase(FaseWhatsapp $fase, ContatoWhatsapp $contato): AvisoWhatsapp
    {
        $texto = sprintf(
            "BlueJus · %s · %s\nOlá, %s! %s em %s/%s: %s",
            $this->getCodigo(),
            $fase->rotulo(),
            self::primeiroNome($contato->getNome()),
            $this->tipoServico,
            $this->cidadeExecucao,
            $this->ufExecucao,
            $fase->frase(),
        );

        return new AvisoWhatsapp($this, $fase, $contato->getNome(), $contato->getTelefone(), $texto);
    }

    private static function primeiroNome(string $nome): string
    {
        return explode(' ', trim($nome))[0];
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

    /** @return list<ConviteDemanda> */
    public function getConvites(): array
    {
        return array_values($this->convites->toArray());
    }

    /** @return list<ContatoWhatsapp> */
    public function getContatosWhatsapp(): array
    {
        return array_values($this->contatosWhatsapp->toArray());
    }

    /** @return list<AvisoWhatsapp> */
    public function getAvisosWhatsapp(): array
    {
        return array_values($this->avisosWhatsapp->toArray());
    }
}
