<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\EditarAcordoInput;
use App\Cobranca\DTO\ParcelaEdicaoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\AcordoNaoAtivoException;
use App\Cobranca\Exception\AcordoNaoEncontradoException;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\ObrigacaoComPagamentoException;
use App\Cobranca\Exception\ObrigacaoDeAcordoException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Exception\ParcelamentoInvalidoException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Edita um Acordo (Ajuste 7, Fatia 4): reajusta o conjunto de parcelas de um acordo ATIVO.
 *
 * História: a negociação mudou depois de fechada — o devedor pediu outra data, o valor foi digitado
 * errado, ou o que falta precisa ser re-parcelado. O gestor reabre o acordo e ajusta as parcelas que
 * AINDA NÃO foram pagas; o que já tem pagamento alocado é intocável, porque mexer nele reescreveria a
 * contrapartida de dinheiro que entrou de verdade.
 *
 * Guardas: acordo resolvido por id + tenant (multi-tenant); só ATIVO edita (INV-D); caso encerrado não
 * aceita mutação (INV-H); parcela com pagamento alocado não muda nem some (INV-C); **parcela que já foi
 * substituída por OUTRO acordo vigente não muda nem some** (invariável 14 — ela é a "original" que
 * voltaria ao saldo se aquele acordo fosse rompido; quem a gere é o acordo substituto, não este); só
 * parcelas DESTE acordo podem ser referenciadas, uma vez cada; e as obrigações SUBSTITUÍDAS por este
 * acordo não são tocadas (INV-E) — desfazer substituição é romper/cancelar, não editar.
 *
 * **Valida tudo ANTES de escrever qualquer coisa**: como é dinheiro, nenhuma parcela é alterada,
 * criada ou removida enquanto houver uma guarda por verificar. Depois aplica e commita junto (flush
 * único no evento). O saldo NÃO é recalculado aqui — é derivado das obrigações (invariável 20); as
 * colunas `valorTotalNegociado`/`valorEntrada` são snapshot descritivo, reescritas para continuarem
 * verdadeiras (D8: a entrada é a linha chamada "Entrada").
 */
