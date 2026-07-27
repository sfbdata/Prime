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
        /**
         * Recorte do anterior: só o que ainda NÃO foi quitado. É o honorário que falta receber.
         *
         * É TAMBÉM o card `Honorários` do cabeçalho (spec §1.2), e não há um segundo campo com o mesmo
         * número: o conjunto somado é idêntico (as listadas na aba Dívida que não estão quitadas) e a
         * regra é a mesma. Duplicar o valor sob outro nome só criaria dois lugares para divergir.
         */
        public readonly int $honorariosEmAberto = 0,
        public readonly int $honorariosRecebidos = 0,
        /**
         * Os quatro cards de dinheiro do cabeçalho (spec §1.2), em centavos. Somados AQUI (no UseCase) e
         * nunca no Twig, sobre EXATAMENTE o mesmo conjunto que a aba Dívida lista — `obrigacoesAvulsas`
         * mais as parcelas dos `gruposAcordo` —, restrito ao que ainda não foi quitado. É a mesma regra
         * dos totais da aba Honorários acima, pelo mesmo motivo: é dinheiro, e aqui há teste.
         *
         * Os quatro fecham entre si por construção:
         * `totalAtualizadoEmAberto = totalPrincipalEmAberto + totalEncargosEmAberto + honorariosEmAberto`.
         *
         * ⚠️ Este é o BRUTO (principal + encargos), NÃO o saldo exigível: `saldoExigivel` acima é
         * líquido dos pagamentos já recebidos e é ele que governa o encerramento (spec §1.2, nota). São
         * contas diferentes de propósito — trocar uma pela outra faz o gestor conferir o número errado.
         */
        public readonly int $totalPrincipalEmAberto = 0,
        /** Σ (juros + multa + correção) das em aberto — o card `Juros e multa` (a correção entra junto). */
        public readonly int $totalEncargosEmAberto = 0,
        public readonly int $totalAtualizadoEmAberto = 0,
        /** Data do relógio da hidratação: o "atualizado em" que o card `Total atualizado` exibe. */
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
