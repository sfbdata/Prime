<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\RegistrarLiquidacaoInput;
use App\Cobranca\Entity\Liquidacao;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Registra uma Liquidação num Caso de Cobrança (SPEC §11): reduz a dívida por bem móvel/imóvel,
 * direito ou outra forma aceita — reconhecimento direto do saldo extinto, sem o rateio de honorários
 * do Pagamento (§18).
 *
 * História: o gestor reconhece que parte (ou o todo) da dívida foi extinta por algo diferente de
 * dinheiro — um carro dado em pagamento, um imóvel, uma cessão de direito. O que reduz o saldo é o
 * valor RECONHECIDO (o quanto da dívida se considera quitado), NÃO o valor atribuído ao bem: o bem
 * pode valer mais ou menos do que se reconhece na negociação, então os dois são guardados separados
 * e o valor do bem é OPCIONAL (SPEC §11). O caso é resolvido por id + tenant (guarda multi-tenant);
 * caso encerrado não recebe novas liquidações (SPEC §17). A liquidação e o evento
 * "liquidação registrada" são commitados juntos (flush único no registro do evento).
 */
final class RegistrarLiquidacaoUseCase
{
    public function __construct(
        private readonly LiquidacaoRepository $liquidacaoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(RegistrarLiquidacaoInput $input, Tenant $tenant, User $criadoPor): Liquidacao
    {
        // Guarda multi-tenant: o caso tem de pertencer ao próprio escritório.
        $caso = $this->casoRepository->findOneByIdDoTenant((int) $input->casoId, $tenant);

        if ($caso === null) {
            throw new CasoNaoEncontradoException((int) $input->casoId);
        }

        // Caso encerrado não recebe novas liquidações (SPEC §17).
        if ($caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        $valorReconhecido = (int) $input->valorReconhecido;

        // Valor do bem (opcional) e valor reconhecido são independentes — nunca se força igualdade (§11).
        $liquidacao = new Liquidacao();
        $liquidacao->setTenant($tenant);
        $liquidacao->setCaso($caso);
        $liquidacao->setTipo($input->tipo);
        $liquidacao->setDescricaoBem(trim((string) $input->descricaoBem));
        $liquidacao->setValorAtribuidoBem($input->valorAtribuidoBem);
        $liquidacao->setValorReconhecido($valorReconhecido);
        $liquidacao->setData($input->data);
        $liquidacao->setCriadoPor($criadoPor);

        // Persiste sem flush; o registro do evento fecha a transação (persiste os dois de uma vez).
        $this->liquidacaoRepository->salvar($liquidacao);

        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::LiquidacaoRegistrada,
            $criadoPor,
            sprintf(
                'Liquidação registrada: %s — reconhecido %s',
                $input->tipo->label(),
                $this->formatarReais($valorReconhecido),
            ),
            [
                'tipo' => $input->tipo->value,
                'valorReconhecido' => $valorReconhecido,
                'valorAtribuidoBem' => $input->valorAtribuidoBem,
            ],
            flush: true,
        );

        return $liquidacao;
    }

    /**
     * Formata centavos inteiros como "R$ 1.234,56" sem passar por float (dinheiro nunca em float):
     * a parte de reais vem de intdiv e os centavos do resto da divisão.
     */
    private function formatarReais(int $centavos): string
    {
        return sprintf(
            'R$ %s,%02d',
            number_format(intdiv($centavos, 100), 0, ',', '.'),
            $centavos % 100,
        );
    }
}
