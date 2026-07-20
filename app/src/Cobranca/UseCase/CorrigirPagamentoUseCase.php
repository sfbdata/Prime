<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\CorrigirPagamentoInput;
use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pagamento;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\PagamentoNaoEncontradoException;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Service\AlocadorPagamento;
use App\Cobranca\Service\AutoAlocadorFifo;
use App\Cobranca\Service\ReconciliadorLiquidacao;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Corrige um Pagamento já registrado (SPEC §22). NÃO há estorno no MVP: a correção REESCREVE a
 * composição do pagamento (valores + alocações) e exige um `motivoCorrecao`; a alteração fica
 * rastreável pela auditoria técnica (a entidade Pagamento é Auditavel).
 *
 * História: o gestor percebe um erro no pagamento e corrige a distribuição/valores. O pagamento é
 * resolvido por id + tenant (guarda multi-tenant); só o próprio caso é corrigível e caso encerrado
 * NÃO aceita correção. O novo rateio de honorários e a validação das alocações (mesmo caso,
 * invariáveis 12 e 20) ficam no AlocadorPagamento. As alocações antigas são descartadas
 * (`limparAlocacoes` + orphanRemoval no flush) e substituídas pelas novas; a data só é sobrescrita
 * quando informada. O pagamento corrigido e o evento "pagamento corrigido" são commitados juntos
 * (flush único no registro do evento).
 */
final class CorrigirPagamentoUseCase
{
    public function __construct(
        private readonly PagamentoRepository $pagamentoRepository,
        private readonly AlocadorPagamento $alocador,
        private readonly AutoAlocadorFifo $autoAlocadorFifo,
        private readonly RegistrarEventoHistorico $registrarEvento,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly ResolvedorConfigEncargos $resolvedorConfig,
        private readonly ReconciliadorLiquidacao $reconciliador,
    ) {
    }

    public function executar(CorrigirPagamentoInput $input, Tenant $tenant, User $usuario): Pagamento
    {
        // Guarda multi-tenant: só um pagamento do próprio escritório pode ser corrigido.
        $pagamento = $this->pagamentoRepository->findOneByIdDoTenant((int) $input->pagamentoId, $tenant);

        if ($pagamento === null) {
            throw new PagamentoNaoEncontradoException((int) $input->pagamentoId);
        }

        $caso = $pagamento->getCaso();

        // Caso encerrado (ou pagamento sem caso, defensivo) não aceita correção.
        if ($caso === null || $caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso?->getId());
        }

        // Composição anterior, para o evento de histórico (rastreio de-para).
        $valorDividaAntes = $pagamento->getValorDivida();
        $valorHonorariosAntes = $pagamento->getValorHonorarios();

        // Data EFETIVA do pagamento após a correção (nova se informada, senão a atual). É a base ÚNICA de
        // tempo desta correção: o FIFO mede o exigível nela e o Reconciliador quita/reabre e snapshota
        // nela — nunca em "hoje", senão pagamento com data != hoje distribui/quita errado (spec §5).
        $dataEfetiva = $input->data ?? $pagamento->getData();

        // Auto-alocação FIFO por padrão; manual só quando o usuário assume a distribuição (Ajuste 6).
        // IMPORTANTE: alocar ANTES de limparAlocacoes/flush — assim a query de saldo do FIFO ainda vê
        // as alocações do PRÓPRIO pagamento (que ele exclui da sala via o parâmetro $pagamento).
        $alocacoesInput = $input->alocarManualmente
            ? $input->alocacoes
            : $this->autoAlocadorFifo->alocar($caso, (int) $input->valorPago, $tenant, $dataEfetiva, $pagamento);

        // Novo rateio + montagem/validação das alocações (invariáveis 12 e 20).
        [$valorDivida, $valorHonorarios, $novasAlocacoes] = $this->alocador->montar(
            $caso,
            (int) $input->valorPago,
            $alocacoesInput,
            $tenant,
        );

        // Snapshot das alocações ANTIGAS antes de descartá-las — o alocado FINAL desta correção é
        // (Σ já persistido, que ainda inclui estas antigas) − antigas + novas.
        $alocacoesAntigas = $pagamento->getAlocacoes()->toArray();

