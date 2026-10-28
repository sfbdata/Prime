<?php

declare(strict_types=1);

namespace App\Agenda\Controller;

use App\Agenda\DTO\CompromissoOutput;
use App\Agenda\DTO\LembreteOutput;
use App\Agenda\UseCase\EstadoDoGoogleAgendaUseCase;
use App\Agenda\UseCase\ContarMeusCompromissosUseCase;
use App\Agenda\UseCase\DispensarAvisoDoGoogleUseCase;
use App\Agenda\Service\PermissoesDaAgenda;
use App\Agenda\UseCase\ListarCompromissosUseCase;
use App\Agenda\UseCase\ListarLembretesUseCase;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tela da Agenda: /agenda, /agenda/nova e /agenda/classica mostram a de 18/10 (desenho 09 - Agenda - VS 18.10.26);
 * a 1A (desenho 09 - Agenda 1A, spec agenda-1a.md) fica guardada em /agenda/1a, sem link (28/10: dono pediu voltar). Desde a entrega 3b responde em /agenda
 * (agenda_index, usada pela barra lateral, pela aba e pelo "Voltar ao Calendário" das páginas antigas);
 * /agenda/nova continua para os links já enviados nos avisos. As páginas antigas de lista, detalhe,
 * novo e editar seguem de pé: o Dashboard e avisos gravados no banco apontam para elas.
 */
final class AgendaNovaController extends AbstractController
{
    private const MAX_DIAS = 400;

    public function __construct(
        private readonly ListarCompromissosUseCase $listarCompromissos,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly UserRepository $usuarios,
        private readonly ListarLembretesUseCase $listarLembretes,
        private readonly PermissoesDaAgenda $daAgenda,
        private readonly EstadoDoGoogleAgendaUseCase $estadoDoGoogle,
        private readonly ContarMeusCompromissosUseCase $contarMeus,
        private readonly DispensarAvisoDoGoogleUseCase $avisoDoGoogle,
    ) {
    }

    #[Route('/agenda', name: 'agenda_index', methods: ['GET'])]
    #[Route('/agenda/nova', name: 'agenda_nova', methods: ['GET'])]
    #[Route('/agenda/classica', name: 'agenda_classica', methods: ['GET'])]
    #[Route('/agenda/1a', name: 'agenda_1a', methods: ['GET'])]
    public function tela(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (null === $tenant || !$this->permissionChecker->canAccessModule($user, $tenant, 'agenda')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de agenda.');

            return $this->redirectToRoute('homepage');
        }
        $google = $this->estadoDoGoogle->executar($tenant, $user);

        // /agenda é a tela de 18/10; a 1A (docs/specs/agenda-1a.md) só abre em /agenda/1a.
        $modelo = 'agenda_1a' === $request->attributes->get('_route') ? 'agenda/um_a.html.twig' : 'agenda/nova.html.twig';

        return $this->render($modelo, [
            'eu'     => ['id' => $user->getId(), 'nome' => $user->getFullName()],
            'podeVerPastas' => $this->daAgenda->verPastas($user, $tenant),
            'tenantId'      => $tenant->getId(),
            'escritorio'    => (string) $tenant->getName(),
            'google'        => $google,
            'googleAviso'   => $this->avisoDoGoogle->mostrar($tenant, $user, $google),
            'equipe' => array_map(
                static fn (User $u): array => ['id' => $u->getId(), 'nome' => $u->getFullName()],
                $this->usuarios->findColaboradoresAtivosPorTenant($tenant),
            ),
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
        $administra = $this->permissionChecker->canAdminister($user, $tenant, 'admin.users.manage');
        $itens = $this->listarCompromissos->executar($tenant, $user, $de, $ate, $soMinha, $this->daAgenda->verPastas($user, $tenant), $this->daAgenda->verPrazos($user, $tenant), $administra);

        return $this->json([
            'itens'     => array_map(static fn (CompromissoOutput $c): array => $c->toArray(), $itens),
            'contagens' => $this->contarMeus->executar($tenant, $user),
        ]);
    }

    /** Lembretes das próximas horas, pedidos pelo lembretes.js em qualquer tela (parcial global). */
    #[Route('/agenda/lembretes', name: 'app_agenda_lembretes', methods: ['GET'])]
    public function lembretes(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (null === $tenant || !$this->permissionChecker->canAccessModule($user, $tenant, 'agenda')) {
            return $this->json(['erro' => 'Sem permissão'], 403);
        }
        $itens = $this->listarLembretes->executar($tenant, $user, new \DateTimeImmutable(), $this->daAgenda->verPastas($user, $tenant), $this->daAgenda->verPrazos($user, $tenant));

        return $this->json(['itens' => array_map(static fn (LembreteOutput $l): array => $l->toArray(), $itens)]);
    }

    private function data(string $valor): ?\DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return false !== $d && $d->format('Y-m-d') === $valor ? $d : null;
    }
}
