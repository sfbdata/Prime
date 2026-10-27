<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\DTO\CriarContaEEscritorioInput;
use App\Auth\Exception\CadastroNaoDisponivel;
use App\Auth\Service\CadastroPeloGoogle;
use App\Auth\UseCase\CriarContaEEscritorioUseCase;
use App\Security\GoogleAuthenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Criar conta" conectado pelo Google (spec entrar-com-google, entrega 2; desenho 10 VS 24.10.26). A identidade vem
 * da sessão (`CadastroPeloGoogle`, gravada pelo `GoogleAuthenticator` na volta do Google), nunca do JSON: o navegador
 * manda só nome, escritório, gênero e aceite. A conta nasce confirmada e entra na hora, pelo mesmo caminho do login
 * (`Security::login` com o autenticador do Google, que leva ao véu `/login/entrando`).
 */
final class CadastroPeloGoogleController extends AbstractController
{
    private const SESSAO_EXPIRADA = 'Sessão expirada. Recarregue a página.';

    public function __construct(
        private readonly CriarContaEEscritorioUseCase $criarConta,
        private readonly CadastroPeloGoogle $cadastroPeloGoogle,
        private readonly RateLimiterFactoryInterface $cadastroAutoLimiter,
        private readonly Security $security,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/cadastro/google/criar', name: 'auth_cadastro_google_criar', methods: ['POST'])]
    public function criar(Request $request): JsonResponse
    {
        if (null !== $this->getUser()) {
            return $this->json(['erro' => 'Saia da sua conta para criar outra.'], Response::HTTP_FORBIDDEN);
        }
        $dados = self::lerJson($request);
        if (!$this->isCsrfTokenValid('cadastro_criar', self::texto($dados, '_token'))) {
            return $this->json(['erro' => self::SESSAO_EXPIRADA], Response::HTTP_FORBIDDEN);
        }
        if (!$this->cadastroAutoLimiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return $this->json(['erro' => 'Muitas tentativas. Tente de novo mais tarde.'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $identidade = $this->cadastroPeloGoogle->ler($request->getSession());
        if (null === $identidade) {
            return $this->json(['erro' => CadastroPeloGoogle::EXPIROU, 'googleExpirou' => true], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $input = new CriarContaEEscritorioInput(
            nome: self::texto($dados, 'nome'),
            escritorio: self::texto($dados, 'escritorio'),
            email: $identidade->email,
            senha: '',
            aceite: in_array($dados['aceite'] ?? false, [true, 1, '1', 'true', 'on'], true),
            genero: self::texto($dados, 'genero'),
        );
        try {
            $conta = $this->criarConta->executarPeloGoogle($input, $identidade, (string) $request->getClientIp(), $request->headers->get('User-Agent'));
        } catch (CadastroNaoDisponivel $e) {
            return $this->recusadoPelaPolitica($e, $request);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        CadastroPeloGoogle::esquecer($request->getSession());

        // A resposta do autenticador é o redirecionamento ao véu com o destino já validado (EntradaDoUsuario).
        $resposta = $this->security->login($conta->user, GoogleAuthenticator::class, 'main');
        $destino = $resposta?->headers->get('Location') ?? $this->generateUrl('app_login_entrando');

        return $this->json(['ok' => true, 'destino' => $destino]);
    }

    /** Política do Master (spec login-config-cadastro): a identidade do Google sai da sessão e nada é criado. */
    private function recusadoPelaPolitica(CadastroNaoDisponivel $e, Request $request): JsonResponse
    {
        CadastroPeloGoogle::esquecer($request->getSession());

        return $this->json(['erro' => $this->tradutor->trans($e->chaveDeTraducao())], Response::HTTP_FORBIDDEN);
    }

    /** "Usar outro e-mail": a identidade do Google sai da sessão e o formulário volta a pedir o e-mail. */
    #[Route('/cadastro/google/esquecer', name: 'auth_cadastro_google_esquecer', methods: ['POST'])]
    public function esquecer(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('cadastro_criar', self::texto(self::lerJson($request), '_token'))) {
            return $this->json(['erro' => self::SESSAO_EXPIRADA], Response::HTTP_FORBIDDEN);
        }
        CadastroPeloGoogle::esquecer($request->getSession());

        return $this->json(['ok' => true]);
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
    private static function texto(array $dados, string $chave): string
    {
        $valor = $dados[$chave] ?? '';

        return is_string($valor) ? $valor : '';
    }
}
