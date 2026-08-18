<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Obrigação (SPEC §10): um valor devido dentro de um Caso de Cobrança (competência, parcela,
 * mensalidade, taxa, aluguel...). Preserva SEMPRE o valor e o vencimento ORIGINAIS (invariável 20);
 * os encargos entram à parte, sem apagar o original (SPEC §10). Valores em CENTAVOS inteiros.
 *
 * Encargos SEPARADOS (spec "encargos configuráveis em cascata", decisão D1): o antigo campo único
 * `encargosReconhecidos` deu lugar a quatro valores materializados — `juros`, `multa`, `correcao` e
 * `honorarios`. `getEncargosReconhecidos()` continua existindo, mas agora é DERIVADO
 * (`juros + multa + correcao`), de modo que a invariante INV-E1 — "a soma dos três é igual ao
 * encargo agregado de antes" — vale POR CONSTRUÇÃO, e não por disciplina de quem escreve o código.
 * Consequência prática: `CalculadoraSaldo`, Dashboard, FIFO e Acordo não mudaram nem precisam mudar.
 *
 * Honorários ficam FORA do `valorExigivel()` (INV-E2/SPEC §18.5): honorário não é dívida do credor.
 * Quem quiser o total exibido na linha do relatório usa `totalComHonorarios()`.
 *
 * Crescimento AO VIVO (spec "encargos ao vivo"): para uma obrigação VIVA os quatro campos são um CACHE
 * — o serviço `EncargosVivos` os recalcula EM MEMÓRIA na leitura (vencimento → hoje × taxa), sem flush.
 * `valorExigivel()` segue puro e sem `$hoje`: lê o que foi hidratado. `encargosCongeladosEm`/`liquidadaEm`
 * marcam os estados que PARAM o relógio — Liquidada (quitada) e Substituída (por acordo) —, cujos campos
 * guardam o SNAPSHOT definitivo na data de corte e não são recalculados.
 */
