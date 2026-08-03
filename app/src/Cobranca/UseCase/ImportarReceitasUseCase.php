<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AbrirCasoInput;
use App\Cobranca\DTO\CriarObjetoInput;
use App\Cobranca\DTO\CriarPessoaInput;
use App\Cobranca\DTO\RegistrarObrigacaoInput;
use App\Cobranca\DTO\VincularPessoaAObjetoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pagamento;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Enum\TipoVinculo;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Service\Importacao\EstadoDaImportacaoDeReceitas;
use App\Cobranca\Service\Importacao\ReceitaImportavel;
use App\Cobranca\Service\Importacao\ResultadoImportacaoReceitas;
use App\Cobranca\Service\Importacao\ResultadoLeituraReceitas;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Importa o relatório "Receitas detalhadas por unidade/cliente" — o 4º e último da contábil L.G (spec
 * `docs/specs/cobranca-importar-receitas.md`). É o relatório que responde **o que foi pago**; os outros
 * três só dizem o que é devido.
 *
 * 🔑 **Por que ele CRIA obrigação (decisão R1 do dono).** Medido nos arquivos de 03/08: dos 2.078 NNs
 * com recebimento, apenas **80 (3,8%)** existem como obrigação, e dos **106 acordos** citados, **zero**.
 * Não é defeito do dado: o sistema só conhece o que a *Inadimplência* traz, e ela só traz o que **não
 * foi pago** — boleto pago sai daquele relatório e nunca entrou aqui. Os dois conjuntos são quase
 * disjuntos por construção. Casar só com o que já existe pousaria em 4% e reportaria o resto; criar o
 * que falta é o que dá ao escritório o panorama do ano e o que dá base à reativação por importação.
 *
 * A obrigação criada nasce **liquidada na data do recebimento**, com o principal e os encargos que a
 * planilha diz terem sido pagos — então ela entra e sai da conta no mesmo valor e **o saldo não se
 * move** por causa dela.
 *
 * Idempotência: a chave é `(obrigação, data de recebimento)`. Reimportar o mesmo arquivo não cria
 * pagamento nenhum na segunda vez.
 *
 * ⚠️ A PRÉVIA carrega ESTADO INTRA-EXECUÇÃO. Dois recebimentos do mesmo objeto não podem contar "objeto
 * criado" duas vezes, e o segundo recebimento de um NN tem de enxergar o primeiro. Prévia que só
 * consulta o banco mente — nesta frente já mentiu duas vezes.
 */
