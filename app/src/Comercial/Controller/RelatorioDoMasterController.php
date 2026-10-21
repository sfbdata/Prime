<?php

declare(strict_types=1);

namespace App\Comercial\Controller;

use App\Comercial\DTO\PedidoDoRelatorio;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\UseCase\AbrirCampanhaDoRelatorioUseCase;
use App\Comercial\UseCase\EncerrarCampanhaUseCase;
use App\Comercial\UseCase\ListarRelatorioDeCampanhasUseCase;
use App\Entity\Auth\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * O "Relatório como caixa de entrada" da Central de envios do /master (desenho 05 VS 21.10.26, L300-305 e L502-514;
 * spec `comercial-relatorio.md`): a lista com as abas e a busca, o visualizador de uma campanha e o "Cancelar envio".
 * Só o Master. JSON; o POST leva o token CSRF `master_comercial` no header `X-CSRF-Token` (o padrão do Comercial) e a
 * recusa do caso de uso volta em 422 com `{erro}`.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class RelatorioDoMasterController extends AbstractController
{
    private const CSRF_INVALIDO = 'Token CSRF inválido. Recarregue a página.';
    private const NAO_ENCONTRADA = 'Campanha não encontrada.';

    #[Route('/master/comercial/relatorio', name: 'master_comercial_relatorio', methods: ['GET'])]
    public function listar(Request $request, ListarRelatorioDeCampanhasUseCase $listar): JsonResponse
    {
        return new JsonResponse($listar->executar(PedidoDoRelatorio::deQuery($request->query->all()))->paraArray());
    }

    /**
     * O visualizador. O HTML vai num iframe `sandbox=""` (srcdoc) na tela; a resposta leva também
     * `Content-Security-Policy: sandbox`, para o caso de alguém abri-la direto.
     */
    #[Route('/master/comercial/campanhas/{id}', name: 'master_comercial_campanha', requirements: ['id' => '[1-9]\d{0,9}'], methods: ['GET'])]
    public function abrir(int $id, AbrirCampanhaDoRelatorioUseCase $abrir): JsonResponse
    {
        $campanha = $abrir->executar($id);
        $resposta = $campanha === null
            ? new JsonResponse(['erro' => self::NAO_ENCONTRADA], Response::HTTP_NOT_FOUND)
            : new JsonResponse($campanha->paraArray());
        $resposta->headers->set('Content-Security-Policy', 'sandbox');

        return $resposta;
    }

    /** "Cancelar envio" (dc L503), depois do `confirm` na tela; só onde o EncerrarCampanhaUseCase aceita. */
    #[Route('/master/comercial/campanhas/{id}/cancelar', name: 'master_comercial_campanha_cancelar', requirements: ['id' => '[1-9]\d{0,9}'], methods: ['POST'])]
    public function cancelar(int $id, Request $request, EncerrarCampanhaUseCase $encerrar): JsonResponse
    {
        if (!$this->isCsrfTokenValid(ComercialDoMasterController::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['erro' => self::CSRF_INVALIDO], Response::HTTP_FORBIDDEN);
        }
        $master = $this->getUser();
        try {
            $mensagem = $encerrar->executar(
                $id,
                EncerrarCampanhaUseCase::MOTIVO_DO_MASTER,
                $master instanceof User ? $master->getId() : null,
                $master instanceof User ? $master->getEmail() : null,
            );
        } catch (CampanhaRecusada $e) {
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['mensagem' => $mensagem]);
    }
}
