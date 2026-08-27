<?php

declare(strict_types=1);

namespace App\Expediente\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\DTO\CriarMarcadorDTO;
use App\Expediente\Repository\MarcadorRepository;
use App\Expediente\UseCase\CriarMarcadorUseCase;
use App\Expediente\UseCase\EditarMarcadorUseCase;
use App\Expediente\UseCase\ExcluirMarcadorUseCase;
use App\Expediente\UseCase\SincronizarMarcadoresDaPastaUseCase;
use App\Pasta\Repository\PastaRepository;
use App\Repository\UserRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ExpedienteController extends AbstractController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly PastaRepository $pastaRepository,
        private readonly UserRepository $userRepository,
        private readonly MarcadorRepository $marcadorRepository,
        private readonly CriarMarcadorUseCase $criarMarcadorUseCase,
        private readonly ExcluirMarcadorUseCase $excluirMarcadorUseCase,
        private readonly EditarMarcadorUseCase $editarMarcadorUseCase,
        private readonly SincronizarMarcadoresDaPastaUseCase $sincronizarMarcadoresUseCase,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ValidatorInterface $validator,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/expediente', name: 'expediente_index')]
    public function index(): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        $marcadores      = $this->marcadorRepository->findRaizPorTenant($tenant);
        $todosMarcadores = $this->marcadorRepository->findTodosPorTenant($tenant);
        $contagemPastas  = $this->pastaRepository->countPorMarcadores($tenant);

        return $this->render('expediente/index.html.twig', [
            'pastas'          => $marcadores,
            'todosMarcadores' => $todosMarcadores,
            'contagemPastas'  => $contagemPastas,
        ]);
    }

    #[Route('/expediente/marcador', name: 'expediente_marcador_criar', methods: ['POST'])]
    public function criarMarcador(Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        if (!$this->isCsrfTokenValid('marcador', $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        $cor = $request->request->get('cor');

        $dto = new CriarMarcadorDTO(
            nome:  trim((string) $request->request->get('nome', '')),
            paiId: $request->request->get('pai_id') !== null && $request->request->get('pai_id') !== ''
                ? (int) $request->request->get('pai_id')
                : null,
            cor:   ($cor === '' || $cor === null) ? null : (string) $cor,
        );

        $erros = $this->validator->validate($dto);
        if (count($erros) > 0) {
            return $this->json(['erro' => (string) $erros->get(0)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $marcador = $this->criarMarcadorUseCase->executar($dto, $user, $tenant);

        return $this->json([
            'id'          => $marcador->getId(),
            'nome'        => $marcador->getNome(),
            'cor'         => $marcador->getCor(),
            'paiId'       => $marcador->getPai()?->getId(),
            'csrfExcluir' => $this->csrfTokenManager->getToken('excluir_marcador_' . $marcador->getId())->getValue(),
            'csrfEditar'  => $this->csrfTokenManager->getToken('editar_marcador_' . $marcador->getId())->getValue(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/expediente/marcador/{id}', name: 'expediente_marcador_editar', methods: ['PATCH'])]
    public function editarMarcador(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        if (!$this->isCsrfTokenValid('editar_marcador_' . $id, $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        $nome = trim((string) $request->request->get('nome', ''));
        if ($nome === '' || mb_strlen($nome) > 100) {
            return $this->json(['erro' => 'Nome inválido.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $cor = $request->request->get('cor');
        $cor = ($cor === '' || $cor === null) ? null : (string) $cor;

        try {
            $marcador = $this->editarMarcadorUseCase->executar($id, $nome, $cor, $tenant);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'id'   => $marcador->getId(),
            'nome' => $marcador->getNome(),
            'cor'  => $marcador->getCor(),
        ]);
    }

    #[Route('/expediente/marcador/{id}', name: 'expediente_marcador_excluir', methods: ['DELETE'])]
    public function excluirMarcador(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        if (!$this->isCsrfTokenValid('excluir_marcador_' . $id, $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $marcador = $this->excluirMarcadorUseCase->executar($id, $tenant);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id'    => $marcador->getId(),
            'paiId' => $marcador->getPai()?->getId(),
        ]);
    }

    #[Route('/expediente/marcador/{id}/pastas', name: 'expediente_marcador_pastas', methods: ['GET'])]
    public function pastasPorMarcador(int $id, Request $request): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('expediente_index');
        }

        $marcador = $this->marcadorRepository->findPorTenant($id, $tenant);

        if ($marcador === null) {
            return $this->json(['erro' => 'Marcador não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $filters             = $this->filtrosDaRequest($request);
        $hasFilters          = array_filter($filters, fn($v) => $v !== '');
        [$ordenar, $direcao] = $this->ordenacaoDaRequest($request);

        $page = max(1, $request->query->getInt('page', 1));

        $totalItems = $hasFilters
            ? $this->pastaRepository->countByFiltrosEMarcador($filters, $marcador, $tenant)
            : $this->pastaRepository->countPorMarcador($marcador, $tenant);

        $totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
        $page       = min($page, $totalPages);

        $pastas = $hasFilters
            ? $this->pastaRepository->findByFiltrosEMarcador($filters, $marcador, $tenant, $page, self::PER_PAGE, $ordenar, $direcao)
            : $this->pastaRepository->findPorMarcador($marcador, $tenant, $page, self::PER_PAGE, $ordenar, $direcao);

        $urlPainel = $this->generateUrl('expediente_marcador_pastas', ['id' => $id]);

        return $this->render('expediente/_painel_marcador.html.twig', [
            'marcador'     => $marcador,
            'pastas'       => $pastas,
            'filters'      => $filters,
            'ordenar'      => $ordenar,
            'direcao'      => $direcao,
            'responsaveis' => $this->userRepository->findColaboradoresAtivosPorTenant($tenant),
            'fotosResponsaveis' => $this->userRepository->findFotoPorColaboradores($tenant),
            'formAction'   => $urlPainel,
            'pagination'   => [
                'current_page' => $page,
                'per_page'     => self::PER_PAGE,
                'total_items'  => $totalItems,
                'total_pages'  => $totalPages,
            ],
        ]);
    }

    #[Route('/expediente/pasta/{id}/marcadores', name: 'expediente_pasta_marcadores', methods: ['POST'])]
    public function atualizarMarcadoresDaPasta(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->assertAccess($user);

        if (!$this->isCsrfTokenValid('pasta_marcadores_' . $id, $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        $marcadorIds = $request->request->all('marcadores') ?? [];
        $marcadorIds = array_map('intval', array_filter($marcadorIds, fn($v) => $v !== '' && $v !== null));

        try {
            $pasta = $this->sincronizarMarcadoresUseCase->executar($id, $marcadorIds, $tenant);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->json(['erro' => $e->getMessage(), 'trace' => $e->getTraceAsString()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'pastaId'    => $pasta->getId(),
            // array_values é obrigatório: removeMarcador() deixa lacunas nos índices da coleção
            // e toArray()/array_map() preservam essas chaves, fazendo json_encode() serializar
            // um OBJETO ({"2":{…}}) em vez de uma lista. O JS consumidor faz marcadores.map(),
            // que estoura TypeError nesse formato — a gravação já ocorreu, mas a tela acusa
            // "Erro de comunicação." e o modal não fecha.
            'marcadores' => array_values(array_map(
                fn($m) => ['id' => $m->getId(), 'nome' => $m->getNome(), 'cor' => $m->getCor()],
                $pasta->getMarcadores()->toArray()
            )),
        ]);
    }

    #[Route('/expediente/painel/acervo-geral', name: 'expediente_acervo_geral')]
    public function acervoGeral(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->assertAccess($user);

        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('expediente_index');
        }

        $filters             = $this->filtrosDaRequest($request);
        [$ordenar, $direcao] = $this->ordenacaoDaRequest($request);

        $page = max(1, $request->query->getInt('page', 1));

        $totalItems = $this->pastaRepository->countByFilters($filters, $tenant);
        $totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
        $page       = min($page, $totalPages);

        return $this->render('expediente/_acervo_geral.html.twig', [
            'pastas'       => $this->pastaRepository->findByFilters($filters, $tenant, $page, self::PER_PAGE, $ordenar, $direcao),
            'filters'      => $filters,
            'ordenar'      => $ordenar,
            'direcao'      => $direcao,
            'responsaveis' => $this->userRepository->findColaboradoresAtivosPorTenant($tenant),
            'fotosResponsaveis' => $this->userRepository->findFotoPorColaboradores($tenant),
            'formAction'   => $this->generateUrl('expediente_acervo_geral'),
            'pagination'   => [
                'current_page' => $page,
                'per_page'     => self::PER_PAGE,
                'total_items'  => $totalItems,
                'total_pages'  => $totalPages,
            ],
        ]);
    }

    /**
     * Extrai os filtros da listagem de pastas a partir da query string.
     *
     * @return array<string, string>
     */
    private function filtrosDaRequest(Request $request): array
    {
        return [
            'busca'       => trim((string) $request->query->get('busca', '')),
            'status'      => $request->query->get('status', ''),
            'responsavel' => $request->query->get('responsavel', ''),
            'prioridade'  => $request->query->get('prioridade', ''),
            'data_de'     => $request->query->get('data_de', ''),
            'data_ate'    => $request->query->get('data_ate', ''),
        ];
    }

    /**
     * Coluna e direção de ordenação vindas do cabeçalho da tabela. A coluna passa por
     * uma allowlist (nomes que o repositório sabe ordenar); direção só asc/desc.
     *
     * @return array{0: string, 1: string}
     */
    private function ordenacaoDaRequest(Request $request): array
    {
        $colunas = ['nup', 'cliente', 'acao', 'prioridade', 'responsavel', 'marcadores', 'situacao'];
        $ordenar = (string) $request->query->get('ordenar', '');
        $ordenar = in_array($ordenar, $colunas, true) ? $ordenar : '';
        $direcao = strtolower((string) $request->query->get('direcao', 'desc')) === 'asc' ? 'asc' : 'desc';

        return [$ordenar, $direcao];
    }

    private function assertAccess(User $user): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || !$this->permissionChecker->canAccessModule($user, $tenant, 'expediente')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Expediente.');
        }

        return $tenant;
    }
}