final class ImportarReceitasUseCase
{
    public function __construct(
        private readonly CarteiraRepository $carteiraRepository,
        private readonly ObjetoCobrancaRepository $objetoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly PagamentoRepository $pagamentoRepository,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly AcordoRepository $acordoRepository,
        private readonly CriarObjetoUseCase $criarObjeto,
        private readonly CriarPessoaUseCase $criarPessoa,
        private readonly VincularPessoaAObjetoUseCase $vincular,
        private readonly AbrirCasoUseCase $abrirCaso,
        private readonly RegistrarObrigacaoUseCase $registrarObrigacao,
        private readonly RegistrarEventoHistorico $registrarEvento,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Dry-run: projeta o que ACONTECERIA, sem escrever nada. Mesma estrutura de saída da confirmação —
     * é o que permite conferir TODOS os campos de uma vez, e não por amostra. (Sem número escrito aqui
     * de propósito: ele já esteve desatualizado em três lugares. Quem garante a cobertura é
     * `ImportarReceitasFluxoTest::testAchatarCobreTodoOResultado`, que a deriva por reflexão.)
     */
    public function prever(int $carteiraId, ResultadoLeituraReceitas $leitura, Tenant $tenant): ResultadoImportacaoReceitas
    {
        $carteira = $this->resolverCarteira($carteiraId, $tenant);
        $estado = new EstadoDaImportacaoDeReceitas();
        $dinheiroParado = $this->mapearDinheiroParadoPelaReativacao($carteira, $leitura, $tenant);

        foreach ($leitura->receitas as $receita) {
            $objeto = $this->objetoRepository->findOnePorIdentificacaoNaCarteira($carteira, $receita->objetoIdentificacao, $tenant);
            $caso = $objeto === null ? null : ($this->casoRepository->casosAtivosDoObjeto($objeto)[0] ?? null);

            // ESTADO: o mesmo objeto/caso aparece em vários recebimentos do arquivo. Sem isto, a prévia
            // contaria uma criação por linha e prometeria um número que a confirmação não entrega.
            $estado->projetarObjetoECaso($receita->objetoIdentificacao, $objeto !== null, $caso !== null);

            // Coluna J (etapa 3): projeta o acordo ANTES da obrigação, na mesma ordem da confirmação.
            // Nada é criado aqui — quem garante que os dois modos contam igual é o ESTADO, que só
            // consome o PRIMEIRO encontro de cada número de acordo.
            $acordoExistente = $this->projetarAcordo($carteira, $receita, $tenant, $estado, $dinheiroParado);

            $obrigacao = $caso === null
                ? null
                : $this->obrigacaoRepository->findOnePorReferenciaECompetenciaNoCaso($caso, $receita->nn, $receita->competencia);

            $this->projetarTrocaDeAcordo($obrigacao, $receita, $acordoExistente, $estado);

            $jaImportado = $obrigacao !== null
                && $this->alocacaoRepository->existeNaObrigacaoComData((int) $obrigacao->getId(), $receita->recebimento, $tenant);

            $estado->projetarRecebimento($receita, $obrigacao !== null, $jaImportado);
        }

        return $estado->resultado($leitura);
    }

    /** Persiste. Transação única: ou o arquivo inteiro entra, ou nada entra. */
    public function confirmar(int $carteiraId, ResultadoLeituraReceitas $leitura, Tenant $tenant, User $user): ResultadoImportacaoReceitas
    {
        $carteira = $this->resolverCarteira($carteiraId, $tenant);

        return $this->em->wrapInTransaction(function () use ($carteira, $leitura, $tenant, $user): ResultadoImportacaoReceitas {
            $estado = new EstadoDaImportacaoDeReceitas();
            /** @var array<int, Acordo> $acordos número externo => acordo tocado nesta execução */
            $acordos = [];

            // ⚠️ ANTES do laço, e é obrigatório que seja antes. O relatório de dinheiro parado consulta
            // ALOCAÇÕES; se fosse calculado dentro do laço, a confirmação veria as alocações que ela
            // mesma acabou de criar nas linhas anteriores (o `RegistrarObrigacaoUseCase` dá flush a
            // cada obrigação) e devolveria um número que a prévia não tem como prever. Calculado aqui,
            // os dois modos leem o MESMO banco intocado — e a igualdade prévia×confirmação passa a ser
            // por construção, não por sorte de cenário.
            $dinheiroParado = $this->mapearDinheiroParadoPelaReativacao($carteira, $leitura, $tenant);

            foreach ($leitura->receitas as $receita) {
                $objeto = $this->objetoRepository->findOnePorIdentificacaoNaCarteira($carteira, $receita->objetoIdentificacao, $tenant);
                $objetoExistia = $objeto !== null;
                if ($objeto === null) {
                    $objeto = $this->criarObjeto->executar($this->objetoInput($carteira, $receita), $tenant, $user);
                }

                $caso = $this->casoRepository->casosAtivosDoObjeto($objeto)[0] ?? null;
                $casoExistia = $caso !== null;
                if ($caso === null) {
                    $pessoa = $this->criarPessoa->executar($this->pessoaInput($receita), $tenant, $user);
                    $this->vincular->executar($this->vinculoInput($pessoa->getId(), $objeto->getId(), $carteira), $tenant, $user);
                    $caso = $this->abrirCaso->executar($this->casoInput($objeto->getId(), $pessoa->getId()), $tenant, $user);
                }
                $estado->projetarObjetoECaso($receita->objetoIdentificacao, $objetoExistia, $casoExistia);

                // Coluna J (etapa 3): acha/cria o Acordo ANTES de resolver a obrigação, porque a
                // parcela precisa apontar pra ele nos DOIS ramos — a que nasce agora e a que já
                // existia (possivelmente criada avulsa por uma importação anterior a esta etapa).
                $acordo = $this->resolverOuCriarAcordo($carteira, $caso, $receita, $tenant, $user, $estado, $dinheiroParado);
                if ($acordo !== null && $receita->acordo !== null) {
                    $acordos[$receita->acordo->numero] = $acordo;
                }

                $obrigacao = $this->obrigacaoRepository->findOnePorReferenciaECompetenciaNoCaso($caso, $receita->nn, $receita->competencia);
                $obrigacaoExistia = $obrigacao !== null;
                $this->projetarTrocaDeAcordo($obrigacao, $receita, $acordo, $estado);

                if ($obrigacao !== null
                    && $this->alocacaoRepository->existeNaObrigacaoComData((int) $obrigacao->getId(), $receita->recebimento, $tenant)) {
                    // Reimportação do mesmo arquivo: nenhum dinheiro entra de novo. Mas o VÍNCULO é
                    // reposto se faltar — é o que conserta a obrigação que nasceu avulsa antes desta
                    // etapa existir. Mesmo tratamento de `ImportarRelatorioCarteiraUseCase:221-227`.
                    $this->garantirVinculoAoAcordo($obrigacao, $acordo);
                    $estado->projetarRecebimento($receita, true, true);

                    continue;
                }

                if ($obrigacao === null) {
                    $obrigacao = $this->criarObrigacaoJaPaga($caso, $receita, $acordo, $tenant, $user);
                } else {
                    // Obrigação preexistente: o valorOriginal NÃO é reescrito (invariável 20). Só o
                    // vínculo entra.
                    $this->garantirVinculoAoAcordo($obrigacao, $acordo);
                }

                // A alocação tem de fechar com o exigível da obrigação, senão a linha nasce devendo (ou
                // sobrando) — e o que decide é a FORMA do `valorOriginal` dela: o honorário já está
                // DENTRO dele, ou está fora?
                //
                // 🔑 O sinal é o override `taxa_honorarios_bp = 0`, e ele é o certo por construção:
                // quem grava obrigação COM o honorário embutido grava esse override junto, na mesma
                // linha de código, exatamente porque o honorário já foi cobrado uma vez
                // (`ImportarRelatorioCarteiraUseCase:479-481`, `ImportarAcordosDetalhadosUseCase:662-663`
                // e o `criarObrigacaoJaPaga` daqui). Quem só VINCULA uma avulsa a um acordo não toca o
                // valor nem o override, que fica NULL (herda a cascata).
                //
                // ⚠️ Duas versões anteriores erraram, uma para cada lado, e nenhuma das duas dava para
                // acertar com a informação que elas olhavam:
                //   • `!$obrigacaoExistia` ("eu criei agora?") deixava a parcela PREEXISTENTE devendo o
                //     honorário;
                //   • `getAcordoOrigem() !== null` ("é parcela?") fazia a avulsa apenas VINCULADA
                //     receber o honorário a mais, abatendo o saldo do caso indevidamente.
                //
                // Medido no dev (`saas_ux`), e é o que mostra que as duas formas coexistem de verdade:
                // das 51 obrigações com `acordo_origem_id`, **14 têm bp = 0** (criadas como parcela) e
                // **37 têm NULL** (avulsas vinculadas) — e o acordo 31 tem das duas.
                $honorarioEmbutidoNoValorOriginal = $acordo !== null && $obrigacao->getTaxaHonorariosBp() === 0;

                $valorAlocado = $honorarioEmbutidoNoValorOriginal
                    ? $receita->totalRecebidoCentavos()
                    : $receita->recuperadoDividaCentavos();

                $this->registrarRecebimento($caso, $obrigacao, $receita, $valorAlocado, $tenant, $user);
                $estado->projetarRecebimento($receita, $obrigacaoExistia, false);
            }

            // Status do acordo (A2), no FIM: só depois do arquivo inteiro se sabe quantas parcelas
            // distintas foram pagas. Decidir na primeira parcela marcaria `Ativo` um acordo de 1/1 ou
            // `Cumprido` nada. A régua e o número saem do MESMO estado que a prévia usa.
            foreach ($estado->numerosCumpridos() as $numero) {
                if (isset($acordos[$numero])) {
                    $acordos[$numero]->marcarCumprido();
                    $this->acordoRepository->salvar($acordos[$numero]);
                }
            }

            $this->em->flush();

            return $estado->resultado($leitura);
        });
    }

    /**
     * Ramo de PROJEÇÃO da coluna J: consulta o mesmo que a confirmação consultaria e entrega ao estado.
     * Não escreve nada.
     */
    /**
     * @param array<int, array{0: list<string>, 1: list<string>}> $dinheiroParado lido antes do laço
     *
     * @return Acordo|null o acordo EXISTENTE no banco (null se a confirmação viria a criá-lo)
     */
    private function projetarAcordo(
        Carteira $carteira,
        ReceitaImportavel $receita,
        Tenant $tenant,
        EstadoDaImportacaoDeReceitas $estado,
        array $dinheiroParado,
    ): ?Acordo {
        if ($receita->acordo === null) {
            return null;
        }

        $existente = $this->acordoRepository->findOnePorNumeroExternoNaCarteira($receita->acordo->numero, $carteira, $tenant);
        $reativa = $existente !== null && !$existente->getStatus()->ehVigente();

        [$parado, $impacto] = $dinheiroParado[$receita->acordo->numero] ?? [[], []];
        $estado->projetarAcordo($receita->acordo, $existente !== null, $reativa, $reativa ? $parado : [], $reativa ? $impacto : []);

        return $existente;
    }

    /**
     * Fotografa, ANTES de qualquer escrita, o dinheiro que a reativação de D6 tiraria do exigível —
     * um acordo por número, mesmo que ele apareça em 40 parcelas.
     *
     * Roda no início da prévia E no início da confirmação, sobre o banco intocado. É o que impede que
     * o número da confirmação inclua alocações criadas pela própria execução, divergindo da prévia
     * (ver `feedback_previa_precisa_de_estado`).
     *
     * @return array<int, array{0: list<string>, 1: list<string>}> número do acordo => [dinheiro parado, impacto]
     */
    private function mapearDinheiroParadoPelaReativacao(
        Carteira $carteira,
        ResultadoLeituraReceitas $leitura,
        Tenant $tenant,
    ): array {
        $mapa = [];
        foreach ($leitura->receitas as $receita) {
            $doRelatorio = $receita->acordo;
            if ($doRelatorio === null || array_key_exists($doRelatorio->numero, $mapa)) {
                continue;
            }

            $acordo = $this->acordoRepository->findOnePorNumeroExternoNaCarteira($doRelatorio->numero, $carteira, $tenant);
            $mapa[$doRelatorio->numero] = $acordo !== null && !$acordo->getStatus()->ehVigente()
                ? $this->dinheiroParadoPelaReativacao($acordo, $tenant)
                : [[], []];
        }

        return $mapa;
    }

    /**
     * Acha o acordo por `(número externo, carteira, tenant)` ou o cria (decisão A1: parcela paga ⇒ o
     * acordo existe). Devolve `null` quando a coluna J está vazia — o caminho avulso, 1.891 dos 2.078.
     *
     * A busca é por CARTEIRA e não só por tenant de propósito: o número do acordo é sequencial por
     * carteira, não globalmente único. Medido em 03/08: os relatórios de Acordos das duas carteiras
     * têm ambos uma aba "31" e uma "32", e são acordos diferentes. O índice
     * `(tenant_id, numero_externo)` não é único e não pegaria isso.
     *
     * `valorTotalNegociado` só é gravado quando o acordo tem UMA parcela: com várias, o arquivo traz
     * as pagas, não o total negociado — inventá-lo seria chutar dado que a fonte não dá.
     */
    private function resolverOuCriarAcordo(
        Carteira $carteira,
        CasoCobranca $caso,
        ReceitaImportavel $receita,
        Tenant $tenant,
        User $user,
        EstadoDaImportacaoDeReceitas $estado,
        array $dinheiroParado,
    ): ?Acordo {
        $doRelatorio = $receita->acordo;
        if ($doRelatorio === null) {
            return null;
        }

        $acordo = $this->acordoRepository->findOnePorNumeroExternoNaCarteira($doRelatorio->numero, $carteira, $tenant);

        if ($acordo === null) {
            $acordo = new Acordo();
            $acordo->setTenant($tenant);
            $acordo->setCaso($caso);
            $acordo->setStatus(StatusAcordo::Ativo);
            $acordo->setDataAcordo($this->dataAcordoPadrao($receita));
            $acordo->setNumeroExterno($doRelatorio->numero);
            $acordo->setNumeroParcelasTotal($doRelatorio->parcelaTotal);
            $acordo->setCriadoPor($user);
            if ($doRelatorio->parcelaTotal === 1) {
                $acordo->setValorTotalNegociado($this->valorNegociadoDaParcela($receita));
            }
            $this->acordoRepository->salvar($acordo, true);

            $estado->projetarAcordo($doRelatorio, false, false);

            return $acordo;
        }

        // D6 — "o importe é a verdade absoluta". Ler o estado ANTES de mexer: depois da reativação o
        // acordo já está vigente e o `$reativa` seguinte daria falso, que é justamente o que faz prévia
        // e confirmação convergirem (as duas só consomem o primeiro encontro).
        $reativa = !$acordo->getStatus()->ehVigente();

        [$parado, $impacto] = $dinheiroParado[$doRelatorio->numero] ?? [[], []];
        $estado->projetarAcordo($doRelatorio, true, $reativa, $reativa ? $parado : [], $reativa ? $impacto : []);

        if ($reativa) {
            $this->reativarPorImportacao($acordo, $doRelatorio->numero, $user);
        }

        if ($acordo->getNumeroParcelasTotal() === null && $doRelatorio->parcelaTotal > 0) {
            $acordo->setNumeroParcelasTotal($doRelatorio->parcelaTotal);
            $this->acordoRepository->salvar($acordo, true);
        }

        return $acordo;
    }

    /**
     * D6 (`docs/specs/cobranca-cancelar-acordo.md` §3.2): em carteira importada o ESTADO do acordo é da
     * planilha. Parcela paga chegando num acordo rompido/cancelado devolve o acordo a `Ativo`, **sem
     * aviso ao usuário** — o histórico já guarda que houve rompimento, e "o importe manda" é regra do
     * produto, não exceção a sinalizar. O motivo antigo cai junto: mantê-lo descreveria um estado que
     * não vale mais.
     *
     * O evento vai para o caso DO ACORDO, não para o caso onde a parcela pousou. Medido em 03/08:
     * nenhum acordo cruza unidade, então os dois são o mesmo — mas quando divergirem, a história de um
     * acordo pertence ao caso dele, e é lá que alguém vai procurar por que o estado mudou.
     */
    private function reativarPorImportacao(Acordo $acordo, int $numeroExterno, User $user): void
    {
        $anterior = $acordo->getStatus();

        $acordo->setStatus(StatusAcordo::Ativo);
        $acordo->setMotivoRompimento(null);
        $acordo->setMotivoCancelamento(null);
        $this->acordoRepository->salvar($acordo, true);

        // A reativação em si já está gravada acima. O evento é o registro dela — e a coluna do caso é
        // NOT NULL, então este ramo é defensivo contra entidade meio-construída, não um caso real.
        $caso = $acordo->getCaso();
        if ($caso === null) {
            return;
        }

        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::AcordoEditado,
            $user,
            sprintf('Acordo %d reativado pela importação de receitas (estava %s)', $numeroExterno, $anterior->label()),
            [
                'acordoId' => $acordo->getId(),
                'numeroExterno' => $numeroExterno,
                'statusAnterior' => $anterior->value,
                'statusNovo' => StatusAcordo::Ativo->value,
                'origem' => 'importacao_receitas',
            ],
        );
    }

