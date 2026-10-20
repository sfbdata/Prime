<?php

declare(strict_types=1);

namespace App\Pasta\Twig;

use App\Pasta\DTO\AvisarClienteOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\UseCase\MontarAvisarClienteUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `avisar_cliente(pastaId)`: o cartão "Avisar cliente" e o que o cartão da Análise IA precisa para o
 * botão "Enviar ao cliente" (spec push-avisar-cliente §2 e §3). Função, e não variável do controller,
 * porque a lista de análises (`inteligencia/_analises_push.html.twig`) também é renderizada sozinha
 * na recarga por XHR e o painel é incluído com `only`; o PastaController (legado) fica intocado.
 *
 * A pasta é buscada com o escritório da sessão (pasta de outro escritório ou sem sessão: null). Uma
 * montagem por pasta por requisição (o cartão e a lista de análises pedem a mesma).
 */
final class AvisarClienteExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<int, AvisarClienteOutput|null> */
    private array $porPasta = [];

    public function __construct(
        private readonly PastaRepository $pastas,
        private readonly TenantContext $tenantContext,
        private readonly MontarAvisarClienteUseCase $montar,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('avisar_cliente', $this->avisarCliente(...)),
        ];
    }

    public function avisarCliente(int $pastaId): ?AvisarClienteOutput
    {
        if (!\array_key_exists($pastaId, $this->porPasta)) {
            $this->porPasta[$pastaId] = $this->montar($pastaId);
        }

        return $this->porPasta[$pastaId];
    }

    public function reset(): void
    {
        $this->porPasta = [];
    }

    private function montar(int $pastaId): ?AvisarClienteOutput
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            return null;
        }
        $pasta = $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $tenant]);

        return $pasta instanceof Pasta ? $this->montar->executar($pasta, $tenant) : null;
    }
}
