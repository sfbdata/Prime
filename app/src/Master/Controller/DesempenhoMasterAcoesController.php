<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Master\DTO\AbrirIncidenteInput;
use App\Master\DTO\RegistrarBackupInput;
use App\Master\DTO\ResolverIncidenteInput;
use App\Master\Exception\DesempenhoRecusado;
use App\Master\UseCase\AbrirIncidenteUseCase;
use App\Master\UseCase\LimparEventosDeDesempenhoUseCase;
use App\Master\UseCase\RegistrarBackupUseCase;
use App\Master\UseCase\ResolverIncidenteUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ações de gravação da seção "Desempenho" do Painel do Master (spec docs/specs/master-desempenho.md D3 e D6):
 * abrir e resolver incidente, registrar backup ou teste de restauração e limpar os eventos medidos. Só o Master;
 * token CSRF `master_desempenho` no header `X-CSRF-Token`. 422 para corpo inválido ou incidente recusado.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/master/desempenho')]
final class DesempenhoMasterAcoesController extends AbstractController
{
    private const TOKEN = 'master_desempenho';

    #[Route('/incidentes', name: 'master_desempenho_incidente_abrir', methods: ['POST'])]
    public function abrir(Request $request, AbrirIncidenteUseCase $useCase): JsonResponse
    {
        $barrada = $this->barrar($request);
        if ($barrada !== null) {
            return $barrada;
        }
        $input = AbrirIncidenteInput::doJson($this->ator(), $this->corpo($request));
        if ($input === null) {
            return $this->json(['erro' => 'Informe o título, a categoria e a gravidade do incidente.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['id' => $useCase->executar($input)->getId()], Response::HTTP_CREATED);
    }

    #[Route('/incidentes/{id}/resolver', name: 'master_desempenho_incidente_resolver', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resolver(int $id, Request $request, ResolverIncidenteUseCase $useCase): JsonResponse
    {
        $barrada = $this->barrar($request);
        if ($barrada !== null) {
            return $barrada;
        }

        try {
            $useCase->executar(ResolverIncidenteInput::doJson($this->ator(), $id, $this->corpo($request)));
        } catch (DesempenhoRecusado $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['id' => $id]);
    }

    #[Route('/backups', name: 'master_desempenho_backup_registrar', methods: ['POST'])]
    public function registrarBackup(Request $request, RegistrarBackupUseCase $useCase): JsonResponse
    {
        $barrada = $this->barrar($request);
        if ($barrada !== null) {
            return $barrada;
        }
        $input = RegistrarBackupInput::doJson($this->ator(), $this->corpo($request));
        if ($input === null) {
            return $this->json(['erro' => 'Informe se é backup ou teste de restauração e se funcionou.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['id' => $useCase->executar($input)->getId()], Response::HTTP_CREATED);
    }

    #[Route('/eventos/limpar', name: 'master_desempenho_eventos_limpar', methods: ['POST'])]
    public function limpar(Request $request, LimparEventosDeDesempenhoUseCase $useCase): JsonResponse
    {
        $barrada = $this->barrar($request);
        if ($barrada !== null) {
            return $barrada;
        }

        return $this->json(['apagados' => $useCase->executar()]);
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
