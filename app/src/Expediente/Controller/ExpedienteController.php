<?php

namespace App\Expediente\Controller;

use App\Expediente\DTO\CriarMarcadorDTO;
use App\Expediente\Repository\MarcadorRepository;
use App\Expediente\UseCase\CriarMarcadorUseCase;
use App\Expediente\UseCase\ExcluirMarcadorUseCase;
use App\Expediente\UseCase\EditarMarcadorUseCase;
use App\Expediente\UseCase\SincronizarMarcadoresDaPastaUseCase;
use App\Repository\PastaRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ExpedienteController extends AbstractController
{
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
    ) {}

    #[Route('/expediente', name: 'expediente_index')]
    public function index(): Response
    {
        $usuario   = $this->getUser();
        $tenant    = $usuario->getTenant();
        $marcadores = $this->marcadorRepository->findRaizPorTenant($tenant);
        $todosMarcadores = $this->marcadorRepository->findTodosPorTenant($tenant);

        return $this->render('expediente/index.html.twig', [
            'pastas'          => $marcadores,
            'todosMarcadores' => $todosMarcadores,
        ]);
    }

    #[Route('/expediente/marcador', name: 'expediente_marcador_criar', methods: ['POST'])]
    public function criarMarcador(Request $request): JsonResponse
    {
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

        $marcador = $this->criarMarcadorUseCase->executar($dto, $this->getUser());

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
            $marcador = $this->editarMarcadorUseCase->executar($id, $nome, $cor, $this->getUser());
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
        if (!$this->isCsrfTokenValid('excluir_marcador_' . $id, $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $marcador = $this->excluirMarcadorUseCase->executar($id, $this->getUser());
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id'    => $marcador->getId(),
            'paiId' => $marcador->getPai()?->getId(),
        ]);
    }

    /**
     * Retorna o painel de pastas de um marcador (AJAX).
     */
    #[Route('/expediente/marcador/{id}/pastas', name: 'expediente_marcador_pastas', methods: ['GET'])]
    public function pastasPorMarcador(int $id, Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('expediente_index');
        }

        $usuario  = $this->getUser();
        $marcador = $this->marcadorRepository->findPorTenant($id, $usuario->getTenant());

        if ($marcador === null) {
            return $this->json(['erro' => 'Marcador não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $filters = [
            'nup'               => $request->query->get('nup', ''),
            'status'            => $request->query->get('status', ''),
            'responsavel'       => $request->query->get('responsavel', ''),
            'status_documentos' => $request->query->get('status_documentos', ''),
        ];
        $hasFilters = array_filter($filters, fn($v) => $v !== '');
        $pastas = $hasFilters
            ? $this->pastaRepository->findByFiltrosEMarcador($filters, $marcador)
            : $this->pastaRepository->findPorMarcador($marcador);

        $urlPainel = $this->generateUrl('expediente_marcador_pastas', ['id' => $id]);

        return $this->render('expediente/_painel_marcador.html.twig', [
            'marcador'    => $marcador,
            'pastas'      => $pastas,
            'filters'     => $filters,
            'nups'        => $this->pastaRepository->findAllNups(),
            'responsaveis' => $this->userRepository->findBy(['isActive' => true], ['fullName' => 'ASC']),
            'formAction'  => $urlPainel,
            'limparUrl'   => $urlPainel,
        ]);
    }

    /**
     * Atualiza os marcadores de uma pasta (multi-select, AJAX).
     * Body: marcadores[] = id1, id2, … (pode ser vazio para remover todos)
     */
    #[Route('/expediente/pasta/{id}/marcadores', name: 'expediente_pasta_marcadores', methods: ['POST'])]
    public function atualizarMarcadoresDaPasta(int $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('pasta_marcadores_' . $id, $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        $marcadorIds = $request->request->all('marcadores') ?? [];
        $marcadorIds = array_map('intval', array_filter($marcadorIds, fn($v) => $v !== '' && $v !== null));

        try {
            $pasta = $this->sincronizarMarcadoresUseCase->executar($id, $marcadorIds, $this->getUser());
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->json(['erro' => $e->getMessage(), 'trace' => $e->getTraceAsString()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'pastaId'    => $pasta->getId(),
            'marcadores' => array_map(
                fn($m) => ['id' => $m->getId(), 'nome' => $m->getNome(), 'cor' => $m->getCor()],
                $pasta->getMarcadores()->toArray()
            ),
        ]);
    }

    #[Route('/expediente/painel/acervo-geral', name: 'expediente_acervo_geral')]
    public function acervoGeral(Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('expediente_index');
        }

        $filters = [
            'nup'               => $request->query->get('nup', ''),
            'status'            => $request->query->get('status', ''),
            'responsavel'       => $request->query->get('responsavel', ''),
            'status_documentos' => $request->query->get('status_documentos', ''),
        ];

        $hasFilters = array_filter($filters, fn($v) => $v !== '');

        return $this->render('expediente/_acervo_geral.html.twig', [
            'pastas'      => $hasFilters ? $this->pastaRepository->findByFilters($filters) : $this->pastaRepository->findAll(),
            'filters'     => $filters,
            'nups'        => $this->pastaRepository->findAllNups(),
            'responsaveis' => $this->userRepository->findBy(['isActive' => true], ['fullName' => 'ASC']),
            'formAction'  => $this->generateUrl('expediente_acervo_geral'),
            'limparUrl'   => $this->generateUrl('expediente_acervo_geral'),
        ]);
    }
}
