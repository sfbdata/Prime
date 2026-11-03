<?php

declare(strict_types=1);

namespace App\Expediente\Controller;

use App\Entity\Auth\User;
use App\Pasta\Controller\PastaDocumentoController;
use App\Pasta\DTO\ExploradorDeDocumentosOutput;
use App\Pasta\DTO\GrupoDaLixeiraDoEscritorio;
use App\Pasta\UseCase\ListarLixeiraDoEscritorioUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * A lixeira do escritório no Expediente (pedido do Samuel, 03/11/2026; sem desenho): o botão
 * "Lixeira" da tela abre uma janela que lê esta rota. JSON, só leitura.
 *
 * Ler: módulo Expediente (403 sem ele, como a própria tela). Quais pastas aparecem é decidido no
 * UseCase (EDITAR a pasta, aba Documentos visível). Cada grupo leva as URLs e o token de lote
 * (`pex_lote_<id>`) das rotas que JÁ existem na lixeira da pasta: a janela restaura e exclui de vez
 * por elas, com as mesmas guardas (nada de regra nova aqui). Pasta excluída (lápide) só se olha:
 * vai sem as URLs de escrita, como a lixeira dela na própria pasta.
 *
 * `?apos=<n>` continua a lista de onde a página anterior parou (`proximo`).
 */
final class LixeiraDoEscritorioController extends AbstractController
{
    public function __construct(
        private readonly ListarLixeiraDoEscritorioUseCase $listarLixeira,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/expediente/lixeira', name: 'expediente_lixeira', methods: ['GET'])]
    public function listar(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || !$this->permissionChecker->canAccessModule($usuario, $tenant, 'expediente')) {
            return $this->json(['erro' => 'Sem acesso ao módulo Expediente.'], Response::HTTP_FORBIDDEN);
        }

        $apos  = $request->query->get('apos');
        $saida = $this->listarLixeira->executar($usuario, $tenant, is_string($apos) && ctype_digit($apos) ? (int) $apos : 0);

        return $this->json([
            'ok'          => true,
            'grupos'      => array_map(fn (GrupoDaLixeiraDoEscritorio $g): array => $g->paraJson() + $this->acoesDoGrupo($g), $saida->grupos),
            'proximo'     => $saida->proximo,
            'tetoPorLote' => PastaDocumentoController::TETO_DE_ITENS_POR_LOTE,
        ]);
    }

    /** @return array<string, string> */
    private function acoesDoGrupo(GrupoDaLixeiraDoEscritorio $grupo): array
    {
        $id    = ['id' => $grupo->pastaId];
        $acoes = [
            'urlLixeira' => $this->generateUrl('pasta_documentos_lixeira', $id),
            'urlPasta'   => $this->generateUrl('pasta_show', $id + ['pex_lixeira' => 1, '_fragment' => 'documentos']),
        ];
        if ($grupo->pastaExcluida) {
            return $acoes;
        }

        return $acoes + [
            'urlRestaurar'    => $this->generateUrl('pasta_documentos_restaurar', $id),
            'urlExcluirDeVez' => $this->generateUrl('pasta_documentos_excluir_de_vez', $id),
            'csrfLote'        => $this->csrfTokenManager->getToken(ExploradorDeDocumentosOutput::idDoTokenDeLote($grupo->pastaId))->getValue(),
        ];
    }
}
