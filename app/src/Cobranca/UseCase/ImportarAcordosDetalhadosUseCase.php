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
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\Importacao\AcordoDetalhadoImportavel;
use App\Cobranca\Service\Importacao\AcordoProcessado;
use App\Cobranca\Service\Importacao\ContaOriginalImportavel;
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
 * Três recusas deliberadas, todas na direção segura:
 *
 * - **O acordo nunca é criado aqui** (§3.1). Se não existe, a aba é reportada e ignorada — criar acordo é
 *   responsabilidade da inadimplência, que é quem tem o dado completo.
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
        $acordo = $this->acordoRepository->findOnePorNumeroExternoNaCarteira($aba->numero, $carteira, $tenant);
        if ($acordo === null) {
            // §3.1: o acordo é responsabilidade da inadimplência. Aba reportada e ignorada, sem escrita.
            return $this->abaIgnorada($aba, sprintf('Acordo %d não existe nesta carteira — quem cria acordo é o relatório de inadimplência (§3.1).', $aba->numero));
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
            $barrado = $this->motivoParaNaoDesativar($acordo, $temParcelaPaga);
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

        [$parcelasCriadas, $parcelasExistentes, $parcelasAmbiguas, $divergencias, $valorParcelas, $parcelasVinculadas, $liquidadasIgnoradas] =
            $this->completarParcelas($aba, $acordo, $caso, $tenant, $usuario, $tocadas);

        [$marcadas, $reconstruidas, $jaMarcadas, $recusadas, $semCompetencia, $maisDivergencias, $principal] =
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
            valorParcelasCriadasCentavos: $valorParcelas,
            situacaoSobrescrita: $sobrescrita?->descricao(),
            parcelasVinculadas: $parcelasVinculadas,
            parcelasLiquidadasIgnoradas: $liquidadasIgnoradas,
            dinheiroParadoPelaReativacao: $dinheiroParado,
            impactoDaReativacaoNoSaldo: $impactoNoSaldo,
        );
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
                    $tocadas->registrarMutada($existente, $caso, $parcela->nn, $parcela->competencia, 'parcela-vinculada');
                    if ($usuario !== null) {
                        $existente->setAcordoOrigem($acordo);
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
            // Registrar ANTES de escrever: é o que faz a reconciliação seguinte (e a de outra aba do
            // mesmo caso) enxergar esta parcela no dry-run, onde o banco não muda. Sem isto a prévia
            // prometeria "reconstruir" uma conta que a confirmação recusaria por INV-I.
            $tocadas->registrarCriada($caso, $parcela->nn, $parcela->competencia, 'parcela');

            if ($usuario !== null) {
                $nova = $this->registrarObrigacao->executar($this->parcelaInput($caso, $parcela), $tenant, $usuario);
                $nova->setAcordoOrigem($acordo);
                $this->obrigacaoRepository->salvar($nova, true);
            }
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
                $recusadas[] = match ($jaTocada) {
                    'parcela' => sprintf('%s: esta MESMA importação a criou como parcela do acordo %d — não é dívida original, não marcada (INV-I).', $conta->nn, $aba->numero),
                    'parcela-vinculada' => sprintf('%s: esta MESMA importação a ligou como parcela de acordo — não é dívida original, não marcada (INV-I).', $conta->nn),
                    'marcada' => sprintf('%s: esta MESMA importação já marcou esta obrigação como substituída — não remarcada (confira: a planilha pode estar listando a mesma dívida em duas competências).', $conta->nn),
                    default => sprintf('%s: esta MESMA importação já a reconstruiu — linha repetida na planilha, ignorada.', $conta->nn),
                };

                continue;
            }

            if ($obrigacao === null) {
                $tocadas->registrarCriada($caso, $conta->nn, $conta->competencia, 'conta');
                $reconstruidas[] = $conta->nn;
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

            // INV-I (ajuste 9): um acordo só substitui DÍVIDA ORIGINAL. Marcar a parcela de outro acordo
            // cria o estado "acordo sobre acordo": ao romper o acordo de origem, a original que ELE
            // substituiu volta ao exigível E esta parcela continua nele — a dívida conta duas vezes. O
            // `CriarAcordoUseCase` lança exceção nesse caso; aqui a importação recusa a linha e segue,
            // para uma aba estranha não derrubar o lote.
            if ($obrigacao->getAcordoOrigem() !== null) {
                $recusadas[] = sprintf('%s: no sistema é parcela do acordo %s, não dívida original — não marcada (INV-I).', $conta->nn, (string) $obrigacao->getAcordoOrigem()->getId());

                continue;
            }

            $substituto = $obrigacao->getAcordoSubstituto();
            if ($substituto !== null) {
                if ($substituto->getId() === $acordo->getId()) {
                    $jaMarcadas[] = $conta->nn; // idempotência (§7)

                    continue;
                }

                $recusadas[] = sprintf('%s: já substituída pelo acordo %s (situação %s) — não remarcada.', $conta->nn, (string) $substituto->getId(), $substituto->getStatus()->value);

                continue;
            }

            $marcadas[] = $conta->nn;
            $principal += $obrigacao->getValorOriginal();
            $tocadas->registrarMutada($obrigacao, $caso, $conta->nn, $conta->competencia, 'marcada');

            if ($usuario !== null) {
                $this->materializarNaDataDoAcordo($obrigacao, $acordo, $configCaso);
                $obrigacao->setAcordoSubstituto($acordo);
                $this->obrigacaoRepository->salvar($obrigacao, true);
            }
        }

        return [$marcadas, $reconstruidas, $jaMarcadas, $recusadas, $semCompetencia, $divergencias, $principal];
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
            'Reconstruída da planilha de acordos (emissão %s)',
            $aba->emissao?->format('d/m/Y') ?? 'sem data',
        );

        $input = new RegistrarObrigacaoInput();
        $input->casoId = $caso->getId();
        $input->descricao = $this->descricaoComProcedencia($conta->descricao(), $procedencia);
        $input->valorOriginal = $conta->valorCentavos;
        $input->vencimentoOriginal = $conta->vencimento;
        $input->referenciaExterna = $conta->nn;
        $input->competencia = $conta->competencia;

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

        $config = $this->resolvedorConfig->aplicarObrigacao($configCaso, $obrigacao);
        $encargos = $this->calculadora->calcular(
            $obrigacao->getValorOriginal(),
            $obrigacao->getVencimentoOriginal(),
            $config,
            $acordo->getDataAcordo(),
        );

        $obrigacao->definirEncargos($encargos['juros'], $encargos['multa'], $encargos['correcao'], $encargos['honorarios'], $acordo->getDataAcordo());
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
    private function motivoParaNaoDesativar(Acordo $acordo, bool $temParcelaPaga): ?string
    {
        // (1) PARCELA PAGA — a decisão do dono de 04/08. Cancelar tira as parcelas do exigível levando a
        // alocação junto: o dinheiro recebido para de abater o saldo e o devedor volta a ser cobrado por
        // algo que pagou. Espelha `CancelarAcordoUseCase::recusarSeAlgumaParcelaFoiPaga`.
        if ($temParcelaPaga) {
            return 'há PARCELA PAGA no sistema. Cancelar agora faria o dinheiro já recebido parar de abater o saldo. '
                . 'Para aplicar: exclua o recebimento da parcela (tela do objeto → Movimentos → Excluir) e importe de novo';
        }

        // (2) PARCELAS RENEGOCIADAS por outro acordo VIGENTE — espelha
        // `AcordoComParcelasRenegociadasException`. Desativar aqui conta a MESMA dívida duas vezes no
        // saldo: as originais deste acordo voltam ao exigível (`asub` deixa de ser vigente) E as parcelas
        // do acordo novo continuam exigíveis (`aorig` segue vigente) — ver `doCasoExigiveis`.
        //
        // Só existe em dado legado: INV-I bloqueia criar o estado hoje. Mas é exatamente o tipo de dado
        // que uma importação de acervo antigo encontra.
        if ($this->obrigacaoRepository->parcelasRenegociadasPorAcordoVigente($acordo) !== []) {
            return 'as parcelas dele foram renegociadas por outro acordo VIGENTE. '
                . 'Cancelar agora contaria a mesma dívida duas vezes no saldo. Resolva os dois acordos à mão antes';
        }

        return null;
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
