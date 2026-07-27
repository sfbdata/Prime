<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Entity\Auth\User;
use App\Cobranca\DTO\AcordoOutput;
use App\Cobranca\DTO\GrupoAcordoObrigacoesOutput;
use App\Cobranca\DTO\CasoDetalheOutput;
use App\Cobranca\DTO\EventoHistoricoOutput;
use App\Cobranca\DTO\LiquidacaoOutput;
use App\Cobranca\DTO\ObrigacaoOutput;
use App\Cobranca\DTO\PagamentoOutput;
use App\Cobranca\DTO\ProximaAcaoOutput;
use App\Cobranca\DTO\QualificacaoContatoOutput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Cobranca\Service\AlertasCobranca;
use App\Cobranca\Service\CalculadoraHonorarios;
use App\Cobranca\Service\CalculadoraPrescricao;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\Service\EncargosVivos;
use App\Cobranca\Service\ResolvedorConfigEncargos;

/**
 * Leitura: monta o detalhe completo do Caso — a tela central (SPEC §9/§26, Etapa 8). Agrega o
 * cabeçalho operacional (saldo derivado, estado, pessoa cobrada, próxima ação, alertas) e as
 * coleções das abas (obrigações, pagamentos, liquidações, acordos, histórico) a partir dos repos
 * tenant-scoped e dos serviços de derivação. O caso já vem resolvido por tenant no controller;
 * nada aqui recalcula regra de negócio — só lê e formata via Output DTOs. Documentos entram na 8C.
 *
 * Ajuste 10 (T5): é aqui que o prefill do "Receber" é derivado (`ObrigacaoOutput::brutoSugerido`),
 * delegando a `CalculadoraHonorarios` — este UseCase é quem tem, ao mesmo tempo, o alocado por
 * obrigação e o caso (do qual a calculadora resolve a política de honorários). A regra continua a
 * morar na calculadora.
 *
 * Redesenho do cabeçalho (2026-07-27): os quatro cards de dinheiro (§1.2), a contagem de obrigações em
 * aberto (§1.1) e o aviso de prescrição (§1.3) são somados/derivados AQUI, sobre EXATAMENTE o conjunto
 * que a aba Dívida lista — nunca no Twig. A listinha de qualificações do painel (§3.4) sai da mesma
 * leitura da linha do tempo que já alimenta o histórico.
 *
 * #9-T2: `formaHonorariosLabel`/`percentualHonorarios` do hero NÃO leem mais o snapshot do caso —
 * a FORMA vem da carteira e a ALÍQUOTA da cascata do objeto (`ResolvedorConfigEncargos::resolverDoObjeto`),
 * a mesma fonte que `CalculadoraHonorarios` usa no split (fecha a divergência I-1).
 */
