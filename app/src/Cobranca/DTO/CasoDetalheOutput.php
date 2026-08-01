<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Leitura completa do Caso para a tela central de detalhe (SPEC §9/§26, Etapa 8). Agrega o
 * cabeçalho operacional (estado, saldo derivado, pessoa cobrada, próxima ação, alertas) e as
 * coleções das abas (obrigações, pagamentos, liquidações, acordos, documentos → 8C, histórico).
 * Montado por `MontarDetalheCasoUseCase` (leitura); o controller não calcula nada. Dinheiro em
 * centavos int (Twig formata com `|centavos`). `prontoParaEncerrar` é indicador derivado (SPEC §17),
 * não um estado do enum.
 *
 * A seção "Dívida em aberto" (Ajuste 8; fundida com a antiga aba Acordos no Ajuste 10) não usa
 * `obrigacoes` cru: ela lê `gruposAcordo` (acordos vigentes com suas parcelas dentro) +
 * `obrigacoesAvulsas`. Desde 01/08, `obrigacoesAvulsas` NÃO é mais "todo o resto": a parcela de acordo
 * ROMPIDO é descartada do par (ela está fora do exigível, e a seção mostra o que compõe o saldo) e vive
 * no acordo, em "Acordos encerrados". `obrigacoes` continua sendo a lista COMPLETA do caso — é dela que
 * sai a contagem e quem mais precisar do conjunto todo.
 *
 * @param list<AlertaCobranca>              $alertas
 * @param list<ObrigacaoOutput>             $obrigacoes
 * @param list<GrupoAcordoObrigacoesOutput> $gruposAcordo
 * @param list<ObrigacaoOutput>             $obrigacoesAvulsas
 * @param list<PagamentoOutput>      $pagamentos
 * @param list<LiquidacaoOutput>     $liquidacoes
 * @param list<AcordoOutput>         $acordos
 * @param list<EventoHistoricoOutput> $historico
 */