#[ORM\Entity(repositoryClass: ObrigacaoRepository::class)]
#[ORM\Table(name: 'cobranca_obrigacao')]
#[ORM\Index(name: 'idx_cobranca_obrigacao_tenant_caso', columns: ['tenant_id', 'caso_id'])]
// A chave de idempotência da importação — (caso, NN, competência) — é um índice ÚNICO PARCIAL com
// `NULLS NOT DISTINCT`, criado por SQL cru na Version20260730120000. O Doctrine não representa índice
// parcial nem essa cláusula, então ele NÃO é declarado aqui de propósito: declarar geraria um índice
// duplicado e sem as duas propriedades que importam.
#[ORM\HasLifecycleCallbacks]
class Obrigacao implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: CasoCobranca::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?CasoCobranca $caso = null;

    #[ORM\Column(length: 255)]
    private string $descricao = '';

    /** Valor original em CENTAVOS (invariável 20 — nunca apagado). */
    #[ORM\Column(type: 'integer')]
    private int $valorOriginal = 0;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $vencimentoOriginal;

    /**
     * COLUNA-SOMBRA do antigo encargo agregado, em centavos (spec §10.3). Mantida por um release
     * para permitir rollback do deploy sem perder o saldo; é escrita automaticamente pelos
     * lifecycle callbacks com `juros + multa + correcao` e NUNCA é lida pela lógica de negócio —
     * quem lê usa `getEncargosReconhecidos()`, que deriva dos três campos. Remover no release
     * seguinte, junto com `sincronizarSombraDeEncargos()`.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $encargosReconhecidos = 0;

    /** Juros de mora materializados, em CENTAVOS. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $juros = 0;

    /** Multa por atraso materializada, em CENTAVOS. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $multa = 0;

    /** Correção monetária materializada, em CENTAVOS. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $correcao = 0;

    /** Honorários materializados, em CENTAVOS — FORA do valor exigível (INV-E2). */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $honorarios = 0;

    /**
     * Quando preenchido, os encargos desta obrigação PARARAM de crescer (INV-E4): foram editados à
     * mão, importados da contabilidade ou negociados. O cron de atualização ignora congeladas.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $encargosCongeladosEm = null;

    /** Data de REFERÊNCIA da última materialização dos encargos (o "atualizado em" da tela). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $encargosAtualizadosEm = null;

    /**
     * Quando preenchido, a obrigação foi LIQUIDADA (quitada) nesta data (modelo "encargos ao vivo",
     * spec §4/§5): o relógio parou aqui — os encargos viram snapshot definitivo e não recalculam mais.
     * É o marcador do estado Liquidada, distinto de `encargosCongeladosEm` (que também vale para
     * Substituída): serve para a correção de pagamento REABRIR a obrigação (voltar a Viva) quando a
     * quitação é desfeita.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $liquidadaEm = null;

    // ------------------------------------------------------------------------------------------
    // Configuração de encargos — NÍVEL 3 (último) da cascata. TODOS nullable: null = "herda o
    // Caso" (que por sua vez herda a Carteira). Resolução campo a campo pelo
    // `ResolvedorConfigEncargos`. `taxaHonorariosBp` supersede a decisão D2: agora existe
    // override de alíquota de honorários por-obrigação; null continua herdando o Caso.
    // ------------------------------------------------------------------------------------------

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $taxaJurosMensalBp = null;

    #[ORM\Column(length: 20, enumType: RegimeJuros::class, nullable: true)]
    private ?RegimeJuros $regimeJuros = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $taxaMultaBp = null;

    #[ORM\Column(length: 20, enumType: BaseEncargo::class, nullable: true)]
    private ?BaseEncargo $baseMulta = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $taxaCorrecaoBp = null;

    #[ORM\Column(length: 20, enumType: BaseEncargo::class, nullable: true)]
    private ?BaseEncargo $baseCorrecao = null;

    #[ORM\Column(length: 20, enumType: BaseEncargo::class, nullable: true)]
    private ?BaseEncargo $baseHonorarios = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $carenciaHonorariosDias = null;

    /** Override da alíquota de honorários DESTA obrigação, em bp (nível 3, supersede D2). null = herda o caso. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $taxaHonorariosBp = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $toleranciaJurosMultaDias = null;

    /** Referência da fonte externa (importação), para deduplicação intra-tenant. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referenciaExterna = null;

    /**
     * Competência: o MÊS a que a dívida se refere, formato `MM/AAAA`. Junto de `referenciaExterna` forma a
     * chave de idempotência da importação (spec `cobranca-importar-chave-competencia.md`).
     *
     * O NN sozinho NÃO identifica a dívida — a contábil o reaproveita (medido em produção: o NN 61457 é
     * uma taxa de 08/2022 na TOP LIFE I e outra de 07/2026 na TOP LIFE 2). E o VENCIMENTO não serve como
     * discriminador: boleto reemitido para o devedor pagar mantém o NN e muda a data, então casar por data
     * duplicaria a dívida. A competência não muda — taxa de 08/2022 reemitida continua sendo de 08/2022.
     *
     * Nula em obrigação cadastrada à mão e em dado anterior ao backfill: nesse caso a importação cai no
     * comportamento antigo (casa só pelo NN), sem regredir.
     */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $competencia = null;

    /** Acordo que GEROU esta obrigação como parcela (SPEC §12); nulo se não é parcela de acordo. */
    #[ORM\ManyToOne(targetEntity: Acordo::class, inversedBy: 'parcelas')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Acordo $acordoOrigem = null;

    /** Acordo que SUBSTITUIU esta obrigação (SPEC §12, invariável 14 — nunca apagada); nulo se ativa. */
    #[ORM\ManyToOne(targetEntity: Acordo::class, inversedBy: 'obrigacoesSubstituidas')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Acordo $acordoSubstituto = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $criadoPor = null;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
        $this->vencimentoOriginal = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function aoCriar(): void
    {
        $this->sincronizarSombraDeEncargos();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
        $this->sincronizarSombraDeEncargos();
    }

    /**
     * Mantém a coluna-sombra `encargos_reconhecidos` igual à soma dos encargos separados, para que
     * um rollback do deploy encontre o saldo intacto (spec §10.3). É escrita-só: nada no código lê
     * essa propriedade além daqui.
     */
    private function sincronizarSombraDeEncargos(): void
    {
        $this->encargosReconhecidos = $this->juros + $this->multa + $this->correcao;
    }

    /**
     * Valor exigível da obrigação em centavos: original + juros + multa + correção (INV-E1).
     * Honorários ficam DE FORA de propósito (INV-E2/SPEC §18.5) — não são dívida do credor e não
     * podem entrar no saldo que alimenta acordos e pagamentos.
     *
     * Método PURO e sem data: o crescimento no tempo vem da materialização periódica dos campos,
     * nunca de recalcular aqui (INV-E3).
     */
    public function valorExigivel(): int
    {
        return $this->valorOriginal + $this->juros + $this->multa + $this->correcao;
    }

    /** Total exibido na linha do relatório: o exigível MAIS os honorários advocatícios. */
    public function totalComHonorarios(): int
    {
        return $this->valorExigivel() + $this->honorarios;
    }

    /**
     * Materializa os quatro encargos calculados para uma data de referência (é o que a
     * `CalculadoraEncargos` devolve). Não toca o valor original nem o congelamento.
     */
    public function definirEncargos(
        int $juros,
        int $multa,
        int $correcao,
        int $honorarios,
        \DateTimeImmutable $referencia,
    ): self {
        $this->juros = $juros;
        $this->multa = $multa;
        $this->correcao = $correcao;
        $this->honorarios = $honorarios;
        $this->encargosAtualizadosEm = $referencia;

        return $this;
    }

    /**
     * Congela os encargos (marca a data de corte): a partir daqui a hidratação ao vivo NÃO recalcula
     * mais esta obrigação — ela lê o snapshot. Usado na liquidação/substituição (via `liquidar()` e o
     * snapshot do acordo). No modelo "ao vivo" nenhuma edição/registro/importação congela.
     */
    public function congelarEncargos(\DateTimeImmutable $em): self
    {
        $this->encargosCongeladosEm = $em;

        return $this;
    }

    /** Volta a obrigação para o recálculo ao vivo (a hidratação passa a recalculá-la de novo). */
    public function descongelarEncargos(): self
    {
        $this->encargosCongeladosEm = null;

        return $this;
    }

    public function encargosCongelados(): bool
    {
        return $this->encargosCongeladosEm !== null;
    }

    /**
     * Os encargos desta obrigação **nunca foram calculados** — distinto de "calculados e deram R$ 0,00".
     * A distinção é do MODELO, não da tela (decisão do dono, 17/08): sem ela o sistema afirma um valor
     * que não tem, e a gerência não consegue julgar a falta batendo o olho na coluna.
     *
     * Acontece quando a obrigação foi substituída por um acordo **sem data**: a data do acordo é a
     * referência do cálculo (`materializarNaDataDoAcordo`), e sem ela não há o que calcular. Derivado do
     * DADO (a data existe ou não), nunca de uma regra — é o oposto da opinião que esta frente remove.
     *
     * ⚠️ Não confie nas colunas `juros/multa/correcao/honorarios` quando isto for verdade: elas guardam
     * o snapshot da última hidratação ao vivo, de uma data arbitrária. A obrigação substituída **não é
     * congelada** (`encargosCongelados()` só vale na liquidação) e **não é re-hidratada** (a query do
     * exigível a exclui), então esse resto ficaria na tela passando por valor atual.
     */
    public function encargosNaoCalculados(): bool
    {
        // ⚠️ VIGENTE-AWARE, como `ObrigacaoOutput::$substituidaPorAcordo`. Se o acordo for rompido ou
        // cancelado, a obrigação VOLTA ao exigível e volta a ser hidratada ao vivo — os encargos dela
        // passam a ser calculados de novo, e reais. O vínculo `acordoSubstituto` permanece (invariável
        // 14: marca-se, nunca se apaga), então testar só a data marcaria como "não calculada" uma
        // obrigação que está sendo calculada todo dia.
        // ⚠️ O teste é `estaLiquidada()`, NÃO `encargosCongelados()` — decisão do dono, 18/08, e a
        // diferença entre os dois é dinheiro na tela. Congelamento tem DUAS origens:
        //
        //   • LIQUIDADA (`liquidadaEm` preenchido) → o snapshot é **fato histórico**: o valor pelo qual a
        //     dívida foi efetivamente quitada. Escondê-lo atrás do traço apagaria informação real. Mostra
        //     o número.
        //   • CONGELADA SEM `liquidadaEm` (legado) → o snapshot é de **data desconhecida**. É exatamente o
        //     "número velho" que esta fatia existe para não exibir. Mostra o traço.
        //
        // Uma versão anterior testava `encargosCongelados()`, que junta os dois — e a justificativa
        // escrita aqui falava da liquidada enquanto a cláusula alcançava principalmente o legado (achado
        // 🟡C da 2ª revisão). O dado distingue os dois casos, então **o sistema não precisa julgar**: é o
        // princípio do dono aplicado, não preferência de tela.
        return $this->acordoSubstituto !== null
            && $this->acordoSubstituto->getStatus()->ehVigente()
            && !$this->acordoSubstituto->temData()
            && !$this->estaLiquidada();
    }

    /**
     * LIQUIDA a obrigação (spec "ao vivo" §4/§6.3): materializa os encargos calculados na data da
     * liquidação, congela (o relógio para) e marca `liquidadaEm`. É a transição atômica Viva → Liquidada
     * disparada quando um pagamento quita o exigível. Após isto, nenhum leitor recalcula: os quatro
     * valores são o snapshot definitivo.
     */
    public function liquidar(int $juros, int $multa, int $correcao, int $honorarios, \DateTimeImmutable $em): self
    {
        $this->definirEncargos($juros, $multa, $correcao, $honorarios, $em);
        $this->congelarEncargos($em);
        $this->liquidadaEm = $em;

        return $this;
    }

    /**
     * REABRE uma obrigação Liquidada (spec §6.3): a correção de um pagamento desfez a quitação
     * (`alocado < exigível`). Limpa `liquidadaEm` e descongela — a obrigação volta a Viva e cresce de
     * novo. Os valores materializados ficam como estão; a hidratação os reescreve na próxima leitura.
     */
    public function reabrir(): self
    {
        $this->liquidadaEm = null;
        $this->descongelarEncargos();

        return $this;
    }

    public function estaLiquidada(): bool
    {
        return $this->liquidadaEm !== null;
    }

    public function getLiquidadaEm(): ?\DateTimeImmutable
    {
        return $this->liquidadaEm;
    }

    /**
     * @deprecated Ponte de compatibilidade da migração de encargos separados (spec §10.2, decisão
     *             D1). Joga o agregado inteiro em `juros` e zera multa/correção — preserva o saldo
     *             ao centavo, mas PERDE o split. Código novo usa `definirEncargos()`.
     */
    public function reconhecerEncargos(int $encargosEmCentavos): self
    {
        return $this->setEncargosReconhecidos($encargosEmCentavos);
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCaso(): ?CasoCobranca
    {
        return $this->caso;
    }

    public function setCaso(CasoCobranca $caso): self
    {
        $this->caso = $caso;

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

    public function getValorOriginal(): int
    {
        return $this->valorOriginal;
    }

    public function setValorOriginal(int $valorOriginal): self
    {
        $this->valorOriginal = $valorOriginal;

        return $this;
    }

    public function getVencimentoOriginal(): \DateTimeImmutable
    {
        return $this->vencimentoOriginal;
    }

    public function setVencimentoOriginal(\DateTimeImmutable $vencimentoOriginal): self
    {
        $this->vencimentoOriginal = $vencimentoOriginal;

        return $this;
    }

    /**
     * Encargos agregados em centavos — DERIVADO de `juros + multa + correcao` (decisão D1). Não lê
     * a coluna-sombra: é justamente por derivar que a invariante INV-E1 vale por construção e o
     * maquinário de saldo continuou intacto.
     */
    public function getEncargosReconhecidos(): int
    {
        return $this->juros + $this->multa + $this->correcao;
    }

    /**
     * @deprecated Ponte de compatibilidade da migração de encargos separados (spec §10.2, decisão
     *             D1): escreve o agregado inteiro em `juros` e zera `multa`/`correcao`. Preserva o
     *             saldo exato (`getEncargosReconhecidos()` devolve o mesmo valor), mas o split se
     *             perde — quem sabe separar deve usar `definirEncargos()`. Existe para os
     *             chamadores antigos (importador, edição, factories de teste) continuarem válidos
     *             enquanto a UI separada (F4) não chega.
     */
    public function setEncargosReconhecidos(int $encargosReconhecidos): self
    {
        $this->juros = $encargosReconhecidos;
        $this->multa = 0;
        $this->correcao = 0;

        return $this;
    }

    public function getJuros(): int
    {
        return $this->juros;
    }

    public function setJuros(int $juros): self
    {
        $this->juros = $juros;

        return $this;
    }

    public function getMulta(): int
    {
        return $this->multa;
    }

    public function setMulta(int $multa): self
    {
        $this->multa = $multa;

        return $this;
    }

    public function getCorrecao(): int
    {
        return $this->correcao;
    }

    public function setCorrecao(int $correcao): self
    {
        $this->correcao = $correcao;

        return $this;
    }

    public function getHonorarios(): int
    {
        return $this->honorarios;
    }

    public function setHonorarios(int $honorarios): self
    {
        $this->honorarios = $honorarios;

        return $this;
    }

    public function getEncargosCongeladosEm(): ?\DateTimeImmutable
    {
        return $this->encargosCongeladosEm;
    }

    public function getEncargosAtualizadosEm(): ?\DateTimeImmutable
    {
        return $this->encargosAtualizadosEm;
    }

    public function getTaxaJurosMensalBp(): ?int
    {
        return $this->taxaJurosMensalBp;
    }

    public function setTaxaJurosMensalBp(?int $taxaJurosMensalBp): self
    {
        $this->taxaJurosMensalBp = $taxaJurosMensalBp;

        return $this;
    }

    public function getRegimeJuros(): ?RegimeJuros
    {
        return $this->regimeJuros;
    }

    public function setRegimeJuros(?RegimeJuros $regimeJuros): self
    {
        $this->regimeJuros = $regimeJuros;

        return $this;
    }

    public function getTaxaMultaBp(): ?int
    {
        return $this->taxaMultaBp;
    }

    public function setTaxaMultaBp(?int $taxaMultaBp): self
    {
        $this->taxaMultaBp = $taxaMultaBp;

        return $this;
    }

    public function getBaseMulta(): ?BaseEncargo
    {
        return $this->baseMulta;
    }

    public function setBaseMulta(?BaseEncargo $baseMulta): self
    {
        $this->baseMulta = $baseMulta;

        return $this;
    }

    public function getTaxaCorrecaoBp(): ?int
    {
        return $this->taxaCorrecaoBp;
    }

    public function setTaxaCorrecaoBp(?int $taxaCorrecaoBp): self
    {
        $this->taxaCorrecaoBp = $taxaCorrecaoBp;

        return $this;
    }

    public function getBaseCorrecao(): ?BaseEncargo
    {
        return $this->baseCorrecao;
    }

    public function setBaseCorrecao(?BaseEncargo $baseCorrecao): self
    {
        $this->baseCorrecao = $baseCorrecao;

        return $this;
    }

    public function getBaseHonorarios(): ?BaseEncargo
    {
        return $this->baseHonorarios;
    }

    public function setBaseHonorarios(?BaseEncargo $baseHonorarios): self
    {
        $this->baseHonorarios = $baseHonorarios;

        return $this;
    }

    public function getCarenciaHonorariosDias(): ?int
    {
        return $this->carenciaHonorariosDias;
    }

    public function setCarenciaHonorariosDias(?int $carenciaHonorariosDias): self
    {
        $this->carenciaHonorariosDias = $carenciaHonorariosDias;

        return $this;
    }

    public function getTaxaHonorariosBp(): ?int
    {
        return $this->taxaHonorariosBp;
    }

    public function setTaxaHonorariosBp(?int $taxaHonorariosBp): self
    {
        $this->taxaHonorariosBp = $taxaHonorariosBp;

        return $this;
    }

    public function getToleranciaJurosMultaDias(): ?int
    {
        return $this->toleranciaJurosMultaDias;
    }

    public function setToleranciaJurosMultaDias(?int $toleranciaJurosMultaDias): self
    {
        $this->toleranciaJurosMultaDias = $toleranciaJurosMultaDias;

        return $this;
    }

    public function getReferenciaExterna(): ?string
    {
        return $this->referenciaExterna;
    }

    public function setReferenciaExterna(?string $referenciaExterna): self
    {
        $this->referenciaExterna = $referenciaExterna;

        return $this;
    }

    public function getCompetencia(): ?string
    {
        return $this->competencia;
    }

    /** Normaliza para `MM/AAAA`; qualquer outra coisa (inclusive string vazia) vira null. */
    public function setCompetencia(?string $competencia): self
    {
        $competencia = $competencia === null ? null : trim($competencia);
        $this->competencia = ($competencia !== null && preg_match('#^\d{2}/\d{4}$#', $competencia) === 1)
            ? $competencia
            : null;

        return $this;
    }

    public function getAcordoOrigem(): ?Acordo
    {
        return $this->acordoOrigem;
    }

    public function setAcordoOrigem(?Acordo $acordoOrigem): self
    {
        $this->acordoOrigem = $acordoOrigem;

        return $this;
    }

    public function getAcordoSubstituto(): ?Acordo
    {
        return $this->acordoSubstituto;
    }

    public function setAcordoSubstituto(?Acordo $acordoSubstituto): self
    {
        $this->acordoSubstituto = $acordoSubstituto;

        return $this;
    }

    /** A obrigação foi substituída por um acordo (marcada, nunca apagada — invariável 14)? */
    public function foiSubstituida(): bool
    {
        return $this->acordoSubstituto !== null;
    }

    /** A obrigação é uma parcela gerada por um acordo? */
    public function ehParcela(): bool
    {
        return $this->acordoOrigem !== null;
    }

    /**
     * A obrigação está TRAVADA por um acordo VIGENTE — parcela de acordo ativo/cumprido, ou original
     * substituída por acordo ativo/cumprido. Um acordo rompido/cancelado NÃO trava: a original volta ao
     * saldo e a parcela vira histórico (StatusAcordo, invariável 20). É a condição que impede editar/
     * excluir a obrigação diretamente (gestão pelo acordo — item 7).
     */
    public function participaDeAcordoVigente(): bool
    {
        return ($this->acordoOrigem?->getStatus()->ehVigente() ?? false)
            || ($this->acordoSubstituto?->getStatus()->ehVigente() ?? false);
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
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
}
