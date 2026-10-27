<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Master\DTO\AlterarMetaDeDivulgacaoInput;
use App\Master\Exception\MetaRecusada;
use App\Master\UseCase\AlterarMetaDeDivulgacaoUseCase;
use App\Master\UseCase\ConsultarMetasDeDivulgacaoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bloco "Metas de divulgação" da Visão geral do /master (desenho 05 Perfis 1.2 VS 25.10.26; spec
 * docs/specs/perfis-1.2-vs-25-10-metas-mapa-config.md, P3). Só o Master: ROLE_SUPER_ADMIN na classe.
 *
 * Token CSRF `master_metas_divulgacao` no header `X-CSRF-Token`. As duas rotas respondem o estado
 * ({@see \App\Master\DTO\MetasDeDivulgacaoOutput::paraArray()}); 422 para pedido recusado.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class MetasDeDivulgacaoController extends AbstractController
{
    public const CSRF = 'master_metas_divulgacao';

    #[Route('/master/metas-divulgacao', name: 'master_metas_divulgacao_dados', methods: ['GET'])]
    public function dados(ConsultarMetasDeDivulgacaoUseCase $consultar): JsonResponse
    {
        return $this->json($consultar->executar()->paraArray());
    }

    #[Route('/master/metas-divulgacao', name: 'master_metas_divulgacao_alterar', methods: ['POST'])]
    public function alterar(Request $request, AlterarMetaDeDivulgacaoUseCase $alterar, ConsultarMetasDeDivulgacaoUseCase $consultar): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['erro' => 'Token CSRF inválido. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $alterar->executar(AlterarMetaDeDivulgacaoInput::doJson($this->ator(), $this->corpo($request)));
        } catch (MetaRecusada $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($consultar->executar()->paraArray());
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
