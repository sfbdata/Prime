<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\UseCase\MudarFaseDaPastaUseCase;
use App\Pasta\UseCase\NomearEtapaDaPastaUseCase;
use App\Service\Tenant\TenantContext;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Andamento da pasta em fases (desenho 1.2.3, E12b): mudar de fase, finalizar, reativar. */
#[Route('/pasta')]
final class PastaAndamentoController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly MudarFaseDaPastaUseCase $mudarFase,
        private readonly NomearEtapaDaPastaUseCase $nomearEtapa,
    ) {
    }

    public static function idDoToken(int $pastaId): string
    {
        return 'pasta_andamento_' . $pastaId;
    }

    #[Route('/{id}/andamento', name: 'pasta_andamento_mudar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function mudar(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->isCsrfTokenValid(self::idDoToken((int) $pasta->getId()), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $texto = $this->mudarFase->executar(
                $pasta,
                $user,
                $tenant,
                $request->request->getInt('fase', -1),
                $request->request->getBoolean('sem_pular'),
                (string) $request->request->get('motivo', ''),
            );
        } catch (PastaDeOutroEscritorioException) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaEditarPastaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UniqueConstraintViolationException) {
            // Dois cliques ao mesmo tempo na primeira mudança: a outra requisição já gravou.
            return $this->json(['erro' => 'A fase acabou de ser alterada. Recarregue a página.'], Response::HTTP_CONFLICT);
        }

        return $this->json(['ok' => true, 'mudou' => $texto !== null, 'texto' => $texto]);
    }

    /** Nome da etapa entre duas fases (desenho 02 de 31/10/2026): texto vazio remove. */
    #[Route('/{id}/andamento/etapa', name: 'pasta_andamento_etapa', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function nomearEtapa(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->isCsrfTokenValid(self::idDoToken((int) $pasta->getId()), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $texto = $this->nomearEtapa->executar($pasta, $user, $tenant, $request->request->getInt('fase', -1), (string) $request->request->get('texto', ''));
        } catch (PastaDeOutroEscritorioException) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaEditarPastaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true, 'mudou' => $texto !== null]);
    }
}
