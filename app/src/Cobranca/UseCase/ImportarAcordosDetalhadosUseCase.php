<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\DTO\RegistrarObrigacaoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Exception\MigrationDeCompetenciaPendenteException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\Importacao\AcordoDetalhadoImportavel;
use App\Cobranca\Service\Importacao\AcordoProcessado;
use App\Cobranca\Service\Importacao\ContaOriginalImportavel;
use App\Cobranca\Service\Importacao\ReferenciaSubstituta;
use App\Cobranca\Service\Importacao\ImpactoDaReativacaoDeAcordo;
use App\Cobranca\Service\Importacao\ObrigacoesTocadasNaImportacao;
use App\Cobranca\Service\Importacao\ParcelaAcordoImportavel;
use App\Cobranca\Service\Importacao\ResultadoImportacaoAcordos;
use App\Cobranca\Service\Importacao\ResultadoLeituraAcordos;
use App\Cobranca\Service\Importacao\SobrescritaDeSituacao;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\Service\RestauradorObrigacoesOriginais;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Importa o relatório "Acordos detalhados" da contábil L.G — spec
 * `docs/specs/cobranca-importar-acordos-detalhados.md`.
 *
 * NÃO é uma melhoria de rastreabilidade: é a **correção de um bug de dinheiro em produção**. O relatório
 * de inadimplência só mostra o que está vencido, então duas coisas ele estruturalmente não consegue fazer:
 *
 * 1. **completar as parcelas futuras** (§3.1) — R$ 1.399,49 a receber que nenhum relatório enxerga;
 * 2. **reconciliar as contas originais** (§3.2) — a contábil as remove do relatório ao fechar o acordo, e
 *    o importador não apaga o que sumiu, então elas ficam abertas para sempre, somando junto com a
 *    parcela do acordo. Medido em produção: R$ 680,00 cobrados a mais de um sacado real.
 *
 * ⚠️ **A primeira das três recusas abaixo foi REVOGADA pelo item 5** (spec
 * `docs/specs/cobranca-importar-acordos-criar-acordo.md`): a aba cujo acordo não existe passa a **criá-lo**.
 * O fundamento original ("criar acordo é responsabilidade da inadimplência, que tem o dado completo")
 * caiu na medição de 07/08 — quem cria acordo é a **Receitas**, e só quando alguém **pagou** uma parcela.
 * Acordo fechado há poucas semanas, sem pagamento nenhum, não nascia em lugar nenhum: **38 dos 392**
 * acordos declarados pela contábil, com **R$ 28.926,43** em parcelas a receber que nenhum relatório
 * enxergava. Ver `criarAcordoDaAba` para as quatro recusas que sobraram na criação.
 *
 * Recusas deliberadas, todas na direção segura:
 *
 * - **O casamento é por NN + COMPETÊNCIA, nunca pelo NN sozinho** (§3.2). O NN é reaproveitado pela
 *   contábil: casar só por ele marcaria 3 dívidas de 2022 da TOP LIFE I como substituídas por acordos de
 *   2026 da TOP LIFE 2, apagando R$ 435,00 de cobrança legítima de terceiros.
 * - **Valor lançado nunca é sobrescrito** (§4). A planilha não é autoridade sobre dinheiro já lançado;
 *   divergência entra no resumo.
 *
 * Uma obrigação NUNCA é apagada (invariável 14) — só marcada com `acordoSubstituto`. Marcar tira do saldo
 * porque `ObrigacaoRepository::doCasoExigiveis` exclui o que está substituído por acordo VIGENTE; romper
 * o acordo restaura as originais e descarta as parcelas por derivação (invariável 20), sem reversão
 * imperativa. É o mesmo mecanismo do `CriarAcordoUseCase`.
 */
final class ImportarAcordosDetalhadosUseCase
{
    /**
     * A marca de procedência da conta original RECONSTRUÍDA (§3.2.1), gravada na descrição porque a
     * `Obrigacao` não tem campo de observação. É o único sinal que distingue a conta reconstruída a
     * partir da planilha do boleto importado de verdade.
     *
     * ⚠️ **Não é durável:** `EditarObrigacaoUseCase` reescreve a descrição, então a marca some se
     * alguém editar a dívida pela tela. Serve para LER a procedência, nunca para decidir dinheiro.
     *
     * ⚠️ **Não é régua de dinheiro, e já foi usada como uma DUAS vezes.** A 1ª versão da fatia do
     * honorário selecionou por esta marca a população de uma correção — teria zerado R$ 102.126,32 de
     * honorário legítimo, porque a marca diz de ONDE a obrigação veio, não o QUE ela é. A 4ª versão a
     * usou como "filtro de segurança" e caiu de novo: medido, as 1.906 parcelas CERTAS em produção
     * **não têm a marca**. Ver `cobranca-honorario-no-total.md` §10.6.
     */
    public const MARCA_RECONSTRUIDA = 'Reconstruída da planilha de acordos';

    /**
     * Situações da fonte que o domínio sabe traduzir (§3). Comparadas em minúsculas e sem acento.
     *
     * As três strings foram MEDIDAS nos arquivos reais de 04/08 (`Situação: …` da primeira aba), não
     * supostas: `Em andamento` · `Liquidado` · `Cancelado`. A API da contábil não tem situação
     * equivalente a `Rompido` (enum `TODOS·EM_ANDAMENTO·LIQUIDADO·CANCELADO`), então o importe nunca
     * PRODUZ `Rompido` — só o consome como estado de origem.
     *
     * Qualquer outra continua reportada e nunca adivinhada.
     */
    private const SITUACOES = [
        'em andamento' => StatusAcordo::Ativo,
        'liquidado' => StatusAcordo::Cumprido,
        'cancelado' => StatusAcordo::Cancelado,
    ];

