<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\DTO\RedefinirSenhaInput;
use App\Auth\DTO\SolicitarRedefinicaoSenhaInput;
use App\Auth\Form\RedefinirSenhaType;
use App\Auth\Form\SolicitarRedefinicaoSenhaType;
use App\Auth\Repository\RedefinicaoSenhaRepository;
use App\Auth\UseCase\RedefinirSenhaUseCase;
use App\Auth\UseCase\SolicitarRedefinicaoSenhaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Recuperação de senha (rotas públicas).
 *
 * Regra que atravessa o arquivo inteiro: **a resposta é sempre a mesma** para e-mail
 * existente e inexistente (RN02). Quem decide enviar o e-mail é o UseCase — o controller
 * não sabe se a conta existe, justamente para não ter como vazar isso.
 */
final class RecuperacaoSenhaController extends AbstractController
{
    public function __construct(
        private readonly SolicitarRedefinicaoSenhaUseCase $solicitar,
        private readonly RedefinirSenhaUseCase $redefinir,
        private readonly RedefinicaoSenhaRepository $redefinicaoRepository,
        private readonly RateLimiterFactoryInterface $senhaEsqueciIpLimiter,
        private readonly RateLimiterFactoryInterface $senhaEsqueciEmailLimiter,
    ) {}

    #[Route('/senha/esqueci', name: 'auth_senha_esqueci', methods: ['GET', 'POST'])]
    public function esqueci(Request $request): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('tenant_selecionar');
        }

        $input = new SolicitarRedefinicaoSenhaInput();
        $form  = $this->createForm(SolicitarRedefinicaoSenhaType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Os dois limites são consumidos SÓ AQUI, depois do CSRF e da validação, para
            // que um POST com corpo lixo não gaste cota nenhuma.
            //
            // Isto encarece o abuso, NÃO o elimina: o token CSRF do Symfony não é de uso
            // único dentro da sessão, então um GET rende um token reutilizável para N POSTs.
            // Quem quiser esgotar a cota de um IP ainda consegue. O que segura de fato a
            // vítima é o limite por e-mail; o limite por IP é largo justamente porque, sem
            // SYMFONY_TRUSTED_PROXIES confirmado na VPS, a chave é o IP do nginx — a mesma
            // para todos. Fechar isso de verdade é o captcha da F4.
            if (!$this->senhaEsqueciIpLimiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
                throw new TooManyRequestsHttpException();
            }

            // Limite por e-mail (RS09): o limite por IP sozinho não segura IP rotativo.
            // A chave é o e-mail digitado — existindo conta ou não, o custo é o mesmo.
            $chave = mb_strtolower(trim($input->email));

            if (!$this->senhaEsqueciEmailLimiter->create($chave)->consume()->isAccepted()) {
                throw new TooManyRequestsHttpException();
            }

            $this->solicitar->executar(
                $input,
                (string) $request->getClientIp(),
                $request->headers->get('User-Agent'),
            );

            return $this->redirectToRoute('auth_senha_enviado');
        }

        return $this->render('auth/senha/esqueci.html.twig', ['form' => $form]);
    }

    #[Route('/senha/enviado', name: 'auth_senha_enviado', methods: ['GET'])]
    public function enviado(): Response
    {
        return $this->render('auth/senha/enviado.html.twig');
    }

    #[Route('/senha/redefinir/{token}', name: 'auth_senha_redefinir', methods: ['GET', 'POST'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function redefinirSenha(string $token, Request $request): Response
    {
        if ($this->getUser() !== null) {
            $this->addFlash('warning', 'Saia da sua conta atual para redefinir a senha por este link.');

            return $this->redirectToRoute('tenant_selecionar');
        }

        // Confere o link antes de mostrar o formulário — quem chega com link morto
        // recebe a explicação na hora, em vez de descobrir depois de digitar a senha.
        $pedido = $this->redefinicaoRepository->encontrarPorToken($token);

        if ($pedido === null || !$pedido->estaValido()) {
            return $this->render('auth/senha/erro.html.twig', [
                'motivo' => 'Este link é inválido, já foi usado ou expirou.',
            ]);
        }

        $input = new RedefinirSenhaInput();
        $form  = $this->createForm(RedefinirSenhaType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->redefinir->executar($token, $input->senha);
                $this->addFlash('success', 'Senha redefinida! Entre com a senha nova.');

                return $this->redirectToRoute('app_login');
            } catch (\DomainException $e) {
                return $this->render('auth/senha/erro.html.twig', ['motivo' => $e->getMessage()]);
            }
        }

        return $this->render('auth/senha/redefinir.html.twig', [
            'form'  => $form,
            'token' => $token,
        ]);
    }
}
