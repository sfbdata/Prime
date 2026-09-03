<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AbrirCasoInput;
use App\Cobranca\DTO\CriarObjetoInput;
use App\Cobranca\DTO\CriarPessoaInput;
use App\Cobranca\DTO\RegistrarObrigacaoInput;
use App\Cobranca\DTO\VincularPessoaAObjetoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Enum\TipoVinculo;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Exception\MigrationDeCompetenciaPendenteException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\VinculoPessoaObjetoRepository;
use App\Cobranca\Service\Importacao\BoletoImportavel;
use App\Cobranca\Service\Importacao\ReferenciaSubstituta;
use App\Cobranca\Service\Importacao\ResultadoImportacao;
use App\Cobranca\Service\Importacao\ResultadoLeitura;
use App\Cobranca\Service\NormalizadorNome;
use App\Cobranca\Service\ResolvedorPessoaNoObjeto;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Importa em lote um relatório já LIDO por um adapter específico (SPEC §21), sempre dentro de UMA
 * Carteira escolhida explicitamente. Reusa os UseCases do cadastro manual (mesmas regras de negócio) e
 * é IDEMPOTENTE: reimportar o mesmo relatório atualiza em vez de duplicar (chave = Carteira+Objeto+NN).
 *
 * Fonte-agnóstico: recebe `ResultadoLeitura` (boletos já normalizados para o domínio); não sabe que a
 * origem era uma planilha. Decisões de mapeamento em `docs/gestao-cobrancas/MAPEAMENTO_FONTE_TOPLIFE.md`.
 *
 * Por boleto, na ordem: resolve/cria Objeto (dedup por identificação na Carteira) → resolve/cria Pessoa
 * cobrada (por nome no Objeto — decisão A) e Caso cobrável → resolve/cria/atualiza Obrigação (dedup por NN).
 * Encargos entram SEPARADOS (juros/multa/correção) e a obrigação nasce VIVA (ao vivo, sem congelar) —
 * ver `materializarEncargosImportados()`: o snapshot do relatório é só o valor INICIAL/cache, e a leitura
 * recalcula (vencimento → hoje × taxa), reproduzindo os números da contabilidade ao centavo quando a
 * carteira está configurada (spec §2/§9-revisado). Honorários do relatório passam a ser persistidos
 * (§4.2) e ENTRAM no valor exigível desde a spec `cobranca-honorario-no-total.md` (INV-E2 revogada).
 * NUNCA cruza tenants.
 *
 * Linhas de acordo (spec `cobranca-importar-linhas-acordo.md` §3.2, tarefa #7-B): quando o adapter
 * reconhece "Acordo N - Parc. p/t" na coluna do relatório (`BoletoImportavel::$acordo`), o NN vira uma
 * PARCELA de um Acordo de verdade, não uma obrigação solta — ver `resolverOuCriarAcordo()`.
 */
final class ImportarRelatorioCarteiraUseCase
{
    public function __construct(
        private readonly CarteiraRepository $carteiraRepository,
        private readonly ObjetoCobrancaRepository $objetoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly VinculoPessoaObjetoRepository $vinculoRepository,
        private readonly AcordoRepository $acordoRepository,
        private readonly CriarObjetoUseCase $criarObjeto,
        private readonly CriarPessoaUseCase $criarPessoa,
        private readonly VincularPessoaAObjetoUseCase $vincular,
        private readonly AbrirCasoUseCase $abrirCaso,
        private readonly RegistrarObrigacaoUseCase $registrarObrigacao,
        private readonly EntityManagerInterface $em,
        private readonly ResolvedorPessoaNoObjeto $resolvedorPessoa,
    ) {
    }

    /**
     * Dry-run: projeta o que ACONTECERIA (SPEC §21 — análise/validação antes da confirmação), sem
     * persistir nada. Read-only: só consulta existência de objeto/caso/obrigação.
     */
    public function prever(int $carteiraId, ResultadoLeitura $leitura, Tenant $tenant): ResultadoImportacao
    {
        $this->exigirSchemaDaCompetencia();
        $carteira = $this->resolverCarteira($carteiraId, $tenant);

        $criadas = [];
        $atualizadas = [];
        $referenciasReutilizadas = [];
        $vencimentosAlterados = [];
        $centavosSemBoleto = 0;
        $divergentes = [];
        $objetosNovos = [];
        $temCasoPorObjeto = [];   // identificacao => bool (caso cobrável real ou simulado nesta prévia)
        $nomesPorObjeto = [];     // identificacao => list<string> nomes normalizados já representados
        $pessoasNovas = 0;
        $casosNovos = 0;

        foreach ($leitura->importaveis as $boleto) {
            $identif = $boleto->objetoIdentificacao;
            $objeto = $this->objetoRepository->findOnePorIdentificacaoNaCarteira($carteira, $identif, $tenant);
            if ($objeto === null && !isset($objetosNovos[$identif])) {
                $objetosNovos[$identif] = true;
            }

            $caso = $objeto !== null ? ($this->casoRepository->casosCobraveisDoObjeto($objeto)[0] ?? null) : null;
            if (!isset($nomesPorObjeto[$identif])) {
                $nomesPorObjeto[$identif] = $this->nomesRepresentadosNoObjeto($objeto, $caso);
                $temCasoPorObjeto[$identif] = $caso !== null;
            }

            $nomeBoleto = NormalizadorNome::normalizar($boleto->sacadoNome);
            if ($temCasoPorObjeto[$identif] === false) {
                ++$casosNovos;
                // PARIDADE com `confirmar()`: lá a pessoa só nasce quando NÃO há, no objeto, alguém com
                // o mesmo nome (o caso da unidade que veio do cadastro). `nomesPorObjeto` já traz as
                // pessoas vinculadas, então a mesma pergunta se responde aqui. Contar pessoa nova
                // incondicionalmente faria a prévia prometer 45 criações e a confirmação criar 0.
                if ($nomeBoleto === null || !in_array($nomeBoleto, $nomesPorObjeto[$identif], true)) {
                    ++$pessoasNovas;
                    if ($nomeBoleto !== null) {
                        $nomesPorObjeto[$identif][] = $nomeBoleto;
                    }
                }
                $temCasoPorObjeto[$identif] = true;
            } elseif ($nomeBoleto !== null && !in_array($nomeBoleto, $nomesPorObjeto[$identif], true)) {
                ++$pessoasNovas;
                $divergentes[] = $identif;
                $nomesPorObjeto[$identif][] = $nomeBoleto;
            }

            // Chave (caso, NN, competência) — ver `cobranca-importar-chave-competencia.md`. Mesmo NN com
            // competência diferente é OUTRA dívida: a prévia tem de mostrá-la como criação, não como
            // atualização, senão o operador confirma sem saber que vai nascer uma obrigação a mais.
            $existente = $caso !== null
                ? $this->obrigacaoRepository->findOnePorReferenciaECompetenciaNoCaso($caso, $boleto->nn, $boleto->competencia)
                : null;
            $centavosSemBoleto += $this->centavosSemBoletoDoBoleto($boleto);
            if ($existente !== null) {
                $atualizadas[] = $boleto->nn;
                if ($this->vencimentoMudou($existente, $boleto)) {
                    $vencimentosAlterados[] = $boleto->nn;
                }

                continue;
            }

            if ($caso !== null && $this->obrigacaoRepository->findOnePorReferenciaExternaNoCaso($caso, $boleto->nn) !== null) {
                $referenciasReutilizadas[] = $boleto->nn;
            }

            $criadas[] = $boleto->nn;
        }

        return new ResultadoImportacao($criadas, $atualizadas, $leitura->rejeitadas, $leitura->linhasIgnoradas, count($objetosNovos), $pessoasNovas, $casosNovos, $divergentes, $referenciasReutilizadas, $vencimentosAlterados, $centavosSemBoleto);
    }

    /**
     * Persiste a importação numa transação única (ou tudo, ou nada). Idempotente na reimportação.
     *
     * Data de referência do congelamento (spec §9 pede "a data do relatório"): a fonte lida hoje NÃO
     * carrega essa data — `ResultadoLeitura`/`BoletoImportavel` não a expõem e inventar um campo aqui
     * seria mudar contrato compartilhado. Usa-se então o momento da importação, capturado UMA vez para
     * o lote inteiro, de modo que todos os boletos do mesmo relatório fiquem com a mesma referência
     * (importante para auditoria: um lote = uma data, não N timestamps espalhados pelo loop).
     */
    public function confirmar(int $carteiraId, ResultadoLeitura $leitura, Tenant $tenant, User $user): ResultadoImportacao
    {
        $this->exigirSchemaDaCompetencia();
        $carteira = $this->resolverCarteira($carteiraId, $tenant);
        $referencia = new \DateTimeImmutable();

        return $this->em->wrapInTransaction(function () use ($carteira, $leitura, $tenant, $user, $referencia): ResultadoImportacao {
            $criadas = [];
            $atualizadas = [];
            $referenciasReutilizadas = [];
            $vencimentosAlterados = [];
            $centavosSemBoleto = 0;
            $divergentes = [];
            $objetosCriados = 0;
            $pessoasCriadas = 0;
            $casosCriados = 0;

            foreach ($leitura->importaveis as $boleto) {
                $objeto = $this->objetoRepository->findOnePorIdentificacaoNaCarteira($carteira, $boleto->objetoIdentificacao, $tenant);
                if ($objeto === null) {
                    $objeto = $this->criarObjeto->executar($this->objetoInput($carteira, $boleto), $tenant, $user);
                    ++$objetosCriados;
                }

                $caso = $this->casoRepository->casosCobraveisDoObjeto($objeto)[0] ?? null;
                if ($caso === null) {
                    // Sem caso, a unidade PODE já ter dono: é o estado que o importe de cadastro deixa
                    // (pessoa vinculada, com CPF e contato, e nenhum caso aberto). Criar pessoa aqui sem
                    // olhar quem já está no objeto duplicava o devedor e fazia o caso cobrar a cópia sem
                    // documento — 9 unidades pela inadimplência e 45 pela receita, de 51, na AMLI.
                    // Spec: docs/specs/cobranca-importe-nao-duplica-devedor-do-cadastro.md
                    $pessoa = $this->resolvedorPessoa->porNome($objeto, $boleto->sacadoNome);
                    if ($pessoa === null) {
                        $pessoa = $this->criarPessoa->executar($this->pessoaInput($boleto), $tenant, $user);
                        ++$pessoasCriadas;
                        $this->vincular->executar($this->vinculoInput($pessoa->getId(), $objeto->getId(), $carteira), $tenant, $user);
                    }
                    $caso = $this->abrirCaso->executar($this->casoInput($objeto->getId(), $pessoa->getId()), $tenant, $user);
                    ++$casosCriados;
                } elseif (!$this->sacadoJaRepresentadoNoObjeto($objeto, $caso, $boleto->sacadoNome)) {
                    // Decisão A: sacado NOVO no MESMO objeto → nova Pessoa + vínculo. Idempotente: só cria
                    // se ainda não há Pessoa com esse nome vinculada ao objeto (reimport reusa). NÃO troca
                    // a pessoa cobrada automaticamente (decisão jurídica sensível é humana — §28); só reporta.
                    $pessoa = $this->criarPessoa->executar($this->pessoaInput($boleto), $tenant, $user);
                    ++$pessoasCriadas;
                    $this->vincular->executar($this->vinculoInput($pessoa->getId(), $objeto->getId(), $carteira), $tenant, $user);
                    $divergentes[] = $boleto->objetoIdentificacao;
                }

                // Linha de acordo (§3.2): acha/cria o Acordo por (carteira + número) ANTES de resolver a
                // obrigação — a parcela precisa apontar pra ele nos dois ramos (nova ou reimportação).
                $acordo = $boleto->acordo !== null
                    ? $this->resolverOuCriarAcordo($carteira, $caso, $boleto, $tenant, $user)
                    : null;

                // Chave (caso, NN, competência) — `cobranca-importar-chave-competencia.md`. Buscar só pelo
                // NN engolia o boleto novo quando a contábil reaproveitava o número, e ainda gravava os
                // encargos dele sobre a dívida antiga.
                $centavosSemBoleto += $this->centavosSemBoletoDoBoleto($boleto);
                $obrigacao = $this->obrigacaoRepository->findOnePorReferenciaECompetenciaNoCaso($caso, $boleto->nn, $boleto->competencia);
                if ($obrigacao === null) {
                    if ($this->obrigacaoRepository->findOnePorReferenciaExternaNoCaso($caso, $boleto->nn) !== null) {
                        // Mesmo NN, competência diferente: outra dívida. Nasce separada e o operador
                        // precisa ver isso no resumo — silêncio aqui é o defeito que a spec corrige.
                        $referenciasReutilizadas[] = $boleto->nn;
                    }
                    $obrigacao = $this->registrarObrigacao->executar($this->obrigacaoInput($caso->getId(), $boleto), $tenant, $user);
                    if ($acordo !== null) {
                        $obrigacao->setAcordoOrigem($acordo);
                        $this->obrigacaoRepository->salvar($obrigacao, true);
                    }
                    $this->materializarEncargosImportados($obrigacao, $boleto, $referencia);
                    $criadas[] = $boleto->nn;

                    continue;
                }

                // Idempotência (§3.2.4): a parcela já existe (dedup por NN) — garante que ela aponta pro
                // acordo achado/criado acima (cobre a obrigação legada de uma 1ª importação anterior a
                // este ajuste, que nasceu sem acordoOrigem).
                if ($acordo !== null && $obrigacao->getAcordoOrigem() !== $acordo) {
                    $obrigacao->setAcordoOrigem($acordo);
                    $this->obrigacaoRepository->salvar($obrigacao, true);
                }

                // Boleto reemitido: mesma dívida (mesma competência) com vencimento novo. A data gravada
                // NÃO é alterada — o importado preserva o cadastro (invariável 20) —, mas o operador tem
                // de ficar sabendo, porque os encargos passam a correr sobre uma data que não é a do
                // documento que ele tem em mãos.
                if ($this->vencimentoMudou($obrigacao, $boleto)) {
                    $vencimentosAlterados[] = $boleto->nn;
                }

                // Reimportação idempotente: atualiza SÓ os encargos ao snapshot novo e RE-CONGELA na data
                // nova (spec §9). Preserva o valorOriginal (invariável 20) e não duplica.
                $this->materializarEncargosImportados($obrigacao, $boleto, $referencia);
                $atualizadas[] = $boleto->nn;
            }

            return new ResultadoImportacao($criadas, $atualizadas, $leitura->rejeitadas, $leitura->linhasIgnoradas, $objetosCriados, $pessoasCriadas, $casosCriados, $divergentes, $referenciasReutilizadas, $vencimentosAlterados, $centavosSemBoleto);
        });
    }

    /**
     * Grava os encargos do relatório SEPARADOS (juros/multa/correção/honorários) como o valor INICIAL da
     * obrigação (cache). Encargos AO VIVO (D6): NÃO congela mais — a obrigação nasce VIVA e a leitura
     * recalcula (vencimento → hoje × taxa). A fórmula reproduz ao centavo os números da contabilidade
     * (spec §2), então, com a carteira configurada, o vivo bate com o importado; sem isso, os valores
     * importados servem de partida até a primeira hidratação. Honorários são persistidos (§4.2) e
     * AFETAM o saldo: desde a spec `cobranca-honorario-no-total.md` o `valorExigivel()` é
     * `valorOriginal + juros + multa + correção + honorários` (INV-E2 revogada).
     *
     * 🔑 **O RAMO É OBRIGATÓRIO — sem ele o mesmo dinheiro entra duas vezes** (spec
     * `cobranca-parcela-de-acordo-so-encargos.md` §5, defeito 2; INV-E5 no `BoletoImportavel`).
     *
     * Na **parcela de acordo**, `valorOriginal` é `somaColunaValorCentavos` (`obrigacaoInput()`), que
     * soma a coluna Valor de TODAS as classes — inclusive das linhas `1.4 - Juros`, `1.5 - Multas` e
     * `1.15 - Honorário`. Gravar aqui o encargo que também soma o H dessas linhas põe o mesmo valor
     * no principal e no encargo. Medido em produção (13/08): **21 obrigações, R$ 1.167,58**, com a
     * pior delas carregando R$ 301,62 de principal contado como multa.
     *
     * No **boleto comum** nada muda: ali `valorOriginal` é `principalCentavos`, que **não** contém
     * aquelas linhas, e somá-las ao encargo é o comportamento correto e já provado (INV-E1).
     *
     * ✅ **Isto ESPELHA a régua da contabilidade — não é interpretação nossa.** Medido no lote de
     * 12/08, nas parcelas de acordo da TOP LIFE I: a multa que a contabilidade lança em cada linha é
     * **exatamente 2% do Valor daquela linha**, em `259/259` linhas de taxa, `114/114` de energia
     * **e também em `23/23` de multa, `21/21` de juros e `23/23` de honorário**.
     *
     * Ela cobra multa **sobre** a linha de multa. Só existe uma leitura possível: para a
     * contabilidade aquele valor é **principal** — dívida velha incorporada ao acordo —, não encargo.
     * Se fosse encargo, ela não cobraria encargo em cima.
     *
     * No NN 74789 a multa que a contabilidade mostra é **R$ 8,00** (2% de R$ 399,37). Os R$ 309,62
     * que o sistema gravava não existiam em nenhuma coluna da planilha: eram a soma que este método
     * fazia. O conserto não muda a régua — devolve a régua deles.
     */
    private function materializarEncargosImportados(Obrigacao $obrigacao, BoletoImportavel $boleto, \DateTimeImmutable $referencia): void
    {
        $ehParcelaDeAcordo = $boleto->acordo !== null;

        $obrigacao->definirEncargos(
            $ehParcelaDeAcordo ? $boleto->jurosDasColunasCentavos : $boleto->jurosCentavos,
            $ehParcelaDeAcordo ? $boleto->multaDasColunasCentavos : $boleto->multaCentavos,
            // Correção é a coluna K nas duas versões: nenhuma classe de linha vira correção.
            $boleto->correcaoCentavos,
            $ehParcelaDeAcordo ? $boleto->honorariosDasColunasCentavos : $boleto->honorariosInformadosCentavos,
            $referencia,
        );
        $this->obrigacaoRepository->salvar($obrigacao, true);
    }

    /**
     * Acha o Acordo por (carteira + número externo) ou cria um novo (§3.2.2). NÃO usa
     * `CriarAcordoUseCase`: aquele valida fechamento (INV-B) e obrigações substituídas — regras da
     * criação MANUAL, que não fazem sentido aqui (o import não substitui nada, só materializa a
     * parcela que o relatório já traz pronta). O acordo pertence ao CASO do objeto.
     *
     * Idempotência (§3.2.4): reimportar reusa o mesmo Acordo (achado pela dedup); se ele nasceu antes
     * de se saber o total de parcelas (ou a 1ª leitura não trouxe `parcelaTotal`), completa quando um
     * total aparece.
     *
     * `valorTotalNegociado` (correção pós-taxa #7-3): só é DERIVÁVEL quando `parcelaTotal === 1` — o
     * relatório traz o acordo INTEIRO nessa única linha, então o total negociado é a soma da coluna
     * Valor dela (`somaColunaValorCentavos`, mesma fonte do `valorOriginal` da parcela). Multi-parcela
     * (`parcelaTotal > 1`) fica `null`: as parcelas 2..N não estão neste relatório — inventar o total
     * seria "chutar" um dado que a fonte não fornece (`MontarDetalheAcordoUseCase` já faz o fallback
     * derivado de Σ parcelas quando `null`). Idempotente: reimportar um 1/1 não regrava se o valor não
     * mudou.
     */
    private function resolverOuCriarAcordo(Carteira $carteira, CasoCobranca $caso, BoletoImportavel $boleto, Tenant $tenant, User $user): Acordo
    {
        $doRelatorio = $boleto->acordo;
        if ($doRelatorio === null) {
            // Defesa: só é chamado quando o chamador já checou `$boleto->acordo !== null`.
            throw new \LogicException('resolverOuCriarAcordo chamado sem acordo reconhecido no boleto.');
        }

        $acordo = $this->acordoRepository->findOnePorNumeroExternoNaCarteira($doRelatorio->numero, $carteira, $tenant);
        if ($acordo === null) {
            $acordo = new Acordo();
            $acordo->setTenant($tenant);
            $acordo->setCaso($caso);
            $acordo->setStatus(StatusAcordo::Ativo);
            // VIOLAÇÃO #3 REMOVIDA (17/08): aqui se gravava `dataAcordoPadrao()` — o 1º dia do mês da
            // competência. Este relatório NÃO traz a data do acordo, e o que a fonte não dá o sistema não
            // inventa: o acordo nasce SEM data e o relatório de acordos detalhados a preenche depois
            // (`ImportarAcordosDetalhadosUseCase`, ramo de atualização). Medido em prod antes de remover:
            // 375 de 395 acordos carregavam a data chutada, com R$ 203.265,07 de encargo assentado nela e
            // 256 dívidas zeradas porque a data inventada precedia o próprio vencimento delas.
            $acordo->setNumeroExterno($doRelatorio->numero);
            $acordo->setNumeroParcelasTotal($doRelatorio->parcelaTotal);
            $acordo->setCriadoPor($user);
            if ($doRelatorio->parcelaTotal === 1) {
                $acordo->setValorTotalNegociado($boleto->somaColunaValorCentavos);
            }
            $this->acordoRepository->salvar($acordo, true);

            return $acordo;
        }

        $sujo = false;
        if ($acordo->getNumeroParcelasTotal() === null && $doRelatorio->parcelaTotal > 0) {
            $acordo->setNumeroParcelasTotal($doRelatorio->parcelaTotal);
            $sujo = true;
        }
        if ($doRelatorio->parcelaTotal === 1 && $acordo->getValorTotalNegociado() !== $boleto->somaColunaValorCentavos) {
            $acordo->setValorTotalNegociado($boleto->somaColunaValorCentavos);
            $sujo = true;
        }
        if ($sujo) {
            $this->acordoRepository->salvar($acordo, true);
        }

        return $acordo;
    }

    /**
     * O sacado do boleto já é uma Pessoa representada no objeto? Casa se for a pessoa cobrada atual OU
     * qualquer Pessoa já vinculada ao objeto (por nome normalizado). Garante que a reimportação de um
     * sacado divergente NÃO recrie a Pessoa toda vez (idempotência da decisão A). Sempre intra-tenant.
     */
    private function sacadoJaRepresentadoNoObjeto(ObjetoCobranca $objeto, CasoCobranca $caso, string $sacado): bool
    {
        $alvo = NormalizadorNome::normalizar($sacado);

        return in_array($alvo, $this->nomesRepresentadosNoObjeto($objeto, $caso), true);
    }

    /**
     * Nomes normalizados já representados no objeto: a pessoa cobrada do caso + as pessoas vinculadas.
     *
     * @return list<string>
     */
    private function nomesRepresentadosNoObjeto(?ObjetoCobranca $objeto, ?CasoCobranca $caso): array
    {
        $nomes = [];
        if ($caso !== null) {
            $cobrada = NormalizadorNome::normalizar($caso->getPessoaCobradaAtual()?->getNome());
            if ($cobrada !== null) {
                $nomes[] = $cobrada;
            }
        }
        if ($objeto !== null) {
            foreach ($this->vinculoRepository->pessoasVinculadasAoObjeto($objeto) as $pessoa) {
                $nome = NormalizadorNome::normalizar($pessoa->getNome());
                if ($nome !== null && !in_array($nome, $nomes, true)) {
                    $nomes[] = $nome;
                }
            }
        }

        return $nomes;
    }

    /**
     * Para antes de escrever se a coluna `competencia` não existe (`Version20260730120000`).
     *
     * A guarda nasceu no importador de acordos, mas ESTE é o caminho que o gestor usa — pela tela. Sem
     * ela, a importação visual morre com um traço de driver no meio do lote (rollback limpo, nada suja o
     * banco) e nada indica que o que falta é rodar a migration.
     */
    private function exigirSchemaDaCompetencia(): void
    {
        if (!$this->obrigacaoRepository->schemaTemCompetencia()) {
            throw new MigrationDeCompetenciaPendenteException();
        }
    }

    private function resolverCarteira(int $carteiraId, Tenant $tenant): Carteira
    {
        $carteira = $this->carteiraRepository->findOneByIdDoTenant($carteiraId, $tenant);
        if ($carteira === null) {
            throw new CarteiraNaoEncontradaException($carteiraId);
        }

        return $carteira;
    }

    private function objetoInput(Carteira $carteira, BoletoImportavel $boleto): CriarObjetoInput
    {
        $input = new CriarObjetoInput();
        $input->carteiraId = $carteira->getId();
        $input->identificacao = $boleto->objetoIdentificacao;
        $input->descricao = $boleto->unidadeMetadata;
        $input->referenciaExterna = $boleto->objetoIdentificacao;

        return $input;
    }

    private function pessoaInput(BoletoImportavel $boleto): CriarPessoaInput
    {
        $input = new CriarPessoaInput();
        $input->nome = $boleto->sacadoNome;

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

    /**
     * Quanto de DÍVIDA (principal + juros + multa + correção) o boleto acrescenta ao balde "sem
     * boleto" — zero quando ele tem Nosso Número de verdade.
     *
     * ⏳ **PENDÊNCIA NOMEADA, não decidida aqui.** O honorário fica de fora desta soma, e a
     * justificativa escrita era a INV-E2 ("não é dívida do credor"), que a spec
     * `cobranca-honorario-no-total.md` REVOGOU. O número não foi mudado junto: este balde é um
     * indicador operacional ("quanto há sem boleto emitido"), não o exigível, e trocá-lo seria decidir
     * por conta própria quanto o operador deve ver — exatamente o que a §1.1 proíbe. Fica registrado
     * para a fatia da varredura, com o defeito visível em vez de escondido atrás de um comentário
     * atualizado.
     */
    private function centavosSemBoletoDoBoleto(BoletoImportavel $boleto): int
    {
        if (!ReferenciaSubstituta::ehSubstituta($boleto->nn)) {
            return 0;
        }

        return $boleto->principalCentavos + $boleto->encargosCentavos();
    }

    /**
     * O relatório traz vencimento diferente do que está gravado? Acontece quando a contábil REEMITE o
     * boleto para o devedor conseguir pagar: mesmo NN, mesma competência, data nova. É a mesma dívida
     * (por isso não duplica), mas o operador precisa ver — os encargos passam a correr de uma data que
     * não é a do documento em mãos.
     *
     * 📌 Este docblock estava DESLOCADO: vivia colado em `centavosSemBoletoDoBoleto`, empilhado sobre o
     * docblock de lá, onde o PHP o ignorava. Devolvido ao método que ele descreve.
     */
    private function vencimentoMudou(Obrigacao $obrigacao, BoletoImportavel $boleto): bool
    {
        return $obrigacao->getVencimentoOriginal()->format('Y-m-d') !== $boleto->vencimento->format('Y-m-d');
    }

    private function obrigacaoInput(?int $casoId, BoletoImportavel $boleto): RegistrarObrigacaoInput
    {
        $descricao = $boleto->descricao();
        $observacao = $boleto->observacao();
        if ($observacao !== null) {
            $descricao .= ' | ' . $observacao;
        }

        $input = new RegistrarObrigacaoInput();
        $input->casoId = $casoId;
        $input->descricao = mb_substr($descricao, 0, 255);
        $input->vencimentoOriginal = $boleto->vencimento;
        $input->referenciaExterna = $boleto->nn;
        $input->competencia = $boleto->competencia;

        if ($boleto->acordo !== null) {
            // Parcela de acordo (§3.2.3): valorOriginal É o principal NEGOCIADO — soma da coluna Valor
            // de TODAS as linhas do NN (o honorário do relatório já está embutido nela, decisão do
            // dono). NUNCA `principalCentavos` (que só soma classes 1.1/1.14/1.6): usar o principal
            // "comum" aqui subestimaria o valor negociado quando o relatório lista juros/multa/honorário
            // como linhas próprias dentro do NN. Honorários = 0 (decisão #8: acordo não cobra
            // honorário sobre honorário) via override de taxa — ao vivo, sem congelar. Juros/multa/
            // correção continuam herdando a cascata Carteira→Caso (crescem ao vivo a partir do
            // vencimento da parcela).
            $input->valorOriginal = $boleto->somaColunaValorCentavos;
            $input->modoHonorarios = 'percent';
            $input->honorariosBp = 0;
        } else {
            $input->valorOriginal = $boleto->principalCentavos;
        }

        return $input;
    }
}
