<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\DTO\ConfirmarCodigoSenhaInput;
use App\Auth\DTO\RedefinirSenhaInput;
use App\Auth\DTO\SolicitarCodigoSenhaInput;
use App\Auth\Form\RedefinirSenhaType;
use App\Auth\Repository\RedefinicaoSenhaRepository;
use App\Auth\UseCase\ConfirmarCodigoSenhaUseCase;
use App\Auth\UseCase\RedefinirSenhaUseCase;
use App\Auth\UseCase\SolicitarCodigoSenhaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Recuperação de senha (rotas públicas).
 *
 * Senha por código (desenho 10): o modal "Recuperar senha" da tela de login chama
 * `/senha/codigo` e `/senha/codigo/confirmar` em JSON. O link antigo `/senha/redefinir/{token}`
 * continua valendo para os pedidos já enviados por e-mail.
 *
 * Regra que atravessa o arquivo: **a resposta não diz se a conta existe** (RN02). Quem decide
 * enviar e quem confere o código são os UseCases; o controller não sabe se a conta existe,
 * justamente para não ter como vazar isso.
 */
final class RecuperacaoSenhaController extends AbstractController
{
    private const CSRF_ID = 'senha_codigo';
    private const ERRO_CSRF = 'Sessão expirada. Recarregue a página.';
    private const ERRO_LIMITE = 'Muitas tentativas. Tente de novo mais tarde.';

    public function __construct(
        private readonly SolicitarCodigoSenhaUseCase $solicitarCodigo,
        private readonly ConfirmarCodigoSenhaUseCase $confirmarCodigo,
        private readonly RedefinirSenhaUseCase $redefinir,
        private readonly RedefinicaoSenhaRepository $redefinicaoRepository,
        private readonly ValidatorInterface $validator,
        private readonly RateLimiterFactoryInterface $senhaEsqueciIpLimiter,
        private readonly RateLimiterFactoryInterface $senhaEsqueciEmailLimiter,
    ) {
    }

    /** O pedido por formulário virou o passo "Recuperar senha" do modal da tela de login. */
    #[Route('/senha/esqueci', name: 'auth_senha_esqueci', methods: ['GET'])]
    public function esqueci(): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('tenant_selecionar');
        }

        return $this->redirectToRoute('app_login', ['abrir' => 'senha']);
    }

    #[Route('/senha/codigo', name: 'auth_senha_codigo_enviar', methods: ['POST'])]
    public function enviarCodigo(Request $request): JsonResponse
    {
        $dados = $this->corpoJson($request);

        if (!$this->isCsrfTokenValid(self::CSRF_ID, $this->texto($dados, '_token'))) {
            return new JsonResponse(['erro' => self::ERRO_CSRF], Response::HTTP_FORBIDDEN);
        }

        $input     = new SolicitarCodigoSenhaInput(trim($this->texto($dados, 'email')));
        $violacoes = $this->validator->validate($input);

        if (count($violacoes) > 0) {
            return new JsonResponse(['erro' => (string) $violacoes->get(0)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$this->dentroDosLimites($request, $input->email)) {
            return new JsonResponse(['erro' => self::ERRO_LIMITE], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $this->solicitarCodigo->executar($input, (string) $request->getClientIp(), $request->headers->get('User-Agent'));

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/senha/codigo/confirmar', name: 'auth_senha_codigo_confirmar', methods: ['POST'])]
    public function confirmarCodigo(Request $request): JsonResponse
    {
        $dados = $this->corpoJson($request);

        if (!$this->isCsrfTokenValid(self::CSRF_ID, $this->texto($dados, '_token'))) {
            return new JsonResponse(['erro' => self::ERRO_CSRF], Response::HTTP_FORBIDDEN);
        }

        // As 5 tentativas por código são a defesa do código; o limite por IP põe teto em quem varre contas.
        if (!$this->senhaEsqueciIpLimiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return new JsonResponse(['erro' => self::ERRO_LIMITE], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $this->confirmarCodigo->executar(new ConfirmarCodigoSenhaInput(
                email: $this->texto($dados, 'email'),
                codigo: $this->texto($dados, 'codigo'),
                senha: $this->texto($dados, 'senha'),
                confirmacao: $this->texto($dados, 'confirmacao'),
            ));
        } catch (\DomainException $e) {
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/senha/redefinir/{token}', name: 'auth_senha_redefinir', methods: ['GET', 'POST'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function redefinirSenha(string $token, Request $request): Response
    {
        if ($this->getUser() !== null) {
            $this->addFlash('warning', 'Saia da sua conta atual para redefinir a senha por este link.');

            return $this->redirectToRoute('tenant_selecionar');
        }

        // Confere o link antes de mostrar o formulário: quem chega com link morto
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

    /**
     * Os dois limites são consumidos SÓ depois do CSRF e da validação, para que um POST com
     * corpo lixo não gaste cota nenhuma.
     *
     * Isto encarece o abuso, não o elimina: o token CSRF não é de uso único dentro da sessão.
     * O que segura de fato a vítima é o limite por e-mail (RS09); o limite por IP é largo
     * porque, sem SYMFONY_TRUSTED_PROXIES confirmado na VPS, a chave é o IP do nginx, a mesma
     * para todos. A chave por e-mail é o digitado normalizado, exista a conta ou não.
     */
    private function dentroDosLimites(Request $request, string $email): bool
    {
        if (!$this->senhaEsqueciIpLimiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return false;
        }

        return $this->senhaEsqueciEmailLimiter->create(mb_strtolower($email))->consume()->isAccepted();
    }

    /** @return array<mixed> corpo JSON; vazio se não for JSON (aí o CSRF já recusa) */
    private function corpoJson(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (JsonException) {
            return [];
        }
    }

    /** @param array<mixed> $dados */
    private function texto(array $dados, string $chave): string
    {
        $valor = $dados[$chave] ?? '';

        return is_string($valor) ? $valor : '';
    }
}
