<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\DTO\FiltroDosModelosInput;
use App\Pasta\DTO\ModeloDePecaInput;
use App\Pasta\DTO\ModeloDePecaOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Enum\BlocoDeMateria;
use App\Pasta\Enum\MateriaDeModelo;
use App\Pasta\Exception\ModeloDePecaInvalidoException;
use App\Pasta\Repository\ModeloDePecaRepository;
use App\Pasta\UseCase\AlternarFavoritoDeModeloUseCase;
use App\Pasta\UseCase\AlternarMateriaFavoritaUseCase;
use App\Pasta\UseCase\ExcluirModeloDePecaUseCase;
use App\Pasta\UseCase\ListarModelosDePecaUseCase;
use App\Pasta\UseCase\SalvarModeloDePecaUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Banco de modelos de petições da aba Modelos da pasta (desenho 02 de 27/10, fatia M1).
 *
 * O modelo é do ESCRITÓRIO e vale em todas as pastas, mas as rotas moram sob `/pasta/{id}` como as
 * do {@see PastaChecklistModeloController}: a pasta aberta dá o contexto de permissão. Ler e favoritar
 * (preferência pessoal, só de quem marca) exigem ver a pasta; criar, editar e excluir exigem poder
 * editá-la. Um único token CSRF por pasta (`modelos_pasta_{id}`) cobre as escritas.
 */
