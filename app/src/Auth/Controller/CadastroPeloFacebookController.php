<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\DTO\CriarContaEEscritorioInput;
use App\Auth\Exception\CadastroNaoDisponivel;
use App\Auth\Service\CadastroPeloFacebook;
use App\Auth\Service\ContaAConfirmar;
use App\Auth\UseCase\CriarContaEEscritorioUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Criar conta" conectado pelo Facebook (spec login-cupom-e-facebook, PR B). A identidade (o `id` do Facebook) vem da
 * sessão (`CadastroPeloFacebook`, gravada pelo `FacebookAuthenticator`), nunca do JSON. O Facebook não prova e-mail:
 * o e-mail é o do corpo do POST (o campo da tela, que o Facebook só sugere) e a conta nasce NÃO confirmada, pelo mesmo
 * `executar` do cadastro por e-mail (link de confirmação, resposta neutra para e-mail que já tem conta). O `id` só é
 * gravado na conta nova. Não há login aqui.
 */
final class CadastroPeloFacebookController extends AbstractController
{
    private const SESSAO_EXPIRADA = 'Sessão expirada. Recarregue a página.';

    public function __construct(
        private readonly CriarContaEEscritorioUseCase $criarConta,
        private readonly CadastroPeloFacebook $cadastroPeloFacebook,
        private readonly ContaAConfirmar $contaAConfirmar,
        private readonly RateLimiterFactoryInterface $cadastroAutoLimiter,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/cadastro/facebook/criar', name: 'auth_cadastro_facebook_criar', methods: ['POST'])]
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
        $identidade = $this->cadastroPeloFacebook->ler($request->getSession());
        if (null === $identidade) {
            return $this->json(['erro' => CadastroPeloFacebook::EXPIROU, 'facebookExpirou' => true], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $input = self::input($dados);
        try {
            $conta = $this->criarConta->executar($input, (string) $request->getClientIp(), $request->headers->get('User-Agent'), $identidade->id);
        } catch (CadastroNaoDisponivel $e) {
            return $this->recusadoPelaPolitica($e, $request);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        CadastroPeloFacebook::esquecer($request->getSession());

        // Igual ao cadastro por e-mail: sem login; a sessão guarda a conta (ou o marcador neutro) para o reenvio.
        $email = CriarContaEEscritorioUseCase::normalizarEmail($input->email);
        if (null !== $conta) {
            $this->contaAConfirmar->guardar($request->getSession(), $conta->user);
            // Só o link aberto nesta sessão mantém o facebook_id (pré-sequestro, spec login-cupom-e-facebook).
            CadastroPeloFacebook::marcarConta($request->getSession(), (int) $conta->user->getId());
        } else {
            $this->contaAConfirmar->guardarNeutro($request->getSession(), $email);
        }

        return $this->json(['ok' => true, 'confirmar' => true, 'email' => $email]);
    }

    /** Política do Master (spec login-config-cadastro): a identidade do Facebook sai da sessão e nada é criado. */
    private function recusadoPelaPolitica(CadastroNaoDisponivel $e, Request $request): JsonResponse
    {
        CadastroPeloFacebook::esquecer($request->getSession());

        return $this->json(['erro' => $this->tradutor->trans($e->chaveDeTraducao())], Response::HTTP_FORBIDDEN);
    }

    /** "Usar outro e-mail": a identidade do Facebook sai da sessão e o formulário volta ao cadastro comum. */
    #[Route('/cadastro/facebook/esquecer', name: 'auth_cadastro_facebook_esquecer', methods: ['POST'])]
    public function esquecer(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('cadastro_criar', self::texto(self::lerJson($request), '_token'))) {
            return $this->json(['erro' => self::SESSAO_EXPIRADA], Response::HTTP_FORBIDDEN);
        }
        CadastroPeloFacebook::esquecer($request->getSession());

        return $this->json(['ok' => true]);
    }

    /** @param array<string, mixed> $dados */
    private static function input(array $dados): CriarContaEEscritorioInput
    {
        return new CriarContaEEscritorioInput(
            nome: self::texto($dados, 'nome'),
            escritorio: self::texto($dados, 'escritorio'),
            email: self::texto($dados, 'email'),
            senha: '',
            aceite: in_array($dados['aceite'] ?? false, [true, 1, '1', 'true', 'on'], true),
            genero: self::texto($dados, 'genero'),
        );
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
