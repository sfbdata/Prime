<?php

namespace App\Profile\Controller;

use App\Entity\Auth\User;
use App\Profile\DTO\DadosPessoaisDTO;
use App\Profile\Form\DadosPessoaisType;
use App\Profile\UseCase\AtualizarDadosPessoaisUseCase;
use App\Profile\UseCase\AtualizarFotoPerfilUseCase;
use App\Profile\UseCase\AtualizarStatusUseCase;
use App\Profile\UseCase\ObterOuCriarPerfilUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/perfil', name: 'app_profile')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly ObterOuCriarPerfilUseCase $obterOuCriarPerfil,
        private readonly AtualizarStatusUseCase $atualizarStatus,
        private readonly AtualizarDadosPessoaisUseCase $atualizarDadosPessoais,
        private readonly AtualizarFotoPerfilUseCase $atualizarFoto,
        private readonly string $fotosPerfilDir,
    ) {}

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $perfil = $this->obterOuCriarPerfil->executar($user);

        $dto = new DadosPessoaisDTO();
        $dto->nomeCompleto = $perfil->getNomeCompleto() ?? $user->getFullName();
        $dto->cpf = $perfil->getCpf();
        $dto->dataNascimento = $perfil->getDataNascimento();
        $dto->ctps = $perfil->getCtps();
        $dto->serie = $perfil->getSerie();

        $formDados = $this->createForm(DadosPessoaisType::class, $dto);

        return $this->render('profile/index.html.twig', [
            'user'       => $user,
            'perfil'     => $perfil,
            'form_dados' => $formDados->createView(),
        ]);
    }

    #[Route('/status', name: '_status', methods: ['POST'])]
    public function salvarStatus(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('profile_status', $request->request->get('_token'))) {
            return new JsonResponse(['erro' => 'Token inválido'], Response::HTTP_FORBIDDEN);
        }

        $perfil = $this->obterOuCriarPerfil->executar($user);
        $this->atualizarStatus->executar($perfil, $request->request->get('status'));

        return new JsonResponse(['ok' => true, 'status' => $perfil->getStatus()]);
    }

    #[Route('/foto', name: '_foto', methods: ['POST'])]
    public function salvarFoto(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('profile_foto', $request->request->get('_token'))) {
            return new JsonResponse(['erro' => 'Token inválido'], Response::HTTP_FORBIDDEN);
        }

        $arquivo = $request->files->get('foto');
        if (!$arquivo) {
            return new JsonResponse(['erro' => 'Nenhuma imagem enviada'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $perfil = $this->obterOuCriarPerfil->executar($user);
            $this->atualizarFoto->executar($perfil, $arquivo);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $urlFoto = $this->generateUrl('app_profile_foto_serve', ['nome' => $perfil->getFotoUrl()]);

        return new JsonResponse(['ok' => true, 'url' => $urlFoto]);
    }

    #[Route('/dados', name: '_dados', methods: ['POST'])]
    public function salvarDados(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $perfil = $this->obterOuCriarPerfil->executar($user);

        $dto = new DadosPessoaisDTO();
        $form = $this->createForm(DadosPessoaisType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->atualizarDadosPessoais->executar($perfil, $dto);
            $this->addFlash('success', 'Dados pessoais atualizados com sucesso.');
        } else {
            $this->addFlash('error', 'Verifique os dados informados.');
        }

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/foto/{nome}', name: '_foto_serve', methods: ['GET'])]
    public function servir(string $nome): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $perfil = $this->obterOuCriarPerfil->executar($user);

        if ($perfil->getFotoUrl() !== $nome) {
            throw $this->createNotFoundException();
        }

        $caminho = $this->fotosPerfilDir . '/' . $nome;
        if (!file_exists($caminho)) {
            throw $this->createNotFoundException();
        }

        return $this->file($caminho, $nome, \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_INLINE);
    }
}
