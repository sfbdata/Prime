<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\DTO\AlterarLinkDaEntradaInput;
use App\Auth\DTO\DefinirDestinatarioSuporteInput;
use App\Auth\DTO\EnviarMensagemSuporteInput;
use App\Auth\DTO\MensagemSuporteItem;
use App\Auth\Repository\MensagemSuporteRepository;
use App\Auth\Service\PaginaDeEntrada;
use App\Auth\UseCase\AlterarLinkDaEntradaUseCase;
use App\Auth\UseCase\DefinirDestinatarioSuporteUseCase;
use App\Auth\UseCase\EnviarMensagemSuporteUseCase;
use App\Entity\Auth\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * "Falar com suporte" e ferramentas SÓ MASTER da tela de entrada (desenho 10, spec login-desenho-10
 * comportamentos 5 e 6).
 *
 * As rotas sob /login são PUBLIC_ACCESS no security.yaml: quem é Master é conferido aqui, com
 * isGranted('ROLE_SUPER_ADMIN'), antes de qualquer outra coisa. Todas as respostas dos POSTs são JSON.
 */
final class SuporteEntradaController extends AbstractController
{
    private const SESSAO_EXPIRADA = 'Sessão expirada. Recarregue a página.';

    public function __construct(
        private readonly EnviarMensagemSuporteUseCase $enviarMensagem,
        private readonly DefinirDestinatarioSuporteUseCase $definirDestinatario,
        private readonly AlterarLinkDaEntradaUseCase $alterarLink,
        private readonly PaginaDeEntrada $paginaDeEntrada,
        private readonly MensagemSuporteRepository $mensagens,
        private readonly ValidatorInterface $validator,
        private readonly RateLimiterFactoryInterface $suporteEntradaLimiter,
    ) {
    }

    /**
     * Ordem: CSRF, honeypot, validação e só então a cota do IP, para um POST inválido não gastar a cota de quem
     * divide o mesmo IP.
     */
    #[Route('/login/suporte', name: 'auth_suporte_enviar', methods: ['POST'])]
    public function enviar(Request $request): JsonResponse
    {
        $dados = $this->corpo($request);
        if (!$this->isCsrfTokenValid('suporte_entrada', $this->campo($dados, '_token'))) {
            return $this->erro(self::SESSAO_EXPIRADA, Response::HTTP_FORBIDDEN);
        }
        $input = EnviarMensagemSuporteInput::doPayload($dados, (string) $request->getClientIp(), $request->headers->get('User-Agent'));
        if ($this->campo($dados, 'site') !== '') {
            return $this->json(['ok' => true, 'email' => $input->email]); // honeypot: robô recebe "ok" e nada é gravado
        }
        $invalido = $this->primeiroErro($input);
        if ($invalido !== null) {
            return $this->erro($invalido, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$this->suporteEntradaLimiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return $this->erro('Muitas tentativas. Tente de novo mais tarde.', Response::HTTP_TOO_MANY_REQUESTS);
        }
        $this->enviarMensagem->executar($input);

        return $this->json(['ok' => true, 'email' => $input->email]);
    }

    #[Route('/login/suporte/destinatario', name: 'auth_suporte_destinatario', methods: ['POST'])]
    public function destinatario(Request $request): JsonResponse
    {
        $dados = $this->corpo($request);
        $recusa = $this->recusaDoMaster($dados);
        if ($recusa !== null) {
            return $recusa;
        }
        $userId = $dados['userId'] ?? null;
        try {
            $texto = $this->definirDestinatario->executar(new DefinirDestinatarioSuporteInput(
                is_numeric($userId) ? (int) $userId : 0,
                $this->master(),
            ));
        } catch (\DomainException $e) {
            return $this->erro($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true, 'texto' => $texto]);
    }

    #[Route('/login/links/{chave}', name: 'auth_entrada_link_salvar', requirements: ['chave' => 'termos|priv|linkedin|instagram'], methods: ['POST'])]
    public function link(string $chave, Request $request): JsonResponse
    {
        $dados = $this->corpo($request);
        $recusa = $this->recusaDoMaster($dados);
        if ($recusa !== null) {
            return $recusa;
        }
        try {
            $this->alterarLink->executar(new AlterarLinkDaEntradaInput($chave, $this->campo($dados, 'acao'), $this->campo($dados, 'url'), $this->master()));
        } catch (\DomainException $e) {
            return $this->erro($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true, 'links' => $this->paginaDeEntrada->montar($this->master())->links]);
    }

    /** Onde o aviso do sino leva. Só o Master ou o destinatário escolhido; para os outros a página não existe. */
    #[Route('/suporte/mensagens', name: 'auth_suporte_mensagens', methods: ['GET'])]
    public function mensagens(): Response
    {
        $user = $this->getUser();
        $ehDestinatario = $user instanceof User && $this->paginaDeEntrada->destinatarioDoSuporte()?->getId() === $user->getId();
        if (!$this->isGranted('ROLE_SUPER_ADMIN') && !$ehDestinatario) {
            throw $this->createNotFoundException();
        }

        return $this->render('auth/suporte/mensagens.html.twig', [
            'mensagens' => array_map(MensagemSuporteItem::fromEntity(...), $this->mensagens->recentes()),
        ]);
    }

    /** @param array<mixed> $dados */
    private function recusaDoMaster(array $dados): ?JsonResponse
    {
        if (!$this->isGranted('ROLE_SUPER_ADMIN') || !$this->getUser() instanceof User) {
            return $this->erro('Só o Master.', Response::HTTP_FORBIDDEN);
        }
        if (!$this->isCsrfTokenValid('entrada_ajuste', $this->campo($dados, '_token'))) {
            return $this->erro(self::SESSAO_EXPIRADA, Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function master(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function primeiroErro(EnviarMensagemSuporteInput $input): ?string
    {
        foreach (['email', 'mensagem'] as $campo) {
            $violacoes = $this->validator->validateProperty($input, $campo);
            if (count($violacoes) > 0) {
                return (string) $violacoes->get(0)->getMessage();
            }
        }

        return null;
    }

    /** @return array<mixed> o corpo JSON; corpo ausente ou quebrado vira vazio (e cai no CSRF) */
    private function corpo(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (JsonException) {
            return [];
        }
    }

    /** @param array<mixed> $dados */
    private function campo(array $dados, string $nome): string
    {
        $valor = $dados[$nome] ?? '';

        return is_scalar($valor) ? trim((string) $valor) : '';
    }

    private function erro(string $mensagem, int $status): JsonResponse
    {
        return $this->json(['erro' => $mensagem], $status);
    }
}
