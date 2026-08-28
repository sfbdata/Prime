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
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\StatusAcordo;
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
 * Ajuste 10 (T5): é aqui que o prefill do "Receber" é derivado (`ObrigacaoOutput::brutoSugerido`).
 * Ele É o restante da obrigação — o gross-up que delegava à `CalculadoraHonorarios` saiu junto com o
 * rateio (spec `cobranca-honorario-no-total.md` §4.3), porque o honorário já está dentro do exigível.
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

        // R5: o "Pago em" da seção "Já pago". Mesma forma em LOTE do mapa acima, pelo mesmo motivo —
        // uma consulta por linha seria N+1. Só EXIBE: quem decide se a obrigação está paga é
        // `ObrigacaoOutput::quitada()`, sobre o alocado.
        $pagoEmPorObrigacao = $this->alocacaoRepository->ultimoPagamentoPorObrigacaoDosCasos(
            [$caso->getId()],
            $caso->getTenant(),
        );

        // O `restante` é recalculado aqui (e não lido de `ObrigacaoOutput::restante()`) porque o DTO ainda
        // não existe neste ponto. A fórmula é a MESMA do DTO e `valorExigivel()` é a fonte única
        // (`Obrigacao::valorExigivel`) — a regra não é duplicada em lugar nenhum.
        $obrigacoes = array_map(
            function (Obrigacao $o) use ($caso, $alocadoPorObrigacao, $pagoEmPorObrigacao): ObrigacaoOutput {
                $alocado = $alocadoPorObrigacao[$o->getId()] ?? 0;
                $restante = max(0, $o->valorExigivel() - $alocado);

                return ObrigacaoOutput::fromEntity(
                    $o,
                    $alocado,
                    // O prefill do "Receber" É o próprio restante (spec `cobranca-honorario-no-total.md`
                    // §4.3). Era `brutoParaRecuperar($caso, $restante)` = restante × (1+p), que existia
                    // porque o exigível NÃO continha honorário e o rateio o retirava depois. Agora o
                    // honorário já está no exigível: multiplicar de novo cobraria honorário sobre
                    // honorário.
                    //
                    // 🔴 **O número na tela MUDA em dívida parcialmente paga** — aqui dizia que "continua
                    // o mesmo", e é falso. Na dívida intacta os dois caminhos coincidem; na parcialmente
                    // paga, não: o gross-up antigo multiplicava o RESTANTE (R$ 800 × 1,10 = R$ 880),
                    // enquanto o honorário do exigível incide sobre a obrigação INTEIRA (R$ 1.200 + R$ 120
                    // − R$ 400 = R$ 920). O valor novo é o certo — é o que a contabilidade cobra —, mas
                    // é mudança visível para quem opera, e por isso está no aviso de deploy (spec §9).
                    // Guardado por `ObjetoShowControllerTest`, que afirma os R$ 920,00.
                    $restante,
                    // Config resolvida (cascata) só para a UI saber a BASE de multa/honorários e exibir o
                    // "%" sobre a base certa. Grafo caso→objeto→carteira já carregado — sem query nova.
                    $this->resolvedorConfig->resolver($o),
                    $pagoEmPorObrigacao[$o->getId()] ?? null,
                );
            },
            $this->obrigacaoRepository->doCaso($caso),
        );
        // O acordo CANCELADO some da tela (decisão do dono, 01/08: "cancelado perde tudo do acordo,
        // não tem nem como abrir"). A linha continua no banco de propósito — é ela que impede dívida em
        // dobro se a contabilidade voltar a trazer o acordo (ver `CancelarAcordoUseCase`) —, mas não
        // chega ao Output: sem isto ele reapareceria em "Acordos encerrados", com um link que agora dá
        // 404. O ROMPIDO continua listado: aconteceu de verdade e o histórico dele importa.
        $sucessorPorAcordo = $this->sucessorPorAcordo($obrigacoes);
        $acordos = array_map(
            static fn (Acordo $a): AcordoOutput => AcordoOutput::fromEntity(
                $a,
                $sucessorPorAcordo[$a->getId()]['id'] ?? null,
                $sucessorPorAcordo[$a->getId()]['qtd'] ?? 0,
            ),
            array_values(array_filter(
                $this->acordoRepository->doCaso($caso),
                static fn (Acordo $a): bool => $a->getStatus() !== StatusAcordo::Cancelado,
            )),
        );
        [$gruposAcordo, $obrigacoesAvulsas] = $this->agruparPorAcordo($obrigacoes, $acordos);

        // ── R5 (spec `cobranca-importar-receitas.md` §3.1): a aba Dívida separa o que se COBRA do que
        // já foi PAGO. Antes da importação de receitas isto era arrumação; depois dela é condição de
        // uso: a importação cria ~2.078 obrigações já pagas contra 3.431 em aberto, e um devedor com 7
        // boletos pagos e 3 em aberto passaria a mostrar 10 linhas com as 3 que importam no meio.
        //
        // A régua é `ObrigacaoOutput::quitada()` — a MESMA que já pinta o chip "Paga" na linha. Usar
        // outra faria a tela ter duas definições de "paga" discordando entre si.
        //
        // ⚠️ `$obrigacoesAvulsas` (a união) NÃO é substituída: `honorariosDasObrigacoes`, os cards do
        // cabeçalho, a prescrição e a aba Honorários saem dela. A partição é ADICIONAL — trocar a base
        // deles mudaria números do cabeçalho que ninguém pediu para mudar.
        $obrigacoesAvulsasEmAberto = [];
        $obrigacoesAvulsasPagas = [];
        $totalPagoDasAvulsas = 0;
        foreach ($obrigacoesAvulsas as $avulsa) {
            if (!$avulsa->quitada()) {
                $obrigacoesAvulsasEmAberto[] = $avulsa;

                continue;
            }

            $obrigacoesAvulsasPagas[] = $avulsa;
            // O RECEBIDO, não o total da linha: é este número que a §8 manda bater com a coluna
            // `Valor recebido` da planilha. Ver a nota do campo no DTO.
            $totalPagoDasAvulsas += $avulsa->alocado;
        }

        // Totais da aba Honorários: somados sobre o MESMO conjunto que a aba lista (avulsas + parcelas
        // dos grupos), para o rodapé sempre bater com as linhas visíveis. Ver a nota no DTO.
        $listadasNaAba = $obrigacoesAvulsas;
        foreach ($gruposAcordo as $grupo) {
            foreach ($grupo->parcelas as $parcela) {
                $listadasNaAba[] = $parcela;
            }
        }

        // Os quatro cards de dinheiro do cabeçalho (spec §1.2) saem do MESMO laço e do MESMO conjunto
        // que os totais da aba Honorários: `$listadasNaAba` restrito ao que está EM ABERTO — e, desde
        // 2026-07-27, restrito TAMBÉM ao que já venceu (ver o `if` do vencido lá embaixo). Somar aqui
        // (e não no Twig) é o que permite ter teste — e é o que garante que o cabeçalho nunca discorde
        // da soma das linhas que a aba Dívida mostra logo abaixo.
        //
        // "Em aberto" tem DUAS exclusões, não uma (a segunda entrou na Etapa 8, achado da revisão da
        // frente inteira): não quitada E não parcela de acordo desfeito. Ver o `continue` abaixo.
        $honorariosDasObrigacoes = 0;
        $honorariosEmAberto = 0;
        $totalPrincipalVencido = 0;
        $totalEncargosVencido = 0;
        $honorariosVencidos = 0;
        $obrigacoesEmAbertoQtd = 0;
        $emAberto = [];
        foreach ($listadasNaAba as $listada) {
            // O total do RODAPÉ da aba Honorários soma tudo que a aba LISTA — hoje isso quer dizer as
            // avulsas e as parcelas de acordo vigente, inclusive as quitadas. Fica ANTES das exclusões
            // abaixo justamente por isso: é o número que tem de fechar com as linhas visíveis.
            $honorariosDasObrigacoes += $listada->honorarios;

            // Parcela de acordo desfeito está fora do exigível: romper o acordo devolveu a obrigação
            // ORIGINAL ao saldo, e somar as duas contaria o MESMO dinheiro duas vezes — no card mais
            // visível da página, e com encargo de snapshot velho, porque `EncargosVivos` só hidrata as
            // exigíveis. Com esta exclusão o conjunto somado aqui é exatamente o de
            // `ObrigacaoRepository::doCasoExigiveis`, que é quem governa o `saldoExigivel`: a diferença
            // entre o BRUTO (card `Total atualizado`) e o LÍQUIDO (linha `Total em aberto`) volta a ser
            // só o que já foi recebido, que é a explicação que a §1.2 dá ao gestor.
            //
            // DEFESA EM PROFUNDIDADE (01/08): desde que `agruparPorAcordo` descarta a parcela desfeita,
            // esta metade do teste não deveria mais ser alcançada. Fica de propósito — é caminho de
            // dinheiro, e o custo de uma comparação é nada perto de recontar dívida se o agrupamento
            // mudar de novo.
            if ($listada->parcelaDeAcordoDesfeito || $listada->quitada()) {
                continue;
            }

            ++$obrigacoesEmAbertoQtd;
            $emAberto[] = $listada;
            $honorariosEmAberto += $listada->honorarios;

            // ── Terceiro recorte, só para os cards do cabeçalho: VENCIDO (decisão do dono, 2026-07-27)
            // O cabeçalho deixou de mostrar "em aberto" e passou a mostrar "vencido" — a parcela que
            // ainda vai vencer não é cobrança de hoje, e o gestor conferia dois pares de números
            // (os cards em aberto × a linha fina com o vencido) que respondiam a perguntas diferentes.
            // A régua é a MESMA de `CalculadoraSaldo::saldoVencido` (`vencimentoOriginal <= hoje`, com
            // o mesmo relógio), para os cards e o conceito de vencido do resto do módulo não divergirem.
            // `honorariosEmAberto` acima NÃO entra neste recorte: ele é o `A receber` da aba Honorários,
            // que continua sendo sobre tudo que está em aberto.
            if ($listada->vencimentoOriginal > $hoje) {
                continue;
            }

            $totalPrincipalVencido += $listada->valorOriginal;
            // Os três encargos, e não `valorAtual - valorOriginal`: a subtração daria o mesmo número
            // (INV-E1) mas esconderia de qual parcela ele veio, e o card se chama "Juros e multa".
            $totalEncargosVencido += $listada->juros + $listada->multa + $listada->correcao;
            $honorariosVencidos += $listada->honorarios;
        }

        // Extraído para variável (antes era montado direto no construtor) porque agora é lido DUAS vezes:
        // a lista da aba e o total de honorários recebidos precisam vir do mesmo array, não de duas
        // consultas que poderiam divergir.
        $pagamentos = array_map(PagamentoOutput::fromEntity(...), $this->pagamentoRepository->doCaso($caso));

        $honorariosRecebidos = 0;
        // `Recuperado` da legenda da barra de composição (redesenho 1a): TUDO que entrou nesta cobrança,
        // não só a fatia do escritório. Somado aqui, e não no Twig, pela mesma regra dos demais: é
        // dinheiro, e aqui há teste.
        //
        // ⚠️ É Σ `valorTotal`, e NÃO `honorariosRecebidos + Σ pagamentos`: o `valorTotal` do
        // `PagamentoOutput` já É `valorDivida + valorEncargos + valorHonorarios` (ver `fromEntity`), então
        // somar o honorário por fora o contaria DUAS VEZES. O handoff do desenho trazia as duas fórmulas
        // em seções diferentes; esta é a que fecha com o extrato.
        $totalRecuperado = 0;
        foreach ($pagamentos as $pagamento) {
            $honorariosRecebidos += $pagamento->valorHonorarios;
            $totalRecuperado += $pagamento->valorTotal;
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
            formaHonorariosLabel: $formaHonorarios->label(),
            percentualHonorarios: $percentualHonorarios,
            pastaJudicialId: $caso->getPastaJudicial()?->getId(),
            proximaAcao: $acaoAtiva !== null ? ProximaAcaoOutput::fromEntity($acaoAtiva, $hoje) : null,
            // Dedupe: reusa o saldoExigivel e a ação ativa já computados acima (evita o recálculo interno
            // do saldo e a re-busca da ação que `alertasDoCaso` faria).
            // O mapa de alocação vai junto (e não é recarregado lá dentro): é ele que diz ao alerta quais
            // vencidas JÁ FORAM PAGAS — a mesma régua que a partição em aberto/pagas usa logo acima.
            alertas: $this->alertasCobranca->alertasComContexto($caso, $saldoExigivel, $acaoAtiva, $alocadoPorObrigacao, $hoje),
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
            totalRecuperado: $totalRecuperado,
            totalPrincipalVencido: $totalPrincipalVencido,
            totalEncargosVencido: $totalEncargosVencido,
            honorariosVencidos: $honorariosVencidos,
            // O card `Total vencido` é a soma dos outros três, não uma quarta conta: assim ele fecha
            // com o que está exibido ao lado, sempre.
            totalAtualizadoVencido: $totalPrincipalVencido + $totalEncargosVencido + $honorariosVencidos,
            totaisAtualizadosEm: $hoje,
            obrigacoesEmAbertoQtd: $obrigacoesEmAbertoQtd,
            // Mesmo relógio, mesmo conjunto: a prescrição olha a competência mais antiga entre EXATAMENTE
            // as obrigações em aberto que os cards somaram (spec §1.3).
            prescricao: $this->calculadoraPrescricao->calcular($emAberto, $hoje),
            qualificacoes: $this->montarQualificacoes($eventos, $usuarioAtual, $hoje),
            obrigacoesAvulsasEmAberto: $obrigacoesAvulsasEmAberto,
            obrigacoesAvulsasPagas: $obrigacoesAvulsasPagas,
            totalPagoDasAvulsas: $totalPagoDasAvulsas,
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
     * 3. **parcela de acordo DESFEITO** (rompido ou cancelado) → DESCARTADA da seção (decisão do dono,
     *    01/08). Ela está fora do exigível e a seção mostra o que compõe o saldo; misturada às originais
     *    restauradas, poluía a lista — no caso real da QUADRA 11 eram 30 parcelas mortas contra 5
     *    dívidas de verdade, com um rótulo cinza como única distinção. Continua existindo (invariável
     *    14). A do ROMPIDO segue acessível abrindo o acordo em "Acordos encerrados"; a do CANCELADO não,
     *    porque o acordo cancelado some da tela inteira (spec `cobranca-cancelar-acordo.md`).
     * 4. **todo o resto** (inclusive a original restaurada) segue na lista solta.
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

            // (3) Parcela de acordo DESFEITO é descartada da seção: está fora do exigível, e a seção
            //     mostra o que compõe o saldo. O registro dela vive no acordo ("Acordos encerrados").
            if ($obrigacao->parcelaDeAcordoDesfeito) {
                continue;
            }

            // (4) O resto segue na lista solta.
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
                substituidoPeloAcordoId: $acordo->substituidoPeloAcordoId,
                qtdSucessores: $acordo->qtdSucessores,
            );
        }

        return [$grupos, $avulsas];
    }

    /**
     * Qual acordo ASSUMIU cada acordo — a resposta ao pedido do dono: *"não quero que apareçam acordos
     * substituídos, apenas os que estão realmente vigentes"* (spec
     * `cobranca-acordo-assume-parcelas-do-anterior.md`, decisão D1).
     *
     * É **derivado**, não gravado: não há coluna nem estado novo. Um acordo está substituído quando
     * **todas** as parcelas dele foram renegociadas por um acordo vigente — nem uma sobrou por conta
     * dele. As duas condições importam:
     *
     * - só "tem parcela renegociada" pegaria os 12 acordos **parcialmente** renegociados do dado real,
     *   que seguem devendo — um deles perde 13 parcelas e fica com 14;
     * - só "não sobrou parcela" pegaria o acordo sem parcela nenhuma, que ninguém substituiu.
     *
     * 🔑 "Sobrou" é parcela **em aberto** que não foi substituída, e a régua custou uma medição para
     * ficar certa. A tentação era usar a mesma do `agruparPorAcordo` (qualquer parcela não substituída,
     * paga ou não), porque é ela que decide se o acordo vira grupo na seção Dívida. Só que no dado real
     * isso pega **8** acordos e deixa **29** de fora — os 29 em que o devedor pagou 1 a 3 parcelas antes
     * de renegociar o resto, que são justamente a forma comum. Eles ficavam na seção Dívida anunciando
     * "Ativo" com as parcelas pagas, que é exatamente a queixa do dono.
     *
     * Por isso o rótulo viaja nos DOIS lugares (`AcordoOutput` e `GrupoAcordoObrigacoesOutput`): o
     * acordo sem nada sobrando cai em "Acordos encerrados", o que sobrou só pago continua virando grupo,
     * e os dois se anunciam como substituídos. Nada some da tela e nada mente.
     *
     * Um estado gravado seria pior aqui: a planilha da contábil continua dizendo "Em andamento" nesses
     * acordos **de propósito** (é o desenho deles, para rastreabilidade), e toda importação teria de
     * decidir qual das duas fontes ganha. Derivando, não existe a disputa.
     *
     * Lê as obrigações que o UseCase já carregou por QUERY (`doCaso`) — nunca a coleção inversa do
     * acordo, que nasce vazia na mesma unidade de trabalho.
     *
     * @param list<ObrigacaoOutput> $obrigacoes
     *
     * @return array<int, array{id: ?int, qtd: int}> id do acordo substituído → o sucessor (nulo
     *                                                se houve mais de um) e quantos foram
     */
    private function sucessorPorAcordo(array $obrigacoes): array
    {
        $sobrouParcela = [];
        /** @var array<int, array<int, true>> $sucessores id do velho → ids distintos dos sucessores */
        $sucessores = [];

        foreach ($obrigacoes as $obrigacao) {
            $origemId = $obrigacao->acordoOrigemId;
            if ($origemId === null) {
                continue;
            }

            if ($obrigacao->substituidaPorAcordo && $obrigacao->acordoSubstitutoId !== null) {
                $sucessores[$origemId][$obrigacao->acordoSubstitutoId] = true;

                continue;
            }

            if (!$obrigacao->quitada()) {
                $sobrouParcela[$origemId] = true;
            }
        }

        $resultado = [];
        foreach ($sucessores as $velhoId => $ids) {
            if (isset($sobrouParcela[$velhoId])) {
                continue;
            }

            // ⚠️ Com mais de um sucessor o ID fica NULO e só a contagem vai para a tela. Medido no dado
            // real: 8 acordos tiveram as parcelas divididas entre acordos novos diferentes (4 deles
            // recebem o selo; os outros 4 ainda têm parcela viva), e no acervo inteiro um acordo chega a
            // 22 sucessores. Escolher um (o último visto, pela ordem da query) e escrever "substituído
            // pelo acordo #N" seria afirmar, para as parcelas que foram para os outros, uma coisa falsa
            // — e o N mudaria conforme a ordenação, sem ninguém perceber.
            $resultado[$velhoId] = [
                'id' => count($ids) === 1 ? array_key_first($ids) : null,
                'qtd' => count($ids),
            ];
        }

        return $resultado;
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
