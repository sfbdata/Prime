<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Conector\DTO\EscritorioNoConectorOutput;
use App\Conector\UseCase\AlterarLiberacaoDoConectorPeloMasterUseCase;
use App\Conector\UseCase\ObterConectorNoPainelDoMasterUseCase;
use App\Entity\Auth\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O conector de I.A. na aba "Configurações da I.A." do Painel do Master (`docs/specs/mcp-remoto-oauth.md`, 14.a nível 2
 * e 14.e: sem desenho, pela convenção do sistema). Mora no domínio Master, como o {@see IaDoMasterController}: a rota e
 * a guarda são do painel; as regras moram nos UseCases do domínio Conector, que conferem o Master de novo.
 *
 * JSON. O POST leva o token CSRF da aba (o mesmo `master_ia` do {@see IaDoMasterController}, a aba é uma só) no header
 * `X-CSRF-Token` e responde o mesmo corpo do GET, já com o estado novo; recusa em 422 com `{erro}` (403 no CSRF, 404 no
 * escritório que não existe ou não está ativo).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class ConectorDoMasterController extends AbstractController
{
    public function __construct(
        private readonly ObterConectorNoPainelDoMasterUseCase $painel,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/master/conector', name: 'master_conector_dados', methods: ['GET'])]
    public function dados(): JsonResponse
    {
        return $this->json($this->estado());
    }

    /** Liberar ou bloquear um escritório. Corpo `{liberado: bool}`. */
    #[Route('/master/conector/escritorios/{tenantId}', name: 'master_conector_escritorio', requirements: ['tenantId' => '\d+'], methods: ['POST'])]
    public function escritorio(int $tenantId, Request $request, AlterarLiberacaoDoConectorPeloMasterUseCase $alterar): JsonResponse
    {
        if (!$this->isCsrfTokenValid(IaDoMasterController::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->recusa('conector.master.recusa.token_invalido', Response::HTTP_FORBIDDEN);
        }
        $liberado = self::corpo($request)['liberado'] ?? null;
        if (!is_bool($liberado)) {
            return $this->recusa('conector.master.recusa.informe_liberado', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $alterar->executar($tenantId, $liberado, $this->master());
        } catch (\DomainException $e) {
            return $this->recusa($e->getMessage(), Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->estado());
    }

    /** @return array{escritorios: list<array<string, mixed>>} */
    private function estado(): array
    {
        return ['escritorios' => array_map(self::escritorioEmJson(...), $this->painel->executar($this->master()))];
    }

    /** @return array<string, mixed> */
    private static function escritorioEmJson(EscritorioNoConectorOutput $e): array
    {
        return [
            'tenantId' => $e->tenantId,
            'nome' => $e->nome,
            'liberado' => $e->liberado,
            'ligado' => $e->ligado,
            'aceiteEm' => $e->aceiteEm?->format('Y-m-d'),
            'aceiteVigente' => $e->aceiteVigente,
            'conexoesAtivas' => $e->conexoesAtivas,
        ];
    }

    private function recusa(string $chave, int $status): JsonResponse
    {
        return $this->json(['erro' => $this->tradutor->trans($chave)], $status);
    }

    private function master(): User
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $usuario;
    }

    /** @return array<mixed> */
    private static function corpo(Request $request): array
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($dados) ? $dados : [];
    }
}
