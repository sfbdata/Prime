<?php

declare(strict_types=1);

namespace App\Comercial\Controller;

use App\Comercial\DTO\PedidoDeEdicao;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\UseCase\ConsultarEdicaoDaCampanhaUseCase;
use App\Comercial\UseCase\EditarCampanhaUseCase;
use App\Comercial\UseCase\ExcluirCampanhaUseCase;
use App\Entity\Auth\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mais opções de ajuste nas campanhas da Central de envios (pedido do Farlei M5.3, 23/11/2026; decisões do
 * Coordenador): abrir uma campanha para editar, salvar a edição (conteúdo, data e frequência, só antes de começar a
 * sair) e "Excluir campanha" (cancela o envio, se ativo, e esconde do Relatório). Só o Master. JSON; o POST leva o token
 * CSRF `master_comercial` no header `X-CSRF-Token` (o padrão do Comercial) e a recusa do caso de uso volta em 422 com
 * `{erro}`; campanha inexistente é 404.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class EdicaoDaCampanhaDoMasterController extends AbstractController
{
    private const CSRF_INVALIDO = 'Token CSRF inválido. Recarregue a página.';
    private const NAO_ENCONTRADA = 'Campanha não encontrada.';

    /** A cópia congelada e a agenda, para o editor entrar em modo edição. */
    #[Route('/master/comercial/campanhas/{id}/edicao', name: 'master_comercial_campanha_edicao', requirements: ['id' => RelatorioDoMasterController::ID], methods: ['GET'])]
    public function edicao(int $id, ConsultarEdicaoDaCampanhaUseCase $consultar): JsonResponse
    {
        try {
            $edicao = $consultar->executar($id);
        } catch (CampanhaRecusada $e) {
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $edicao === null
            ? new JsonResponse(['erro' => self::NAO_ENCONTRADA], Response::HTTP_NOT_FOUND)
            : new JsonResponse($edicao->paraArray());
    }

    /** "Salvar alterações": `{modelo, agendar, frequencia}`. */
    #[Route('/master/comercial/campanhas/{id}/editar', name: 'master_comercial_campanha_editar', requirements: ['id' => RelatorioDoMasterController::ID], methods: ['POST'])]
    public function editar(int $id, Request $request, EditarCampanhaUseCase $editar): JsonResponse
    {
        return $this->protegido($request, function () use ($id, $request, $editar): JsonResponse {
            $json = json_decode($request->getContent(), true);
            $mensagem = $editar->executar($id, PedidoDeEdicao::deArray(\is_array($json) ? $json : []), $this->master());

            return $mensagem === null
                ? new JsonResponse(['erro' => self::NAO_ENCONTRADA], Response::HTTP_NOT_FOUND)
                : new JsonResponse(['mensagem' => $mensagem]);
        });
    }

    /** "Excluir campanha", depois do `confirm` na tela. */
    #[Route('/master/comercial/campanhas/{id}/excluir', name: 'master_comercial_campanha_excluir', requirements: ['id' => RelatorioDoMasterController::ID], methods: ['POST'])]
    public function excluir(int $id, Request $request, ExcluirCampanhaUseCase $excluir): JsonResponse
    {
        return $this->protegido($request, function () use ($id, $excluir): JsonResponse {
            $mensagem = $excluir->executar($id, $this->master());

            return $mensagem === null
                ? new JsonResponse(['erro' => self::NAO_ENCONTRADA], Response::HTTP_NOT_FOUND)
                : new JsonResponse(['mensagem' => $mensagem]);
        });
    }

    /** @param \Closure(): JsonResponse $trabalho */
    private function protegido(Request $request, \Closure $trabalho): JsonResponse
    {
        if (!$this->isCsrfTokenValid(ComercialDoMasterController::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['erro' => self::CSRF_INVALIDO], Response::HTTP_FORBIDDEN);
        }
        try {
            return $trabalho();
        } catch (CampanhaRecusada $e) {
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function master(): User
    {
        $usuario = $this->getUser();

        return $usuario instanceof User ? $usuario : throw new CampanhaRecusada('Sessão inválida.');
    }
}
