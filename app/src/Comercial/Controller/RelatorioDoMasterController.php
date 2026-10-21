<?php

declare(strict_types=1);

namespace App\Comercial\Controller;

use App\Comercial\DTO\PedidoDeMarcas;
use App\Comercial\DTO\PedidoDoRelatorio;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\UseCase\AbrirCampanhaDoRelatorioUseCase;
use App\Comercial\UseCase\EncerrarCampanhaUseCase;
use App\Comercial\UseCase\ExportarDestinatariosUseCase;
use App\Comercial\UseCase\ListarRelatorioDeCampanhasUseCase;
use App\Comercial\UseCase\MarcarCampanhasDoRelatorioUseCase;
use App\Entity\Auth\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * O "Relatório como caixa de entrada" da Central de envios do /master (desenho 05 VS 21.10.26, L300-305 e L502-514;
 * spec `comercial-relatorio.md`): a lista com as abas e a busca, o visualizador de uma campanha e o "Cancelar envio".
 * Da F3e (spec `comercial-marcas.md`): as marcas de cada Master (estrela, arquivo, remoção) e o "Exportar
 * destinatários" (CSV). Só o Master. JSON; o POST leva o token CSRF `master_comercial` no header `X-CSRF-Token` (o padrão do Comercial) e a
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
        return new JsonResponse($listar->executar(PedidoDoRelatorio::deQuery($request->query->all()), $this->masterId())->paraArray());
    }

    /** As marcas (dc L301, L304 e L704): `{ids, acao}`; a recusa (ação inválida, nenhuma ou mais de 50) em 422. */
    #[Route('/master/comercial/relatorio/marcas', name: 'master_comercial_relatorio_marcas', methods: ['POST'])]
    public function marcar(Request $request, MarcarCampanhasDoRelatorioUseCase $marcar): JsonResponse
    {
        if (!$this->isCsrfTokenValid(ComercialDoMasterController::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['erro' => self::CSRF_INVALIDO], Response::HTTP_FORBIDDEN);
        }
        $json = json_decode($request->getContent(), true);
        try {
            $marcadas = $marcar->executar(PedidoDeMarcas::deJson(\is_array($json) ? $json : []), $this->masterId());
        } catch (CampanhaRecusada $e) {
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['marcadas' => $marcadas]);
    }

    /** "Exportar destinatários" (dc L503, L704, L708): `formato=completo` (o padrão) ou `emails`. */
    #[Route('/master/comercial/campanhas/{id}/destinatarios.csv', name: 'master_comercial_campanha_destinatarios', requirements: ['id' => '[1-9]\d{0,9}'], methods: ['GET'])]
    public function exportar(int $id, Request $request, ExportarDestinatariosUseCase $exportar): Response
    {
        $master = $this->getUser();
        $csv = $exportar->executar($id, (string) $request->query->get('formato', ''), $this->masterId(), $master instanceof User ? $master->getEmail() : null);
        if ($csv === null) {
            return new JsonResponse(['erro' => self::NAO_ENCONTRADA], Response::HTTP_NOT_FOUND);
        }

        return new Response($csv->conteudo, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $csv->nomeDoArquivo),
            'Cache-Control' => 'private, no-store',
        ]);
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

    private function masterId(): int
    {
        $master = $this->getUser();

        return $master instanceof User ? (int) $master->getId() : 0;
    }
}