    /**
     * ⚠️ O efeito colateral de D6 que a spec-cancelar §3.2 manda vigiar, medido e REPORTADO — nunca
     * corrigido em silêncio.
     *
     * Reativar o acordo devolve as obrigações originais ao estado "substituída", e elas saem do
     * exigível. `CalculadoraSaldo` só abate alocação de obrigação exigível — então o dinheiro que
     * alguém tenha recebido numa original enquanto o acordo estava rompido **para de abater o saldo**,
     * e o devedor volta a ser cobrado por algo que já pagou. É o pior erro possível neste domínio.
     *
     * Decidir para onde esse dinheiro vai não é do importador. Ele mede e põe na frente de quem
     * confirma.
     *
     * ⚠️ Devolve DOIS canais separados, e a separação é o ponto. Uma versão anterior misturava os dois
     * numa lista só, e o texto do aviso — "o devedor passa a ser cobrado por algo que já pagou" —
     * disparava também quando NINGUÉM tinha pagado nada. Alarme falso em aviso de dinheiro é pior do
     * que aviso nenhum: ensina o operador a ignorar.
     *
     * ⚠️ Os valores vêm do SNAPSHOT gravado, não do recálculo ao vivo. Uma original que voltou ao
     * exigível por rompimento antigo cresceu desde então, e o aviso subestima o quanto ela pesa. É
     * ordem de grandeza para segurar a mão de quem confirma, não número de fechamento.
     *
     * @return array{0: list<string>, 1: list<string>} [dinheiro já pago que para de abater, impacto no saldo]
     */
    private function dinheiroParadoPelaReativacao(Acordo $acordo, Tenant $tenant): array
    {
        $substituidas = $this->obrigacaoRepository->substituidasPorAcordo($acordo, $tenant);
        if ($substituidas === []) {
            return [[], []];
        }

        $ids = [];
        foreach ($substituidas as $obrigacao) {
            $id = $obrigacao->getId();
            if ($id !== null) {
                $ids[$id] = $obrigacao;
            }
        }

        // ⚠️ Havia aqui um `totalAlocadoEmObrigacoes` no conjunto inteiro, com return antecipado quando
        // dava zero. Foi REMOVIDO: era redundante com a checagem por obrigação abaixo e punha duas
        // defesas em SÉRIE, o que torna o teste do caso negativo improvável — relaxar só uma das duas
        // deixava a suíte verde, que é como um assert vacuoso nasce. Uma defesa, uma prova.
        //
        // QUERY, e não a coleção inversa: `Acordo::$obrigacoesSubstituidas` nasce vazia na mesma
        // unidade de trabalho, e este é caminho de dinheiro.
        $dinheiroParado = [];
        $saldoQueSaiLiquido = 0;
        foreach ($ids as $id => $obrigacao) {
            // ⚠️ A régua é EXISTIR alocação, não ser positiva. `AlocacaoPagamentoRepository:70-71`
            // registra que `total > 0` deixaria passar uma alocação de valor ZERO — que é uma linha de
            // pagamento real, com histórico —, e a etapa 2 cria 10 delas. É a mesma régua que o
            // `CancelarAcordoUseCase` usa para responder à mesma pergunta.
            $temAlocacao = $this->alocacaoRepository->existeAlocacaoEmObrigacoes([$id], $tenant);
            $alocado = $temAlocacao ? $this->alocacaoRepository->totalAlocadoEmObrigacoes([$id], $tenant) : 0;

            // O EFEITO no saldo é o exigível MENOS o que já foi alocado — é isso que `CalculadoraSaldo`
            // deixa de contar. Somar o exigível bruto (como uma versão anterior fazia) imprimia
            // R$ 500,00 onde o saldo se move R$ 350,00: um número certo respondendo a outra pergunta.
            $saldoQueSaiLiquido += max(0, $obrigacao->valorExigivel() - $alocado);

            if (!$temAlocacao) {
                continue;
            }

            $dinheiroParado[] = sprintf(
                'Acordo %d: a obrigação "%s" (NN %s) tem R$ %s alocados e sai do exigível com a reativação',
                (int) $acordo->getNumeroExterno(),
                $obrigacao->getDescricao(),
                $obrigacao->getReferenciaExterna() ?? '—',
                number_format($alocado / 100, 2, ',', '.'),
            );
        }

        $impacto = [];
        if ($saldoQueSaiLiquido > 0) {
            $impacto[] = sprintf(
                'Acordo %d: %d obrigação(ões) originais saem do exigível — o saldo devedor se move em R$ %s',
                (int) $acordo->getNumeroExterno(),
                count($ids),
                number_format($saldoQueSaiLiquido / 100, 2, ',', '.'),
            );
        }

        return [$dinheiroParado, $impacto];
    }

