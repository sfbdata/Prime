<?php

declare(strict_types=1);

namespace App\Inteligencia\EventListener;

use App\Entity\Auth\User;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Service\VisibilidadeDaAnaliseDaPasta;
use App\Pasta\Entity\Pasta;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Análise de IA por id (`inteligencia_analise_*`: status, .txt, registro, lida, não lida, excluir,
 * interna, recolher) para quem tem oculta alguma aba de onde ela tirou conteúdo (spec
 * pasta-ocultar-aba, revisão de permissão): 404, sem confirmar que a análise existe. A regra é a
 * de {@see VisibilidadeDaAnaliseDaPasta}, a mesma das listas.
 *
 * Num ponto só, e não em cada UseCase, como o `BarreiraDeAbaOcultaListener`: rota nova com
 * `{analiseId}` sob o prefixo nasce coberta. Pasta de outro escritório ou análise que não é desta
 * pasta passam reto: a action responde o próprio 404.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS)]
final class BarreiraDeAnaliseDeAbaOcultaListener
{
    public const PREFIXO = 'inteligencia_analise_';

    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly VisibilidadeDaAnaliseDaPasta $visibilidade,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $analiseId = $request->attributes->get('analiseId');
        $user = $this->security->getUser();
        if (!str_starts_with((string) $request->attributes->get('_route'), self::PREFIXO) || !is_numeric($analiseId) || !$user instanceof User) {
            return;
        }

        $pasta = $this->pastaDosArgumentos($event->getArguments());
        $analise = $pasta !== null ? $this->analiseDaPasta((int) $analiseId, $pasta) : null;
        if ($analise === null || $this->visibilidade->podeLer($analise, $pasta, $user)) {
            return;
        }

        $resposta = new JsonResponse(['erro' => 'Análise não encontrada.'], Response::HTTP_NOT_FOUND);
        $event->setController(static fn (): Response => $resposta);
        $event->setArguments([]);
    }

    /** @param array<int, mixed> $argumentos */
    private function pastaDosArgumentos(array $argumentos): ?Pasta
    {
        foreach ($argumentos as $argumento) {
            if ($argumento instanceof Pasta) {
                return $argumento;
            }
        }

        return null;
    }

    private function analiseDaPasta(int $analiseId, Pasta $pasta): ?AnaliseDeInteligencia
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return null;
        }

        return $this->analises->findOneDoTenantEAlvo($analiseId, $tenant, AnaliseDeInteligencia::ALVO_PASTA, (int) $pasta->getId());
    }
}
