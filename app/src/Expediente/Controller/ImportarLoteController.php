<?php

declare(strict_types=1);

namespace App\Expediente\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CriarPastaDTO;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\CriarPastaUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Sync\Service\SincronizacaoPastaDispatcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * "Importar pastas em lote" do Expediente (desenho 03 de 30/10, spec importar-pastas-em-lote.md).
 *
 * O navegador orquestra o lote: para cada subpasta escolhida chama esta rota, que cria UMA pasta pelo
 * mesmo `CriarPastaUseCase` do modal Nova Pasta, e depois sobe os arquivos um a um pela rota de upload
 * da aba Documentos (`pasta_peticionar_upload`). Assim cada pedido cabe no limite de upload do PHP e
 * as travas do upload (tipo, tamanho, cota do plano) valem sem cópia (D1).
 */
final class ImportarLoteController extends AbstractController
{
    public const CSRF = 'expediente_importar_lote';

    public function __construct(
        private readonly CriarPastaUseCase $criarPastaUseCase,
        private readonly SincronizacaoPastaDispatcher $syncDispatcher,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/expediente/importar-lote/pasta', name: 'expediente_importar_lote_pasta', methods: ['POST'])]
    public function criarPasta(Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        $nomeCliente = trim((string) $request->request->get('nome_cliente', ''));
        $recusa      = $this->recusa($request, $user, $tenant, $nomeCliente);
        // `$tenant === null` já vem recusado; o teste repetido só estreita o tipo para o UseCase.
        if ($recusa !== null || $tenant === null) {
            return $recusa ?? $this->erro('Você não tem permissão para acessar o módulo de pastas.', Response::HTTP_FORBIDDEN);
        }

        // Sem o aviso de pasta duplicada do modal Nova Pasta: o desenho do lote não tem a etapa e
        // cliente repetido é caso real (D3). O número é sempre o do servidor (D5).
        $pasta = $this->criarPastaUseCase->executar(
            new CriarPastaDTO(nomeCliente: $nomeCliente, vincularAoCadastroDoMesmoNome: true),
            $user,
            $tenant,
        );

        $this->syncDispatcher->despachar($pasta, $user, $tenant);

        return $this->criada($pasta, $user, $tenant);
    }

    /** Por que o pedido não cria pasta, ou null quando pode criar. */
    private function recusa(Request $request, User $user, ?Tenant $tenant, string $nomeCliente): ?JsonResponse
    {
        // Mesma porta do `pasta_new`: o módulo Pastas (D2).
        if ($tenant === null || !$this->permissionChecker->canAccessModule($user, $tenant, 'pastas')) {
            return $this->erro('Você não tem permissão para acessar o módulo de pastas.', Response::HTTP_FORBIDDEN);
        }
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->erro('Sessão expirada. Recarregue a página e tente de novo.', Response::HTTP_FORBIDDEN);
        }
        if ($nomeCliente === '') {
            return $this->erro('Todas as pastas marcadas precisam de um nome de cliente.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // A coluna `nome_cliente` tem 255; nome de subpasta maior viraria erro de banco.
        if (mb_strlen($nomeCliente) > 255) {
            return $this->erro('O nome do cliente passa de 255 caracteres.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return null;
    }

    private function criada(Pasta $pasta, User $user, Tenant $tenant): JsonResponse
    {
        $id = (int) $pasta->getId();
        // O token de upload só vai para quem pode anexar na pasta criada (D2); sem ele a pasta
        // existe e a linha avisa que os arquivos não foram anexados.
        $podeAnexar = $this->permissionChecker->canAccessResource($user, $tenant, 'pasta', $id, 'edit');

        return new JsonResponse([
            'status'      => 'ok',
            'id'          => $id,
            'nup'         => $pasta->getNup(),
            'cliente'     => mb_strtoupper((string) $pasta->getNomeCliente()),
            'uploadUrl'   => $podeAnexar ? $this->generateUrl('pasta_peticionar_upload', ['id' => $id]) : null,
            'uploadToken' => $podeAnexar ? $this->csrfTokenManager->getToken('peticionar_upload_' . $id)->getValue() : null,
        ]);
    }

    private function erro(string $mensagem, int $status): JsonResponse
    {
        return new JsonResponse(['status' => 'erro', 'mensagem' => $mensagem], $status);
    }
}
