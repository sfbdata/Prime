<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\AlocacaoPagamentoInput;
use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Exception\ObrigacaoDeOutroCasoException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Exception\PagamentoInconsistenteException;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Entity\Tenant\Tenant;

/**
 * Monta e valida as alocações de um Pagamento (SPEC §11), reutilizado pelo registro e pela correção.
 * Serviço read-only: NÃO persiste nem flusha — só constrói as AlocacaoPagamento em memória e
 * garante as invariantes de dinheiro.
 *
 * O rateio de honorários vem do CalculadoraHonorarios::ratearPagamento (SPEC §18): dado o BRUTO pago
 * pelo devedor, separa `[parteDivida, parteHonorarios]` (na forma `acrescido_divida`) ou devolve
 * `[total, 0]` nas demais. Toda obrigação alocada tem de ser do MESMO caso do pagamento — comparação
 * por IDENTIDADE de instância (invariável 12). A Σ das alocações tem de fechar EXATAMENTE com a parte
 * da dívida (invariável 20), senão o pagamento é inconsistente.
 */
final class AlocadorPagamento
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly CalculadoraHonorarios $calculadoraHonorarios,
    ) {
    }

    /**
     * @param AlocacaoPagamentoInput[] $alocacoesInput
     *
     * @return array{0:int,1:int,2:list<AlocacaoPagamento>} [valorDivida, valorHonorarios, alocacoes]
     */
    public function montar(CasoCobranca $caso, int $valorPago, array $alocacoesInput, Tenant $tenant): array
    {
        [$valorDivida, $valorHonorarios] = $this->calculadoraHonorarios->ratearPagamento($caso, $valorPago);

        $alocacoes = [];
        $soma = 0;

        foreach ($alocacoesInput as $item) {
            $obrigacao = $this->obrigacaoRepository->findOneByIdDoTenant((int) $item->obrigacaoId, $tenant);

            if ($obrigacao === null) {
                throw new ObrigacaoNaoEncontradaException((int) $item->obrigacaoId);
            }

            // Invariável 12: o pagamento não atravessa casos — identidade de instância (identity-map).
            if ($obrigacao->getCaso() !== $caso) {
                throw new ObrigacaoDeOutroCasoException((int) $item->obrigacaoId, (int) $caso->getId());
            }

            $valor = (int) $item->valor;

            $alocacao = new AlocacaoPagamento();
            $alocacao->setTenant($tenant);
            $alocacao->setObrigacao($obrigacao);
            $alocacao->setValor($valor);

            $alocacoes[] = $alocacao;
            $soma += $valor;
        }

        // Invariável 20: a Σ das alocações tem de fechar exatamente com a parte da dívida.
        if ($soma !== $valorDivida) {
            throw new PagamentoInconsistenteException($soma, $valorDivida);
        }

        return [$valorDivida, $valorHonorarios, $alocacoes];
    }
}
