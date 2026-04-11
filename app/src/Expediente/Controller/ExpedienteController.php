<?php

namespace App\Expediente\Controller;

use App\Expediente\DTO\CriarPastaOrganizadoraDTO;
use App\Expediente\Repository\PastaOrganizadoraRepository;
use App\Expediente\UseCase\CriarPastaOrganizadoraUseCase;
use App\Expediente\UseCase\ExcluirPastaOrganizadoraUseCase;
use App\Expediente\UseCase\EditarPastaOrganizadoraUseCase;
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
        private readonly PastaOrganizadoraRepository $pastaOrganizadoraRepository,
        private readonly CriarPastaOrganizadoraUseCase $criarPastaUseCase,
        private readonly ExcluirPastaOrganizadoraUseCase $excluirPastaUseCase,
        private readonly EditarPastaOrganizadoraUseCase $editarPastaUseCase,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('/expediente', name: 'expediente_index')]
    public function index(): Response
    {
        $usuario = $this->getUser();
        $pastas  = $this->pastaOrganizadoraRepository->findRaizPorTenant($usuario->getTenant());

        return $this->render('expediente/index.html.twig', [
            'pastas' => $pastas,
        ]);
    }

    #[Route('/expediente/pasta-organizadora', name: 'expediente_pasta_criar', methods: ['POST'])]
    public function criarPasta(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('pasta_organizadora', $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        $dto = new CriarPastaOrganizadoraDTO(
            nome:  trim((string) $request->request->get('nome', '')),
            paiId: $request->request->get('pai_id') !== null && $request->request->get('pai_id') !== ''
                ? (int) $request->request->get('pai_id')
                : null,
        );

        $erros = $this->validator->validate($dto);
        if (count($erros) > 0) {
            return $this->json(['erro' => (string) $erros->get(0)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $pasta = $this->criarPastaUseCase->executar($dto, $this->getUser());

        return $this->json([
            'id'          => $pasta->getId(),
            'nome'        => $pasta->getNome(),
            'cor'         => $pasta->getCor(),
            'paiId'       => $pasta->getPai()?->getId(),
            'csrfExcluir' => $this->csrfTokenManager->getToken('excluir_pasta_' . $pasta->getId())->getValue(),
            'csrfEditar'  => $this->csrfTokenManager->getToken('editar_pasta_' . $pasta->getId())->getValue(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/expediente/pasta-organizadora/{id}', name: 'expediente_pasta_editar', methods: ['PATCH'])]
    public function editarPasta(int $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('editar_pasta_' . $id, $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        $nome = trim((string) $request->request->get('nome', ''));
        if ($nome === '' || mb_strlen($nome) > 100) {
            return $this->json(['erro' => 'Nome inválido.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $cor = $request->request->get('cor');
        $cor = ($cor === '' || $cor === null) ? null : (string) $cor;

        try {
            $pasta = $this->editarPastaUseCase->executar($id, $nome, $cor, $this->getUser());
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'id'  => $pasta->getId(),
            'nome' => $pasta->getNome(),
            'cor'  => $pasta->getCor(),
        ]);
    }

    #[Route('/expediente/pasta-organizadora/{id}', name: 'expediente_pasta_excluir', methods: ['DELETE'])]
    public function excluirPasta(int $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('excluir_pasta_' . $id, $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $pasta = $this->excluirPastaUseCase->executar($id, $this->getUser());
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id'    => $pasta->getId(),
            'paiId' => $pasta->getPai()?->getId(),
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