#[Route('/pasta')]
final class PastaModelosController extends AbstractController
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly ModeloDePecaRepository $modeloRepository,
        private readonly ListarModelosDePecaUseCase $listarUseCase,
        private readonly SalvarModeloDePecaUseCase $salvarUseCase,
        private readonly ExcluirModeloDePecaUseCase $excluirUseCase,
        private readonly AlternarFavoritoDeModeloUseCase $favoritoUseCase,
        private readonly AlternarMateriaFavoritaUseCase $materiaFavoritaUseCase,
    ) {
    }

    /** Coluna esquerda e linhas da lista, já em HTML, para o JS trocar no lugar. */
    #[Route('/{id}/modelos', name: 'pasta_modelos_listar', methods: ['GET'])]
    public function listar(Pasta $pasta, Request $request): JsonResponse
    {
        if (($negado = $this->negar($pasta, 'view')) !== null) {
            return $negado;
        }

        $filtro = FiltroDosModelosInput::daRequisicao(
            $request->query->get('materia'),
            $request->query->get('tipo'),
            $request->query->get('q'),
            $request->query->get('ord'),
        );
        $dados = $this->listarUseCase->executar($this->tenantContext->getCurrentTenant(), $this->usuario(), $filtro);

        return $this->json([
            'coluna' => $this->renderView('pasta/modelos/_coluna.html.twig', $this->contextoDaColuna($dados, $filtro)),
            'lista'  => $this->renderView('pasta/modelos/_lista.html.twig', ['linhas' => $dados['linhas']]),
            'total'    => $dados['total'],
            'contador' => trim($this->renderView('pasta/modelos/_contador.html.twig', ['total' => $dados['total']])),
        ]);
    }

    #[Route('/{id}/modelos/{modeloId}', name: 'pasta_modelos_abrir', requirements: ['modeloId' => '\d+'], methods: ['GET'])]
    public function abrir(Pasta $pasta, int $modeloId): JsonResponse
    {
        if (($negado = $this->negar($pasta, 'view')) !== null) {
            return $negado;
        }

        $modelo = $this->modeloRepository->buscarDoTenant($modeloId, $this->tenantContext->getCurrentTenant());
        if ($modelo === null) {
            return $this->json(['erro' => 'Modelo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['modelo' => ModeloDePecaOutput::fromEntity($modelo)->toArray()]);
    }

    #[Route('/{id}/modelos', name: 'pasta_modelos_criar', methods: ['POST'])]
    public function criar(Pasta $pasta, Request $request): JsonResponse
    {
        return $this->gravar($pasta, $request, null);
    }

    #[Route('/{id}/modelos/{modeloId}', name: 'pasta_modelos_editar', requirements: ['modeloId' => '\d+'], methods: ['POST'])]
    public function editar(Pasta $pasta, int $modeloId, Request $request): JsonResponse
    {
        return $this->gravar($pasta, $request, $modeloId);
    }

    #[Route('/{id}/modelos/{modeloId}/excluir', name: 'pasta_modelos_excluir', requirements: ['modeloId' => '\d+'], methods: ['POST'])]
    public function excluir(Pasta $pasta, int $modeloId, Request $request): JsonResponse
    {
        if (($negado = $this->negar($pasta, 'edit', $request)) !== null) {
            return $negado;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $modelo = $this->modeloRepository->buscarDoTenant($modeloId, $tenant);
        if ($modelo === null) {
            return $this->json(['erro' => 'Modelo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $this->excluirUseCase->executar($modelo, $tenant);

        return $this->json(['sucesso' => true]);
    }

    #[Route('/{id}/modelos/{modeloId}/favorito', name: 'pasta_modelos_favoritar', requirements: ['modeloId' => '\d+'], methods: ['POST'])]
    public function favoritar(Pasta $pasta, int $modeloId, Request $request): JsonResponse
    {
        if (($negado = $this->negar($pasta, 'view', $request)) !== null) {
            return $negado;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $modelo = $this->modeloRepository->buscarDoTenant($modeloId, $tenant);
        if ($modelo === null) {
            return $this->json(['erro' => 'Modelo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['favorito' => $this->favoritoUseCase->executar($modelo, $this->usuario(), $tenant)]);
    }

    #[Route('/{id}/modelos/materias/{materia}/favorita', name: 'pasta_modelos_materia_favoritar', methods: ['POST'])]
    public function favoritarMateria(Pasta $pasta, string $materia, Request $request): JsonResponse
    {
        if (($negado = $this->negar($pasta, 'view', $request)) !== null) {
            return $negado;
        }

        $enum = MateriaDeModelo::tryFrom($materia);
        if ($enum === null) {
            return $this->json(['erro' => 'Matéria não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['favorita' => $this->materiaFavoritaUseCase->executar($enum, $this->usuario(), $this->tenantContext->getCurrentTenant())]);
    }

    private function gravar(Pasta $pasta, Request $request, ?int $modeloId): JsonResponse
    {
        if (($negado = $this->negar($pasta, 'edit', $request)) !== null) {
            return $negado;
        }

        $tenant     = $this->tenantContext->getCurrentTenant();
        $existente  = $modeloId === null ? null : $this->modeloRepository->buscarDoTenant($modeloId, $tenant);
        if ($modeloId !== null && $existente === null) {
            return $this->json(['erro' => 'Modelo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $input = new ModeloDePecaInput(
            (string) $request->request->get('titulo', ''),
            (string) $request->request->get('materia', ''),
            (string) $request->request->get('tipo', ''),
            (string) $request->request->get('corpo', ''),
        );

        try {
            $modelo = $this->salvarUseCase->executar($input, $tenant, $this->usuario(), $existente);
        } catch (ModeloDePecaInvalidoException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AccessDeniedException) {
            return $this->json(['erro' => 'Modelo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['modelo' => ModeloDePecaOutput::fromEntity($modelo)->toArray()], $existente === null ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * @param array{total: int, totalFavoritos: int, porMateria: array<string, int>, porBloco: array<string, int>, materiasFavoritas: list<MateriaDeModelo>} $dados
     *
     * @return array<string, mixed>
     */
    private function contextoDaColuna(array $dados, FiltroDosModelosInput $filtro): array
    {
        $ativa = $filtro->soFavoritos ? '★' : ($filtro->materia->value ?? '');

        return [
            'ativa'             => $ativa,
            'total'             => $dados['total'],
            'totalFavoritos'    => $dados['totalFavoritos'],
            'porMateria'        => $dados['porMateria'],
            'porBloco'          => $dados['porBloco'],
            'blocos'            => BlocoDeMateria::cases(),
            'materiasFavoritas' => $dados['materiasFavoritas'],
            'favoritasValores'  => array_map(static fn (MateriaDeModelo $m): string => $m->value, $dados['materiasFavoritas']),
        ];
    }

    /** Escritório → permissão (ver ou editar a pasta) → CSRF nas escritas. Devolve a resposta de recusa, ou null. */
    private function negar(Pasta $pasta, string $acao, ?Request $request = null): ?JsonResponse
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            return $this->json(['erro' => 'Escritório não identificado.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->permissionChecker->canAccessResource($this->usuario(), $tenant, 'pasta', (int) $pasta->getId(), $acao)) {
            return $this->json(['erro' => 'Sem permissão para esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        if ($request !== null && !$this->isCsrfTokenValid('modelos_pasta_' . $pasta->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function usuario(): User
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        return $usuario;
    }
}