final class CasoDetalheOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $objetoIdentificacao,
        public readonly ?string $objetoDescricao,
        public readonly int $carteiraId,
        public readonly string $carteiraNome,
        public readonly string $pessoaCobradaNome,
        public readonly ?string $pessoaCobradaCpf,
        public readonly ?string $pessoaCobradaCnpj,
        public readonly ?string $pessoaCobradaEmail,
        public readonly ?string $pessoaCobradaTelefone,
        public readonly string $statusLabel,
        public readonly string $statusBadgeClass,
        public readonly bool $encerrado,
        public readonly bool $prontoParaEncerrar,
        /**
         * Saldo exigível do caso (líquido de pagamentos e liquidações). NÃO é mais exibido no
         * cabeçalho — desde 2026-07-27 a linha fina `Total em aberto`/`Total vencido` saiu da tela —,
         * mas continua governando o encerramento (`prontoParaEncerrar`), os alertas e o tooltip do
         * botão `Encerrar cobrança` ("Faltam X"). O par `saldoVencido` foi removido junto com a linha:
         * o vencido agora é o que os quatro cards somam, no BRUTO.
         */
        public readonly int $saldoExigivel,
        public readonly string $formaHonorariosLabel,
        public readonly ?string $percentualHonorarios,
        public readonly ?int $pastaJudicialId,
        public readonly ?ProximaAcaoOutput $proximaAcao,
        public readonly array $alertas,
        public readonly array $obrigacoes,
        public readonly array $gruposAcordo,
        public readonly array $obrigacoesAvulsas,
        public readonly array $pagamentos,
        public readonly array $liquidacoes,
        public readonly array $acordos,
        public readonly array $historico,
        /**
         * Base RESOLVIDA dos honorários do caso é COMPOSTA (true) ou Principal (false). A UI usa isto para o
         * espelho %↔R$ do honorário nos modais de obrigação converter sobre a base certa (parâmetro opcional
         * no fim para não quebrar chamadores antigos por posição; default composta = o do domínio).
         */
        public readonly bool $baseHonorariosComposta = true,
        /**
         * Carência RESOLVIDA dos honorários (cascata Carteira→Objeto, #9-T3), em dias. A UI usa isto para o
         * `data-carencia` do preview JS (%↔R$) nos modais de registrar/editar obrigação — antes lido do
         * snapshot morto do Caso (editor aposentado da tela); agora é o mesmo `configCaso.carenciaHonorariosDias`
         * já resolvido acima para `baseHonorariosComposta`.
         */
        public readonly ?int $carenciaHonorariosDias = null,
        /**
         * Totais da aba Honorários (SPEC UX §14.2), em centavos. São SOMAS do que a própria aba lista —
         * nenhuma regra financeira nova, nenhuma fonte de dados nova: o honorário por obrigação é o
         * `ObrigacaoOutput::honorarios` já exibido na composição da dívida, e o por recebimento é o
         * `PagamentoOutput::valorHonorarios` já exibido no extrato.
         *
         * Somados no UseCase (e não no Twig) porque são dinheiro: aqui têm teste, e o conjunto somado é
         * EXATAMENTE o que a tela percorre — `obrigacoesAvulsas` + as parcelas dos `gruposAcordo`, que a
         * partição de `agruparPorAcordo` garante disjuntos. Ficam de fora dos dois, porque a aba não as
         * lista e estão fora do exigível: as substituídas por acordo vigente e (desde 01/08) as parcelas
         * de acordo rompido. O rodapé sempre bate com a soma das linhas visíveis — é o que permite
         * conferir a olho, e o conjunto encolheu junto com a lista, mantendo a igualdade.
         */
        public readonly int $honorariosDasObrigacoes = 0,
        /**
         * Recorte do anterior: só o que ainda NÃO foi quitado. É o honorário que falta receber, e é o
         * `A receber` da aba Honorários.
         *
         * ⚠️ Não confundir com `honorariosVencidos`, o card `Honorários` do cabeçalho: até 2026-07-27
         * os dois eram o MESMO número e um campo só servia aos dois lugares. Deixaram de ser quando o
         * cabeçalho passou a somar só o vencido — a aba continua sobre TUDO que está em aberto, porque
         * a parcela a vencer é honorário a receber do mesmo jeito. Os dois campos existem por isso, e
         * a diferença entre eles é exatamente o honorário das obrigações que ainda não venceram.
         */
        public readonly int $honorariosEmAberto = 0,
        public readonly int $honorariosRecebidos = 0,
        /**
         * Os quatro cards de dinheiro do cabeçalho (spec §1.2), em centavos. Somados AQUI (no UseCase) e
         * nunca no Twig, sobre EXATAMENTE o mesmo conjunto que a aba Dívida lista — `obrigacoesAvulsas`
         * mais as parcelas dos `gruposAcordo` —, restrito ao que ainda não foi quitado E JÁ VENCEU. É a
         * mesma regra dos totais da aba Honorários acima, pelo mesmo motivo: é dinheiro, e aqui há teste.
         *
         * O recorte do VENCIDO entrou em 2026-07-27, por decisão do dono: o cabeçalho responde "quanto
         * está vencido hoje", não "quanto existe em aberto". A régua é a de `CalculadoraSaldo::saldoVencido`
         * (`vencimentoOriginal <= hoje`), para não haver duas definições de vencido no módulo.
         *
         * Os quatro fecham entre si por construção:
         * `totalAtualizadoVencido = totalPrincipalVencido + totalEncargosVencido + honorariosVencidos`.
         *
         * ⚠️ Este é o BRUTO, NÃO o saldo exigível: `saldoExigivel` acima é líquido dos pagamentos já
         * recebidos. Um pagamento PARCIAL numa obrigação vencida não muda estes cards (a obrigação segue
         * em aberto e entra pelo valor cheio); só a quitação a tira da soma. Era assim antes também — o
         * que saiu da tela em 2026-07-27 foi a linha fina que mostrava o líquido ao lado.
         */
        public readonly int $totalPrincipalVencido = 0,
        /** Σ (juros + multa + correção) das vencidas — o card `Juros e multa` (a correção entra junto). */
        public readonly int $totalEncargosVencido = 0,
        /** Σ `honorarios` das vencidas — o card `Honorários`. Ver a ⚠️ de `honorariosEmAberto` acima. */
        public readonly int $honorariosVencidos = 0,
        public readonly int $totalAtualizadoVencido = 0,
        /** Data do relógio da hidratação: o "atualizado em" que o card `Total vencido` exibe. */
        public readonly ?\DateTimeImmutable $totaisAtualizadosEm = null,
        /** `N obrigações em aberto` da linha meta (spec §1.1) — contadas sobre o mesmo conjunto acima. */
        public readonly int $obrigacoesEmAbertoQtd = 0,
        /**
         * Aviso de prescrição do cabeçalho (spec §1.3). `null` quando não há obrigação em aberto — não
         * há o que prescrever, e a caixa nem aparece na tela.
         */
        public readonly ?PrescricaoOutput $prescricao = null,
        /**
         * Listinha do painel de qualificação da aba Responsáveis (spec §3.4), mais recente primeiro. Só
         * as qualificações — o histórico completo continua em `historico`.
         *
         * @var list<QualificacaoContatoOutput>
         */
        public readonly array $qualificacoes = [],
    ) {
    }
}
