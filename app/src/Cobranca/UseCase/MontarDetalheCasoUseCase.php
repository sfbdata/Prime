<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AcordoOutput;
use App\Cobranca\DTO\GrupoAcordoObrigacoesOutput;
use App\Cobranca\DTO\CasoDetalheOutput;
use App\Cobranca\DTO\EventoHistoricoOutput;
use App\Cobranca\DTO\LiquidacaoOutput;
use App\Cobranca\DTO\ObrigacaoOutput;
use App\Cobranca\DTO\PagamentoOutput;
use App\Cobranca\DTO\ProximaAcaoOutput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Cobranca\Service\AlertasCobranca;
use App\Cobranca\Service\CalculadoraHonorarios;
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
 * delegando a `CalculadoraHonorarios` — este UseCase é quem tem, ao mesmo tempo, o snapshot de
 * honorários do caso e o alocado por obrigação. A regra continua a morar na calculadora.
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
    ) {
    }

    public function executar(CasoCobranca $caso): CasoDetalheOutput
    {
        // Fonte de tempo ÚNICA: o mesmo relógio da hidratação (spec §5) — a data do "vencido" e do
        // encargo não podem divergir, e nada de `new \DateTimeImmutable()` no caminho do dinheiro.
        $hoje = $this->encargosVivos->agora();

        // Encargos AO VIVO (spec §6.2/INV-V5): hidrata EM MEMÓRIA as obrigações EXIGÍVEIS (vivas) do
        // caso para HOJE, resolvendo a config 1× — assim exibição e saldo leem o MESMO exigível vivo.
        // Congeladas (Liquidada/Substituída) e não-exigíveis (substituídas por acordo vigente, parcelas
        // de acordo rompido) ficam fora de `doCasoExigiveis` e mantêm o snapshot. As instâncias managed
        // são as mesmas que `doCaso` devolve abaixo (identity map do Doctrine): a hidratação aqui não
        // depende do efeito colateral do `saldoExigivel`.
        $this->encargosVivos->hidratar(
            $this->resolvedorConfig->resolverDoCaso($caso),
            $this->obrigacaoRepository->doCasoExigiveis($caso),
        );

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
            formaHonorariosLabel: $caso->getFormaHonorarios()->label(),
            percentualHonorarios: $caso->getPercentualHonorarios(),
            pastaJudicialId: $caso->getPastaJudicial()?->getId(),
            proximaAcao: $acaoAtiva !== null ? ProximaAcaoOutput::fromEntity($acaoAtiva, $hoje) : null,
            // Dedupe: reusa o saldoExigivel e a ação ativa já computados acima (evita o recálculo interno
            // do saldo e a re-busca da ação que `alertasDoCaso` faria).
            alertas: $this->alertasCobranca->alertasComContexto($caso, $saldoExigivel, $acaoAtiva, $hoje),
            obrigacoes: $obrigacoes,
            gruposAcordo: $gruposAcordo,
            obrigacoesAvulsas: $obrigacoesAvulsas,
            pagamentos: array_map(PagamentoOutput::fromEntity(...), $this->pagamentoRepository->doCaso($caso)),
            liquidacoes: array_map(LiquidacaoOutput::fromEntity(...), $this->liquidacaoRepository->doCaso($caso)),
            acordos: $acordos,
            historico: array_map(EventoHistoricoOutput::fromEntity(...), $this->eventoRepository->doCaso($caso)),
            // Base resolvida do honorário do CASO (para o espelho %↔R$ do honorário nos modais converter
            // sobre a base certa — composta soma valor+juros+multa+correção; principal usa só o valor).
            baseHonorariosComposta: $this->resolvedorConfig->resolverDoCaso($caso)->baseHonorarios === BaseEncargo::Composta,
        );
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
}
