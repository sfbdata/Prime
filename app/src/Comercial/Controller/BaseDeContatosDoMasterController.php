<?php

declare(strict_types=1);

namespace App\Comercial\Controller;

use App\Comercial\DTO\ContatoComercialOutput;
use App\Comercial\Exception\ContatoRecusado;
use App\Comercial\UseCase\EditarNomeDoContatoComercialUseCase;
use App\Comercial\UseCase\ExcluirContatosDoComercialUseCase;
use App\Comercial\UseCase\ExportarContatosDoComercialUseCase;
use App\Comercial\UseCase\ImportarContatosDoComercialUseCase;
use App\Comercial\UseCase\IncluirContatoDoComercialUseCase;
use App\Comercial\UseCase\ListarContatosDoComercialUseCase;
use App\Entity\Auth\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A "Base de contatos" do Comercial no /master. Só o Master. JSON; as escritas pedem o token CSRF `master_comercial`
 * no header `X-CSRF-Token` (o padrão das outras rotas do Comercial) e recusam em 422 com a mensagem.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class BaseDeContatosDoMasterController extends AbstractController
{
    private const CSRF_INVALIDO = 'Token CSRF inválido. Recarregue a página.';

    #[Route('/master/comercial/base-de-contatos', name: 'master_comercial_base_listar', methods: ['GET'])]
    public function listar(Request $request, ListarContatosDoComercialUseCase $listar): JsonResponse
    {
        $lista = $listar->executar(self::busca($request), max(1, $request->query->getInt('pagina', 1)));

        return new JsonResponse([
            'total' => $lista['total'],
            'pagina' => $lista['pagina'],
            'porPagina' => $lista['porPagina'],
            'itens' => array_map(static fn (ContatoComercialOutput $i): array => $i->paraJson(), $lista['itens']),
        ]);
    }

    #[Route('/master/comercial/base-de-contatos', name: 'master_comercial_base_incluir', methods: ['POST'])]
    public function incluir(Request $request, IncluirContatoDoComercialUseCase $incluir): JsonResponse
    {
        return $this->protegido($request, function (array $corpo) use ($incluir): JsonResponse {
            $contato = $incluir->executar(self::texto($corpo, 'email'), self::texto($corpo, 'nome'), $this->master());

            return new JsonResponse(['ok' => true, 'id' => $contato->getId()]);
        });
    }

    #[Route('/master/comercial/base-de-contatos/importar', name: 'master_comercial_base_importar', methods: ['POST'])]
    public function importar(Request $request, ImportarContatosDoComercialUseCase $importar): JsonResponse
    {
        return $this->protegido($request, function (array $corpo) use ($importar): JsonResponse {
            $arquivo = self::texto($corpo, 'arquivo');

            return new JsonResponse($importar->executar(self::texto($corpo, 'csv'), $arquivo !== '' ? $arquivo : null, $this->master())->paraJson());
        });
    }

    #[Route('/master/comercial/base-de-contatos/excluir', name: 'master_comercial_base_excluir', methods: ['POST'])]
    public function excluir(Request $request, ExcluirContatosDoComercialUseCase $excluir): JsonResponse
    {
        return $this->protegido($request, function (array $corpo) use ($excluir): JsonResponse {
            $ids = \is_array($corpo['ids'] ?? null) ? array_values(array_filter($corpo['ids'], '\is_int')) : [];

            return new JsonResponse(['excluidos' => $excluir->executar($ids, $this->master())]);
        });
    }

    #[Route('/master/comercial/base-de-contatos/{id}', name: 'master_comercial_base_editar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function editar(int $id, Request $request, EditarNomeDoContatoComercialUseCase $editar): JsonResponse
    {
        return $this->protegido($request, function (array $corpo) use ($id, $editar): JsonResponse {
            $editar->executar($id, self::texto($corpo, 'nome'), $this->master());

            return new JsonResponse(['ok' => true]);
        });
    }

    #[Route('/master/comercial/base-de-contatos.csv', name: 'master_comercial_base_csv', methods: ['GET'])]
    public function csv(Request $request, ExportarContatosDoComercialUseCase $exportar): Response
    {
        return new Response($exportar->executar(self::busca($request), $this->master()), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . ExportarContatosDoComercialUseCase::ARQUIVO . '"',
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
            return $trabalho(\is_array($corpo) ? $corpo : throw new ContatoRecusado('Pedido inválido.'));
        } catch (ContatoRecusado $e) {
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function master(): User
    {
        $usuario = $this->getUser();

        return $usuario instanceof User ? $usuario : throw new ContatoRecusado('Sessão inválida.');
    }

    private static function busca(Request $request): ?string
    {
        $busca = trim((string) $request->query->get('q', ''));

        return $busca !== '' ? $busca : null;
    }

    /** @param array<mixed> $corpo */
    private static function texto(array $corpo, string $chave): string
    {
        return \is_string($corpo[$chave] ?? null) ? $corpo[$chave] : '';
    }
}
