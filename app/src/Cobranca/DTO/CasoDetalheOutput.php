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
 * A aba Obrigações (Ajuste 8) não usa `obrigacoes` cru: ela lê `gruposAcordo` (acordos vigentes com
 * suas parcelas dentro) + `obrigacoesAvulsas` (o resto). `obrigacoes` continua sendo a lista COMPLETA
 * do caso — é dela que sai a contagem da aba e quem mais precisar do conjunto todo.
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
    ) {
    }
}
