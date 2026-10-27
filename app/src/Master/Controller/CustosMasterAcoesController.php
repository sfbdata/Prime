<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Master\DTO\LancarCustoInput;
use App\Master\Exception\CustoRecusado;
use App\Master\UseCase\CopiarCustosDoMesAnteriorUseCase;
use App\Master\UseCase\ExcluirLancamentoDeCustoUseCase;
use App\Master\UseCase\LancarCustoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ações de gravação da seção "Custos" do Painel do Master (spec docs/specs/master-custos.md): lançar, excluir e copiar
 * do mês anterior. Só o Master; token CSRF `master_custos` no header `X-CSRF-Token`. 422 com a mensagem do motivo.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/master/custos')]
final class CustosMasterAcoesController extends AbstractController
{
    private const TOKEN = 'master_custos';

    #[Route('/lancamentos', name: 'master_custos_lancar', methods: ['POST'])]
    public function lancar(Request $request, LancarCustoUseCase $useCase): JsonResponse
    {
        $barrada = $this->barrar($request);
        if ($barrada !== null) {
            return $barrada;
        }

        try {
            $lancamento = $useCase->executar(LancarCustoInput::doJson($this->ator(), $this->corpo($request)));
        } catch (CustoRecusado $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['id' => $lancamento->getId()], Response::HTTP_CREATED);
    }

    #[Route('/lancamentos/{id}/excluir', name: 'master_custos_excluir', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function excluir(int $id, Request $request, ExcluirLancamentoDeCustoUseCase $useCase): JsonResponse
    {
        $barrada = $this->barrar($request);
        if ($barrada !== null) {
            return $barrada;
        }

        try {
            $useCase->executar($id);
        } catch (CustoRecusado $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['id' => $id]);
    }

    #[Route('/copiar', name: 'master_custos_copiar', methods: ['POST'])]
    public function copiar(Request $request, CopiarCustosDoMesAnteriorUseCase $useCase): JsonResponse
    {
        $barrada = $this->barrar($request);
        if ($barrada !== null) {
            return $barrada;
        }
        $mes = $this->corpo($request)['mes'] ?? '';

        try {
            $copiados = $useCase->executar(is_string($mes) ? $mes : '', $this->ator());
        } catch (CustoRecusado $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['copiados' => $copiados], Response::HTTP_CREATED);
    }

    private function barrar(Request $request): ?JsonResponse
    {
        if ($this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return null;
        }

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
