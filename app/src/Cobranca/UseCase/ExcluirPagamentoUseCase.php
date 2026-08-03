<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\PagamentoNaoEncontradoException;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Exclui (hard delete) um recebimento lançado por engano — o "desfazer" do caminho do dinheiro (spec
 * `cobranca-excluir-recebimento.md`). É ele que abre o portão do §D4 de `cobranca-cancelar-acordo.md`:
 * acordo com parcela paga passa a ter como ser cancelado, bastando apagar o pagamento antes.
 *
 * História: o gestor lançou um recebimento errado (valor, caso ou parcela trocada) e quer removê-lo.
 * O pagamento é resolvido por id + tenant (guarda multi-tenant); caso encerrado NÃO aceita exclusão,
 * mesma regra da correção. `motivo` é OPCIONAL (decisão E2 do dono) — diferente de corrigir obrigação.
 *
 * O passo que não pode faltar é a RECONCILIAÇÃO: uma obrigação que este pagamento havia LIQUIDADO tem
 * de voltar a VIVA, senão fica dívida quitada no banco com o pagamento apagado — dinheiro que não
 * existe abatendo dívida que existe. Ela é feita AQUI, sem data e sem o `ReconciliadorLiquidacao` —
 * o porquê está inteiro em `reconciliarLiquidacoes`, e é a parte desta classe que mais custou.
 *
 * ⚠️ O alocado usado é o FINAL (`Σ persistido − as alocações DESTE pagamento`), NUNCA zero. Zerar
 * reabriria uma obrigação que OUTRO pagamento ainda cobre, e o devedor voltaria a ser cobrado pelo que
 * já pagou. O Σ persistido ainda inclui as alocações a apagar (o `remove` só some no flush), então
 * subtrair é obrigatório — e suficiente.
 *
 * O saldo NÃO é reescrito: ele é derivado por query agregada (invariável 20), então cai sozinho quando
 * as alocações somem. O evento `PagamentoExcluido` é autocontido (o pagamento não existirá para ser
 * consultado) e é registrado ANTES da remoção; o mesmo flush commita os dois.
 */
final class ExcluirPagamentoUseCase
{
    public function __construct(
        private readonly PagamentoRepository $pagamentoRepository,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(int $pagamentoId, ?string $motivo, Tenant $tenant, User $usuario): void
    {
        // Guarda multi-tenant: só um pagamento do próprio escritório pode ser excluído.
        $pagamento = $this->pagamentoRepository->findOneByIdDoTenant($pagamentoId, $tenant);

        if ($pagamento === null) {
            throw new PagamentoNaoEncontradoException($pagamentoId);
        }

        $caso = $pagamento->getCaso();

        // Caso encerrado (ou pagamento sem caso, defensivo) não aceita exclusão — mesma regra da correção.
        if ($caso === null || $caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso?->getId());
        }

        // Alocações por QUERY, nunca pela coleção inversa `$pagamento->getAlocacoes()`: ela nasce vazia
        // quando o pagamento foi criado na mesma unidade de trabalho, e o erro se esconderia justamente
        // nos testes (em produção é outro request e a coleção carrega do banco). Ver spec §3.4.
        $alocacoes = $this->alocacaoRepository->doPagamento($pagamentoId, $tenant);

        // Opcional (E2) e limitado no SERVIDOR: o `maxlength` do modal é só HTML e não vale como guarda —
        // sem o corte, texto arbitrário entraria na descrição do evento e no payload JSON.
        $motivo = $motivo === null ? null : mb_substr(trim($motivo), 0, 255);
        $motivo = $motivo === '' ? null : $motivo;

        // Snapshot AUTOCONTIDO para o histórico, montado antes da remoção.
        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::PagamentoExcluido,
            $usuario,
            $this->descricaoDoEvento($pagamento->valorTotalRecebido(), $pagamento->getData(), $motivo),
            [
                'pagamentoId' => $pagamentoId,
                'data' => $pagamento->getData()->format('Y-m-d'),
                'valorDivida' => $pagamento->getValorDivida(),
                'valorEncargos' => $pagamento->getValorEncargos(),
                'valorHonorarios' => $pagamento->getValorHonorarios(),
                'valorTotal' => $pagamento->valorTotalRecebido(),
                'motivo' => $motivo,
                'alocacoes' => array_map(
                    static fn (AlocacaoPagamento $a): array => [
                        'obrigacaoId' => $a->getObrigacao()?->getId(),
                        'descricao' => $a->getObrigacao()?->getDescricao(),
                        'valor' => $a->getValor(),
                    ],
                    $alocacoes,
                ),
            ],
            flush: false,
        );

        $this->reconciliarLiquidacoes(
            $this->obrigacoesTocadas($alocacoes),
            $this->alocadoFinalPorObrigacao($caso, $tenant, $alocacoes),
        );

