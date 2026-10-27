<?php

declare(strict_types=1);

namespace App\Pagamento\UseCase;

use App\Pagamento\DTO\CobrancaPixOutput;
use App\Pagamento\Enum\OrigemDoPagamento;
use App\Pagamento\Repository\PagamentoRepository;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/** A cobrança mais recente de um consumidor (leitura pura, do escritório dele), sem entregar a entidade. */
// Público para o teste funcional alcançá-lo pelo container.
#[Autoconfigure(public: true)]
final class ConsultarCobrancaDaOrigemUseCase
{
    public function __construct(private readonly PagamentoRepository $pagamentos)
    {
    }

    public function executar(int $tenantId, OrigemDoPagamento $origem, int $origemId): ?CobrancaPixOutput
    {
        $ultimo = $this->pagamentos->ultimoDaOrigem($tenantId, $origem, $origemId);

        return $ultimo === null ? null : CobrancaPixOutput::fromEntity($ultimo, $this->pagamentos->contarDaOrigem($tenantId, $origem, $origemId));
    }
}