    public function __construct(
        private readonly CarteiraRepository $carteiraRepository,
        private readonly AcordoRepository $acordoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        // Item 5: resolver a unidade da aba até o caso onde o acordo novo será pendurado. Só LEITURA —
        // este importador não cria objeto, pessoa nem caso (decisão do dono, 07/08).
        private readonly ObjetoCobrancaRepository $objetoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly RegistrarObrigacaoUseCase $registrarObrigacao,
        private readonly CalculadoraEncargos $calculadora,
        private readonly ResolvedorConfigEncargos $resolvedorConfig,
        private readonly RegistrarEventoHistorico $registrarEvento,
        private readonly RestauradorObrigacoesOriginais $restaurador,
        private readonly ImpactoDaReativacaoDeAcordo $impactoDaReativacao,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Dry-run — **é o produto principal desta entrega** (§6): imprime, por acordo, o que criaria e o que
     * marcaria, com unidade e sacado, antes de qualquer escrita. Read-only.
     */
    public function prever(int $carteiraId, ResultadoLeituraAcordos $leitura, Tenant $tenant): ResultadoImportacaoAcordos
    {
        return $this->processar($carteiraId, $leitura, $tenant, null);
    }

    /** Persiste numa transação única (ou tudo, ou nada). Idempotente: rodar de novo não altera nada (§7). */
    public function confirmar(int $carteiraId, ResultadoLeituraAcordos $leitura, Tenant $tenant, User $user): ResultadoImportacaoAcordos
    {
        return $this->em->wrapInTransaction(
            fn (): ResultadoImportacaoAcordos => $this->processar($carteiraId, $leitura, $tenant, $user),
        );
    }

    /**
     * Prévia e confirmação percorrem ESTE mesmo método — `$usuario === null` é o dry-run.
     *
     * Diverge de propósito do `ImportarRelatorioCarteiraUseCase`, que tem `prever()` e `confirmar()`
     * escritos separadamente: ali as duas cópias já divergiram uma vez. Aqui a spec exige que a projeção
     * seja conferida contra o efeito antes de mexer em dinheiro de produção, e duas implementações da
     * mesma decisão não conseguem garantir isso — uma só, sim, por construção.
     */
    private function processar(int $carteiraId, ResultadoLeituraAcordos $leitura, Tenant $tenant, ?User $usuario): ResultadoImportacaoAcordos
    {
        // Antes de qualquer coisa: sem a coluna `competencia` não há casamento seguro, e o lote morreria
        // no primeiro INSERT com um traço de driver em vez de uma instrução. Vale para o dry-run também —
        // uma prévia calculada sobre o casamento errado seria pior que erro nenhum.
        if (!$this->obrigacaoRepository->schemaTemCompetencia()) {
            throw new MigrationDeCompetenciaPendenteException();
        }

        $carteira = $this->resolverCarteira($carteiraId, $tenant);

        // Fotografa o efeito das REATIVAÇÕES sobre o banco INTOCADO, antes de qualquer escrita, nos dois
        // modos. Ler isto dentro do laço faria a confirmação medir um banco que ela própria já mexeu, e
        // o número divergiria da prévia (`feedback_previa_precisa_de_estado`). Mesmo serviço que o
        // importador de receitas usa para a mesma decisão.
        $impactoPorAcordo = $this->impactoDaReativacao->mapearPorNumeroExterno(
            $carteira,
            array_map(static fn (AcordoDetalhadoImportavel $aba): int => $aba->numero, $leitura->acordos),
            $tenant,
        );

        // Mesma foto, pela mesma razão, para a pergunta oposta: quais acordos têm PARCELA PAGA. É o que
        // barra a desativação por importe (§5.3, decisão do dono de 04/08). Medido aqui, e não no laço,
        // para prévia e confirmação decidirem pelo MESMO valor — a invariável do §6.
        $parcelaPagaPorAcordo = $this->mapearParcelasPagas($carteira, $leitura, $tenant);

        $processados = [];
        $liquidadas = [];
        $situacoesDesconhecidas = [];
        // Canal SEPARADO das desconhecidas: situação reconhecida cuja aplicação foi RECUSADA por uma
        // guarda de dinheiro (§5.3). "Não reconhecida" e "reconhecida e recusada" pedem ações opostas.
        $sobrescritasBarradas = [];
        $conferencias = [];
        // O que ESTA execução já criou ou mutou. Ver a classe: são três índices, e cada um fecha um
        // caminho por onde a prévia voltaria a divergir da confirmação.
        $tocadas = new ObrigacoesTocadasNaImportacao();

        foreach ($leitura->acordos as $aba) {
            foreach ($aba->parcelas as $parcela) {
                if ($parcela->constaLiquidada) {
                    // §5: a baixa de pagamento fica FORA desta entrega (é irreversível na prática).
                    $liquidadas[] = $parcela->nn;
                }
            }

            $conferencia = $this->conferirTotaisDaAba($aba);
            if ($conferencia !== null) {
                $conferencias[] = $conferencia;
            }

            $processados[] = $this->processarAba(
                $aba,
                $carteira,
                $tenant,
                $usuario,
                $situacoesDesconhecidas,
                $sobrescritasBarradas,
                $tocadas,
                $impactoPorAcordo[$aba->numero] ?? [[], []],
                $parcelaPagaPorAcordo[$aba->numero] ?? false,
            );
        }

        return new ResultadoImportacaoAcordos(
            $processados,
            $leitura->rejeitadas,
            $leitura->linhasIgnoradas,
            $liquidadas,
            $situacoesDesconhecidas,
            $conferencias,
            $sobrescritasBarradas,
        );
    }

    /**
     * Confere a soma das LINHAS lidas contra o cabeçalho da PRÓPRIA aba — a única conferência disponível
     * sem sair do arquivo, e a que pega leitura parcial: linha rejeitada, seção truncada, coluna
     * deslocada. Foi assim, à mão, que o adapter foi validado contra a planilha real (14 conferências
     * independentes); aqui isso vira automático, em vez de depender de alguém repetir a conta.
     *
     * Só avisa — a planilha nunca manda no que já está lançado (§4), e um cabeçalho divergente pode ser
     * da própria fonte.
     */
    private function conferirTotaisDaAba(AcordoDetalhadoImportavel $aba): ?string
    {
        $avisos = [];

        $cabecalhoOriginais = $aba->valorTotalContasOriginaisCentavos;
        if ($cabecalhoOriginais !== null && $aba->contasOriginais !== [] && $cabecalhoOriginais !== $aba->somaContasOriginaisCentavos()) {
            $avisos[] = sprintf(
                'contas originais lidas somam R$ %s, o cabeçalho diz R$ %s',
                number_format($aba->somaContasOriginaisCentavos() / 100, 2, ',', '.'),
                number_format($cabecalhoOriginais / 100, 2, ',', '.'),
            );
        }

        $cabecalhoFinal = $aba->valorFinalAcordadoCentavos;
        if ($cabecalhoFinal !== null && $aba->parcelas !== [] && $cabecalhoFinal !== $aba->somaParcelasCentavos()) {
            $avisos[] = sprintf(
                'parcelas lidas somam R$ %s, o "Valor final acordado" diz R$ %s',
                number_format($aba->somaParcelasCentavos() / 100, 2, ',', '.'),
                number_format($cabecalhoFinal / 100, 2, ',', '.'),
            );
        }

        if ($avisos === []) {
            return null;
        }

        return sprintf('Acordo %d: %s — a leitura não fecha com o cabeçalho da própria planilha; confira o arquivo.', $aba->numero, implode(' · ', $avisos));
    }

    /**
     * @param list<string>          $situacoesDesconhecidas acumulador do lote — situação NÃO mapeada
     * @param list<string>          $sobrescritasBarradas   acumulador do lote — situação mapeada e RECUSADA (§5.3)
     * @param ObrigacoesTocadasNaImportacao $tocadas                registro do lote (ver `processar`)
     * @param array{0: list<string>, 1: list<string>} $impactoDaReativacao medido sobre o banco intocado
     * @param bool                  $temParcelaPaga         idem — barra a desativação por importe (§5.3)
     */
    private function processarAba(
        AcordoDetalhadoImportavel $aba,
        Carteira $carteira,
        Tenant $tenant,
        ?User $usuario,
        array &$situacoesDesconhecidas,
        array &$sobrescritasBarradas,
        ObrigacoesTocadasNaImportacao $tocadas,
        array $impactoDaReativacao,
        bool $temParcelaPaga,
    ): AcordoProcessado {
        $acordoCriado = false;
        $acordo = $this->acordoRepository->findOnePorNumeroExternoNaCarteira($aba->numero, $carteira, $tenant);
        if ($acordo === null) {
            // ITEM 5: a aba cujo acordo não existe passa a CRIÁ-LO. Devolve o motivo (string) quando uma
            // das quatro recusas barra a criação — aí a aba segue ignorada, como antes, sem escrita.
            $novo = $this->criarAcordoDaAba($aba, $carteira, $tenant, $usuario);
            if (is_string($novo)) {
                return $this->abaIgnorada($aba, $novo);
            }

            $acordo = $novo;
            $acordoCriado = true;
        }

        $caso = $acordo->getCaso();
        if ($caso === null) {
            return $this->abaIgnorada($aba, sprintf('Acordo %d está sem caso de cobrança — dado inconsistente, nada foi tocado.', $aba->numero));
        }

        // §4 — A SITUAÇÃO DA PLANILHA MANDA. Decisão do dono de 04/08: "o importe sempre sobrescreve o
        // sistema". Resolvida ANTES da guarda de vigência e IDÊNTICA nos dois modos: é o que faz prévia
        // e confirmação processarem exatamente as mesmas abas (§6).
        $sobrescrita = $this->resolverSobrescrita($aba, $acordo, $situacoesDesconhecidas);

        // §5.3 — A ÚNICA EXCEÇÃO a "o importe sobrescreve sempre" (decisão do dono, 04/08).
        //
        // Desativar um acordo com PARCELA PAGA tira as parcelas do exigível levando a alocação junto: o
        // dinheiro recebido para de abater o saldo e o devedor volta a ser cobrado por algo que pagou.
        // O caminho manual RECUSA esse cancelamento (`CancelarAcordoUseCase::recusarSeAlgumaParcelaFoiPaga`,
        // `AcordoComParcelaPagaException`), e o dono decidiu que o importe faz o mesmo em vez de aplicar
        // e reportar: **não aplica, e avisa para excluir o pagamento primeiro.**
        //
        // Por que aqui e não lá dentro: barrado ANTES de `$statusFinal`, a guarda de vigência continua
        // enxergando o status vigente do banco, e a aba segue sendo processada normalmente — que é o
        // certo, porque o acordo continua vigente de fato. Se a barreira ficasse depois, o status não
        // mudaria mas a aba seria pulada como se tivesse mudado.
        //
        // ⚠️ Não lança exceção, ao contrário do caminho manual: derrubaria o lote inteiro por causa de
        // uma aba. Vira aviso acionável, com o caminho da saída (a etapa 1 apaga o recebimento).
        if ($sobrescrita?->desativa() === true) {
            $barrado = $this->motivoParaNaoDesativar($acordo, $temParcelaPaga, $aba->numero, $tocadas);
            if ($barrado !== null) {
                $sobrescritasBarradas[] = sprintf(
                    'Acordo %d: a contábil diz "%s", mas %s — status mantido em %s.',
                    $aba->numero,
                    $aba->situacao,
                    $barrado,
                    $acordo->getStatus()->value,
                );
                $sobrescrita = null;
            }
        }

        // ⚠️ O status que decide daqui para a frente sai da SOBRESCRITA, nunca de `$acordo->getStatus()`:
        // depois de a confirmação escrever, a entidade já responde o valor novo, e a prévia — que não
        // escreve — responderia o antigo. Consultar a entidade aqui é como as duas passam a divergir.
        $statusFinal = $sobrescrita?->novo ?? $acordo->getStatus();

        // A DECISÃO de status entra no acumulador nos DOIS modos — a escrita, não. É daqui que a
        // INV-I responde "o acordo de origem continua vigente?" sem que a prévia e a confirmação
        // deem respostas diferentes: a entidade só reflete o status novo na confirmação.
        $tocadas->registrarStatusDecidido($aba->numero, $statusFinal);

        if ($sobrescrita !== null && $usuario !== null) {
            $this->aplicarSobrescrita($acordo, $caso, $sobrescrita, $tenant, $usuario);
        }

        // Avisos só existem quando a transição REALMENTE reativa; medida ou não, a lista fica vazia no
        // resto dos casos. Alarme falso em aviso de dinheiro ensina o operador a ignorar.
        [$dinheiroParado, $impactoNoSaldo] = $sobrescrita?->reativa() === true ? $impactoDaReativacao : [[], []];

        if (!$statusFinal->ehVigente()) {
            // Acordo não vigente: a aba inteira é pulada, e não só o status.
            //
            // Escrever aqui CRIA DÍVIDA. A conta reconstruída pelo §3.2.1 nasce com `acordoSubstituto`,
            // e `doCasoExigiveis` só exclui o que está substituído por acordo VIGENTE — com o acordo
            // não vigente ela entra no saldo e cobra de novo uma dívida que a planilha listou como
            // renegociada. A parcela futura tem o defeito espelhado: nasceria ligada a um acordo que
            // não vale mais. Marcar as originais seria inócuo pelo mesmo motivo, e igualmente confuso.
            //
            // 🔑 O que mudou com a sobrescrita: a guarda agora consulta o status que a PLANILHA diz, não
            // o que estava no banco. Uma aba `Liquidado` cujo acordo estava `rompido` no sistema deixa de
            // ser pulada — vira `cumprido`, que é vigente, e é processada. E uma aba `Cancelado` passa a
            // ser pulada mesmo que o sistema a tivesse como ativa. O status foi escrito acima de
            // qualquer forma: o que a guarda decide é o que se ESCREVE CONTRA o acordo, não o status.
            return $this->abaIgnorada(
                $aba,
                sprintf(
                    'Acordo %d está %s — aba pulada inteira: escrever contra acordo não vigente devolveria as contas ao saldo. Confira à mão.',
                    $aba->numero,
                    $statusFinal->value,
                ),
                $sobrescrita,
                $dinheiroParado,
                $impactoNoSaldo,
            );
        }

        if ($caso->estaEncerrado()) {
            // Caso encerrado não recebe obrigação (SPEC §17). Sem esta guarda o `RegistrarObrigacaoUseCase`
            // lançaria e derrubaria o LOTE INTEIRO por causa de uma aba.
            return $this->abaIgnorada(
                $aba,
                sprintf('Acordo %d pertence a um caso ENCERRADO — caso encerrado não recebe obrigação (SPEC §17).', $aba->numero),
                $sobrescrita,
                $dinheiroParado,
                $impactoNoSaldo,
            );
        }

        $configCaso = $this->resolvedorConfig->resolverDoCaso($caso);

        // 🔑 6ª VIOLAÇÃO — O RAMO DE ATUALIZAÇÃO PASSA A PREENCHER A DATA (17/08).
        //
        // Até aqui `setDataAcordo($aba->dataBase)` só existia no ramo de CRIAÇÃO (`criarAcordoDaAba`), e
        // este ramo nunca reescrevia a data. Como os três importadores compartilham o `Acordo` (achado por
        // `carteira + numeroExterno`), **quem criava primeiro fixava a data para sempre** — e o relatório
        // que TEM a data verdadeira não conseguia corrigi-la. Medido em prod: 375 de 395 acordos com a data
        // derivada, sem caminho de conserto.
        //
        // Sem esta peça, remover `dataAcordoPadrao()` dos outros dois importadores deixaria o campo nascer
        // em branco e ficar em branco para sempre — estado PIOR que o anterior. Por isso a decisão (B) e a
        // 6ª violação saem na mesma fatia (spec §2.1).
        //
        // Só PREENCHE o vazio; **não sobrescreve** data já existente. Uma data que veio da própria coluna
        // "Data base" de uma importação anterior é o mesmo dado, e regravá-la a cada lote sujaria o
        // histórico sem mudar nada. Divergência entre duas leituras da mesma fonte é outro problema — e
        // seria opinião resolvê-la aqui, no escuro.
        $dataPreenchidaAgora = null;
        if (!$acordo->temData() && $aba->dataBase !== null) {
            $dataPreenchidaAgora = $aba->dataBase;

            // A escrita só na CONFIRMAÇÃO (`$usuario !== null`), como todo o resto deste UseCase; a
            // prévia decide igual e não grava, que é o que faz as duas convergirem (§6).
            if ($usuario !== null) {
                $acordo->setDataAcordo($aba->dataBase);
                $this->acordoRepository->salvar($acordo, true);

                // A data chegou: as obrigações que este acordo substituiu estavam com o encargo NÃO
                // CALCULADO (mostrando "—" na tela) esperando exatamente por ela. Agora dá para calcular.
                // Sem isto o traço ficaria na tela para sempre mesmo com a data já preenchida.
                // ⚠️ QUERY EXPLÍCITA, não `$acordo->getObrigacoesSubstituidas()` (achado 🟡3 da revisão).
                // Duas razões, ambas documentadas em `ObrigacaoRepository::substituidasPorAcordo`:
                // (1) a coleção INVERSA nasce vazia quando o lado dono foi escrito na mesma unidade de
                //     trabalho — o laço não materializaria nada, **em silêncio**;
                // (2) a query passa o TENANT explícito, e o `TenantFilter` fica DESLIGADO em CLI, que é
                //     exatamente como este importador roda. Isolamento entre escritórios é inegociável.
                foreach ($this->obrigacaoRepository->substituidasPorAcordo($acordo, $tenant) as $substituida) {
                    $this->materializarNaDataDoAcordo($substituida, $acordo, $configCaso);
                    $this->obrigacaoRepository->salvar($substituida);
                }
            }
        }

        [$parcelasCriadas, $parcelasExistentes, $parcelasAmbiguas, $divergencias, $valorParcelas, $parcelasVinculadas, $liquidadasIgnoradas] =
            $this->completarParcelas($aba, $acordo, $caso, $tenant, $usuario, $tocadas);

        [$marcadas, $reconstruidas, $jaMarcadas, $recusadas, $semCompetencia, $maisDivergencias, $principal, $centavosSemBoleto] =
            $this->reconciliarContasOriginais($aba, $acordo, $caso, $tenant, $usuario, $configCaso, $tocadas);

        return new AcordoProcessado(
            numero: $aba->numero,
            unidade: $aba->unidade,
            sacado: $aba->sacado,
            ignoradoPorque: null,
            parcelasCriadas: $parcelasCriadas,
            parcelasExistentes: $parcelasExistentes,
            contasMarcadas: $marcadas,
            contasReconstruidas: $reconstruidas,
            contasJaMarcadas: $jaMarcadas,
            divergenciasDeValor: [...$divergencias, ...$maisDivergencias],
            casadasSemCompetencia: $semCompetencia,
            contasRecusadas: $recusadas,
            parcelasAmbiguas: $parcelasAmbiguas,
            principalReconciliadoCentavos: $principal,
            centavosSemBoleto: $centavosSemBoleto,
            valorParcelasCriadasCentavos: $valorParcelas,
            situacaoSobrescrita: $sobrescrita?->descricao(),
            parcelasVinculadas: $parcelasVinculadas,
            parcelasLiquidadasIgnoradas: $liquidadasIgnoradas,
            dinheiroParadoPelaReativacao: $dinheiroParado,
            impactoDaReativacaoNoSaldo: $impactoNoSaldo,
            acordoCriado: $acordoCriado,
            situacaoDoAcordoCriado: $acordoCriado ? $aba->situacao : null,
            dataDoAcordoCriado: $acordoCriado ? $acordo->getDataAcordo() : null,
            dataPreenchidaAgora: $dataPreenchidaAgora,
        );
    }

    /**
     * ITEM 5 — o acordo que a planilha declara e o sistema não conhece **nasce aqui**. Devolve o `Acordo`
     * ou, quando uma das quatro recusas barra a criação, o **motivo** (string) que vira o
     * `ignoradoPorque` da aba.
     *
     * ⚠️ **Construído nos DOIS modos, persistido só na confirmação.** É o que mantém a invariável do §6:
     * prévia e confirmação percorrem as mesmas abas pelos mesmos ramos. A prévia trabalha com um `Acordo`
     * transiente — nunca passado ao `persist` —, e `CasoCobranca` não tem coleção inversa de acordos, então
     * não há cascade que o grave por acidente. O `id` nulo é lido em dois pontos (`:452` e `:601`), e nos
     * dois a comparação dá o MESMO resultado que daria com o id real: o outro lado sempre vem do banco,
     * com id preenchido.
     *
     * As quatro recusas nunca lançam: uma aba estranha não pode derrubar o lote.
     *
     * 1. **situação fora do mapa** — nunca adivinhar status, a mesma régua da sobrescrita;
     * 2. **situação não vigente** (`Cancelado`) — acordo não vigente tem a aba pulada inteira logo abaixo;
     *    criá-lo deixaria um acordo vazio no sistema, e contraria "cancelados ficam de fora";
     * 3. **sem `Data base`** — é a data em que o relógio dos juros das dívidas renegociadas para
     *    (`materializarNaDataDoAcordo`). Chutá-la é decidir dinheiro no escuro;
     * 4. **unidade sem cobrança cobrável (não encerrada) na carteira** — decisão do dono de 07/08: este relatório NÃO abre
     *    cobrança nova (objeto/pessoa/caso), diferente da inadimplência e da receitas. Medido no mesmo dia:
     *    38 de 38 unidades já existem, então a recusa não custa nada hoje; o que ela evita é o relatório de
     *    Acordos ganhar poder de abrir cobrança por um caminho que nunca foi exercitado no dado real.
     *
     * ⚠️ As recusas 2 e 3 **não disparam no dado de hoje** (o validador do rodapé barra o
     * `*_CANCELADO.xlsx`; 38 de 38 abas trazem `Data base`). Estão provadas só por teste, e ficam
     * registradas como tal — a mesma honestidade que a spec-mãe usa para `Cumprido → Ativo`.
     *
     * 🔑 **A recusa 4, ao contrário, dispara MUITO** — e isso só apareceu ao rodar o dry-run contra um
     * banco de verdade (achado da 2ª revisão). Medido em `saas_ux`, TL1 `EM_ANDAMENTO`: **21 abas
     * recusadas por ela**, porque naquele banco a inadimplência completa nunca foi importada. Ela não é
     * decoração: é o que impede o importe de pendurar acordo onde não há cobrança.
     *
     * ⚠️ **NÃO registra evento no histórico**, de propósito, e isto é decisão medida, não esquecimento:
     * `TipoEventoHistorico::AcordoCriado` é o que a Central de Acompanhamento — que está em PRODUÇÃO —
     * conta como a coluna "Acordos" do trabalho humano de cobrança
     * (`EventoHistoricoRepository::agregarAtividadePorUsuario`), e alimenta a "Última ação". Registrar
     * aqui creditaria dezenas de acordos "fechados" num único dia a quem rodou a importação, e um
     * relatório que conta importação como trabalho para de medir trabalho — a mesma distorção que o
     * comentário de `PagamentoExcluido` no enum diz que a Central existe para evitar. A procedência não
     * se perde: `numeroExterno` só é preenchido por importação, e as contas reconstruídas carregam
     * "Reconstruída da planilha de acordos (emissão …)" na descrição (§3.2.1).
     */
    private function criarAcordoDaAba(
        AcordoDetalhadoImportavel $aba,
        Carteira $carteira,
        Tenant $tenant,
        ?User $usuario,
    ): Acordo|string {
        $mapeada = self::SITUACOES[$this->semAcento($aba->situacao)] ?? null;
        if ($mapeada === null) {
            return sprintf(
                'Acordo %d não existe nesta carteira e a situação "%s" não é reconhecida — acordo NÃO criado, nada foi tocado. Confira a linha "Situação:" da aba.',
                $aba->numero,
                $aba->situacao,
            );
        }

        if (!$mapeada->ehVigente()) {
            return sprintf(
                'Acordo %d não existe nesta carteira e a planilha o dá como "%s" — acordo NÃO criado: um acordo não vigente teria a aba pulada de qualquer forma.',
                $aba->numero,
                $aba->situacao,
            );
        }

        if ($aba->dataBase === null) {
            return sprintf(
                'Acordo %d não existe nesta carteira e a aba não traz "Data base" — acordo NÃO criado: é a data em que os juros das dívidas renegociadas param de correr, e ela não se adivinha.',
                $aba->numero,
            );
        }

        $identificacao = $aba->objetoIdentificacao();
        $objeto = $this->objetoRepository->findOnePorIdentificacaoNaCarteira($carteira, $identificacao, $tenant);
        // `casosCobraveisDoObjeto` devolve os NÃO ENCERRADOS — caso encerrado não recebe obrigação
        // (SPEC §17) e, por consequência, também não recebe acordo. Caso JUDICIALIZADO recebe: pela
        // §16 a judicialização não encerra a cobrança, e o ramo que ATUALIZA acordo existente (mais
        // acima, via `AcordoRepository`) já operava sobre ele — só a criação recusava. Era o mesmo
        // importador com dois pesos.
        //
        // ⚠️ Isto NÃO afrouxa a decisão D2 (`cobranca-importar-acordos-criar-acordo.md`): o relatório
        // de Acordos continua sem abrir cobrança nova (objeto/pessoa/caso). Ele apenas deixa de
        // recusar onde a cobrança JÁ existe.
        $caso = $objeto !== null ? ($this->casoRepository->casosCobraveisDoObjeto($objeto)[0] ?? null) : null;
        if ($caso === null) {
            return sprintf(
                'Acordo %d não existe nesta carteira e a unidade "%s" não tem cobrança aqui — acordo NÃO criado. Importe a inadimplência desta carteira primeiro e rode de novo.',
                $aba->numero,
                $identificacao,
            );
        }

        $acordo = new Acordo();
        $acordo->setTenant($tenant);
        $acordo->setCaso($caso);
        $acordo->setStatus($mapeada);
        // D3 (dono, 07/08): a `Data base`, nunca o `Criado em`. A primeira é a data ECONÔMICA do acordo —
        // aquela em que a contábil parou o relógio dos juros; a segunda é quando alguém digitou o acordo
        // no sistema deles. Divergem em 4 das 38 abas medidas (o acordo 39: base 13/07, digitado 30/07),
        // e usar a errada congelaria os encargos 17 dias depois do combinado.
        $acordo->setDataAcordo($aba->dataBase);
        $acordo->setNumeroExterno($aba->numero);
        // ⚠️ Só quando esta importação tem alguma parcela a materializar. `Acordo::estaIncompleto()` usa
        // este campo para estampar "⚠ Faltam N parcelas" na tela do acordo, comparando-o com as linhas
        // que existem. Medido em 07/08 nos 3 arquivos `*_LIQUIDADO`: **627 de 627 parcelas vêm com data
        // de liquidação, em 310 de 310 abas** — e parcela que consta paga NÃO é criada (decisão de
        // escopo, §5 da spec-mãe: não se escreve pagamento a partir de planilha). Gravar o total aqui
        // deixaria os 13 acordos `Cumprido` que nascem desta frente com um aviso PERMANENTE e falso na
        // tela. Aviso que sempre dispara ninguém lê — é a mesma régua que mantém a sobrescrita fora do
        // bloco de avisos do relatório.
        $acordo->setNumeroParcelasTotal($aba->temParcelaAMaterializar() ? $aba->totalDeParcelas() : null);
        // Gravado SEMPRE, diferente do `ImportarReceitasUseCase` (que só grava com uma parcela). A
        // restrição de lá existe porque a Receitas traz só as parcelas PAGAS e inventaria o total; aqui a
        // fonte IMPRIME o "Valor final acordado" no cabeçalho da aba.
        $acordo->setValorTotalNegociado($aba->valorFinalAcordadoCentavos);

        if ($usuario !== null) {
            $acordo->setCriadoPor($usuario);
            // Persistido ANTES da primeira parcela, obrigatoriamente: o `RegistrarObrigacaoUseCase` dá
            // flush por obrigação, e ligar uma obrigação a um `Acordo` não gerenciado estouraria com
            // "new entity found through relationship".
            $this->acordoRepository->salvar($acordo, true);
        }

        return $acordo;
    }

    /**
     * §3.1 — completar as parcelas futuras. A parcela que não existe nasce como obrigação do caso, ligada
     * ao acordo (`acordoOrigem`), com honorários ZERO e encargos ao vivo a partir do próprio vencimento.
     *
     * @param ObrigacoesTocadasNaImportacao $tocadas registro do que ESTA execução já criou ou mutou
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<string>, 4: int, 5: list<string>, 6: list<string>}
     */
    private function completarParcelas(
        AcordoDetalhadoImportavel $aba,
        Acordo $acordo,
        CasoCobranca $caso,
        Tenant $tenant,
        ?User $usuario,
        ObrigacoesTocadasNaImportacao $tocadas,
    ): array {
        $criadas = [];
        $existentes = [];
        $ambiguas = [];
        $divergencias = [];
        $vinculadas = [];
        $liquidadasIgnoradas = [];
        $valor = 0;

        foreach ($aba->parcelas as $parcela) {
            // Esta MESMA importação já mexeu nesta dívida (outra aba do mesmo caso — dois acordos da
            // mesma unidade compartilham o caso). No dry-run o banco não mudou, então sem esta consulta a
            // projeção a trataria como intocada e contaria o efeito duas vezes. É no-op nos dois modos.
            if ($tocadas->tipoDoTrio($caso, $parcela->nn, $parcela->competencia) !== null) {
                $existentes[] = $parcela->nn;

                continue;
            }

            $existente = $this->obrigacaoRepository->findOnePorReferenciaECompetenciaNoCaso($caso, $parcela->nn, $parcela->competencia);

            // A busca resolveu para uma LINHA que esta execução já tocou — só acontece pelo fallback do
            // legado, que casa uma obrigação sem competência com qualquer competência da planilha. O trio
            // é outro, a obrigação é a mesma.
            if ($existente !== null && $tocadas->tipoDaObrigacao($existente) !== null) {
                $existentes[] = $parcela->nn;

                continue;
            }

            if ($existente !== null) {
                $existentes[] = $parcela->nn;
                $divergencia = $this->divergenciaDeValor($parcela->nn, $existente, $parcela->valorCentavos);
                if ($divergencia !== null) {
                    $divergencias[] = $divergencia;
                }

                // A parcela existe mas está SOLTA (importada da inadimplência antes de o acordo ser
                // reconhecido, ou lançada à mão). Sem `acordoOrigem` ela não sai do saldo quando o acordo
                // é rompido — invariável 20 descarta as parcelas POR DERIVAÇÃO, e derivação precisa do
                // vínculo. No dia do rompimento a dívida seria contada duas vezes: a original volta e a
                // parcela órfã fica. Ligar é a mesma coisa que o importador de inadimplência já faz.
                $origem = $existente->getAcordoOrigem();
                if ($origem === null) {
                    $vinculadas[] = $parcela->nn;
                    // Mutação também entra no acumulador, não só criação: sem isto a aba seguinte do mesmo
                    // caso veria `acordoOrigem` ainda nulo na prévia (o banco não mudou) e contaria o
                    // vínculo de novo, enquanto a confirmação reportaria divergência.
                    $tocadas->registrarMutada($existente, $caso, $parcela->nn, $parcela->competencia, 'parcela-vinculada', $acordo, $existente->getValorOriginal());
                    if ($usuario !== null) {
                        $existente->setAcordoOrigem($acordo);
                        // ⚠️ O VÍNCULO entra sozinho: NÃO se grava override de honorário aqui.
                        //
                        // Uma versão desta fatia gravava `taxaHonorariosBp = 0` junto, com o argumento
                        // de que "a contabilidade não cobra encargo em parcela de acordo — 0 de 8.671
                        // linhas do relatório de acordos". **O argumento estava errado, e a medição de
                        // 19/08 o derrubou:** a parcela ATRASADA sai do relatório de acordos e entra no
                        // de INADIMPLÊNCIA, que tem as colunas de encargo — e lá ela cobra. Medido nas
                        // três carteiras, no lote de 17/08: **114 parcelas de acordo atrasadas, 338
                        // linhas com honorário, R$ 6.601,57**.
                        //
                        // 🔑 O defeito do override aqui não é o valor: é a DURAÇÃO. Ele é permanente e o
                        // fato que descreve é temporário — o honorário está dentro do valor negociado no
                        // dia em que a parcela nasce, e volta a ser cobrado por fora no dia em que ela
                        // atrasa. Gravá-lo ao vincular põe o sistema para discordar do dado que a
                        // própria contabilidade mandou (§1.1).
                        //
                        // O efeito já é visível em produção, e é anterior a esta fatia: das 93 parcelas
                        // em que ela cobra honorário, **12 mostram R$ 0,00** — são as que o cálculo ao
                        // vivo zerou em 07/08 e nenhuma importação restaurou desde então (R$ 722,92). O
                        // número OSCILA: certo no dia do lote, zerado no vão entre lotes.
                        //
                        // Isto é fatia própria, e a pergunta dela é a regra primordial: o sistema grava
                        // o encargo que ela informa, sempre — em vez de decidir sozinho quando cobrar.
                        // Ver `cobranca-honorario-no-total.md` §10.8.
                        $this->obrigacaoRepository->salvar($existente, true);
                    }
                } elseif ($origem->getId() !== $acordo->getId()) {
                    // Já pertence a OUTRO acordo: reatribuir moveria dívida entre acordos em silêncio.
                    $divergencias[] = sprintf('%s: no sistema é parcela do acordo %s, não do %d — vínculo NÃO alterado.', $parcela->nn, (string) $origem->getId(), $aba->numero);
                }

                continue;
            }

            // A planilha diz que ESTA parcela já foi paga, e ela não existe no sistema. Criá-la abriria
            // uma dívida vencida, com juros e multa correndo, para cobrar de novo o que já foi pago — a
            // "direção que cobra", a mesma que o NN ambíguo logo abaixo recusa. Dar baixa também não é
            // opção: a liquidação está fora de escopo por decisão da spec (§5), é irreversível na prática
            // e não se escreve pagamento a partir de planilha. Então não cria, e reporta.
            if ($parcela->constaLiquidada) {
                $liquidadasIgnoradas[] = $parcela->nn;

                continue;
            }

            // O mesmo NN já existe no caso com OUTRA competência. A spec dá duas chaves para parcela
            // (§7 diz "por NN", §3.2 exige NN+competência) e aqui elas discordam. Criar é a direção
            // PERIGOSA — adicionaria dinheiro ao saldo a partir de um casamento duvidoso —, então a
            // importação recusa e devolve a decisão ao humano. Não criar só adia; criar errado cobra.
            // A consulta ao registro entra JUNTO com a do banco: uma parcela criada por uma aba anterior
            // desta mesma execução torna a desta aba ambígua exatamente como se já estivesse gravada — e
            // no dry-run o banco não a mostraria.
            if ($tocadas->temAlgumaComNn($caso, $parcela->nn)
                || $this->obrigacaoRepository->findOnePorReferenciaExternaNoCaso($caso, $parcela->nn) !== null) {
                $ambiguas[] = $parcela->nn;

                continue;
            }

            $criadas[] = $parcela->nn;
            $valor += $parcela->valorCentavos;

            $nova = null;
            if ($usuario !== null) {
                $nova = $this->registrarObrigacao->executar($this->parcelaInput($caso, $parcela), $tenant, $usuario);
                $nova->setAcordoOrigem($acordo);
                $this->obrigacaoRepository->salvar($nova, true);
            }

            // Registrar junto da escrita: é o que faz a reconciliação seguinte (e a de outra aba do
            // mesmo caso) enxergar esta parcela no dry-run, onde o banco não muda. Sem isto a prévia
            // prometeria "reconstruir" uma conta que a confirmação recusaria por INV-I.
            //
            // O número do acordo e o valor vão junto: é com eles que a porta B da INV-I confere a prova
            // da coluna F contra o que ESTA execução acabou de criar. O valor sai da PLANILHA, e não da
            // entidade, porque no dry-run entidade não há — e os dois modos precisam somar o mesmo
            // principal (§6).
            $tocadas->registrarCriada($caso, $parcela->nn, $parcela->competencia, 'parcela', $acordo, $parcela->valorCentavos, $nova);
        }

        return [$criadas, $existentes, $ambiguas, $divergencias, $valor, $vinculadas, $liquidadasIgnoradas];
    }

    /**
     * §3.2 — **a correção**. Para cada conta original da planilha: se existe e está aberta, marca com
     * `acordoSubstituto` (sai do saldo); se já está marcada, no-op; se não existe, reconstrói já
     * substituída (§3.2.1).
     *
     * @param ObrigacoesTocadasNaImportacao $tocadas registro do que ESTA execução já criou ou mutou
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<string>, 4: list<string>, 5: list<string>, 6: int}
     */
    private function reconciliarContasOriginais(
        AcordoDetalhadoImportavel $aba,
        Acordo $acordo,
        CasoCobranca $caso,
        Tenant $tenant,
        ?User $usuario,
        ConfigEncargos $configCaso,
        ObrigacoesTocadasNaImportacao $tocadas,
    ): array {
        $marcadas = [];
        $reconstruidas = [];
        $jaMarcadas = [];
        $centavosSemBoleto = 0;
        $recusadas = [];
        $semCompetencia = [];
        $divergencias = [];
        $principal = 0;

        foreach ($aba->contasOriginais as $conta) {
            // O que ESTA execução já tocou conta como se o banco já refletisse — senão a prévia e a
            // confirmação divergem: no dry-run o banco nunca muda, então a mesma obrigação vista de novo
            // (outra aba do MESMO caso — dois acordos da mesma unidade compartilham o caso, ou a planilha
            // repete a linha) seria tratada como intocada e o efeito contado duas vezes.
            //
            // ⚠️ Inclui MUTAÇÃO, não só criação: marcar sem registrar fazia a prévia somar o mesmo
            // principal duas vezes — e `principalReconciliadoCentavos` é exatamente o número que §1/§8
            // mandam o operador conferir antes de mandar gravar.
            $jaTocada = $tocadas->tipoDoTrio($caso, $conta->nn, $conta->competencia);

            // A CHAVE. Nunca `findOnePorReferenciaExternaNoCaso` — casar só pelo NN aqui marcaria dívida
            // de outra competência (e de outro devedor) como substituída, apagando cobrança legítima.
            $obrigacao = $jaTocada === null
                ? $this->obrigacaoRepository->findOnePorReferenciaECompetenciaNoCaso($caso, $conta->nn, $conta->competencia)
                : null;

            // A busca resolveu para uma LINHA já tocada por outro trio. Só o fallback do legado faz isso:
            // uma obrigação sem competência casa com QUALQUER competência da planilha, então `X|01/2026` e
            // `X|02/2026` resolvem para a mesma obrigação. Sem esta consulta a prévia somaria o principal
            // dela duas vezes — e é justamente o caminho que produção terá, e que o replay não exercitou.
            if ($obrigacao !== null) {
                $jaTocada = $tocadas->tipoDaObrigacao($obrigacao);
            }

            if ($jaTocada !== null) {
                // PORTA B da INV-I — a obrigação virou parcela de um acordo nesta MESMA execução (a aba
                // dele veio antes neste arquivo). É a porta que produziu 285 das 286 recusas da
                // importação do zero. A prova da coluna F vale aqui exatamente como na porta A: se a
                // planilha declara que esta conta é parcela do acordo que ESTA execução acabou de
                // registrar como origem dela, a substituição é a renegociação em cadeia que a contábil
                // documenta — e não o "acordo sobre acordo" cego que a INV-I existe para barrar.
                $procedencia = $tocadas->procedenciaDoTrio($caso, $conta->nn, $conta->competencia)
                    ?? ($obrigacao !== null ? $tocadas->procedenciaDaObrigacao($obrigacao) : null);

                // ⚠️ A vigência é LIDA da entidade guardada, não suposta. A tentação é afirmar que
                // `completarParcelas` só roda para aba vigente — é verdade no instante da criação, mas
                // uma aba SEGUINTE do mesmo lote pode desativar aquele acordo antes de a aba do sucessor
                // chegar. Aí a regra 4 do `motivoSemProva`, que existe justamente para fechar a brecha de
                // ordem, ficaria desligada e a dobra do §2.1 voltaria pela porta B.
                $origemNaExecucao = $procedencia['acordoOrigem'] ?? null;
                $semProva = $this->motivoSemProva(
                    $conta,
                    $origemNaExecucao?->getNumeroExterno(),
                    $aba->numero,
                    $this->origemAindaVigente($origemNaExecucao, $tocadas),
                );

                if ($semProva === null && ($jaTocada === 'parcela' || $jaTocada === 'parcela-vinculada')) {
                    $alvo = $procedencia['obrigacao'] ?? null;
                    // O principal sai do acumulador, NUNCA da entidade: no dry-run a parcela criada por
                    // uma aba anterior não existe no banco, e prévia e confirmação têm de somar o mesmo
                    // número (§6). O valor guardado veio da planilha e é idêntico nos dois modos.
                    $valorContado = $procedencia['valor'] ?? $conta->valorCentavos;

                    // A MESMA guarda da porta A, e ela não é decorativa aqui: `completarParcelas` vincula
                    // olhando só `acordoOrigem === null`, sem olhar o substituto — então uma obrigação já
                    // substituída por um acordo vigente pode chegar aqui como `parcela-vinculada`. Sem
                    // isto, o `setAcordoSubstituto` abaixo trocaria esse vínculo em SILÊNCIO: o principal
                    // seria somado como "sai do saldo" para dívida que já estava fora, e a única memória
                    // de quem a substituiu antes se perderia (o rompimento daquele acordo deixaria de
                    // devolvê-la). Recusar aqui é o que mantém as duas portas simétricas.
                    $substitutoAtual = $alvo?->getAcordoSubstituto();
                    if ($substitutoAtual !== null) {
                        if ($substitutoAtual->getId() === $acordo->getId()) {
                            $jaMarcadas[] = $conta->nn; // idempotência (§7)
                            $centavosSemBoleto += ReferenciaSubstituta::ehSubstituta($conta->nn) ? $conta->valorCentavos : 0;

                            continue;
                        }

                        $recusadas[] = sprintf('%s: já substituída pelo acordo %s (situação %s) — não remarcada.', $conta->nn, $this->identificaAcordo($substitutoAtual), $substitutoAtual->getStatus()->value);

                        continue;
                    }

                    $marcadas[] = $conta->nn;
                    $centavosSemBoleto += ReferenciaSubstituta::ehSubstituta($conta->nn) ? $conta->valorCentavos : 0;
                    $principal += $valorContado;

                    if ($alvo !== null) {
                        $tocadas->registrarMutada($alvo, $caso, $conta->nn, $conta->competencia, 'marcada', $origemNaExecucao, $valorContado);
                        // ⚠️ `$usuario !== null` é o que separa prever() de confirmar(), e a falta dele
                        // aqui não era "a prévia grava um pouco": `$alvo` é uma obrigação REAL sempre que
                        // ela já existia no banco, e o flush levava junto o acordo novo, que na prévia
                        // nunca foi persistido — a projeção morria com "new entity was found through the
                        // relationship". A prévia não escreve NADA.
                        if ($usuario !== null) {
                            $this->materializarNaDataDoAcordo($alvo, $acordo, $configCaso);
                            $alvo->setAcordoSubstituto($acordo);
                            $this->obrigacaoRepository->salvar($alvo, true);
                        }
                    } else {
                        $tocadas->registrarCriada($caso, $conta->nn, $conta->competencia, 'marcada', $origemNaExecucao, $valorContado);
                    }

                    continue;
                }

                $recusadas[] = match ($jaTocada) {
                    'parcela' => sprintf('%s: esta MESMA importação a criou como parcela do acordo %d — não é dívida original, não marcada (INV-I)%s.', $conta->nn, $origemNaExecucao?->getNumeroExterno() ?? $aba->numero, (string) $semProva),
                    'parcela-vinculada' => sprintf('%s: esta MESMA importação a ligou como parcela de acordo — não é dívida original, não marcada (INV-I)%s.', $conta->nn, (string) $semProva),
                    'marcada' => sprintf('%s: esta MESMA importação já marcou esta obrigação como substituída — não remarcada (confira: a planilha pode estar listando a mesma dívida em duas competências).', $conta->nn),
                    default => sprintf('%s: esta MESMA importação já a reconstruiu — linha repetida na planilha, ignorada.', $conta->nn),
                };

                continue;
            }

            if ($obrigacao === null) {
                $tocadas->registrarCriada($caso, $conta->nn, $conta->competencia, 'conta');
                $reconstruidas[] = $conta->nn;
                $centavosSemBoleto += ReferenciaSubstituta::ehSubstituta($conta->nn) ? $conta->valorCentavos : 0;
                if ($usuario !== null) {
                    $this->reconstruirContaOriginal($conta, $aba, $acordo, $caso, $tenant, $usuario, $configCaso);
                }

                continue;
            }

            // Casou pelo fallback do legado (obrigação anterior ao backfill, sem competência gravada):
            // na prática casou só pelo NN. Não é errado — dentro de UM caso o NN não se repete no dado
            // real —, mas é exatamente o casamento que a spec manda desconfiar. Reportar, nunca silenciar.
            if ($obrigacao->getCompetencia() === null) {
                $semCompetencia[] = $conta->nn;
            }

            $divergencia = $this->divergenciaDeValor($conta->nn, $obrigacao, $conta->valorCentavos);
            if ($divergencia !== null) {
                $divergencias[] = $divergencia;
            }

            // PORTA A da INV-I (ajuste 9) — a obrigação já estava no banco como parcela de outro acordo.
            //
            // A regra de 2026-07 era "nunca": marcar a parcela de outro acordo cria o estado "acordo
            // sobre acordo" e, no dia em que o acordo de origem for rompido, a original que ELE
            // substituiu volta ao exigível E esta parcela continua nele — a dívida conta duas vezes.
            //
            // O que mudou (spec `cobranca-acordo-assume-parcelas-do-anterior.md`): renegociar parcela de
            // acordo é a operação NORMAL da contábil, e ela documenta cada uma na coluna F ("Acordo 163 -
            // Parcela 4/12"). Com a prova, a recusa cega passava a CAUSAR a dobra que existia para
            // impedir — medido: 302 parcelas / R$ 67.469,44 no saldo ao lado das parcelas novas que as
            // substituem. Sem a prova, a recusa continua exatamente como era.
            //
            // O vetor do rompimento continua fechado, agora pela INV-L: `RomperAcordoUseCase` e
            // `CancelarAcordoUseCase` recusam desfazer acordo cujas parcelas outro acordo vigente
            // renegociou, e `motivoParaNaoDesativar` faz o mesmo no caminho da importação.
            $origem = $obrigacao->getAcordoOrigem();
            $semProvaNoBanco = $origem === null
                ? null
                : $this->motivoSemProva($conta, $origem->getNumeroExterno(), $aba->numero, $this->origemAindaVigente($origem, $tocadas));

            if ($origem !== null && $semProvaNoBanco !== null) {
                // O número EXTERNO, não o `id` interno: é o número que a contábil usa e o único que quem
                // confere contra a planilha consegue procurar. (Antes imprimia o id, que não existe em
                // fonte nenhuma.)
                $recusadas[] = sprintf(
                    '%s: no sistema é parcela do acordo %s, não dívida original — não marcada (INV-I)%s.',
                    $conta->nn,
                    $this->identificaAcordo($origem),
                    $semProvaNoBanco,
                );

                continue;
            }

            $substituto = $obrigacao->getAcordoSubstituto();
            if ($substituto !== null) {
                if ($substituto->getId() === $acordo->getId()) {
                    $jaMarcadas[] = $conta->nn; // idempotência (§7)
                    $centavosSemBoleto += ReferenciaSubstituta::ehSubstituta($conta->nn) ? $conta->valorCentavos : 0;

                    continue;
                }

                $recusadas[] = sprintf('%s: já substituída pelo acordo %s (situação %s) — não remarcada.', $conta->nn, $this->identificaAcordo($substituto), $substituto->getStatus()->value);

                continue;
            }

            $marcadas[] = $conta->nn;
            $centavosSemBoleto += ReferenciaSubstituta::ehSubstituta($conta->nn) ? $conta->valorCentavos : 0;
            $principal += $obrigacao->getValorOriginal();
            // O número do acordo de origem vai junto quando esta é uma parcela renegociada (porta A
            // aceita): é o que permite à guarda de desativação saber, ainda dentro desta execução, que
            // aquele acordo passou a ter parcela renegociada por um acordo vigente.
            $tocadas->registrarMutada($obrigacao, $caso, $conta->nn, $conta->competencia, 'marcada', $origem, $obrigacao->getValorOriginal());

            if ($usuario !== null) {
                $this->materializarNaDataDoAcordo($obrigacao, $acordo, $configCaso);
                $obrigacao->setAcordoSubstituto($acordo);
                $this->obrigacaoRepository->salvar($obrigacao, true);
            }
        }

        return [$marcadas, $reconstruidas, $jaMarcadas, $recusadas, $semCompetencia, $divergencias, $principal, $centavosSemBoleto];
    }

    /**
     * Como um acordo é identificado numa linha de relatório: pelo **número externo**, que é o número que
     * a contábil usa e o único que quem confere contra a planilha consegue procurar. O `id` interno só
     * aparece quando não há número externo — e aí, rotulado, para ninguém procurá-lo na planilha.
     */
    private function identificaAcordo(Acordo $acordo): string
    {
        $externo = $acordo->getNumeroExterno();

        return $externo !== null ? (string) $externo : sprintf('sem número externo (id %s)', (string) $acordo->getId());
    }

    /**
     * O acordo de origem continua vigente? Pela DECISÃO desta execução quando alguma aba o tocou, e pela
     * entidade quando nenhuma tocou.
     *
     * A ordem não é detalhe: `aplicarSobrescrita` grava o status só na confirmação, então perguntar
     * direto à entidade faria a porta da INV-I responder uma coisa na prévia e outra na confirmação —
     * a divergência que a §6 da spec-mãe proíbe. Um acordo que esta execução não tocou tem o mesmo
     * status nos dois modos, e aí a entidade é a fonte certa.
     */
    private function origemAindaVigente(?Acordo $origem, ObrigacoesTocadasNaImportacao $tocadas): bool
    {
        if ($origem === null) {
            return false;
        }

        $status = $tocadas->statusDecidido($origem->getNumeroExterno()) ?? $origem->getStatus();

        return $status->ehVigente();
    }

    /**
     * A prova documental que autoriza um acordo a substituir a PARCELA de outro (spec
     * `cobranca-acordo-assume-parcelas-do-anterior.md`). Nas duas portas da INV-I a pergunta é a mesma:
     *
     * > a coluna F desta conta declara que ela é parcela **exatamente** do acordo que o sistema já
     * > registrou como origem dela?
     *
     * Quatro recusas, e cada uma tem um motivo próprio:
     *
     * 1. **sem declaração** (coluna F em `-`, vazia ou com texto fora do padrão) — é o caso da dívida
     *    original comum; nada mudou para ela;
     * 2. **declara OUTRO acordo** — a planilha e o sistema discordam sobre a procedência da mesma
     *    dívida. Aceitar seria escolher em silêncio qual das duas fontes vale, mexendo em dinheiro.
     *    Medido no dado real de 04/08: **zero** ocorrências — mas é justamente por ser inesperado que
     *    precisa cair na recusa, e não passar batido;
     * 3. **declara o PRÓPRIO acordo da aba** — o acordo substituiria uma parcela dele mesmo, e a
     *    obrigação ficaria com `acordoOrigem` e `acordoSubstituto` apontando para a mesma linha. Nenhuma
     *    query de exigibilidade sabe responder a isso; é estado sem significado, e nasce de planilha
     *    malformada ou de NN repetido nas duas seções da mesma aba;
     * 4. **o acordo de origem NÃO está vigente** — herdada do enunciado original da INV-I ("parcela de
     *    acordo rompido/cancelado é histórico e nem sequer é exigível"), e é ela que fecha a brecha de
     *    ORDEM. Sem ela: a aba do acordo velho vem primeiro dizendo "Cancelado", a desativação passa
     *    (nada foi marcado ainda, a guarda não vê nada), as originais dele voltam ao saldo — e depois a
     *    aba do sucessor marcaria as parcelas dele, deixando as duas coisas no saldo ao mesmo tempo. Com
     *    ela, os dois sentidos ficam fechados: sucessor primeiro → `motivoParaNaoDesativar` barra a
     *    desativação; velho primeiro → esta condição barra a marcação. Nada legítimo se perde: nos 302
     *    casos medidos no dado real, o acordo de origem está `ativo` em 302.
     *
     * Devolve `null` quando a prova vale — e, quando não vale, **o motivo exato**, que vai para o bloco
     * "Linhas recusadas" do relatório. Um motivo genérico aqui seria pior que nenhum: quem confere a
     * planilha precisa saber se o que falta é a declaração, se as duas fontes discordam, ou se o
     * problema é o estado do acordo antigo — são três investigações diferentes.
     */
    private function motivoSemProva(ContaOriginalImportavel $conta, ?int $numeroExternoDaOrigem, int $numeroDaAba, bool $origemVigente): ?string
    {
        $declarado = $conta->acordoOrigemDeclarado;

        if ($declarado === null) {
            return ' — a coluna "Detalhamento" da planilha não declara de qual acordo ela é parcela';
        }

        $declaracao = sprintf('"Acordo %d - Parcela %s"', $declarado, (string) $conta->parcelaOrigemDeclarada);

        if ($numeroExternoDaOrigem === null) {
            return sprintf(' — a planilha declara %s, mas o acordo de origem no sistema não tem número externo para conferir', $declaracao);
        }

        // ⚠️ A discordância entre as fontes é testada ANTES da autorreferência, e a ordem importa para
        // quem confere. Quando a coluna F declara o número DESTA aba mas o sistema registra outro acordo
        // como origem, as duas coisas são verdade ao mesmo tempo — e a investigação que resolve é
        // "planilha e sistema discordam", não "o acordo apontou para si mesmo".
        if ($declarado !== $numeroExternoDaOrigem) {
            return sprintf(' — a planilha declara %s, e no sistema ela é parcela do acordo %d: as duas fontes discordam da procedência', $declaracao, $numeroExternoDaOrigem);
        }

        if ($declarado === $numeroDaAba) {
            return sprintf(' — a planilha declara %s, ou seja, o PRÓPRIO acordo desta aba: um acordo não substitui parcela de si mesmo', $declaracao);
        }

        if (!$origemVigente) {
            return sprintf(' — a planilha declara %s, mas esse acordo não está mais vigente no sistema: as parcelas dele já estão fora do saldo', $declaracao);
        }

        return null;
    }

    /**
     * §3.2.1 — a conta original que NUNCA foi importada (virou acordo na contábil antes de qualquer
     * importação passar por ela) é criada JÁ substituída: nasce fora do saldo e nunca entra nele.
     *
     * ⚠️ **Assimetria deliberada:** a parcela recusa ser criada quando o mesmo NN já existe no caso com
     * outra competência (`completarParcelas`); a conta original **não** faz esse teste. É intencional e
     * está testado (`testNaoMarcaDividaDeOutraCompetenciaComMesmoNn`): a reconstruída nasce JÁ
     * substituída, fora do saldo, então criá-la a mais não cobra ninguém hoje — enquanto não criar a
     * parcela evita adicionar dinheiro ao exigível. O risco residual aparece no dia do rompimento, em que
     * a linha reconstruída volta a ser cobrável; é o risco aceito do §3.2.1, registrado aqui para quem
     * revisar não achar que a diferença entre os dois caminhos é descuido.
     *
     * A **marcação de procedência** é obrigatória e vai na descrição — a `Obrigacao` não tem campo de
     * observação, e o importador de inadimplência já usa a mesma convenção (`descrição | observação`).
     * Sem ela ninguém consegue, depois, distinguir o que foi boleto importado de verdade do que foi
     * reconstruído a partir de documento — e é exatamente essa distinção que alguém vai precisar no dia
     * em que o acordo for rompido e estes valores voltarem para a cobrança.
     */
    private function reconstruirContaOriginal(
        ContaOriginalImportavel $conta,
        AcordoDetalhadoImportavel $aba,
        Acordo $acordo,
        CasoCobranca $caso,
        Tenant $tenant,
        User $usuario,
        ConfigEncargos $configCaso,
    ): void {
        $procedencia = sprintf(
            self::MARCA_RECONSTRUIDA . ' (emissão %s)',
            $aba->emissao?->format('d/m/Y') ?? 'sem data',
        );

        $input = new RegistrarObrigacaoInput();
        $input->casoId = $caso->getId();
        $input->descricao = $this->descricaoComProcedencia($conta->descricao(), $procedencia);
        $input->valorOriginal = $conta->valorCentavos;
        $input->vencimentoOriginal = $conta->vencimento;
        $input->referenciaExterna = $conta->nn;
        $input->competencia = $conta->competencia;
        // ⚠️ SEM override de honorário aqui, e isso é DELIBERADO (spec §10, corrigido em 19/08).
        //
        // A primeira versão desta fatia punha `honorariosBp = 0` nesta linha, com o argumento de que
        // `montarContaOriginal` SOMA todas as linhas do NN e o honorário já estaria dentro. A medição
        // em produção derrubou o argumento: das 3.482 contas reconstruídas, só **27** têm linha
        // `1.15 - Honorário advocatício` dentro do grupo. Nas outras 3.455 o honorário está FORA do
        // valor, e zerá-lo apagaria R$ 102.126,32 de honorário que a carteira cobra legitimamente.
        //
        // 🔑 A conta reconstruída NÃO é parcela de acordo — é a dívida VELHA que o acordo engoliu, e
        // nessa a carteira cobra honorário normalmente. A produção já segue essa regra em 3.473
        // dívidas velhas.
        //
        // ⛔ **E o guard também NÃO vai em `completarParcelas`** — esta frase estava aqui e mandava o
        // contrário do que a linha ~658 daquele método diz hoje. O guard chegou a existir lá e foi
        // REVERTIDO em `cc1892c1`, porque a premissa que o autorizava caiu: parcela de acordo atrasada
        // migra para o relatório de inadimplência e lá a contabilidade **cobra** encargo (spec §10.8).
        // ⚠️ Nenhum importador grava o override ao VINCULAR uma obrigação que já existe. Não confunda
        // com a parcela CRIADA aqui (`parcelaInput`, ~290 linhas abaixo), que grava `honorariosBp = 0`
        // desde julho — como fazem `ImportarReceitasUseCase` e `ImportarRelatorioCarteiraUseCase`. É
        // esse override de nascença que a §10.8 identificou como defeito de DURAÇÃO, e ele é assunto da
        // fatia própria, não desta. Quem tira honorário de parcela já gravada é o comando
        // `app:cobranca:reconciliar-honorario-parcela`, com lista informada por humano.

        $nova = $this->registrarObrigacao->executar($input, $tenant, $usuario);

        $this->materializarNaDataDoAcordo($nova, $acordo, $configCaso);
        $nova->setAcordoSubstituto($acordo);
        $this->obrigacaoRepository->salvar($nova, true);
    }

    /**
     * `descrição | procedência` em no máximo 255 chars, cortando a DESCRIÇÃO e nunca a procedência.
     *
     * A ordem segue a convenção do importador de inadimplência (`descrição | observação`), mas cortar o
     * texto inteiro pelo fim, como antes, fazia a procedência ser a primeira a sumir — justo o campo que
     * existe para alguém, um dia, distinguir a conta reconstruída da conta importada de verdade. Cortar
     * a descrição preserva a convenção E a informação que só existe aqui.
     */
    private function descricaoComProcedencia(string $descricao, string $procedencia): string
    {
        $sufixo = ' | ' . $procedencia;
        $espaco = 255 - mb_strlen($sufixo);
        if ($espaco <= 0) {
            return mb_substr($procedencia, 0, 255);
        }

        return mb_substr($descricao, 0, $espaco) . $sufixo;
    }

    /**
     * Ao ser substituída, o relógio da obrigação PARA na data do acordo — o mesmo que o
     * `CriarAcordoUseCase` faz quando um acordo nasce pela tela. Materializa (não congela): a obrigação
     * sai do exigível e deixa de ser hidratada ao vivo, então este snapshot é o número que a tela passa a
     * exibir. Sem ele a linha ficaria mostrando o cache da última vez que alguém abriu a tela — uma data
     * arbitrária — em vez do valor que estava valendo quando a dívida foi renegociada.
     *
     * Não altera o SALDO em nenhum dos dois casos: substituída por acordo vigente está fora do exigível.
     */
    private function materializarNaDataDoAcordo(Obrigacao $obrigacao, Acordo $acordo, ConfigEncargos $configCaso): void
    {
        if ($obrigacao->encargosCongelados()) {
            return; // já Liquidada/legado: mantém o snapshot que tinha
        }

        // 🔑 ACORDO SEM DATA NÃO MATERIALIZA (decisão do dono, 17/08). A data do acordo É a referência do
        // cálculo; sem ela não há o que calcular, e inventar uma (era `dataAcordoPadrao()`) é justamente a
        // violação #3 que esta frente removeu.
        //
        // A obrigação fica com `encargosNaoCalculados() === true` e a tela mostra "— ⚠ acordo sem data",
        // em vez de um número. ⚠️ Note que ela NÃO é congelada e NÃO é re-hidratada (a query do exigível a
        // exclui): as colunas `juros/multa/...` seguem com o resto da última hidratação ao vivo, de uma
        // data arbitrária. É por isso que o estado precisa ser distinguível no MODELO e a tela precisa
        // ignorar aquelas colunas — mostrá-las trocaria a data inventada no importe por um número velho na
        // tela, que também não é espelho.
        //
        // Quando a data chegar pelo relatório de acordos, `processarAba` materializa estas obrigações (6ª
        // violação) e o traço vira número.
        $dataAcordo = $acordo->getDataAcordo();
        if ($dataAcordo === null) {
            return;
        }

        $config = $this->resolvedorConfig->aplicarObrigacao($configCaso, $obrigacao);
        $encargos = $this->calculadora->calcular(
            $obrigacao->getValorOriginal(),
            $obrigacao->getVencimentoOriginal(),
            $config,
            $dataAcordo,
        );

        $obrigacao->definirEncargos($encargos['juros'], $encargos['multa'], $encargos['correcao'], $encargos['honorarios'], $dataAcordo);
    }

    /**
     * §5.3 — as recusas do caminho manual, replicadas no importe. Devolve o MOTIVO (com o que fazer) ou
     * `null` quando a desativação pode ser aplicada.
     *
     * `CancelarAcordoUseCase` tem DUAS recusas duras, e o importe replica as duas. Uma versão anterior
     * desta frente replicou só a primeira e afirmou, na spec e no commit, que "some a única contradição
     * com o caminho manual" — não somia: sobrava esta segunda, e a afirmação era falsa (achado da 1ª
     * revisão).
     *
     * ⚠️ Diferença deliberada em relação ao manual: **nunca lança**. Uma exceção derrubaria o lote
     * inteiro por causa de uma aba; aqui vira aviso acionável, com a saída indicada.
     */
    private function motivoParaNaoDesativar(Acordo $acordo, bool $temParcelaPaga, int $numeroDaAba, ObrigacoesTocadasNaImportacao $tocadas): ?string
    {
        // ⚠️ ACUMULA as duas, nunca retorna na primeira. Uma versão anterior reportava só o primeiro
        // impedimento, e o aviso dizia "exclua o recebimento e importe de novo" — o operador apagaria um
        // pagamento real (irreversível na prática) e a reimportação **continuaria não cancelando**, agora
        // pela segunda recusa. Aviso que manda destruir dado sem resolver o problema é pior que aviso
        // nenhum (achado da 2ª revisão).
        $motivos = [];

        // (1) PARCELA PAGA — a decisão do dono de 04/08. Cancelar tira as parcelas do exigível levando a
        // alocação junto: o dinheiro recebido para de abater o saldo e o devedor volta a ser cobrado por
        // algo que pagou. Espelha `CancelarAcordoUseCase::recusarSeAlgumaParcelaFoiPaga`.
        if ($temParcelaPaga) {
            $motivos[] = 'há PARCELA PAGA no sistema (cancelar faria o dinheiro já recebido parar de abater o saldo) — '
                . 'exclua o recebimento da parcela (tela do objeto → Movimentos → Excluir)';
        }

        // (2) PARCELAS RENEGOCIADAS por outro acordo VIGENTE — espelha
        // `AcordoComParcelasRenegociadasException`. Desativar aqui conta a MESMA dívida duas vezes no
        // saldo: as originais deste acordo voltam ao exigível (`asub` deixa de ser vigente) E as parcelas
        // do acordo novo continuam exigíveis (`aorig` segue vigente) — ver `doCasoExigiveis`.
        //
        // 🔑 Deixou de ser "só dado legado" em 08/2026: com a prova da coluna F o importador passou a
        // CRIAR este estado (spec `cobranca-acordo-assume-parcelas-do-anterior.md`), e esta guarda virou
        // a proteção principal contra o dano do §2.1 do ajuste 9 — não mais um alarme de acervo antigo.
        //
        // ⚠️ Por isso a pergunta não pode ser só ao banco. Numa importação em que a aba do acordo VELHO
        // vem ANTES da aba do sucessor, a query não enxerga a renegociação que esta mesma execução vai
        // fazer daqui a pouco, e a desativação passaria — o resultado dependeria da ordem das abas
        // dentro do arquivo. O acumulador responde pelo que já foi marcado nesta execução.
        $renegociadaNesteLote = in_array($numeroDaAba, $tocadas->acordosComParcelaRenegociadaNaExecucao(), true);

        if ($renegociadaNesteLote || $this->obrigacaoRepository->parcelasRenegociadasPorAcordoVigente($acordo) !== []) {
            $motivos[] = 'as parcelas dele foram renegociadas por outro acordo VIGENTE (cancelar contaria a mesma '
                . 'dívida duas vezes no saldo) — resolva os dois acordos à mão';
        }

        if ($motivos === []) {
            return null;
        }

        // Com os dois impedimentos, quem for agir precisa ver os dois: resolver um só não destrava nada.
        return count($motivos) === 1
            ? $motivos[0]
            : 'DOIS impedimentos, e resolver só um não destrava: (1) ' . $motivos[0] . '; (2) ' . $motivos[1];
    }

    /**
     * Quais acordos do lote têm ao menos uma PARCELA PAGA — a foto que barra a desativação (§5.3).
     *
     * Medido ANTES do laço e sobre o banco intocado, pela mesma razão do impacto da reativação: prévia e
     * confirmação têm de decidir pelo mesmo valor (§6). Aqui a foto é estável de qualquer modo — este
     * importador não cria alocação (a baixa de pagamento está fora de escopo, §5) —, mas depender disso
     * seria depender de uma propriedade que a próxima entrega pode remover sem perceber.
     *
     * A régua é EXISTIR alocação, não ser positiva: é a mesma de `existeAlocacaoEmObrigacoes`, que o
     * `CancelarAcordoUseCase` usa para responder a esta pergunta no caminho manual. Alocação de valor
     * zero é uma linha de pagamento real, com histórico — e a etapa 2 criou 10 delas.
     *
     * @return array<int, bool> número externo do acordo => tem parcela paga
     */
    private function mapearParcelasPagas(Carteira $carteira, ResultadoLeituraAcordos $leitura, Tenant $tenant): array
    {
        $mapa = [];
        foreach ($leitura->acordos as $aba) {
            if (array_key_exists($aba->numero, $mapa)) {
                continue;
            }

            $acordo = $this->acordoRepository->findOnePorNumeroExternoNaCarteira($aba->numero, $carteira, $tenant);
            if ($acordo === null) {
                $mapa[$aba->numero] = false;

                continue;
            }

            // QUERY, e não `Acordo::getParcelas()`: coleção inversa nasce vazia na mesma unidade de
            // trabalho, e isto decide se um cancelamento que mexe em dinheiro acontece ou não.
            $ids = [];
            foreach ($this->obrigacaoRepository->parcelasDoAcordo($acordo, $tenant) as $parcela) {
                $id = $parcela->getId();
                if ($id !== null) {
                    $ids[] = $id;
                }
            }

            $mapa[$aba->numero] = $ids !== [] && $this->alocacaoRepository->existeAlocacaoEmObrigacoes($ids, $tenant);
        }

        return $mapa;
    }

    /**
     * §3/§4 — a situação do acordo, decidida sem escrever nada.
     *
     * **Este importador passou a escrever o status** (decisão do dono, 04/08: *"o importe sempre
     * sobrescreve o sistema"*). A recusa anterior tinha dois fundamentos e os dois caíram: o primeiro
     * (*"`Em andamento` é a única situação da fonte, escrever seria no-op"*) era verdade só porque o
     * export manual saía filtrado — baixando pela API, `Liquidado` é a MAIORIA do dado (259 contra 66
     * na TOP LIFE 1); o segundo (*"status é decisão manual do escritório"*) foi decidido contra.
     *
     * Devolve `null` quando nada muda: situação não mapeada (vira aviso, status intocado) ou situação
     * que já bate com o sistema (idempotência — a segunda execução não reescreve nem registra evento).
     *
     * @param list<string> $situacoesDesconhecidas acumulador do lote
     */
    private function resolverSobrescrita(AcordoDetalhadoImportavel $aba, Acordo $acordo, array &$situacoesDesconhecidas): ?SobrescritaDeSituacao
    {
        $mapeada = self::SITUACOES[$this->semAcento($aba->situacao)] ?? null;

        if ($mapeada === null) {
            // Nunca adivinhar: situação desconhecida deixa o status como está e vira linha de aviso.
            $situacoesDesconhecidas[] = sprintf('Acordo %d: situação "%s" não reconhecida — status mantido em %s.', $aba->numero, $aba->situacao, $acordo->getStatus()->value);

            return null;
        }

        if ($acordo->getStatus() === $mapeada) {
            return null;
        }

        return new SobrescritaDeSituacao($aba->numero, $aba->situacao, $acordo->getStatus(), $mapeada);
    }

    /**
     * Escreve o status novo. **Só na confirmação** — a prévia nunca chega aqui, porque o `Acordo` é
     * entidade *managed* e um `setStatus` no dry-run sujaria a UnitOfWork: um flush posterior gravaria
     * exatamente a mudança que a prévia prometeu não fazer.
     *
     * Não passa por `MarcarAcordoCumpridoUseCase` / `RomperAcordoUseCase` / `CancelarAcordoUseCase`: os
     * três exigem `estaAtivo()` e lançam `AcordoNaoAtivoException` fora disso — recusariam justamente as
     * transições desta spec (`cumprido → ativo`, `cancelado → ativo`). A escrita é direta na entidade,
     * como o `ImportarReceitasUseCase::reativarPorImportacao` já faz pela mesma razão.
     */
    private function aplicarSobrescrita(Acordo $acordo, CasoCobranca $caso, SobrescritaDeSituacao $sobrescrita, Tenant $tenant, User $usuario): void
    {
        // §5.2 — vigente → não vigente devolve as originais ao exigível. Sem o descongelamento elas
        // voltam ao saldo COM OS JUROS PARADOS: é o defeito que o dono reportou e que a frente
        // `cobranca-cancelar-acordo` corrigiu. Lido ANTES da troca de status, porque `substituidasDe`
        // depende do vínculo e o restaurador precisa do conjunto de antes.
        $substituidas = $sobrescrita->desativa() ? $this->restaurador->substituidasDe($acordo, $tenant) : [];

        $acordo->setStatus($sobrescrita->novo);

        // Motivo de rompimento/cancelamento pertence ao estado que acabou de sair. Mantê-los faria a
        // tela mostrar "rompido por X" num acordo ativo. Mesma limpeza do `reativarPorImportacao`.
        if ($sobrescrita->novo->ehVigente()) {
            $acordo->setMotivoRompimento(null);
            $acordo->setMotivoCancelamento(null);
        }

        $this->acordoRepository->salvar($acordo);

        if ($substituidas !== []) {
            $this->restaurador->restaurar($substituidas);
        }

        // Mudança de status move dinheiro; sem a linha no histórico ninguém descobre depois por que o
        // estado mudou. Sem flush: quem fecha a transação é o `wrapInTransaction` da confirmação.
        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::AcordoEditado,
            $usuario,
            $sobrescrita->descricao(),
            [
                'acordoId' => $acordo->getId(),
                'numeroExterno' => $sobrescrita->numero,
                'situacaoDaPlanilha' => $sobrescrita->situacaoDaPlanilha,
                'statusAnterior' => $sobrescrita->anterior->value,
                'statusNovo' => $sobrescrita->novo->value,
                'origem' => 'importacao_acordos_detalhados',
            ],
        );
    }

