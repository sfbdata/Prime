<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Master\DTO\RegistrarEventoDeDesempenhoInput;
use App\Master\UseCase\RegistrarEventoDeDesempenhoUseCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Recebe os eventos que o `bj-monitor.js` mede nas telas (spec docs/specs/master-desempenho.md, D2). Qualquer
 * usuário logado; CSRF no cabeçalho `X-CSRF-Token`; corpo de até 1 KB; no máximo 60 eventos por minuto por usuário
 * (acima disso responde 429 e descarta). Resposta sempre sem corpo: o navegador não precisa de retorno.
 */
#[IsGranted('IS_AUTHENTICATED')]
final class MonitorEventoController extends AbstractController
{
    public const TOKEN = 'monitor_evento';
    public const CORPO_MAXIMO = 1024;
    public const LIMITE_POR_MINUTO = 60;

    public function __construct(
        private readonly RegistrarEventoDeDesempenhoUseCase $useCase,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    #[Route('/monitor/evento', name: 'monitor_evento', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(null, Response::HTTP_FORBIDDEN);
        }
        $bruto = $request->getContent();
        if (strlen($bruto) > self::CORPO_MAXIMO) {
            return new JsonResponse(null, Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $usuario = $this->getUser();
        $usuario = $usuario instanceof User ? $usuario : null;
        $corpo = json_decode($bruto, true);
        $input = is_array($corpo) ? RegistrarEventoDeDesempenhoInput::doJson($corpo, $usuario) : null;
        if ($input === null) {
            return new JsonResponse(null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$this->dentroDoLimite($usuario)) {
            return new JsonResponse(null, Response::HTTP_TOO_MANY_REQUESTS);
        }
        $this->useCase->executar($input);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Contador por usuário e por minuto no cache da aplicação; sem estado no banco. */
    private function dentroDoLimite(?User $usuario): bool
    {
        $item = $this->cache->getItem(sprintf('monitor_evento.%d.%s', $usuario?->getId() ?? 0, gmdate('YmdHi')));
        $n = (int) ($item->isHit() ? $item->get() : 0);
        if ($n >= self::LIMITE_POR_MINUTO) {
            return false;
        }
        $this->cache->save($item->set($n + 1)->expiresAfter(90));

        return true;
    }
}