    /**
     * `dataAcordo` quando a fonte não a traz — a Receitas dá a parcela, não a data da negociação.
     * Mesmo default do importador de inadimplência (`ImportarRelatorioCarteiraUseCase::dataAcordoPadrao`):
     * 1º dia da COMPETÊNCIA da parcela, com fallback ao vencimento.
     *
     * É uma aproximação, e sabidamente tardia num acordo de muitas parcelas (a competência da parcela
     * 8/40 não é a data do acordo). Não move dinheiro: `dataAcordo` aqui é descritiva — só o
     * `CriarAcordoUseCase` a usa para materializar encargos, e ele não passa por este caminho.
     */
    private function dataAcordoPadrao(ReceitaImportavel $receita): \DateTimeImmutable
    {
        if (preg_match('#^(\d{2})/(\d{4})$#', $receita->competencia, $m) === 1) {
            return new \DateTimeImmutable(sprintf('%s-%s-01', $m[2], $m[1]));
        }

        return $receita->vencimento;
    }

    /**
     * Repõe o vínculo da parcela ao acordo quando ele faltar — idempotente, não regrava à toa.
     *
     * ⚠️ Quando a obrigação já apontava para OUTRO acordo, isto a MOVE entre acordos e altera a
     * composição de saldo dos dois. Medido em 03/08: nenhum NN aparece em dois acordos, então não
     * acontece na fonte de hoje — mas a mudança não pode ser silenciosa se acontecer, porque a fonte de
     * amanhã não deve nada a essa propriedade.
     */
    private function garantirVinculoAoAcordo(Obrigacao $obrigacao, ?Acordo $acordo): void
    {
        if ($acordo === null || $obrigacao->getAcordoOrigem() === $acordo) {
            return;
        }

        $obrigacao->setAcordoOrigem($acordo);
        $this->obrigacaoRepository->salvar($obrigacao, true);
    }

