<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\DTO\CriarContaEEscritorioInput;
use App\Auth\Service\ContaAConfirmar;
use App\Auth\UseCase\ConfirmarCadastroUseCase;
use App\Auth\UseCase\ConfirmarEmailDaContaUseCase;
use App\Auth\UseCase\CriarContaEEscritorioUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cadastro público do desenho 10: o formulário mora na tela de entrada (passo "Criar conta e escritório"); aqui
 * ficam o POST em JSON que cria a conta e o link de confirmação do e-mail. A conta nasce sem login: só entra depois
 * de confirmar o e-mail (spec `cadastro-exige-confirmacao.md`). O `/cadastro/confirmar/{token}` antigo continua para
 * os cadastros pendentes já enviados (valem 24 h).
 */
final class CadastroController extends AbstractController
{
    public function __construct(
        private readonly CriarContaEEscritorioUseCase $criarConta,
        private readonly ConfirmarEmailDaContaUseCase $confirmarEmail,
        private readonly ConfirmarCadastroUseCase $confirmarCadastro,
        private readonly RateLimiterFactoryInterface $cadastroAutoLimiter,
        private readonly ContaAConfirmar $contaAConfirmar,
    ) {}

    #[Route('/cadastro', name: 'auth_cadastro', methods: ['GET'])]
    public function cadastrar(): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('tenant_selecionar');
        }

        return $this->redirectToRoute('app_login', ['abrir' => 'cadastro']);
    }

    #[Route('/cadastro/criar', name: 'auth_cadastro_criar', methods: ['POST'])]
    public function criar(Request $request): JsonResponse
    {
        if ($this->getUser() !== null) {
            return $this->json(['erro' => 'Saia da sua conta para criar outra.'], Response::HTTP_FORBIDDEN);
        }

        $dados = self::lerJson($request);

        if (!$this->isCsrfTokenValid('cadastro_criar', self::texto($dados, '_token'))) {
            return $this->json(['erro' => 'Sessão expirada. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        // Depois do CSRF: um POST forjado não gasta a cota de quem divide o IP.
        if (!$this->cadastroAutoLimiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return $this->json(['erro' => 'Muitas tentativas. Tente de novo mais tarde.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $conta = $this->criarConta->executar(self::input($dados), (string) $request->getClientIp(), $request->headers->get('User-Agent'));
        } catch (\InvalidArgumentException | \DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Sem login: a tela mostra "Confirme seu e-mail". A sessão guarda a conta para o botão de reenviar o link.
        $this->contaAConfirmar->guardar($request->getSession(), $conta->user);

        return $this->json(['ok' => true, 'confirmar' => true, 'email' => $conta->user->getEmail()]);
    }

    #[Route('/cadastro/confirmar-email/{token}', name: 'auth_cadastro_confirmar_email', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function confirmarEmail(string $token): Response
    {
        try {
            $this->confirmarEmail->executar($token);
        } catch (\DomainException $e) {
            return $this->render('auth/cadastro/confirmar_email.html.twig', ['motivo' => $e->getMessage()]);
        }

        return $this->render('auth/cadastro/confirmar_email.html.twig', ['motivo' => null]);
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

    /** @return array<string, mixed> */
    private static function lerJson(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $dados */
    private static function input(array $dados): CriarContaEEscritorioInput
    {
        return new CriarContaEEscritorioInput(
            nome: self::texto($dados, 'nome'),
            escritorio: self::texto($dados, 'escritorio'),
            email: self::texto($dados, 'email'),
            senha: self::texto($dados, 'senha'),
            aceite: in_array($dados['aceite'] ?? false, [true, 1, '1', 'true', 'on'], true),
        );
    }

    /** @param array<string, mixed> $dados */
    private static function texto(array $dados, string $chave): string
    {
        $valor = $dados[$chave] ?? '';

        return is_string($valor) ? $valor : '';
    }
}
