<?php

declare(strict_types=1);

namespace App\Sync\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Sync\DTO\ConexaoDriveOutput;
use App\Sync\Repository\TenantDriveConexaoRepository;
use App\Sync\Service\GoogleDriveOAuthInterface;
use App\Sync\UseCase\ConectarDriveUseCase;
use App\Sync\UseCase\DefinirRootFolderDriveUseCase;
use App\Sync\UseCase\DesconectarDriveUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Conectar meu Drive" — o escritório conecta a PRÓPRIA conta Google (OAuth) para o sync agir no
 * Drive dele. Gate: administrador do escritório (`admin.tenant.settings.manage`) ou super-admin.
 * Multi-tenant: sempre o tenant ATUAL (TenantContext); nunca o de outro escritório.
 */
final class DriveConexaoController extends AbstractController
{
    private const SESSION_STATE = 'sync_drive_oauth_state';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly UserTenantRepository $userTenants,
        private readonly TenantDriveConexaoRepository $conexoes,
        private readonly GoogleDriveOAuthInterface $oauth,
        private readonly ConectarDriveUseCase $conectar,
        private readonly DefinirRootFolderDriveUseCase $definirRootFolder,
        private readonly DesconectarDriveUseCase $desconectar,
    ) {
    }

    #[Route('/sync/drive/conexao', name: 'app_sync_drive_conexao', methods: ['GET'])]
    public function conexao(): Response
    {
        $tenant = $this->tenantAdministravel();

        return $this->render('sync/drive_conexao.html.twig', [
            'conexao' => ConexaoDriveOutput::fromEntity($this->conexoes->findDoTenant($tenant)),
        ]);
    }

    #[Route('/sync/drive/conexao/iniciar', name: 'app_sync_drive_iniciar', methods: ['GET'])]
    public function iniciar(Request $request): Response
    {
        $this->tenantAdministravel();

        $state = bin2hex(random_bytes(16));
        $request->getSession()->set(self::SESSION_STATE, $state);

        $url = $this->oauth->criarUrlDeAutorizacao($this->redirectUri(), $state);

        return $this->redirect($url);
    }

    #[Route('/sync/drive/conexao/callback', name: 'app_sync_drive_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        $tenant = $this->tenantAdministravel();
        $usuario = $this->usuarioAtual();

        $esperado = $request->getSession()->remove(self::SESSION_STATE);
        $recebido = $request->query->getString('state');
        if ($esperado === null || !hash_equals((string) $esperado, $recebido)) {
            throw $this->createAccessDeniedException('State OAuth inválido.');
        }

        if ($request->query->getString('error') !== '') {
            $this->addFlash('danger', 'Autorização do Google cancelada ou negada.');

            return $this->redirectToRoute('app_sync_drive_conexao');
        }

        try {
            $auth = $this->oauth->trocarCodePorRefreshToken($request->query->getString('code'), $this->redirectUri());
            $this->conectar->executar($tenant, $usuario, $auth);
        } catch (\Throwable) {
            $this->addFlash('danger', 'Não foi possível concluir a conexão com o Google. Tente novamente.');

            return $this->redirectToRoute('app_sync_drive_conexao');
        }

        $this->addFlash('success', 'Conta Google conectada. Agora informe a pasta-raiz do acervo.');

        return $this->redirectToRoute('app_sync_drive_conexao');
    }

    #[Route('/sync/drive/conexao/pasta-raiz', name: 'app_sync_drive_pasta_raiz', methods: ['POST'])]
    public function definirPastaRaiz(Request $request): Response
    {
        $tenant = $this->tenantAdministravel();

        if (!$this->isCsrfTokenValid('sync_drive_pasta_raiz', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        try {
            $this->definirRootFolder->executar($tenant, $request->request->getString('pasta_raiz'));
            $this->addFlash('success', 'Pasta-raiz definida. A sincronização deste escritório está ativa.');
        } catch (\InvalidArgumentException | \DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_sync_drive_conexao');
    }

    #[Route('/sync/drive/conexao/desconectar', name: 'app_sync_drive_desconectar', methods: ['POST'])]
    public function desconectarAcao(Request $request): Response
    {
        $tenant = $this->tenantAdministravel();

        if (!$this->isCsrfTokenValid('sync_drive_desconectar', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $this->desconectar->executar($tenant);
        $this->addFlash('success', 'Drive desconectado.');

        return $this->redirectToRoute('app_sync_drive_conexao');
    }

    /** URL absoluta do callback — deve bater com o redirect_uri registrado no app `bluejus-sync`. */
    private function redirectUri(): string
    {
        return $this->generateUrl('app_sync_drive_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function usuarioAtual(): User
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $usuario;
    }

    /** Gate: administrador do escritório atual (ou super-admin). Devolve o tenant atual. */
    private function tenantAdministravel(): Tenant
    {
        $usuario = $this->usuarioAtual();

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('Nenhum escritório selecionado.');
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $usuario->getRoles(), true);
        $isAdminDoTenant = $this->userTenants->existeVinculoAtivo($usuario, $tenant)
            && $this->permissionChecker->canAdminister($usuario, $tenant, 'admin.tenant.settings.manage');

        if (!$isSuperAdmin && !$isAdminDoTenant) {
            throw $this->createAccessDeniedException();
        }

        return $tenant;
    }
}