    /**
     * A obrigação já pertence a OUTRO acordo? Então esta importação vai movê-la, mudando a composição
     * de saldo dos dois acordos.
     *
     * ⚠️ A régua é IDENTIDADE DE ENTIDADE, a MESMA de `garantirVinculoAoAcordo`. Uma versão anterior
     * comparava `numeroExterno`, e as duas divergiam exatamente onde dói: o índice
     * `(tenant_id, numero_externo)` NÃO é único, e o `AcordoRepository` documenta tolerar dois acordos
     * com o mesmo número na mesma carteira (pega o `id DESC`). Nesse caso o vínculo MUDAVA de acordo e
     * o aviso não saía — a mudança silenciosa que este método existe para impedir.
     *
     * `$alvo` é o acordo que já existe no banco; `null` significa que a confirmação vai criar um novo,
     * e aí qualquer vínculo anterior é necessariamente uma troca. Os dois modos passam o resultado da
     * MESMA busca, então a prévia mostra o que a confirmação fará.
     */
    private function projetarTrocaDeAcordo(
        ?Obrigacao $obrigacao,
        ReceitaImportavel $receita,
        ?Acordo $alvo,
        EstadoDaImportacaoDeReceitas $estado,
    ): void {
        $doRelatorio = $receita->acordo;
        $anterior = $obrigacao?->getAcordoOrigem();
        if ($doRelatorio === null || $anterior === null || $anterior === $alvo) {
            return;
        }

        $estado->projetarTrocaDeAcordo(sprintf(
            'NN %s sai do acordo %s e passa para o %d',
            $receita->nn,
            $anterior->getNumeroExterno() === null ? '(sem número externo)' : (string) $anterior->getNumeroExterno(),
            $doRelatorio->numero,
        ));
    }

