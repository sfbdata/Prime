<?php

declare(strict_types=1);

namespace App\Kanban\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Kanban\Repository\KanbanAnexoRepository;
use App\Kanban\Repository\KanbanCardRepository;
use App\Kanban\UseCase\AdicionarAnexoUseCase;
use App\Kanban\UseCase\ExcluirAnexoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Service\ArquivoStorageInterface;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/kanban')]
final class KanbanAnexoController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly AdicionarAnexoUseCase $adicionarAnexo,
        private readonly ExcluirAnexoUseCase $excluirAnexo,
        private readonly KanbanCardRepository $cardRepository,
        private readonly KanbanAnexoRepository $anexoRepository,
        private readonly ArquivoStorageInterface $storage,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/card/{cardId}/anexo', name: 'kanban_anexo_upload', methods: ['POST'], requirements: ['cardId' => '\d+'])]
    public function upload(int $cardId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        $card = $this->cardRepository->findPorTenantEId($cardId, $tenant);
        if ($card === null || !$card->getBoard()->temAcesso($user)) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Card não encontrado.'], 404);
        }

        // CSRF após o guard de tenant/acesso (o 404 tem prioridade e fica testável).
        $this->validarCsrfAjax($request);

        $arquivo = $request->files->get('arquivo');
        if ($arquivo === null) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Nenhum arquivo enviado.'], 422);
        }

        $output = $this->adicionarAnexo->executar($arquivo, $card, $user);

        return $this->json([
            'sucesso' => true,
            'anexo'   => [
                'id'           => $output->id,
                'nomeOriginal' => $output->nomeOriginal,
                'tamanho'      => $output->tamanhoFormatado,
                'isImagem'     => $output->isImagem,
                'servirUrl'    => $this->generateUrl('kanban_anexo_servir', ['id' => $output->id]),
            ],
        ]);
    }

    #[Route('/anexo/{id}', name: 'kanban_anexo_servir', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function servir(int $id): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        $anexo = $this->anexoRepository->findPorTenantEId($id, $tenant);
        if ($anexo === null) {
            throw $this->createNotFoundException('Anexo não encontrado.');
        }

        return $this->storage->servir($anexo->getCaminho(), $anexo->getNomeOriginal(), inline: true);
    }

    #[Route('/anexo/{id}/excluir', name: 'kanban_anexo_excluir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function excluir(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        if (!$this->isCsrfTokenValid('kanban_anexo_excluir_' . $id, (string) $request->request->get('_token'))) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Token inválido.'], 403);
        }

        $anexo = $this->anexoRepository->findPorTenantEId($id, $tenant);
        if ($anexo === null) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Anexo não encontrado.'], 404);
        }

        $this->excluirAnexo->executar($anexo);

        return $this->json(['sucesso' => true]);
    }

    private function assertAccess(User $user): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || !$this->permissionChecker->canAccessModule($user, $tenant, 'kanban')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Kanban.');
        }

        return $tenant;
    }
}