    /** §4: a planilha não é autoridade sobre dinheiro já lançado — diverge, reporta, não aplica. */
    private function divergenciaDeValor(string $nn, Obrigacao $obrigacao, int $valorPlanilhaCentavos): ?string
    {
        if ($obrigacao->getValorOriginal() === $valorPlanilhaCentavos) {
            return null;
        }

        return sprintf(
            '%s: sistema R$ %s, planilha R$ %s — o valor lançado NÃO foi alterado.',
            $nn,
            number_format($obrigacao->getValorOriginal() / 100, 2, ',', '.'),
            number_format($valorPlanilhaCentavos / 100, 2, ',', '.'),
        );
    }

    private function parcelaInput(CasoCobranca $caso, ParcelaAcordoImportavel $parcela): RegistrarObrigacaoInput
    {
        $input = new RegistrarObrigacaoInput();
        $input->casoId = $caso->getId();
        $input->descricao = mb_substr($parcela->descricao(), 0, 255);
        // O principal NEGOCIADO é a soma da coluna "Valor acordado" do NN — o honorário do relatório já
        // está embutido nela (mesma decisão do importador de inadimplência, §3.2.3 da spec irmã).
        $input->valorOriginal = $parcela->valorCentavos;
        $input->vencimentoOriginal = $parcela->vencimento;
        $input->referenciaExterna = $parcela->nn;
        $input->competencia = $parcela->competencia;
        // Honorários ZERO por TAXA (§3.1, decisão #8: acordo não cobra honorário sobre honorário). Gravar
        // a taxa, e não só o valor, é o que impede a hidratação ao vivo de reintroduzir o honorário na
        // próxima leitura. Juros/multa/correção seguem herdando a cascata, ao vivo desde o vencimento.
        $input->modoHonorarios = 'percent';
        $input->honorariosBp = 0;

        return $input;
    }