    /**
     * O principal NEGOCIADO da parcela, em centavos: tudo o que a planilha recebeu **menos juros e
     * multa** (classes `1.4`/`1.5`).
     *
     * 🔑 Por que o honorário entra e os juros não. Numa parcela de acordo o honorário advocatício não é
     * encargo do escritório calculado sobre a dívida: ele foi **consolidado dentro** da parcela quando
     * o acordo foi feito — por isso a Parc. 1/40 do Acordo 348 é 100% classe `1.15`. Já os juros e a
     * multa dessa linha são o atraso no pagamento DA PARCELA, e continuam sendo encargo.
     *
     * É o mesmo critério de `ImportarRelatorioCarteiraUseCase::obrigacaoInput`, traduzido para esta
     * fonte: lá o "Valor" é coluna própria e juros/multa vêm em colunas separadas; aqui tudo chega na
     * coluna I, separado por classe de conta.
     *
     * Medido em 03/08: R$ 86.616,56 nas 187 parcelas (bruto R$ 92.187,81 − R$ 5.571,25 de juros/multa).
     */
    private function valorNegociadoDaParcela(ReceitaImportavel $receita): int
    {
        return $receita->valorDividaCentavos + $receita->valorHonorariosCentavos;
    }

    /**
     * Cria a obrigação que o sistema nunca conheceu (R1) e a marca liquidada NA DATA DO RECEBIMENTO,
     * com o principal e os encargos que a planilha diz terem sido pagos.
     *
     * Ela nasce liquidada de propósito: `valorExigivel()` fica igual ao que o pagamento aloca, então a
     * obrigação entra e sai da conta no mesmo valor e **o saldo do caso não se move**. Congelada, ela
     * também não volta a crescer — é história, não dívida viva.
     */
    private function criarObrigacaoJaPaga(
        CasoCobranca $caso,
        ReceitaImportavel $receita,
        ?Acordo $acordo,
        Tenant $tenant,
        User $user,
    ): Obrigacao {
        $ehParcela = $acordo !== null;

        $input = new RegistrarObrigacaoInput();
        $input->casoId = $caso->getId();
        $input->descricao = mb_substr($receita->descricao($ehParcela), 0, 255);
        $input->valorOriginal = $ehParcela ? $this->valorNegociadoDaParcela($receita) : $receita->valorDividaCentavos;
        $input->vencimentoOriginal = $receita->vencimento;
        $input->referenciaExterna = $receita->nn;
        $input->competencia = $receita->competencia;

        if ($ehParcela) {
            // Honorário 0 por override de taxa (mesma decisão de `ImportarRelatorioCarteiraUseCase`:
            // acordo não cobra honorário sobre honorário). Diferente do caminho avulso, aqui a linha
            // NÃO é inerte: `modoHonorarios = 'percent'` é o que faz o `ConversorTaxaEncargo` aceitar o
            // bp; no default `'herda'` ele devolve null e o zero seria descartado.
            //
            // Efeito prático: a parcela nasce congelada por `liquidar()` de qualquer jeito, mas se a
            // etapa 1 (exclusão do recebimento) a REABRIR, ela volta a crescer sem honorário — fechando
            // metade da §9.2 da spec-mãe.
            $input->modoHonorarios = 'percent';
            $input->honorariosBp = 0;
        }

        // ⚠️ NÃO há override de taxa aqui, e isso é deliberado — mas custou um comentário falso.
        //
        // Havia um `$input->honorariosBp = 0` com o comentário "encargos vêm da planilha, não da
        // cascata da carteira". A linha era INERTE: `modoHonorarios` fica no default `'herda'`, e
        // `ConversorTaxaEncargo::bpDe` devolve `null` para 'herda' — o bp submetido é descartado antes
        // de chegar à entidade. A obrigação sempre gravou o override herdado da cascata, nunca zero.
        //
        // O que de fato trava os encargos é o `liquidar()` logo abaixo: ele materializa os quatro
        // encargos com os valores da planilha e CONGELA, então a cascata não tem por onde agir. Ou
        // seja, o efeito prometido existe — só não vinha de onde o comentário dizia.
        //
        // A diferença aparece num único caminho: `reabrir()` (exclusão do recebimento, etapa 1)
        // descongela a obrigação, e aí ela volta a crescer pela taxa da CARTEIRA. Isso é decisão de
        // produto, não bug — boleto reaberto virou dívida viva de novo —, e travar em zero seria
        // decidir por conta própria que histórico reaberto nunca acumula encargo. Fica registrado na
        // spec §9 para o dono bater o martelo.
        $obrigacao = $this->registrarObrigacao->executar($input, $tenant, $user);

        if ($ehParcela) {
            $obrigacao->setAcordoOrigem($acordo);
        }

        // ⚠️ O honorário entra em UM lugar só. Na parcela ele já está dentro do `valorOriginal`; se
        // fosse materializado aqui também, `totalComHonorarios()` o contaria duas vezes e a linha
        // nasceria devendo o honorário. Na avulsa é o contrário: ele NÃO está no valorOriginal e
        // precisa ser materializado. As duas metades andam juntas — mexer numa sem a outra é o defeito.
        $obrigacao->liquidar(
            $receita->valorJurosCentavos,
            $receita->valorMultaCentavos,
            0,
            $ehParcela ? 0 : $receita->valorHonorariosCentavos,
            $receita->recebimento,
        );
        $this->obrigacaoRepository->salvar($obrigacao);

        return $obrigacao;
    }

