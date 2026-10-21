<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Master\DTO\AlternarTemaInput;
use App\Master\DTO\DefinirModoDoTemaInput;
use App\Master\Exception\TemaRecusado;
use App\Master\Service\TemaDoDia;
use App\Master\UseCase\AlternarTemaComemorativoUseCase;
use App\Master\UseCase\DefinirModoDoTemaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Card "Temas comemorativos" da aba "Telas e recursos" de /master (spec docs/specs/temas-comemorativos.md).
 * Só o Master: ROLE_SUPER_ADMIN na classe.
 *
 * Token CSRF `master_temas` no header `X-CSRF-Token` (o interceptador global do base.html.twig não troca um header
 * já posto). As duas rotas respondem o estado novo ({@see TemaDoDia::estado()}), que redesenha o status e a grade;
 * 422 para modo inválido, tema desconhecido ou corpo sem `ligado`.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class TemasComemorativosController extends AbstractController
{
    public const CSRF = 'master_temas';

    /** Corpo `{modo: "automatico"|"manual"|"desligado", manual?: id do tema}`. */
    #[Route('/master/temas/modo', name: 'master_temas_modo', methods: ['POST'])]
    public function modo(Request $request, DefinirModoDoTemaUseCase $definir, TemaDoDia $temaDoDia): JsonResponse
    {
        if (!$this->tokenValido($request)) {
            return $this->tokenInvalido();
        }

        try {
            $config = $definir->executar(DefinirModoDoTemaInput::doJson($this->ator(), $this->corpo($request)));
        } catch (TemaRecusado $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($temaDoDia->estado($config));
    }

    /** Corpo `{ligado: bool}`. */
    #[Route('/master/temas/{id}/ligado', name: 'master_tema_ligar', requirements: ['id' => '[a-z]{1,20}'], methods: ['POST'])]
    public function ligar(string $id, Request $request, AlternarTemaComemorativoUseCase $alternar, TemaDoDia $temaDoDia): JsonResponse
    {
        if (!$this->tokenValido($request)) {
            return $this->tokenInvalido();
        }

        $input = AlternarTemaInput::doJson($this->ator(), $id, $this->corpo($request));
        if ($input === null) {
            return $this->json(['erro' => 'Informe se o tema fica ligado ou desligado.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            return $this->json($temaDoDia->estado($alternar->executar($input)));
        } catch (TemaRecusado $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function tokenValido(Request $request): bool
    {
        return $this->isCsrfTokenValid(self::CSRF, (string) $request->headers->get('X-CSRF-Token', ''));
    }

    private function tokenInvalido(): JsonResponse
    {
        return $this->json(['erro' => 'Token CSRF inválido. Recarregue a página.'], Response::HTTP_FORBIDDEN);
    }

    private function ator(): ?User
    {
        $ator = $this->getUser();

        return $ator instanceof User ? $ator : null;
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
