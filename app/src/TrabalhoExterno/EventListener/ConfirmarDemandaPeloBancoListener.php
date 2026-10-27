<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\EventListener;

use App\Pagamento\Enum\OrigemDoPagamento;
use App\Pagamento\Event\PagamentoConfirmado;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\RegistrarPagamentoDaDemandaService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * O banco confirmou o PIX de uma demanda do Trabalho Externo: leva a demanda a Pago. Ouve só a origem
 * `trabalho_externo`; as outras origens do registro único têm o próprio ouvinte. O escritório vem do evento (do
 * `pagamento`), nunca do aviso do banco; a demanda tem de ser do mesmo escritório do pagamento.
 */
#[AsEventListener]
final class ConfirmarDemandaPeloBancoListener
{
    public function __construct(
        private readonly DemandaExternaRepository $demandas,
        private readonly RegistrarPagamentoDaDemandaService $registro,
    ) {
    }

    public function __invoke(PagamentoConfirmado $evento): void
    {
        if ($evento->origem !== OrigemDoPagamento::TrabalhoExterno) {
            return;
        }
        $demanda = $this->demandas->find($evento->origemId);
        if ($demanda === null || (int) $demanda->getTenant()->getId() !== $evento->tenantId) {
            return;
        }
        $this->registro->registrarPeloBanco($demanda, self::reais($evento->valorPagoCentavos));
    }

    /** "R$ 1.500,00" do valor que entrou. */
    private static function reais(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }
}
