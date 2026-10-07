<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\DTO\MencionavelOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\UseCase\ListarMencionaveisDaPastaUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Autocompletar do "@" no Registro da pasta (item 20b). GET de leitura, sem CSRF.
 *
 * Guardas (no UseCase): pasta do escritório da sessão (404 — o resolver busca por PK e o
 * TenantFilter não se aplica a `find()`) e permissão de VER a pasta (403). A resposta traz id,
 * nome, iniciais, cargo e a URL da foto — nunca o e-mail.
 */
#[Route('/pasta')]
final class PastaMencaoController extends AbstractController
{
    public function __construct(
        private readonly ListarMencionaveisDaPastaUseCase $listarMencionaveis,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route(
        '/{id}/mencionaveis',
        name: 'pasta_mencionaveis',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function listar(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $pessoas = $this->listarMencionaveis->executar($pasta, $usuario, $tenant, (string) $request->query->get('q', ''));
        } catch (PastaDeOutroEscritorioException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaVerPastaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return $this->json(array_map(fn (MencionavelOutput $p): array => $p->paraArray() + [
            'foto' => $p->foto !== null && $p->foto !== '' ? $this->generateUrl('app_profile_foto_serve', ['nome' => $p->foto]) : null,
        ], $pessoas));
    }
}
