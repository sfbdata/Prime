<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\RegistrarPagamentoInput;
use App\Cobranca\Entity\Pagamento;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Service\AlocadorPagamento;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Registra um Pagamento monetário num Caso de Cobrança (SPEC §11), confirmado MANUALMENTE.
 *
 * História: o gestor confirma quanto o devedor pagou (BRUTO, em CENTAVOS) e distribui esse valor
 * entre as obrigações do caso. O caso é resolvido por id + tenant (guarda multi-tenant); caso
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
        private readonly RegistrarEventoHistorico $registrarEvento,
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

        // Rateio de honorários + montagem/validação das alocações (invariáveis 12 e 20).
        [$valorDivida, $valorHonorarios, $alocacoes] = $this->alocador->montar(
            $caso,
            (int) $input->valorPago,
            $input->alocacoes,
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

    /** Formata centavos como reais para a descrição do histórico — sem float na conta. */
    private function formatarReais(int $centavos): string
    {
        return sprintf('R$ %s,%02d', number_format(intdiv($centavos, 100), 0, ',', '.'), $centavos % 100);
    }
}
