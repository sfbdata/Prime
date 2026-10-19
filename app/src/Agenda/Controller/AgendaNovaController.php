<?php

declare(strict_types=1);

namespace App\Agenda\Controller;

use App\Agenda\DTO\CompromissoOutput;
use App\Agenda\UseCase\ListarCompromissosUseCase;
use App\Entity\Auth\User;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tela da Agenda nova (desenho 09 - Agenda - VS 18.10.26). Fica em /agenda/nova até a entrega 3
 * trazer o modal de edição; a /agenda antiga continua inteira enquanto isso.
 */
final class AgendaNovaController extends AbstractController
{
    private const MAX_DIAS = 400;

    public function __construct(
        private readonly ListarCompromissosUseCase $listarCompromissos,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/agenda/nova', name: 'agenda_nova', methods: ['GET'])]
    public function tela(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (null === $tenant || !$this->permissionChecker->canAccessModule($user, $tenant, 'agenda')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de agenda.');

            return $this->redirectToRoute('homepage');
        }

        return $this->render('agenda/nova.html.twig', [
            'eu'              => ['id' => $user->getId(), 'nome' => $user->getFullName()],
            'tenantId'        => $tenant->getId(),
            'podeVerPastas'   => $this->permissionChecker->canAccessModule($user, $tenant, 'pastas'),
        ]);
    }

    #[Route('/agenda/compromissos', name: 'app_agenda_compromissos', methods: ['GET'])]
    public function compromissos(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (null === $tenant || !$this->permissionChecker->canAccessModule($user, $tenant, 'agenda')) {
            return $this->json(['erro' => 'Sem permissão'], 403);
        }
        $de = $this->data($request->query->getString('de'));
        $ate = $this->data($request->query->getString('ate'));
        if (null === $de || null === $ate || $ate < $de || $de->diff($ate)->days > self::MAX_DIAS) {
            return $this->json(['erro' => 'Informe de e ate (AAAA-MM-DD), com até 400 dias de intervalo.'], 400);
        }
        $soMinha = 'minha' === $request->query->getString('escopo');
        $verPastas = $this->permissionChecker->canAccessModule($user, $tenant, 'pastas');
        $itens = $this->listarCompromissos->executar($tenant, $user, $de, $ate, $soMinha, $verPastas);

        return $this->json(['itens' => array_map(static fn (CompromissoOutput $c): array => $c->toArray(), $itens)]);
    }

    private function data(string $valor): ?\DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return false !== $d && $d->format('Y-m-d') === $valor ? $d : null;
    }
}
