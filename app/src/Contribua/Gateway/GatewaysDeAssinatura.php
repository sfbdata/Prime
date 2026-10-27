<?php

declare(strict_types=1);

namespace App\Contribua\Gateway;

use App\Contribua\Repository\ConfiguracaoDePagamentoRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Os adaptadores de cartão instalados (tag `contribua.gateway`), achados pelo `codigo()`.
 *
 * `configurado()` é o que o Master escolheu no modal "Pagamento por cartão"; `porCodigo()` serve ao
 * webhook e à conciliação, que precisam continuar atendendo assinaturas de um gateway mesmo depois que
 * o Master trocar de gateway para os pedidos novos.
 */
final class GatewaysDeAssinatura
{
    /** @var array<string, GatewayDeAssinatura> */
    private array $porCodigo = [];

    /** @param iterable<GatewayDeAssinatura> $gateways */
    public function __construct(
        #[AutowireIterator(GatewayDeAssinatura::TAG)]
        iterable $gateways,
        private readonly ConfiguracaoDePagamentoRepository $configuracao,
    ) {
        foreach ($gateways as $gateway) {
            $this->porCodigo[$gateway->codigo()] = $gateway;
        }
    }

    public function porCodigo(string $codigo): ?GatewayDeAssinatura
    {
        return $this->porCodigo[$codigo] ?? null;
    }

    /** O gateway escolhido pelo Master, se ele estiver instalado. Null = lista de espera (C2). */
    public function configurado(): ?GatewayDeAssinatura
    {
        $codigo = $this->configuracao->atual()->getGateway();

        return $codigo === null ? null : $this->porCodigo($codigo);
    }

    /** @return list<string> */
    public function codigosInstalados(): array
    {
        return array_keys($this->porCodigo);
    }
}
