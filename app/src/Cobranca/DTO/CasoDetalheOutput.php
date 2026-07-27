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
 * `obrigacoesAvulsas` (o resto). `obrigacoes` continua sendo a lista COMPLETA do caso — é dela que sai
 * a contagem e quem mais precisar do conjunto todo.
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
        public readonly int $saldoExigivel,
        public readonly int $saldoVencido,
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
         * partição de `agruparPorAcordo` garante disjuntos. As substituídas por acordo vigente ficam de
         * fora dos dois (a aba não as lista, e elas estão fora do exigível), então o rodapé sempre bate
         * com a soma das linhas visíveis — é o que permite conferir a olho.
         */
        public readonly int $honorariosDasObrigacoes = 0,
        /** Recorte do anterior: só o que ainda NÃO foi quitado. É o honorário que falta receber. */
        public readonly int $honorariosEmAberto = 0,
        public readonly int $honorariosRecebidos = 0,
    ) {
    }
}
