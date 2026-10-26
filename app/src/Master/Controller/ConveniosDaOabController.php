<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Master\DTO\AlterarConvenioDaUfInput;
use App\Master\Exception\ConvenioRecusado;
use App\Master\UseCase\AlterarConvenioDaUfUseCase;
use App\Master\UseCase\ConsultarConveniosDaOabUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Painel "Convênio OAB por estado" da seção Contas do /master (desenho 05 Perfis 1.2 VS 25.10.26; spec
 * docs/specs/perfis-1.2-vs-25-10-metas-mapa-config.md, P4b). Só o Master: ROLE_SUPER_ADMIN na classe.
 *
 * Token CSRF `master_convenios_oab` no header `X-CSRF-Token`. As duas rotas respondem o estado dos 27 estados
 * ({@see \App\Master\DTO\ConveniosDaOabOutput::paraArray()}); 422 para pedido recusado.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class ConveniosDaOabController extends AbstractController
{
    public const CSRF = 'master_convenios_oab';

    #[Route('/master/convenios', name: 'master_convenios_dados', methods: ['GET'])]
    public function dados(ConsultarConveniosDaOabUseCase $consultar): JsonResponse
    {
        return $this->json($consultar->executar()->paraArray());
    }

    #[Route('/master/convenios', name: 'master_convenios_alterar', methods: ['POST'])]
    public function alterar(Request $request, AlterarConvenioDaUfUseCase $alterar, ConsultarConveniosDaOabUseCase $consultar): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['erro' => 'Token CSRF inválido. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $alterar->executar(AlterarConvenioDaUfInput::doJson($this->ator(), $this->corpo($request)));
        } catch (ConvenioRecusado $e) {
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
