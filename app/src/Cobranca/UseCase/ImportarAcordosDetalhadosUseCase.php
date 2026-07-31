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
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Exception\MigrationDeCompetenciaPendenteException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\Importacao\AcordoDetalhadoImportavel;
use App\Cobranca\Service\Importacao\AcordoProcessado;
use App\Cobranca\Service\Importacao\ContaOriginalImportavel;
use App\Cobranca\Service\Importacao\ParcelaAcordoImportavel;
use App\Cobranca\Service\Importacao\ResultadoImportacaoAcordos;
use App\Cobranca\Service\Importacao\ResultadoLeituraAcordos;
use App\Cobranca\Service\ResolvedorConfigEncargos;
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
     * Situações da fonte que o domínio sabe traduzir (§3.3). Comparadas em minúsculas e sem acento.
     * `Em andamento` é a única presente no dado atual; qualquer outra é reportada, nunca adivinhada.
     */
    private const SITUACOES = [
        'em andamento' => StatusAcordo::Ativo,
    ];

    public function __construct(
        private readonly CarteiraRepository $carteiraRepository,
        private readonly AcordoRepository $acordoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly RegistrarObrigacaoUseCase $registrarObrigacao,
        private readonly CalculadoraEncargos $calculadora,
        private readonly ResolvedorConfigEncargos $resolvedorConfig,
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

        $processados = [];
        $liquidadas = [];
        $situacoesDesconhecidas = [];
        $conferencias = [];

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

            $processados[] = $this->processarAba($aba, $carteira, $tenant, $usuario, $situacoesDesconhecidas);
        }

        return new ResultadoImportacaoAcordos(
            $processados,
            $leitura->rejeitadas,
            $leitura->linhasIgnoradas,
            $liquidadas,
            $situacoesDesconhecidas,
            $conferencias,
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
     * @param list<string> $situacoesDesconhecidas acumulador do lote
     */
    private function processarAba(
        AcordoDetalhadoImportavel $aba,
        Carteira $carteira,
        Tenant $tenant,
        ?User $usuario,
        array &$situacoesDesconhecidas,
    ): AcordoProcessado {
        $acordo = $this->acordoRepository->findOnePorNumeroExternoNaCarteira($aba->numero, $carteira, $tenant);
        if ($acordo === null) {
            // §3.1: o acordo é responsabilidade da inadimplência. Aba reportada e ignorada, sem escrita.
            return $this->abaIgnorada($aba, sprintf('Acordo %d não existe nesta carteira — quem cria acordo é o relatório de inadimplência (§3.1).', $aba->numero));
        }

        if (!$acordo->getStatus()->ehVigente()) {
            // Acordo rompido/cancelado: a aba inteira é pulada, e não só o status (§3.3).
            //
            // Escrever aqui CRIA DÍVIDA. A conta reconstruída pelo §3.2.1 nasce com `acordoSubstituto`,
            // e `doCasoExigiveis` só exclui o que está substituído por acordo VIGENTE — com o acordo
            // rompido ela entra no saldo e cobra de novo uma dívida que a planilha listou como
            // renegociada. A parcela futura tem o defeito espelhado: nasceria ligada a um acordo que
            // não vale mais. Marcar as originais seria inócuo pelo mesmo motivo, e igualmente confuso.
            //
            // A janela é estreita — romper é registrado nos dois lados, então a planilha seguinte já vem
            // alinhada —, mas pular custa nada e a fecha inteira. Quem decide o que fazer com um acordo
            // rompido é gente, com o relatório na mão.
            return $this->abaIgnorada($aba, sprintf(
                'Acordo %d está %s no sistema — aba pulada inteira: escrever contra acordo não vigente devolveria as contas ao saldo. A planilha diz "%s"; confira à mão.',
                $aba->numero,
                $acordo->getStatus()->value,
                $aba->situacao,
            ));
        }

        $caso = $acordo->getCaso();
        if ($caso === null) {
            return $this->abaIgnorada($aba, sprintf('Acordo %d está sem caso de cobrança — dado inconsistente, nada foi tocado.', $aba->numero));
        }
        if ($caso->estaEncerrado()) {
            // Caso encerrado não recebe obrigação (SPEC §17). Sem esta guarda o `RegistrarObrigacaoUseCase`
            // lançaria e derrubaria o LOTE INTEIRO por causa de uma aba.
            return $this->abaIgnorada($aba, sprintf('Acordo %d pertence a um caso ENCERRADO — caso encerrado não recebe obrigação (SPEC §17).', $aba->numero));
        }

        $configCaso = $this->resolvedorConfig->resolverDoCaso($caso);

        [$parcelasCriadas, $parcelasExistentes, $parcelasAmbiguas, $divergencias, $valorParcelas, $parcelasVinculadas] =
            $this->completarParcelas($aba, $acordo, $caso, $tenant, $usuario);

        [$marcadas, $reconstruidas, $jaMarcadas, $recusadas, $semCompetencia, $maisDivergencias, $principal] =
            $this->reconciliarContasOriginais($aba, $acordo, $caso, $tenant, $usuario, $configCaso);

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
            situacaoDivergente: $this->conferirSituacao($aba, $acordo, $situacoesDesconhecidas),
            parcelasVinculadas: $parcelasVinculadas,
        );
    }

    /**
     * §3.1 — completar as parcelas futuras. A parcela que não existe nasce como obrigação do caso, ligada
     * ao acordo (`acordoOrigem`), com honorários ZERO e encargos ao vivo a partir do próprio vencimento.
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<string>, 4: int, 5: list<string>}
     */
    private function completarParcelas(
        AcordoDetalhadoImportavel $aba,
        Acordo $acordo,
        CasoCobranca $caso,
        Tenant $tenant,
        ?User $usuario,
    ): array {
        $criadas = [];
        $existentes = [];
        $ambiguas = [];
        $divergencias = [];
        $vinculadas = [];
        $valor = 0;

        foreach ($aba->parcelas as $parcela) {
            $existente = $this->obrigacaoRepository->findOnePorReferenciaECompetenciaNoCaso($caso, $parcela->nn, $parcela->competencia);
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

            // O mesmo NN já existe no caso com OUTRA competência. A spec dá duas chaves para parcela
            // (§7 diz "por NN", §3.2 exige NN+competência) e aqui elas discordam. Criar é a direção
            // PERIGOSA — adicionaria dinheiro ao saldo a partir de um casamento duvidoso —, então a
            // importação recusa e devolve a decisão ao humano. Não criar só adia; criar errado cobra.
            if ($this->obrigacaoRepository->findOnePorReferenciaExternaNoCaso($caso, $parcela->nn) !== null) {
                $ambiguas[] = $parcela->nn;

                continue;
            }

            $criadas[] = $parcela->nn;
            $valor += $parcela->valorCentavos;

            if ($usuario !== null) {
                $nova = $this->registrarObrigacao->executar($this->parcelaInput($caso, $parcela), $tenant, $usuario);
                $nova->setAcordoOrigem($acordo);
                $this->obrigacaoRepository->salvar($nova, true);
            }
        }

        return [$criadas, $existentes, $ambiguas, $divergencias, $valor, $vinculadas];
    }

    /**
     * §3.2 — **a correção**. Para cada conta original da planilha: se existe e está aberta, marca com
     * `acordoSubstituto` (sai do saldo); se já está marcada, no-op; se não existe, reconstrói já
     * substituída (§3.2.1).
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
    ): array {
        $marcadas = [];
        $reconstruidas = [];
        $jaMarcadas = [];
        $recusadas = [];
        $semCompetencia = [];
        $divergencias = [];
        $principal = 0;

        foreach ($aba->contasOriginais as $conta) {
            // A CHAVE. Nunca `findOnePorReferenciaExternaNoCaso` — casar só pelo NN aqui marcaria dívida
            // de outra competência (e de outro devedor) como substituída, apagando cobrança legítima.
            $obrigacao = $this->obrigacaoRepository->findOnePorReferenciaECompetenciaNoCaso($caso, $conta->nn, $conta->competencia);

            if ($obrigacao === null) {
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
     * §3.3 — situação do acordo. Este importador **nunca escreve o status**, e isso é deliberado.
     *
     * A única situação que a fonte traz hoje (`Em andamento`) mapeia para `Ativo`, que já é o status de
     * todo acordo nascido da importação — escrever seria no-op. E em todo caso em que NÃO seria no-op, o
     * status do sistema é uma decisão MANUAL do escritório (romper, cancelar, marcar cumprido) que move
     * dinheiro: ressuscitar um acordo rompido a partir de uma planilha tiraria as dívidas originais do
     * saldo de novo, desfazendo em silêncio o que uma pessoa decidiu. Então divergência vira AVISO.
     *
     * @param list<string> $situacoesDesconhecidas acumulador do lote
     */
    private function conferirSituacao(AcordoDetalhadoImportavel $aba, Acordo $acordo, array &$situacoesDesconhecidas): ?string
    {
        $chave = $this->semAcento($aba->situacao);
        $mapeada = self::SITUACOES[$chave] ?? null;

        if ($mapeada === null) {
            $situacoesDesconhecidas[] = sprintf('Acordo %d: situação "%s" não reconhecida — status mantido em %s.', $aba->numero, $aba->situacao, $acordo->getStatus()->value);

            return null;
        }

        if ($acordo->getStatus() === $mapeada) {
            return null;
        }

        return sprintf(
            'Acordo %d: a contábil diz "%s" (→ %s) e o sistema está "%s". O status do sistema é decisão do escritório e foi MANTIDO — confira à mão.',
            $aba->numero,
            $aba->situacao,
            $mapeada->value,
            $acordo->getStatus()->value,
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

    private function abaIgnorada(AcordoDetalhadoImportavel $aba, string $motivo): AcordoProcessado
    {
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
            situacaoDivergente: null,
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