final class MontarDetalheCasoUseCase
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly PagamentoRepository $pagamentoRepository,
        private readonly LiquidacaoRepository $liquidacaoRepository,
        private readonly AcordoRepository $acordoRepository,
        private readonly EventoHistoricoRepository $eventoRepository,
        private readonly ProximaAcaoRepository $proximaAcaoRepository,
        private readonly CalculadoraSaldo $calculadoraSaldo,
        private readonly AlertasCobranca $alertasCobranca,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly CalculadoraHonorarios $calculadoraHonorarios,
        private readonly ResolvedorConfigEncargos $resolvedorConfig,
        private readonly EncargosVivos $encargosVivos,
        private readonly CalculadoraPrescricao $calculadoraPrescricao,
    ) {
    }

    /**
     * `$usuarioAtual` só serve para decidir, POR LINHA do histórico, se aquele leitor pode corrigir a
     * própria anotação (ajuste 2026-07-22). Opcional: quem não passa (ex.: a Central) recebe tudo com
     * `editavel: false`, que é o correto — lá ninguém edita nada.
     */
    public function executar(CasoCobranca $caso, ?User $usuarioAtual = null): CasoDetalheOutput
    {
        // Fonte de tempo ÚNICA: o mesmo relógio da hidratação (spec §5) — a data do "vencido" e do
        // encargo não podem divergir, e nada de `new \DateTimeImmutable()` no caminho do dinheiro.
        $hoje = $this->encargosVivos->agora();

        // Config efetiva do CASO (cascata Carteira → Objeto, T1) resolvida UMA vez e reusada abaixo
        // na hidratação, no rótulo/alíquota de honorários do hero (T2) e na base do espelho %↔R$.
        $configCaso = $this->resolvedorConfig->resolverDoCaso($caso);

        // Encargos AO VIVO (spec §6.2/INV-V5): hidrata EM MEMÓRIA as obrigações EXIGÍVEIS (vivas) do
        // caso para HOJE — assim exibição e saldo leem o MESMO exigível vivo. Congeladas
        // (Liquidada/Substituída) e não-exigíveis (substituídas por acordo vigente, parcelas de
        // acordo rompido) ficam fora de `doCasoExigiveis` e mantêm o snapshot. As instâncias managed
        // são as mesmas que `doCaso` devolve abaixo (identity map do Doctrine): a hidratação aqui não
        // depende do efeito colateral do `saldoExigivel`.
        $this->encargosVivos->hidratar($configCaso, $this->obrigacaoRepository->doCasoExigiveis($caso));

        $objeto = $caso->getObjeto();
        $carteira = $objeto?->getCarteira();
        $pessoa = $caso->getPessoaCobradaAtual();
        $status = $caso->getStatus();
        $saldoExigivel = $this->calculadoraSaldo->saldoExigivel($caso);

        $acaoAtiva = $this->proximaAcaoRepository->findAtivaDoCaso($caso);

        // Aba Obrigações (Ajuste 8): as parcelas de acordo VIGENTE saem da lista solta e viram grupo.
        // Ajuste 10: UMA query para o mapa `obrigacaoId => alocado` do caso inteiro — mesmo padrão de
        // `MontarDetalheAcordoUseCase:39`. Nunca por obrigação (N+1).
        $alocadoPorObrigacao = $this->alocacaoRepository->somasPorObrigacaoDosCasos(
            [$caso->getId()],
            $caso->getTenant(),
        );

        // O `restante` é recalculado aqui (e não lido de `ObrigacaoOutput::restante()`) porque o DTO ainda
        // não existe neste ponto. A fórmula é a MESMA do DTO e `valorExigivel()` é a fonte única
        // (`Obrigacao::valorExigivel`) — a regra não é duplicada em lugar nenhum.
        $obrigacoes = array_map(
            function (Obrigacao $o) use ($caso, $alocadoPorObrigacao): ObrigacaoOutput {
                $alocado = $alocadoPorObrigacao[$o->getId()] ?? 0;
                $restante = max(0, $o->valorExigivel() - $alocado);

                return ObrigacaoOutput::fromEntity(
                    $o,
                    $alocado,
                    // O prefill do "Receber": o bruto cuja parte-dívida é exatamente o restante (spec §5.1).
                    $this->calculadoraHonorarios->brutoParaRecuperar($caso, $restante),
                    // Config resolvida (cascata) só para a UI saber a BASE de multa/honorários e exibir o
                    // "%" sobre a base certa. Grafo caso→objeto→carteira já carregado — sem query nova.
                    $this->resolvedorConfig->resolver($o),
                );
            },
            $this->obrigacaoRepository->doCaso($caso),
        );
        $acordos = array_map(AcordoOutput::fromEntity(...), $this->acordoRepository->doCaso($caso));
        [$gruposAcordo, $obrigacoesAvulsas] = $this->agruparPorAcordo($obrigacoes, $acordos);

        // Totais da aba Honorários: somados sobre o MESMO conjunto que a aba lista (avulsas + parcelas
        // dos grupos), para o rodapé sempre bater com as linhas visíveis. Ver a nota no DTO.
        $listadasNaAba = $obrigacoesAvulsas;
        foreach ($gruposAcordo as $grupo) {
            foreach ($grupo->parcelas as $parcela) {
                $listadasNaAba[] = $parcela;
            }
        }

        // Os quatro cards de dinheiro do cabeçalho (spec §1.2) saem do MESMO laço e do MESMO conjunto
        // que os totais da aba Honorários: `$listadasNaAba` restrito ao que ainda não foi quitado. Somar
        // aqui (e não no Twig) é o que permite ter teste — e é o que garante que o cabeçalho nunca
        // discorde da soma das linhas que a aba Dívida mostra logo abaixo.
        $honorariosDasObrigacoes = 0;
        $honorariosEmAberto = 0;
        $totalPrincipalEmAberto = 0;
        $totalEncargosEmAberto = 0;
        $obrigacoesEmAbertoQtd = 0;
        $emAberto = [];
        foreach ($listadasNaAba as $listada) {
            $honorariosDasObrigacoes += $listada->honorarios;

            if ($listada->quitada()) {
                continue;
            }

            ++$obrigacoesEmAbertoQtd;
            $emAberto[] = $listada;
            $honorariosEmAberto += $listada->honorarios;
            $totalPrincipalEmAberto += $listada->valorOriginal;
            // Os três encargos, e não `valorAtual - valorOriginal`: a subtração daria o mesmo número
            // (INV-E1) mas esconderia de qual parcela ele veio, e o card se chama "Juros e multa".
            $totalEncargosEmAberto += $listada->juros + $listada->multa + $listada->correcao;
        }

        // Extraído para variável (antes era montado direto no construtor) porque agora é lido DUAS vezes:
        // a lista da aba e o total de honorários recebidos precisam vir do mesmo array, não de duas
        // consultas que poderiam divergir.
        $pagamentos = array_map(PagamentoOutput::fromEntity(...), $this->pagamentoRepository->doCaso($caso));

        $honorariosRecebidos = 0;
        foreach ($pagamentos as $pagamento) {
            $honorariosRecebidos += $pagamento->valorHonorarios;
        }

        // #9-T2: FORMA sempre da carteira (não sobreponível); ALÍQUOTA já resolvida em `$configCaso`
        // (cascata do objeto) — a mesma fonte que `CalculadoraHonorarios` usa no split. O snapshot do
        // caso (`getFormaHonorarios`/`getPercentualHonorarios`) não é mais lido aqui.
        $formaHonorarios = $carteira?->getFormaHonorarios() ?? FormaHonorarios::SemPercentual;
        $percentualHonorarios = $formaHonorarios->exigePercentual()
            ? $this->formatarPercentualDeBp($configCaso->taxaHonorariosBp)
            : null;

        // UMA leitura da linha do tempo serve aos dois consumidores: o histórico completo e a listinha
        // de qualificações do painel (spec §3.4). Antes o array era montado direto no construtor; agora
        // é variável porque é percorrido duas vezes — nunca duas consultas, que poderiam divergir.
        $eventos = $this->eventoRepository->doCaso($caso);

        return new CasoDetalheOutput(
            id: $caso->getId() ?? 0,
            objetoIdentificacao: $objeto?->getIdentificacao() ?? '—',
            objetoDescricao: $objeto?->getDescricao(),
            carteiraId: $carteira?->getId() ?? 0,
            carteiraNome: $carteira?->getNome() ?? '—',
            pessoaCobradaNome: $pessoa?->getNome() ?? '—',
            pessoaCobradaCpf: $pessoa?->getCpf(),
            pessoaCobradaCnpj: $pessoa?->getCnpj(),
            pessoaCobradaEmail: $pessoa?->getEmail(),
            pessoaCobradaTelefone: $pessoa?->getTelefone(),
            statusLabel: $status->label(),
            statusBadgeClass: $status->badgeClass(),
            encerrado: $status === StatusCaso::Encerrado,
            prontoParaEncerrar: $status !== StatusCaso::Encerrado && $saldoExigivel === 0,
            saldoExigivel: $saldoExigivel,
            saldoVencido: $this->calculadoraSaldo->saldoVencido($caso, $hoje),
            formaHonorariosLabel: $formaHonorarios->label(),
            percentualHonorarios: $percentualHonorarios,
            pastaJudicialId: $caso->getPastaJudicial()?->getId(),
            proximaAcao: $acaoAtiva !== null ? ProximaAcaoOutput::fromEntity($acaoAtiva, $hoje) : null,
            // Dedupe: reusa o saldoExigivel e a ação ativa já computados acima (evita o recálculo interno
            // do saldo e a re-busca da ação que `alertasDoCaso` faria).
            alertas: $this->alertasCobranca->alertasComContexto($caso, $saldoExigivel, $acaoAtiva, $hoje),
            obrigacoes: $obrigacoes,
            gruposAcordo: $gruposAcordo,
            obrigacoesAvulsas: $obrigacoesAvulsas,
            pagamentos: $pagamentos,
            liquidacoes: array_map(LiquidacaoOutput::fromEntity(...), $this->liquidacaoRepository->doCaso($caso)),
            acordos: $acordos,
            // `$hoje` é o mesmo relógio injetado usado no resto do método — a janela de edição da
            // anotação não pode medir o tempo por uma fonte diferente da que rege a tela.
            historico: array_map(
                static fn ($e) => EventoHistoricoOutput::fromEntity($e, $usuarioAtual, $hoje),
                $eventos,
            ),
            // Base resolvida do honorário do CASO (para o espelho %↔R$ do honorário nos modais converter
            // sobre a base certa — composta soma valor+juros+multa+correção; principal usa só o valor).
            baseHonorariosComposta: $configCaso->baseHonorarios === BaseEncargo::Composta,
            // #9-T3: carência resolvida da cascata (Carteira→Objeto) para o `data-carencia` do preview JS
            // — o editor de honorários do Caso saiu da tela, este era o único lugar que ainda a expunha.
            carenciaHonorariosDias: $configCaso->carenciaHonorariosDias,
            honorariosDasObrigacoes: $honorariosDasObrigacoes,
            honorariosEmAberto: $honorariosEmAberto,
            honorariosRecebidos: $honorariosRecebidos,
            totalPrincipalEmAberto: $totalPrincipalEmAberto,
            totalEncargosEmAberto: $totalEncargosEmAberto,
            // O card `Total atualizado` é a soma dos outros três, não uma quinta conta: assim ele fecha
            // com o que está exibido ao lado, sempre.
            totalAtualizadoEmAberto: $totalPrincipalEmAberto + $totalEncargosEmAberto + $honorariosEmAberto,
            totaisAtualizadosEm: $hoje,
            obrigacoesEmAbertoQtd: $obrigacoesEmAbertoQtd,
            // Mesmo relógio, mesmo conjunto: a prescrição olha a competência mais antiga entre EXATAMENTE
            // as obrigações em aberto que os cards somaram (spec §1.3).
            prescricao: $this->calculadoraPrescricao->calcular($emAberto, $hoje),
            qualificacoes: $this->montarQualificacoes($eventos, $usuarioAtual, $hoje),
        );
    }

    /**
     * A listinha do painel de qualificação (spec §3.4), mais recente primeiro — a mesma ordem em que
     * `EventoHistoricoRepository::doCaso` já devolve a linha do tempo.
     *
     * `$ehMaisRecente` é decidido pela POSIÇÃO entre os eventos do tipo, ANTES de o payload ser lido, e
     * só a primeira o recebe. A ordem importa: `ultimaQualificacaoDoCaso` (a quarta condição de desfazer,
     * no servidor) escolhe o evento mais recente do tipo sem olhar o payload. Se a primeira linha tivesse
     * um payload irreconhecível e o `true` escorregasse para a seguinte, a tela ofereceria um botão que a
     * rota vai recusar — o defeito exato que decidir no servidor existe para evitar.
     *
     * @param list<EventoHistorico> $eventos
     *
     * @return list<QualificacaoContatoOutput>
     */
    private function montarQualificacoes(array $eventos, ?User $usuarioAtual, \DateTimeImmutable $hoje): array
    {
        $qualificacoes = [];
        $jaViuAPrimeira = false;

        foreach ($eventos as $evento) {
            if ($evento->getTipo() !== TipoEventoHistorico::QualificacaoContato) {
                continue;
            }

            $ehMaisRecente = !$jaViuAPrimeira;
            $jaViuAPrimeira = true;

            $linha = QualificacaoContatoOutput::tentarDe($evento, $usuarioAtual, $hoje, $ehMaisRecente);
            if ($linha !== null) {
                $qualificacoes[] = $linha;
            }
        }

        return $qualificacoes;
    }

    /**
     * Reorganiza a seção "Dívida em aberto" (Ajuste 8; a partir do Ajuste 10 essa seção fundiu as
     * antigas abas Obrigações e Acordos) SEM nova query — só reparticiona o que já foi carregado. A
     * seção mostra o que está VIVO (o que compõe o saldo); o registro completo fica no detalhe do
     * acordo. A ordem dos testes importa:
     *
     * 1. **substituída por acordo VIGENTE** → sai da lista solta e vira anexo (recolhido) do acordo que
     *    a substituiu (Ajuste 10) — antes era descartada e a dívida "sumia" sem explicação. Uma parcela
     *    de A que já foi substituída por B (acordo-sobre-acordo — ver spec do ajuste 7 §13) está FORA do
     *    exigível (`doCasoExigiveis` a exclui), então não pode entrar no grupo de A nem somar no total
     *    dele — inflaria o grupo contra o saldo derivado (invariável 20) e a contaria de novo no
     *    "substituiu N" de B. Ela continua existindo (invariável 14) e aparece no detalhe de A, travada,
     *    e agora também recolhida no grupo do acordo que a substituiu (se este ainda estiver vigente).
     * 2. **parcela de acordo VIGENTE** (e não substituída) → entra no grupo daquele acordo.
     * 3. **todo o resto** (inclusive parcela de acordo ROMPIDO/CANCELADO, que é histórico e voltou a
     *    ser editável, e original restaurada por rompimento) segue na lista solta.
     *
     * Só acordo vigente vira grupo, e só se tiver ao menos uma parcela viva. (Acordo vigente SEM
     * nenhuma parcela não existe hoje — `CriarAcordoInput`/`EditarAcordoInput` exigem `Count(min:1)`;
     * se passasse a existir, suas substituídas sumiriam da aba sem grupo que as represente.)
     *
     * @param list<ObrigacaoOutput> $obrigacoes
     * @param list<AcordoOutput>    $acordos
     *
     * @return array{0: list<GrupoAcordoObrigacoesOutput>, 1: list<ObrigacaoOutput>}
     */
    private function agruparPorAcordo(array $obrigacoes, array $acordos): array
    {
        $vigentes = [];
        foreach ($acordos as $acordo) {
            if ($acordo->vigente) {
                $vigentes[$acordo->id] = $acordo;
            }
        }

        $parcelasPorAcordo = [];
        $substituidasPorAcordo = [];
        $avulsas = [];

        foreach ($obrigacoes as $obrigacao) {
            // (1) Substituída por acordo vigente sai da lista solta e vira anexo do acordo que a
            //     substituiu (Ajuste 10) — antes era descartada e a dívida "sumia" sem explicação.
            if ($obrigacao->substituidaPorAcordo) {
                if ($obrigacao->acordoSubstitutoId !== null) {
                    $substituidasPorAcordo[$obrigacao->acordoSubstitutoId][] = $obrigacao;
                }

                continue;
            }

            // (2) Parcela viva de acordo vigente → grupo daquele acordo.
            if ($obrigacao->acordoOrigemId !== null && isset($vigentes[$obrigacao->acordoOrigemId])) {
                $parcelasPorAcordo[$obrigacao->acordoOrigemId][] = $obrigacao;

                continue;
            }

            // (3) O resto segue na lista solta.
            $avulsas[] = $obrigacao;
        }

        $grupos = [];
        foreach ($vigentes as $acordoId => $acordo) {
            $parcelas = $parcelasPorAcordo[$acordoId] ?? [];
            if ($parcelas === []) {
                continue;
            }

            $valorTotal = 0;
            foreach ($parcelas as $parcela) {
                $valorTotal += $parcela->valorAtual;
            }

            $grupos[] = new GrupoAcordoObrigacoesOutput(
                acordoId: $acordoId,
                dataAcordo: $acordo->dataAcordo,
                statusLabel: $acordo->statusLabel,
                statusBadgeClass: $acordo->statusBadgeClass,
                qtdParcelas: count($parcelas),
                qtdSubstituidas: $acordo->qtdObrigacoesSubstituidas,
                valorTotal: $valorTotal,
                parcelas: $parcelas,
                substituidas: $substituidasPorAcordo[$acordoId] ?? [],
            );
        }

        return [$grupos, $avulsas];
    }

    /**
     * Basis points (2000) → decimal string ("20.00") para o hero, sem float no caminho do dinheiro
     * (aritmética inteira, mesmo padrão de `TaxaBpParaTextoTransformer`, só com '.' em vez de ',').
     */
    private function formatarPercentualDeBp(int $bp): string
    {
        $sinal = $bp < 0 ? '-' : '';
        $absoluto = abs($bp);
        $inteiro = intdiv($absoluto, 100);
        $centesimos = $absoluto % 100;

        return $sinal . $inteiro . '.' . str_pad((string) $centesimos, 2, '0', STR_PAD_LEFT);
    }
}
