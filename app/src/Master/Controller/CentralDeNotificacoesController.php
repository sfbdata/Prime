<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Master\DTO\MarcarNaCentralInput;
use App\Master\Exception\RespostaRecusada;
use App\Master\UseCase\AbrirMensagemDaCentralUseCase;
use App\Master\UseCase\ListarCentralDoMasterUseCase;
use App\Master\UseCase\MarcarNaCentralUseCase;
use App\Master\UseCase\ResponderNaCentralUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A seção "Central de Notificações" do Painel do Master (desenho 05 VS 21.10.26; spec
 * master-central-de-notificacoes.md). Só o Master (D2). JSON, com o token CSRF `master_notificacoes` no header
 * `X-CSRF-Token` em todo POST. Abrir é POST porque marca a mensagem como lida.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/master/notificacoes')]
final class CentralDeNotificacoesController extends AbstractController
{
    public const TOKEN = 'master_notificacoes';
    private const ORIGEM = '[a-z_]{3,20}';

    #[Route('', name: 'master_notificacoes_listar', methods: ['GET'])]
    public function listar(Request $request, ListarCentralDoMasterUseCase $listar): JsonResponse
    {
        $caixa = $listar->executar(
            $this->master(),
            (string) $request->query->get('aba', 'rec'),
            mb_substr((string) $request->query->get('q', ''), 0, 200),
            max(1, $request->query->getInt('pagina', 1)),
        );

        return $this->json($caixa->emJson());
    }

    #[Route('/marcar', name: 'master_notificacoes_marcar', methods: ['POST'])]
    public function marcar(Request $request, MarcarNaCentralUseCase $marcar): JsonResponse
    {
        if (!$this->tokenValido($request)) {
            return $this->json(['erro' => 'Token CSRF inválido. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }
        $input = MarcarNaCentralInput::doCorpo($this->corpo($request));
        if ($input === null) {
            return $this->json(['erro' => 'Escolha as mensagens e a ação.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['marcadas' => $marcar->executar($this->master(), $input)]);
    }

    #[Route('/{origem}/{id}/abrir', name: 'master_notificacoes_abrir', requirements: ['origem' => self::ORIGEM, 'id' => '\d{1,10}'], methods: ['POST'])]
    public function abrir(string $origem, int $id, Request $request, AbrirMensagemDaCentralUseCase $abrir): JsonResponse
    {
        if (!$this->tokenValido($request)) {
            return $this->json(['erro' => 'Token CSRF inválido. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }
        $aberta = $abrir->executar($this->master(), $origem, $id);

        return $aberta === null
            ? $this->json(['erro' => 'Mensagem não encontrada.'], Response::HTTP_NOT_FOUND)
            : $this->json($aberta->emJson());
    }

    #[Route('/{origem}/{id}/responder', name: 'master_notificacoes_responder', requirements: ['origem' => self::ORIGEM, 'id' => '\d{1,10}'], methods: ['POST'])]
    public function responder(string $origem, int $id, Request $request, ResponderNaCentralUseCase $responder): JsonResponse
    {
        if (!$this->tokenValido($request)) {
            return $this->json(['erro' => 'Token CSRF inválido. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }
        $texto = $this->corpo($request)['texto'] ?? '';
        try {
            $resposta = $responder->executar($this->master(), $origem, $id, is_string($texto) ? $texto : '');
        } catch (RespostaRecusada $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($resposta->emJson());
    }

    private function master(): User
    {
        $usuario = $this->getUser();

        return $usuario instanceof User ? $usuario : throw $this->createAccessDeniedException();
    }

    private function tokenValido(Request $request): bool
    {
        return $this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''));
    }

    /** @return array<mixed> */
    private function corpo(Request $request): array
    {
        $corpo = json_decode($request->getContent(), true);

        return is_array($corpo) ? $corpo : [];
    }
}
