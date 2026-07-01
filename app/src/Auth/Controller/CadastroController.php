<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\DTO\IniciarCadastroPublicoInput;
use App\Auth\Form\CadastroPublicoType;
use App\Auth\Service\CadastroMailer;
use App\Auth\UseCase\ConfirmarCadastroUseCase;
use App\Auth\UseCase\IniciarCadastroPublicoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class CadastroController extends AbstractController
{
    public function __construct(
        private readonly IniciarCadastroPublicoUseCase $iniciarCadastro,
        private readonly ConfirmarCadastroUseCase $confirmarCadastro,
        private readonly CadastroMailer $cadastroMailer,
        private readonly RateLimiterFactory $cadastroAutoLimiter,
    ) {}

    #[Route('/cadastro', name: 'auth_cadastro', methods: ['GET', 'POST'])]
    public function cadastrar(Request $request): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('tenant_selecionar');
        }

        if ($request->isMethod('POST')
            && !$this->cadastroAutoLimiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        $input = new IniciarCadastroPublicoInput();
        $form  = $this->createForm(CadastroPublicoType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $cadastro = $this->iniciarCadastro->executar(
                    $input,
                    (string) $request->getClientIp(),
                    $request->headers->get('User-Agent'),
                );
                $this->cadastroMailer->enviarConfirmacao($cadastro);

                return $this->redirectToRoute('auth_cadastro_verifique', ['email' => $cadastro->getEmail()]);
            } catch (\DomainException | \InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
            } catch (\RuntimeException) {
                $this->addFlash('danger', 'Não foi possível enviar o e-mail de confirmação. Tente novamente em instantes.');
            }
        }

        return $this->render('auth/cadastro/form.html.twig', ['form' => $form]);
    }

    #[Route('/cadastro/verifique-email', name: 'auth_cadastro_verifique', methods: ['GET'])]
    public function verifique(Request $request): Response
    {
        return $this->render('auth/cadastro/verifique_email.html.twig', [
            'email' => (string) $request->query->get('email', ''),
        ]);
    }

    #[Route('/cadastro/confirmar/{token}', name: 'auth_cadastro_confirmar', methods: ['GET'])]
    public function confirmar(string $token): Response
    {
        // Usuário já logado não confirma cadastro novo (evita criar conta alheia / queimar token de terceiro).
        if ($this->getUser() !== null) {
            $this->addFlash('warning', 'Saia da sua conta atual para confirmar um novo cadastro.');

            return $this->redirectToRoute('tenant_selecionar');
        }

        try {
            $this->confirmarCadastro->executar($token);
            $this->addFlash('success', 'Cadastro confirmado! Faça login para entrar no seu escritório.');

            return $this->redirectToRoute('app_login');
        } catch (\DomainException $e) {
            return $this->render('auth/cadastro/erro.html.twig', ['motivo' => $e->getMessage()]);
        }
    }
}