final class EditarAcordoUseCase
{
    public function __construct(
        private readonly AcordoRepository $acordoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(EditarAcordoInput $input, Tenant $tenant, User $editadoPor): Acordo
    {
        $acordoId = (int) $input->acordoId;
        $acordo = $this->acordoRepository->findOneByIdDoTenant($acordoId, $tenant);

        if ($acordo === null) {
            throw new AcordoNaoEncontradoException($acordoId);
        }

        // INV-D: só acordo ATIVO é editável (cumprido/rompido/cancelado é histórico congelado).
        if (!$acordo->estaAtivo()) {
            throw new AcordoNaoAtivoException($acordoId, $acordo->getStatus());
        }

        $caso = $acordo->getCaso();

        // INV-H: caso encerrado não aceita mutação operacional/financeira (SPEC §17).
        if ($caso === null || $caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso?->getId());
        }

        /** @var array<int, Obrigacao> $atuais parcelas de HOJE, indexadas por id */
        $atuais = [];
        foreach ($acordo->getParcelas() as $parcela) {
            $atuais[(int) $parcela->getId()] = $parcela;
        }

        // Uma query para todas as obrigações do caso: INV-C depende disso.
        $alocadoPorObrigacao = $this->alocacaoRepository->somasPorObrigacaoDosCasos([(int) $caso->getId()], $tenant);

        // ── Fase 1: VALIDAÇÃO (nada é escrito aqui) ────────────────────────────────────────────
        $somaParcelas = 0;
        /** @var array<int, ParcelaEdicaoInput> $alteracoes */
        $alteracoes = [];
        /** @var list<ParcelaEdicaoInput> $novas */
        $novas = [];
        $vistas = [];

        foreach ($input->parcelas as $linha) {
            // Contrato do UseCase (o Form já valida, mas a chamada direta não pode furar).
            if ($linha->vencimento === null || (int) $linha->valor <= 0 || trim((string) $linha->descricao) === '') {
                throw new ParcelamentoInvalidoException('Toda parcela precisa de descrição, valor positivo e vencimento.');
            }

            $somaParcelas += (int) $linha->valor;

            if ($linha->obrigacaoId === null) {
                $novas[] = $linha;

                continue;
            }

            $obrigacaoId = (int) $linha->obrigacaoId;

            // A mesma parcela duas vezes inflaria a soma (e o snapshot) sem alterar o conjunto real.
            if (isset($vistas[$obrigacaoId])) {
                throw new ParcelamentoInvalidoException(sprintf('A parcela %d foi enviada mais de uma vez.', $obrigacaoId));
            }
            $vistas[$obrigacaoId] = true;

            // Só parcelas DESTE acordo: id de outro acordo/caso/tenant nunca é alcançado.
            $parcela = $atuais[$obrigacaoId] ?? null;
            if ($parcela === null) {
                throw new ObrigacaoNaoEncontradaException($obrigacaoId);
            }

            // Linha inalterada não é mutação: passa direto. A UI reenvia TODAS as linhas (inclusive as
            // travadas), então travar aqui deixaria o acordo inteiro ineditável — as guardas abaixo só
            // valem para quem realmente mudou.
            if (!$this->linhaMudouParcela($linha, $parcela)) {
                continue;
            }

            // INV-C: paga é congelada — só passa se vier idêntica.
            $alocado = $alocadoPorObrigacao[$obrigacaoId] ?? 0;
            if ($alocado > 0) {
                throw new ObrigacaoComPagamentoException($obrigacaoId, $alocado);
            }

            // Invariável 14: quem gere esta parcela é o acordo que a substituiu, não este.
            $this->recusarSeGeridaPorOutroAcordo($parcela, $obrigacaoId);

            $alteracoes[$obrigacaoId] = $linha;
        }

        /** @var array<int, Obrigacao> $remocoes */
        $remocoes = [];
        foreach ($atuais as $obrigacaoId => $parcela) {
            if (isset($vistas[$obrigacaoId])) {
                continue;
            }

            // INV-C: remover parcela paga apagaria a contrapartida de um pagamento real.
            $alocado = $alocadoPorObrigacao[$obrigacaoId] ?? 0;
            if ($alocado > 0) {
                throw new ObrigacaoComPagamentoException($obrigacaoId, $alocado);
            }

            $this->recusarSeGeridaPorOutroAcordo($parcela, $obrigacaoId);

            $remocoes[$obrigacaoId] = $parcela;
        }

        // INV-B: o total informado tem de fechar com a soma das parcelas (a entrada está dentro — D8).
        if ($input->valorTotalNegociado !== null && $input->valorTotalNegociado !== $somaParcelas) {
            throw new ParcelamentoInvalidoException(sprintf(
                'A soma das parcelas (%d) não fecha com o total negociado (%d).',
                $somaParcelas,
                $input->valorTotalNegociado,
            ));
        }

        // ── Fase 2: APLICAÇÃO (tudo já validado) ───────────────────────────────────────────────
        $totalAnterior = $acordo->getValorTotalNegociado();
        $qtdAnterior = count($atuais);
        $removidasSnapshot = [];

        foreach ($alteracoes as $obrigacaoId => $linha) {
            $parcela = $atuais[$obrigacaoId];
            $parcela->setDescricao(trim((string) $linha->descricao));
            $parcela->setValorOriginal((int) $linha->valor);
            $parcela->setVencimentoOriginal($linha->vencimento);
            $this->obrigacaoRepository->salvar($parcela);
        }

        foreach ($novas as $linha) {
            $this->criarParcela($linha, $acordo, $caso, $tenant, $editadoPor);
        }

        foreach ($remocoes as $obrigacaoId => $parcela) {
            // Hard delete: guarda o retrato no evento, senão a auditoria não sabe o que sumiu.
            $removidasSnapshot[] = [
                'obrigacaoId' => $obrigacaoId,
                'descricao' => $parcela->getDescricao(),
                'valorOriginal' => $parcela->getValorOriginal(),
                'vencimentoOriginal' => $parcela->getVencimentoOriginal()->format('Y-m-d'),
            ];
            $this->obrigacaoRepository->remover($parcela);
        }

        // Snapshot descritivo reescrito (nunca lido para saldo).
        $acordo->setValorTotalNegociado($somaParcelas);
        $acordo->setValorEntrada($this->entradaDasLinhas($input->parcelas));
        $this->acordoRepository->salvar($acordo);

        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::AcordoEditado,
            $editadoPor,
            sprintf(
                'Acordo editado: %d parcela(s) alterada(s), %d adicionada(s), %d removida(s) — total %s (antes %s)',
                count($alteracoes),
                count($novas),
                count($remocoes),
                number_format($somaParcelas / 100, 2, ',', '.'),
                $totalAnterior === null ? '—' : number_format($totalAnterior / 100, 2, ',', '.'),
            ),
            [
                'antes' => [
                    'total_negociado' => $totalAnterior,
                    'qtd_parcelas' => $qtdAnterior,
                ],
                'depois' => [
                    'total_negociado' => $somaParcelas,
                    'qtd_parcelas' => count($input->parcelas),
                ],
                'alteradas' => count($alteracoes),
                'adicionadas' => count($novas),
                'removidas' => count($remocoes),
                'removidas_detalhe' => $removidasSnapshot,
            ],
            flush: true,
        );

