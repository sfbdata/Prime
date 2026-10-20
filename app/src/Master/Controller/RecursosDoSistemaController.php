<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Master\DTO\AlternarRecursoInput;
use App\Master\Exception\RecursoRecusado;
use App\Master\UseCase\AlternarRecursoUseCase;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Aba "Telas e recursos" de /master (spec docs/specs/perfis-1.2-telas-e-recursos.md §3): liga e
 * desliga uma chave do catálogo para todas as contas. Só o Master (R7): ROLE_SUPER_ADMIN na classe.
 *
 * Corpo JSON `{ativo: bool, motivo?: string, ate?: "AAAA-MM-DD"}`; token CSRF `master_recursos` no
 * header `X-CSRF-Token` (o interceptador global do base.html.twig não troca um header já posto).
 * Responde o estado novo; 422 para chave desconhecida, tela essencial ou corpo sem `ativo`.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class RecursosDoSistemaController extends AbstractController
{
    #[Route('/master/recursos/{chave}', name: 'master_recurso_alternar', requirements: ['chave' => '[a-z]{1,40}'], methods: ['POST'])]
    public function alternar(string $chave, Request $request, AlternarRecursoUseCase $alternar, ClockInterface $relogio): JsonResponse
    {
        if (!$this->isCsrfTokenValid('master_recursos', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['erro' => 'Token CSRF inválido. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        $ator = $this->getUser();
        $input = AlternarRecursoInput::doJson($ator instanceof User ? $ator : null, $chave, $this->corpo($request), $relogio->now());
        if ($input === null) {
            return $this->json(['erro' => 'Informe se a tela fica ativa ou desativada.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            return $this->json($alternar->executar($input)->paraJson());
        } catch (RecursoRecusado $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /** @return array<mixed> */
    private function corpo(Request $request): array
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($dados) ? $dados : [];
    }
}
