<?php

declare(strict_types=1);

namespace App\Agenda\Controller;

use App\Agenda\Contract\EnviadorDePush;
use App\Agenda\DTO\InscricaoDePushInput;
use App\Agenda\UseCase\CancelarInscricaoDePushUseCase;
use App\Agenda\UseCase\InscreverPushUseCase;
use App\Entity\Auth\User;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Inscrição do navegador no push dos lembretes (entrega 5b). A chave pública VAPID é dada ao navegador
 * para ele se inscrever; a inscrição é guardada para a varredura enviar com o navegador fechado.
 */
final class PushController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly EnviadorDePush $enviador,
        private readonly InscreverPushUseCase $inscrever,
        private readonly CancelarInscricaoDePushUseCase $cancelar,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/agenda/push/chave', name: 'app_agenda_push_chave', methods: ['GET'])]
    public function chave(): JsonResponse
    {
        return $this->json(['chave' => $this->enviador->chavePublica()]);
    }

    #[Route('/agenda/push/inscrever', name: 'app_agenda_push_inscrever', methods: ['POST'])]
    public function inscrever(Request $request): JsonResponse
    {
        /** @var User $eu */
        $eu = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (null === $tenant || !$this->permissionChecker->canAccessModule($eu, $tenant, 'agenda')) {
            return $this->json(['erro' => 'Sem permissão'], 403);
        }
        $this->validarCsrfAjax($request);
        try {
            $this->inscrever->executar($tenant, $eu, InscricaoDePushInput::doJson($request->toArray()));
        } catch (\DomainException|JsonException $e) {
            return $this->json(['erro' => $e->getMessage()], 422);
        }

        return $this->json(['ok' => true]);
    }

    #[Route('/agenda/push/cancelar', name: 'app_agenda_push_cancelar', methods: ['POST'])]
    public function cancelar(Request $request): JsonResponse
    {
        /** @var User $eu */
        $eu = $this->getUser();
        $this->validarCsrfAjax($request);
        try {
            $this->cancelar->executar($eu, InscricaoDePushInput::doJson($request->toArray())->endpoint);
        } catch (JsonException $e) {
            return $this->json(['erro' => $e->getMessage()], 422);
        }

        return $this->json(['ok' => true]);
    }
}
