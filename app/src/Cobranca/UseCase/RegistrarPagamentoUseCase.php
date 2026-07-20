<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\RegistrarPagamentoInput;
use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pagamento;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Service\AlocadorPagamento;
use App\Cobranca\Service\AutoAlocadorFifo;
use App\Cobranca\Service\ReconciliadorLiquidacao;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Registra um Pagamento monetário num Caso de Cobrança (SPEC §11), confirmado MANUALMENTE.
 *
 * História: o gestor confirma quanto o devedor pagou (BRUTO, em CENTAVOS). Por padrão o sistema
 * distribui automaticamente pela dívida mais antiga (auto-alocação FIFO, Ajuste 6); só em modo manual
 * o gestor informa as alocações. O caso é resolvido por id + tenant (guarda multi-tenant); caso
 * encerrado NÃO recebe pagamentos. O rateio de honorários (forma `acrescido_divida`) e a validação
 * das alocações — mesmo caso, invariáveis 12 e 20 — ficam no AlocadorPagamento. A composição gravada
 * separa a dívida do credor (`valorDivida`) dos honorários do escritório (`valorHonorarios`);
 * `valorEncargos` nasce zerado (o MVP não desmembra encargos por pagamento). O pagamento e o evento
 * "pagamento registrado" são commitados juntos (flush único no registro do evento).
 */
final class RegistrarPagamentoUseCase
{
    public function __construct(
        private readonly PagamentoRepository $pagamentoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly AlocadorPagamento $alocador,
        private readonly AutoAlocadorFifo $autoAlocadorFifo,
        private readonly RegistrarEventoHistorico $registrarEvento,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly ResolvedorConfigEncargos $resolvedorConfig,
        private readonly ReconciliadorLiquidacao $reconciliador,
    ) {
    }

    public function executar(RegistrarPagamentoInput $input, Tenant $tenant, User $criadoPor): Pagamento
    {
        // Guarda multi-tenant: o caso tem de pertencer ao próprio escritório.
        $caso = $this->casoRepository->findOneByIdDoTenant((int) $input->casoId, $tenant);

        if ($caso === null) {
            throw new CasoNaoEncontradoException((int) $input->casoId);
        }

        // Caso encerrado não recebe pagamentos.
        if ($caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        // Auto-alocação FIFO por padrão; manual só quando o usuário assume a distribuição (Ajuste 6).
        // O AlocadorPagamento revalida sempre (Σ == parte-dívida, mesmo caso — invariáveis 12 e 20).
        $alocacoesInput = $input->alocarManualmente
            ? $input->alocacoes
            : $this->autoAlocadorFifo->alocar($caso, (int) $input->valorPago, $tenant);

        // Rateio de honorários + montagem/validação das alocações (invariáveis 12 e 20).
        [$valorDivida, $valorHonorarios, $alocacoes] = $this->alocador->montar(
            $caso,
            (int) $input->valorPago,
            $alocacoesInput,
            $tenant,
        );

        // Composição: dívida do credor separada dos honorários; encargos zerados no MVP (SPEC §11/§18).
        $pagamento = new Pagamento();
        $pagamento->setTenant($tenant);
        $pagamento->setCaso($caso);
        $pagamento->setData($input->data);
        $pagamento->setValorDivida($valorDivida);
        $pagamento->setValorEncargos(0);
        $pagamento->setValorHonorarios($valorHonorarios);
        $pagamento->setCriadoPor($criadoPor);

        foreach ($alocacoes as $alocacao) {
            $pagamento->adicionarAlocacao($alocacao);
        }

        // Encargos AO VIVO (spec §6.3): quita as obrigações que este pagamento fechou. O alocado FINAL
        // por obrigação = o já alocado (antes deste pagamento) + o que este pagamento aloca a ela; onde
        // isso cobre o exigível vivo, o Reconciliador materializa o snapshot na DATA do pagamento,
        // congela e marca `liquidadaEm` (o relógio para). Obrigação apenas parcial segue Viva (cresce).
        $this->reconciliador->reconciliar(
            $this->resolvedorConfig->resolverDoCaso($caso),
            $this->obrigacoesTocadas($alocacoes),
            $this->alocadoFinalPorObrigacao($caso, $tenant, $alocacoes),
            $input->data,
        );

        // Persiste sem flush (cascade nas alocações); o registro do evento fecha a transação.
        $this->pagamentoRepository->salvar($pagamento);

        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::PagamentoRegistrado,
            $criadoPor,
            sprintf('Pagamento registrado: %s', $this->formatarReais($pagamento->valorTotalRecebido())),
            [
                'valorDivida' => $valorDivida,
                'valorHonorarios' => $valorHonorarios,
                'alocacoes' => count($alocacoes),
            ],
            flush: true,
        );

        return $pagamento;
    }

    /**
     * Obrigações distintas tocadas por este pagamento (obrigacaoId => Obrigacao) — o conjunto que o
     * Reconciliador avalia para quitar.
     *
     * @param AlocacaoPagamento[]     $alocacoes
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
     * Alocado FINAL por obrigação após este pagamento: o Σ já persistido (este pagamento ainda não foi
     * flushado) MAIS o que estas alocações somam a cada obrigação.
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
                $final[$id] = ($final[$id] ?? 0) + $alocacao->getValor();
            }
        }

        return $final;
    }

    /** Formata centavos como reais para a descrição do histórico — sem float na conta. */
    private function formatarReais(int $centavos): string
    {
        return sprintf('R$ %s,%02d', number_format(intdiv($centavos, 100), 0, ',', '.'), $centavos % 100);
    }
}
