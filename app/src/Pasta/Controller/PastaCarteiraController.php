<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\Entity\CarteiraDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\UseCase\MoverPastaDeCarteiraUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Mover para outra carteira" do menu ⋮ da pasta (desenho 02 - EXPEDIENTES VS 18.10.26, `menuPasta('mover')`).
 *
 * Guardas: pasta do escritório da sessão (404; o resolver busca por PK e o TenantFilter não se aplica a `find()`),
 * CSRF por pasta (403), carteira da lista fixa (400) e permissão de EDITAR a pasta (403, no UseCase). Pasta excluída
 * recebe a recusa do PastaSomenteLeituraListener, como todo POST nela; o UseCase recusa de novo (422).
 */
#[Route('/pasta')]
final class PastaCarteiraController extends AbstractController
{
    public function __construct(
        private readonly MoverPastaDeCarteiraUseCase $moverDeCarteira,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/{id}/carteira', name: 'pasta_mover_carteira', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function mover(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->isCsrfTokenValid('pasta_carteira_' . $pasta->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }
        $carteira = CarteiraDaPasta::tryFrom((string) $request->request->get('carteira', ''));
        if ($carteira === null) {
            return $this->json(['erro' => 'Carteira inválida.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $mudou = $this->moverDeCarteira->executar($pasta, $usuario, $tenant, $carteira);
        } catch (PastaDeOutroEscritorioException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaEditarPastaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['sucesso' => true, 'mudou' => $mudou, 'carteira' => $carteira->value, 'rotulo' => $carteira->rotulo()]);
    }
}