    /**
     * Cria o `Pagamento` do recebimento e a alocação que o amarra à obrigação.
     *
     * ⚠️ O `Pagamento` NÃO muda nesta etapa. Os três baldes (`valorDivida`/`valorEncargos`/
     * `valorHonorarios`) continuam exatamente o que a planilha diz, inclusive na parcela de acordo — a
     * contabilidade já rateou e é contra o rodapé dela que a conferência fecha. O que a etapa 3 muda é
     * só quanto a ALOCAÇÃO abate, porque o exigível da parcela é outro.
     */
    private function registrarRecebimento(
        CasoCobranca $caso,
        Obrigacao $obrigacao,
        ReceitaImportavel $receita,
        int $valorAlocado,
        Tenant $tenant,
        User $user,
    ): void {
        $pagamento = new Pagamento();
        $pagamento->setTenant($tenant);
        $pagamento->setCaso($caso);
        $pagamento->setData($receita->recebimento);
        $pagamento->setValorDivida($receita->valorDividaCentavos);
        $pagamento->setValorEncargos($receita->valorEncargosCentavos());
        $pagamento->setValorHonorarios($receita->valorHonorariosCentavos);
        $pagamento->setCriadoPor($user);

        $alocacao = new AlocacaoPagamento();
        $alocacao->setTenant($tenant);
        $alocacao->setObrigacao($obrigacao);
        $alocacao->setValor($valorAlocado);
        $pagamento->adicionarAlocacao($alocacao);

        $this->pagamentoRepository->salvar($pagamento);

        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::PagamentoRegistrado,
            $user,
            sprintf('Recebimento importado: R$ %s de %s (NN %s)',
                number_format($receita->totalRecebidoCentavos() / 100, 2, ',', '.'),
                $receita->recebimento->format('d/m/Y'),
                $receita->nn,
            ),
            [
                'nn' => $receita->nn,
                'competencia' => $receita->competencia,
                'data' => $receita->recebimento->format('Y-m-d'),
                'valorDivida' => $receita->valorDividaCentavos,
                'valorEncargos' => $receita->valorEncargosCentavos(),
                'valorHonorarios' => $receita->valorHonorariosCentavos,
                'origem' => 'importacao_receitas',
            ],
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

    private function objetoInput(Carteira $carteira, ReceitaImportavel $receita): CriarObjetoInput
    {
        $input = new CriarObjetoInput();
        $input->carteiraId = $carteira->getId();
        $input->identificacao = $receita->objetoIdentificacao;
        $input->descricao = $receita->unidadeMetadata;
        $input->referenciaExterna = $receita->objetoIdentificacao;

        return $input;
    }

    private function pessoaInput(ReceitaImportavel $receita): CriarPessoaInput
    {
        $input = new CriarPessoaInput();
        $input->nome = $receita->sacadoNome;

        return $input;
    }

    private function vinculoInput(?int $pessoaId, ?int $objetoId, Carteira $carteira): VincularPessoaAObjetoInput
    {
        $input = new VincularPessoaAObjetoInput();
        $input->pessoaId = $pessoaId;
        $input->objetoId = $objetoId;
        $input->tipoVinculo = $carteira->getTipoVinculoPreferido() ?? TipoVinculo::Outro;

        return $input;
    }

    private function casoInput(?int $objetoId, ?int $pessoaId): AbrirCasoInput
    {
        $input = new AbrirCasoInput();
        $input->objetoId = $objetoId;
        $input->pessoaCobradaId = $pessoaId;

        return $input;
    }
}