        // Descarta as alocações antigas (orphanRemoval as apaga no flush) e aplica as novas.
        $pagamento->limparAlocacoes();

        foreach ($novasAlocacoes as $alocacao) {
            $pagamento->adicionarAlocacao($alocacao);
        }

        $pagamento->setValorDivida($valorDivida);
        $pagamento->setValorEncargos(0);
        $pagamento->setValorHonorarios($valorHonorarios);
        $pagamento->setMotivoCorrecao(trim((string) $input->motivoCorrecao));

        // Data só é sobrescrita quando informada (campo opcional).
        if ($input->data !== null) {
            $pagamento->setData($input->data);
        }

        // Encargos AO VIVO (spec §6.3): a correção pode QUITAR (o alocado subiu e passou a cobrir o
        // exigível) ou REABRIR (o alocado caiu abaixo do exigível → volta a Viva e cresce). Reconcilia
        // na DATA efetiva do pagamento sobre as obrigações tocadas (antigas ∪ novas).
        $this->reconciliador->reconciliar(
            $this->resolvedorConfig->resolverDoCaso($caso),
            $this->obrigacoesTocadas($alocacoesAntigas, $novasAlocacoes),
            $this->alocadoFinalPorObrigacao($caso, $tenant, $alocacoesAntigas, $novasAlocacoes),
            $dataEfetiva,
        );

        // Persiste sem flush; o registro do evento fecha a transação.
        $this->pagamentoRepository->salvar($pagamento);

        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::PagamentoCorrigido,
            $usuario,
            sprintf('Pagamento corrigido: %s', $pagamento->getMotivoCorrecao()),
            [
                'motivo' => $pagamento->getMotivoCorrecao(),
                'de' => [
                    'valorDivida' => $valorDividaAntes,
                    'valorHonorarios' => $valorHonorariosAntes,
                ],
                'para' => [
                    'valorDivida' => $valorDivida,
                    'valorHonorarios' => $valorHonorarios,
                ],
            ],
            flush: true,
        );

        return $pagamento;
    }

    /**
     * Obrigações distintas tocadas pela correção (antes ∪ depois) — o conjunto que o Reconciliador
     * avalia para quitar ou reabrir.
     *
     * @param AlocacaoPagamento[] $antigas
     * @param AlocacaoPagamento[] $novas
     *
     * @return array<int, Obrigacao>
     */
    private function obrigacoesTocadas(array $antigas, array $novas): array
    {
        $tocadas = [];
        foreach (array_merge($antigas, $novas) as $alocacao) {
            $obrigacao = $alocacao->getObrigacao();
            $id = $obrigacao?->getId();
            if ($obrigacao !== null && $id !== null) {
                $tocadas[$id] = $obrigacao;
            }
        }

        return $tocadas;
    }

    /**
     * Alocado FINAL por obrigação após a correção: o Σ já persistido (que ainda inclui as alocações
     * ANTIGAS deste pagamento, pois o descarte só some no flush) MENOS as antigas MAIS as novas.
     *
     * @param AlocacaoPagamento[] $antigas
     * @param AlocacaoPagamento[] $novas
     *
     * @return array<int, int>
     */
    private function alocadoFinalPorObrigacao(CasoCobranca $caso, Tenant $tenant, array $antigas, array $novas): array
    {
        $casoId = $caso->getId();
        $final = $casoId === null
            ? []
            : $this->alocacaoRepository->somasPorObrigacaoDosCasos([$casoId], $tenant);

        foreach ($antigas as $alocacao) {
            $id = $alocacao->getObrigacao()?->getId();
            if ($id !== null) {
                $final[$id] = ($final[$id] ?? 0) - $alocacao->getValor();
            }
        }

        foreach ($novas as $alocacao) {
            $id = $alocacao->getObrigacao()?->getId();
            if ($id !== null) {
                $final[$id] = ($final[$id] ?? 0) + $alocacao->getValor();
            }
        }

        return $final;
    }
}