        // `cascade: ['remove']` + `orphanRemoval` apagam as alocações antes do pagamento, na ordem certa
        // do UnitOfWork. Este flush commita a remoção, o evento e as obrigações reabertas juntos.
        $this->pagamentoRepository->remover($pagamento, flush: true);
    }

    /** Descrição visível na timeline — autoexplicativa, porque o payload JSON não é exibido em Twig. */
    private function descricaoDoEvento(int $valorTotal, \DateTimeImmutable $data, ?string $motivo): string
    {
        $texto = sprintf(
            'Recebimento excluído: R$ %s de %s',
            number_format($valorTotal / 100, 2, ',', '.'),
            $data->format('d/m/Y'),
        );

        return $motivo === null ? $texto : $texto . ' — ' . $motivo;
    }

    /**
     * Reconcilia o estado Liquidada/Viva das obrigações tocadas — SEM data e SEM o
     * `ReconciliadorLiquidacao`, e é aí que mora a correção.
     *
     * 🔑 **Apagar pagamento só TIRA dinheiro.** Disso decorre a regra inteira desta operação:
     *
     * - obrigação **viva** continua viva. Ela não foi liquidada quando tinha MAIS alocado; com menos,
     *   não há como passar a ser. Uma exclusão nunca pode quitar dívida.
     * - obrigação **liquidada** segue quitada se o que sobrou ainda cobre o **snapshot congelado**
     *   (`valorExigivel()`); senão, `reabrir()` — volta a viva e descongela, para o que falta crescer.
     *
     * Nenhum dos dois casos precisa de data, e é justamente a data que produzia defeito. O
     * `ReconciliadorLiquidacao` recalcula o exigível AO VIVO na data que recebe (`:77-84`), ignorando o
     * congelamento — enquanto `Obrigacao::liquidar()` afirma o contrário: *"após isto, nenhum leitor
     * recalcula: os quatro valores são o snapshot definitivo"*. Chamado com a data do pagamento
     * APAGADO, numa carteira com juros (as duas reais têm), ele errava por TRÊS caminhos, todos com
     * efeito de dinheiro e todos alcançáveis pela tela:
     *
     * 1. **falsa reabertura** — apagar uma DUPLICATA lançada meses depois: o exigível recalculado na
     *    data dela supera o alocado que liquidou a dívida lá atrás, e `reabrir()` dispara sobre
     *    obrigação legitimamente paga. O devedor volta a ser cobrado, com juros, pelo que já entregou.
     * 2. **sub-reabertura** — apagar um PARCIAL anterior à quitação: na data do parcial a dívida era
     *    menor, o que sobra parece cobri-la, e a obrigação fica liquidada e CONGELADA com valor a
     *    descoberto. O saldo mostra o que falta, mas o relógio para e a linha se diz quitada.
     * 3. **liquidação espúria** — apagar um recebimento PEQUENO e ANTIGO: o alocado que sobra supera o
     *    exigível da data velha e o Reconciliador LIQUIDA uma dívida viva, congelando-a numa data
     *    anterior ao pagamento que a cobriu e deixando o saldo do caso NEGATIVO (crédito fictício).
     *
     * Os três somem quando a data sai da conta. Substituída por acordo VIGENTE não é tocada em hipótese
     * alguma (mesma guarda de `ReconciliadorLiquidacao:63`): quem manda nela é o acordo. Congelada mas
     * NÃO liquidada (encargo legado travado) também fica intacta — não é dívida viva nem quitação.
     *
     * O recálculo por data continua valendo em REGISTRAR e CORRIGIR pagamento, onde entra dinheiro e a
     * data é a base legítima da quitação. Mudar aquele serviço COMPARTILHADO não é desta frente — fica
     * como achado registrado.
     *
     * @param array<int, Obrigacao> $tocadas
     * @param array<int, int>       $alocadoFinal
     */
    private function reconciliarLiquidacoes(array $tocadas, array $alocadoFinal): void
    {
        foreach ($tocadas as $id => $obrigacao) {
            if ($obrigacao->getAcordoSubstituto()?->getStatus()->ehVigente() ?? false) {
                continue;
            }

            if (!$obrigacao->estaLiquidada()) {
                continue;
            }

            if (($alocadoFinal[$id] ?? 0) < $obrigacao->valorExigivel()) {
                $obrigacao->reabrir();
            }
        }
    }

    /**
     * Obrigações distintas que este pagamento abateu — o conjunto que o Reconciliador reavalia. As
     * demais obrigações do caso não mudaram de alocado e não têm por que ser tocadas.
     *
     * @param AlocacaoPagamento[] $alocacoes
     *
     * @return array<int, Obrigacao>
     */
    private function obrigacoesTocadas(array $alocacoes): array
    {
        $tocadas = [];
        foreach ($alocacoes as $alocacao) {
            $obrigacao = $alocacao->getObrigacao();
            $id = $obrigacao?->getId();
            if ($obrigacao !== null && $id !== null) {
                $tocadas[$id] = $obrigacao;
            }
        }

        return $tocadas;
    }

    /**
     * Alocado FINAL por obrigação depois da exclusão: o Σ já persistido (que ainda inclui as alocações
     * deste pagamento, pois o descarte só some no flush) MENOS as alocações deste pagamento.
     *
     * O que sobra é o que OUTROS pagamentos alocaram — e é por isso que este método existe em vez de um
     * mapa zerado: obrigação coberta por outro pagamento tem de continuar liquidada.
     *
     * @param AlocacaoPagamento[] $alocacoes
     *
     * @return array<int, int>
     */
    private function alocadoFinalPorObrigacao(CasoCobranca $caso, Tenant $tenant, array $alocacoes): array
    {
        $casoId = $caso->getId();
        $final = $casoId === null
            ? []
            : $this->alocacaoRepository->somasPorObrigacaoDosCasos([$casoId], $tenant);

        foreach ($alocacoes as $alocacao) {
            $id = $alocacao->getObrigacao()?->getId();
            if ($id !== null) {
                $final[$id] = ($final[$id] ?? 0) - $alocacao->getValor();
            }
        }

        return $final;
    }
}