    /**
     * Aba cujo CONTEÚDO foi pulado (acordo inexistente, sem caso, não vigente, caso encerrado): nenhuma
     * parcela criada, nenhuma conta marcada.
     *
     * ⚠️ "Ignorada" deixou de significar "nada foi escrito". A sobrescrita de status acontece antes das
     * guardas e é independente delas — uma aba `Cancelado` tem o status gravado e o conteúdo pulado. Por
     * isso a sobrescrita e os avisos de reativação atravessam para cá em vez de serem zerados: o
     * operador precisa ver a escrita que de fato saiu.
     *
     * @param list<string> $dinheiroParado
     * @param list<string> $impactoNoSaldo
     */
    private function abaIgnorada(
        AcordoDetalhadoImportavel $aba,
        string $motivo,
        ?SobrescritaDeSituacao $sobrescrita = null,
        array $dinheiroParado = [],
        array $impactoNoSaldo = [],
    ): AcordoProcessado {
        return new AcordoProcessado(
            numero: $aba->numero,
            unidade: $aba->unidade,
            sacado: $aba->sacado,
            ignoradoPorque: $motivo,
            parcelasCriadas: [],
            parcelasExistentes: [],
            contasMarcadas: [],
            contasReconstruidas: [],
            contasJaMarcadas: [],
            divergenciasDeValor: [],
            casadasSemCompetencia: [],
            contasRecusadas: [],
            parcelasAmbiguas: [],
            principalReconciliadoCentavos: 0,
            centavosSemBoleto: 0,
            valorParcelasCriadasCentavos: 0,
            situacaoSobrescrita: $sobrescrita?->descricao(),
            dinheiroParadoPelaReativacao: $dinheiroParado,
            impactoDaReativacaoNoSaldo: $impactoNoSaldo,
        );
    }

    private function resolverCarteira(int $carteiraId, Tenant $tenant): Carteira
    {
        $carteira = $this->carteiraRepository->findOneByIdDoTenant($carteiraId, $tenant);
        if ($carteira === null) {
            throw new CarteiraNaoEncontradaException($carteiraId);
        }

        return $carteira;
    }

    private function semAcento(string $valor): string
    {
        $valor = mb_strtolower(trim($valor));

        return strtr($valor, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ç' => 'c',
        ]);
    }
}