        return $acordo;
    }

    /**
     * Invariável 14: a parcela já foi substituída por OUTRO acordo vigente — é a "original" que
     * voltaria ao saldo se aquele acordo fosse rompido. Quem a gere é o acordo substituto; editá-la
     * ou apagá-la aqui reescreveria (ou destruiria) a dívida que ele guarda.
     *
     * Não dá para usar `Obrigacao::participaDeAcordoVigente()`: ela também olha `acordoOrigem`, que é
     * justamente ESTE acordo (vigente), e travaria toda parcela.
     */
    private function recusarSeGeridaPorOutroAcordo(Obrigacao $parcela, int $obrigacaoId): void
    {
        if ($parcela->getAcordoSubstituto()?->getStatus()->ehVigente() ?? false) {
            throw new ObrigacaoDeAcordoException($obrigacaoId);
        }
    }

    private function criarParcela(ParcelaEdicaoInput $linha, Acordo $acordo, CasoCobranca $caso, Tenant $tenant, User $editadoPor): void
    {
        $nova = new Obrigacao();
        $nova->setTenant($tenant);
        $nova->setCaso($caso);
        $nova->setDescricao(trim((string) $linha->descricao));
        $nova->setValorOriginal((int) $linha->valor);
        $nova->setVencimentoOriginal($linha->vencimento);
        $nova->setAcordoOrigem($acordo);
        $nova->setCriadoPor($editadoPor);

        $this->obrigacaoRepository->salvar($nova);
    }

    /** A linha submetida difere da parcela persistida? (encargos não entram: o editor não os toca). */
    private function linhaMudouParcela(ParcelaEdicaoInput $linha, Obrigacao $parcela): bool
    {
        return trim((string) $linha->descricao) !== $parcela->getDescricao()
            || (int) $linha->valor !== $parcela->getValorOriginal()
            || $linha->vencimento?->format('Y-m-d') !== $parcela->getVencimentoOriginal()->format('Y-m-d');
    }

    /**
     * Snapshot da entrada (D8): valor da linha cuja descrição é "Entrada" (trim, case-insensitive);
     * 0 se não houver. Convenção herdada da criação — renomear a linha a torna parcela comum; havendo
     * mais de uma (nada impede: descrição é texto livre), a primeira vale.
     *
     * @param ParcelaEdicaoInput[] $linhas
     */
    private function entradaDasLinhas(array $linhas): int
    {
        foreach ($linhas as $linha) {
            if (mb_strtolower(trim((string) $linha->descricao)) === 'entrada') {
                return (int) $linha->valor;
            }
        }

        return 0;
    }
}
