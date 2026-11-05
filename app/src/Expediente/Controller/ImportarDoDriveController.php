<?php

declare(strict_types=1);

namespace App\Expediente\Controller;

use App\Entity\Auth\User;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Sync\Exception\DownloadDoDriveFalhouException;
use App\Sync\Exception\ImportacaoDoDriveRecusadaException;
use App\Sync\Exception\TenantSemDriveException;
use App\Sync\Exception\TokenDoDriveRecusadoException;
use App\Sync\Service\GoogleDriveClientFactoryInterface;
use App\Sync\Service\LoteDoDrive;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Importar do Google Drive" no modal Nova Pasta (spec importar-do-drive.md). Esta rota só entrega a árvore da
 * pasta do Drive no formato de caminho do lote do computador; o navegador segue com o mesmo JS do "Importar em
 * lote" e envia cada arquivo pela `pasta_peticionar_upload_drive`.
 */
final class ImportarDoDriveController extends AbstractController
{
    public const SEM_DRIVE = 'O Google Drive do escritório não está conectado.';
    public const FALHA_NO_DRIVE = 'O Google Drive não respondeu. Tente de novo em instantes.';

    public function __construct(
        private readonly GoogleDriveClientFactoryInterface $drives,
        private readonly LoteDoDrive $loteDoDrive,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/expediente/importar-lote/drive/arvore', name: 'expediente_importar_lote_drive_arvore', methods: ['GET'])]
    public function arvore(Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        // A mesma porta de criar pasta (o lote cria pastas).
        if ($tenant === null || !$this->permissionChecker->canAccessModule($user, $tenant, 'pastas')) {
            return $this->erro('Você não tem permissão para acessar o módulo de pastas.', Response::HTTP_FORBIDDEN);
        }

        try {
            $arvore = $this->loteDoDrive->arvore($this->drives->paraTenant($tenant), (string) $request->query->get('pasta', ''));
        } catch (TenantSemDriveException) {
            return $this->erro(self::SEM_DRIVE, Response::HTTP_CONFLICT);
        } catch (\InvalidArgumentException) {
            return $this->erro('Cole o link de uma pasta do Google Drive.', Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ImportacaoDoDriveRecusadaException $e) {
            return $this->erro($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (TokenDoDriveRecusadoException|DownloadDoDriveFalhouException|\Google\Service\Exception) {
            return $this->erro(self::FALHA_NO_DRIVE, Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['status' => 'ok'] + $arvore);
    }

    private function erro(string $mensagem, int $status): JsonResponse
    {
        return new JsonResponse(['status' => 'erro', 'mensagem' => $mensagem], $status);
    }
}
