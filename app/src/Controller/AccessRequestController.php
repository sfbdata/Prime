<?php

namespace App\Controller;

use App\Entity\Permission\AccessRequest;
use App\Entity\Permission\ResourceAccess;
use App\Repository\AccessRequestRepository;
use App\Repository\ResourceAccessRepository;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Painel de aprovação de solicitações de acesso por item.
 *
 * Isolamento de tenant: admin só visualiza e decide sobre solicitações
 * de usuários do próprio tenant. Verificação feita em assertAccess() e
 * reforçada nas ações de approve/deny via assertBelongsToAdminTenant().
 */
#[Route('/access-requests')]
final class AccessRequestController extends AbstractController
{
    /**
     * Garante que o usuário autenticado tem admin.access_requests.approve
     * e que pertence a um tenant válido.
     */
    private function assertAccess(PermissionChecker $checker): void
    {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        if (!$checker->canAdminister($user, 'admin.access_requests.approve')) {
            throw $this->createAccessDeniedException('Você não tem permissão para aprovar solicitações de acesso.');
        }

        if ($user->getTenant() === null) {
            throw $this->createAccessDeniedException('Usuário sem tenant associado.');
        }
    }

    /**
     * Garante que a solicitação pertence ao tenant do admin logado.
     * Reforço de isolamento antes de qualquer mutação.
     */
    private function assertBelongsToAdminTenant(AccessRequest $request): void
    {
        $admin = $this->getUser();

        $adminTenantId   = $admin->getTenant()?->getId();
        $requestTenantId = $request->getUser()?->getTenant()?->getId();

        if ($adminTenantId !== $requestTenantId) {
            throw $this->createAccessDeniedException('Esta solicitação não pertence ao seu escritório.');
        }
    }

