<?php

declare(strict_types=1);

namespace App\Comercial\Controller;

use App\Comercial\DTO\DescadastradoDoComercialOutput;
use App\Comercial\Exception\DescadastroRecusado;
use App\Comercial\UseCase\ExportarDescadastradosDoComercialUseCase;
use App\Comercial\UseCase\ListarDescadastradosDoComercialUseCase;
use App\Comercial\UseCase\ReativarDescadastradoDoComercialUseCase;
use App\Comercial\UseCase\RegistrarDescadastroDoComercialUseCase;
use App\Comercial\UseCase\ResumirComercialUseCase;
use App\Entity\Auth\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * KPIs e Descadastrados da seção Comercial do /master (spec comercial-e-emails §10, itens 9 e 10; desenho 05 VS
 * 21.10.26, L245-249, L325, L690-697). Só o Master. JSON; as escritas pedem o token CSRF `master_comercial` no header
 * `X-CSRF-Token` (o padrão das outras rotas do Comercial) e recusam em 422 com a mensagem.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class DescadastradosDoMasterController extends AbstractController
{
    private const CSRF_INVALIDO = 'Token CSRF inválido. Recarregue a página.';

    #[Route('/master/comercial/resumo', name: 'master_comercial_resumo', methods: ['GET'])]
    public function resumo(ResumirComercialUseCase $resumir): JsonResponse
    {
        return new JsonResponse($resumir->executar()->paraJson());
    }

    #[Route('/master/comercial/descadastrados', name: 'master_comercial_descadastrados', methods: ['GET'])]
    public function listar(Request $request, ListarDescadastradosDoComercialUseCase $listar): JsonResponse
    {
        $lista = $listar->executar(self::busca($request), max(1, $request->query->getInt('pagina', 1)));

        return new JsonResponse([
            'total' => $lista['total'],
            'pagina' => $lista['pagina'],
            'porPagina' => $lista['porPagina'],
            'itens' => array_map(static fn (DescadastradoDoComercialOutput $i): array => $i->paraJson(), $lista['itens']),
        ]);
    }

    #[Route('/master/comercial/descadastrados', name: 'master_comercial_descadastrados_registrar', methods: ['POST'])]
    public function registrar(Request $request, RegistrarDescadastroDoComercialUseCase $registrar): JsonResponse
    {
        return $this->protegido($request, function (array $corpo) use ($registrar): JsonResponse {
            $registrar->executar(self::texto($corpo, 'email'), $this->master());

            return new JsonResponse(['ok' => true]);
        });
    }

    #[Route('/master/comercial/descadastrados/reativar', name: 'master_comercial_descadastrados_reativar', methods: ['POST'])]
    public function reativar(Request $request, ReativarDescadastradoDoComercialUseCase $reativar): JsonResponse
    {
        return $this->protegido($request, function (array $corpo) use ($reativar): JsonResponse {
            $reativar->executar(self::texto($corpo, 'email'), self::texto($corpo, 'assunto'), $this->master());

            return new JsonResponse(['ok' => true]);
        });
    }

    #[Route('/master/comercial/descadastrados.csv', name: 'master_comercial_descadastrados_csv', methods: ['GET'])]
    public function csv(Request $request, ExportarDescadastradosDoComercialUseCase $exportar): Response
    {
        return new Response($exportar->executar(self::busca($request)), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . ExportarDescadastradosDoComercialUseCase::ARQUIVO . '"',
        ]);
    }

    /** @param \Closure(array<mixed>): JsonResponse $trabalho */
    private function protegido(Request $request, \Closure $trabalho): JsonResponse
    {
        if (!$this->isCsrfTokenValid(ComercialDoMasterController::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['erro' => self::CSRF_INVALIDO], Response::HTTP_FORBIDDEN);
        }
        $corpo = json_decode($request->getContent(), true);
        try {
            return $trabalho(\is_array($corpo) ? $corpo : throw new DescadastroRecusado('Pedido inválido.'));
        } catch (DescadastroRecusado $e) {
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function master(): User
    {
        $usuario = $this->getUser();

        return $usuario instanceof User ? $usuario : throw new DescadastroRecusado('Sessão inválida.');
    }

    private static function busca(Request $request): ?string
    {
        $busca = trim((string) $request->query->get('q', ''));

        return $busca !== '' ? mb_substr($busca, 0, 120) : null;
    }

    /** @param array<mixed> $corpo */
    private static function texto(array $corpo, string $chave): string
    {
        return \is_string($corpo[$chave] ?? null) ? $corpo[$chave] : '';
    }
}
