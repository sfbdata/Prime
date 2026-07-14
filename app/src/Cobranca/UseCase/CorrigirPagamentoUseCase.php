<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\CorrigirPagamentoInput;
use App\Cobranca\Entity\Pagamento;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\PagamentoNaoEncontradoException;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Service\AlocadorPagamento;
use App\Cobranca\Service\AutoAlocadorFifo;
use App\Cobranca\Service\RegistrarEventoHistorico;
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

        // Auto-alocação FIFO por padrão; manual só quando o usuário assume a distribuição (Ajuste 6).
        // IMPORTANTE: alocar ANTES de limparAlocacoes/flush — assim a query de saldo do FIFO ainda vê
        // as alocações do PRÓPRIO pagamento (que ele exclui da sala via o parâmetro $pagamento).
        $alocacoesInput = $input->alocarManualmente
            ? $input->alocacoes
            : $this->autoAlocadorFifo->alocar($caso, (int) $input->valorPago, $tenant, $pagamento);

        // Novo rateio + montagem/validação das alocações (invariáveis 12 e 20).
        [$valorDivida, $valorHonorarios, $novasAlocacoes] = $this->alocador->montar(
            $caso,
            (int) $input->valorPago,
            $alocacoesInput,
            $tenant,
        );

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
}