    /**
     * Endpoint AJAX: usuário submete solicitação de acesso com justificativa.
     *
     * POST /access-requests/submit
     * Body JSON: { resourceType, resourceId, action, description }
     */
    #[Route('/submit', name: 'app_access_request_submit', methods: ['POST'])]
    public function submit(
        Request $httpRequest,
        AccessRequestRepository $accessRequestRepository,
        PermissionChecker $checker,
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'Não autenticado.'], 401);
        }

        if (!$this->isCsrfTokenValid('access_request_submit', $httpRequest->request->get('_token'))) {
            return $this->json(['error' => 'Token CSRF inválido.'], 403);
        }

        $resourceType = $httpRequest->request->get('resourceType');
        $resourceId   = (int) $httpRequest->request->get('resourceId');
        $action       = $httpRequest->request->get('action', AccessRequest::ACTION_VIEW);
        $description  = trim((string) $httpRequest->request->get('description', ''));

        $validTypes   = [AccessRequest::RESOURCE_CLIENTE, AccessRequest::RESOURCE_PASTA, AccessRequest::RESOURCE_PROCESSO];
        $validActions = [AccessRequest::ACTION_VIEW, AccessRequest::ACTION_EDIT, AccessRequest::ACTION_DELETE];

        if (!in_array($resourceType, $validTypes, true) || !in_array($action, $validActions, true) || $resourceId <= 0) {
            return $this->json(['error' => 'Parâmetros inválidos.'], 400);
        }

        // Verifica se já tem acesso
        if ($checker->canAccessResource($user, $resourceType, $resourceId, $action)) {
            return $this->json(['error' => 'Você já tem acesso a este recurso.'], 400);
        }

        // Evita duplicata de solicitação pendente
        $existing = $accessRequestRepository->findPendingForUserAndResource($user, $resourceType, $resourceId, $action);
        if ($existing !== null) {
            return $this->json(['message' => 'Solicitação já enviada. Aguarde aprovação do administrador.']);
        }

        $accessRequest = (new AccessRequest())
            ->setUser($user)
            ->setResourceType($resourceType)
            ->setResourceId($resourceId)
            ->setAction($action)
            ->setDescription($description !== '' ? $description : null);

        $accessRequestRepository->save($accessRequest, true);

        return $this->json(['message' => 'Solicitação enviada com sucesso. Aguarde aprovação do administrador.']);
    }

    /**
     * Painel: lista solicitações pendentes do tenant do admin.
     */
    #[Route('', name: 'app_access_request_index', methods: ['GET'])]
    public function index(
        AccessRequestRepository $repository,
        PermissionChecker $checker,
    ): Response {
        $this->assertAccess($checker);

        $admin = $this->getUser();

        $requests = $repository->findPendingByTenant($admin->getTenant());

        return $this->render('access_request/index.html.twig', [
            'requests' => $requests,
        ]);
    }

    /**
     * Aprovar solicitação: cria ou atualiza ResourceAccess com as ações escolhidas
     * e marca a AccessRequest como aprovada.
     *
     * POST /access-requests/{id}/approve
     * Body: canView=1, canEdit=1, canDelete=0  (checkboxes)
     */
    #[Route('/{id}/approve', name: 'app_access_request_approve', methods: ['POST'])]
    public function approve(
        int $id,
        Request $httpRequest,
        AccessRequestRepository $accessRequestRepository,
        ResourceAccessRepository $resourceAccessRepository,
        EntityManagerInterface $em,
        PermissionChecker $checker,
    ): Response {
        $this->assertAccess($checker);

        $accessRequest = $accessRequestRepository->find($id);

        if (!$accessRequest) {
            throw $this->createNotFoundException('Solicitação não encontrada.');
        }

        $this->assertBelongsToAdminTenant($accessRequest);

        if (!$accessRequest->isPending()) {
            $this->addFlash('warning', 'Esta solicitação já foi decidida.');
            return $this->redirectToRoute('app_access_request_index');
        }

        if (!$this->isCsrfTokenValid('approve_request_' . $id, $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $canView   = (bool) $httpRequest->request->get('canView', false);
        $canEdit   = (bool) $httpRequest->request->get('canEdit', false);
        $canDelete = (bool) $httpRequest->request->get('canDelete', false);

        // Pelo menos "view" deve ser concedido em uma aprovação
        if (!$canView && !$canEdit && !$canDelete) {
            $this->addFlash('error', 'Selecione ao menos uma ação para aprovar.');
            return $this->redirectToRoute('app_access_request_index');
        }

        $admin          = $this->getUser();
        $requestingUser = $accessRequest->getUser();
        $resourceType   = $accessRequest->getResourceType();
        $resourceId     = $accessRequest->getResourceId();

        // Busca ou cria o ResourceAccess para o usuário+item
        $resourceAccess = $resourceAccessRepository->findForUserAndResource($requestingUser, $resourceType, $resourceId);

        if ($resourceAccess === null) {
            $resourceAccess = new ResourceAccess();
            $resourceAccess->setUser($requestingUser);
            $resourceAccess->setResourceType($resourceType);
            $resourceAccess->setResourceId($resourceId);
        }

        $resourceAccess->setCanView($canView);
        $resourceAccess->setCanEdit($canEdit);
        $resourceAccess->setCanDelete($canDelete);
        $resourceAccess->setGrantedBy($admin);

        $resourceAccessRepository->save($resourceAccess);

        // Marca a solicitação como aprovada
        $accessRequest->setStatus(AccessRequest::STATUS_APPROVED);
        $accessRequest->setDecidedAt(new \DateTimeImmutable());
        $accessRequest->setDecidedBy($admin);

        $em->flush();

        $this->addFlash('success', sprintf(
            'Acesso de %s ao %s #%d aprovado.',
            $requestingUser->getFullName(),
            $resourceType,
            $resourceId,
        ));

        return $this->redirectToRoute('app_access_request_index');
    }

    /**
     * Negar solicitação: marca como denied com decidedAt e decidedBy.
     *
     * POST /access-requests/{id}/deny
     */
    #[Route('/{id}/deny', name: 'app_access_request_deny', methods: ['POST'])]
    public function deny(
        int $id,
        Request $httpRequest,
        AccessRequestRepository $accessRequestRepository,
        EntityManagerInterface $em,
        PermissionChecker $checker,
    ): Response {
        $this->assertAccess($checker);

        $accessRequest = $accessRequestRepository->find($id);

        if (!$accessRequest) {
            throw $this->createNotFoundException('Solicitação não encontrada.');
        }

        $this->assertBelongsToAdminTenant($accessRequest);

        if (!$accessRequest->isPending()) {
            $this->addFlash('warning', 'Esta solicitação já foi decidida.');
            return $this->redirectToRoute('app_access_request_index');
        }

        if (!$this->isCsrfTokenValid('deny_request_' . $id, $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $admin = $this->getUser();

        $accessRequest->setStatus(AccessRequest::STATUS_DENIED);
        $accessRequest->setDecidedAt(new \DateTimeImmutable());
        $accessRequest->setDecidedBy($admin);

        $em->flush();

        $this->addFlash('success', sprintf(
            'Solicitação de %s ao %s #%d negada.',
            $accessRequest->getUser()->getFullName(),
            $accessRequest->getResourceType(),
            $accessRequest->getResourceId(),
        ));

        return $this->redirectToRoute('app_access_request_index');
    }
}
