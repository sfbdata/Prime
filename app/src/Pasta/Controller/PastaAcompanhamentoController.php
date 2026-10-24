<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\UseCase\AlternarAcompanhamentoDaPastaUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Acompanhar alterações" do menu ⋮ da pasta (desenho "02 - EXPEDIENTES - VS 18.10.26",
 * `prefPasta('seguir')`).
 *
 * Guardas: pasta do escritório da sessão (404; o resolver busca por PK e o TenantFilter não se
 * aplica a `find()`), permissão de VER a pasta (403), ambas no UseCase, e CSRF por pasta (403),
 * porque é escrita. Pasta excluída (lápide) recebe a recusa do PastaSomenteLeituraListener, como
 * o favorito; com o cadeado em Visualizar a rota passa (preferência pessoal, na lista
 * `BarreiraDoCadeadoListener::ROTAS_DO_VISUALIZAR`); em Oculto, a barreira recusa.
 */
#[Route('/pasta')]
final class PastaAcompanhamentoController extends AbstractController
{
    public function __construct(
        private readonly AlternarAcompanhamentoDaPastaUseCase $alternarAcompanhamento,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route(
        '/{id}/acompanhar',
        name: 'pasta_acompanhamento_alternar',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function alternar(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('pasta_acompanhar_' . $pasta->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $acompanha = $this->alternarAcompanhamento->executar($pasta, $usuario, $tenant);
        } catch (PastaDeOutroEscritorioException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaVerPastaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['acompanha' => $acompanha]);
    }
}
