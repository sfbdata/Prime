<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\UseCase\CumprirPrazoDaPastaUseCase;
use App\Pasta\UseCase\SalvarPrazoDaPastaUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Prazos e audiências registrados à mão no trilho da pasta (desenho 1.2.3, "Próximos prazos"): salvar (novo ou
 * edição), cumprir e excluir. Token CSRF único da pasta (`pasta_prazos_<id>`). A pasta excluída (lápide) é
 * recusada pelo PastaSomenteLeituraListener, que vê a `Pasta` nos argumentos.
 */
#[Route('/pasta')]
final class PastaPrazoController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SalvarPrazoDaPastaUseCase $salvar,
        private readonly CumprirPrazoDaPastaUseCase $encerrar,
    ) {
    }

    public static function idDoToken(int $pastaId): string
    {
        return 'pasta_prazos_' . $pastaId;
    }

    #[Route('/{id}/prazos', name: 'pasta_prazo_salvar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function salvar(Pasta $pasta, Request $request): JsonResponse
    {
        return $this->responder($pasta, $request, function (User $user) use ($pasta, $request): array {
            $id = (string) $request->request->get('prazo_id', '');
            $prazo = $this->salvar->executar(
                $pasta,
                $user,
                $this->tenantContext->getCurrentTenant(),
                $id === '' ? null : (int) $id,
                (string) $request->request->get('tipo', 'prazo'),
                (string) $request->request->get('titulo', ''),
                (string) $request->request->get('data', ''),
                (string) $request->request->get('hora', ''),
                (string) $request->request->get('responsavel', ''),
            );

            return ['id' => $prazo->getId(), 'tipo' => $prazo->getTipo(), 'aviso' => $prazo->isAudiencia() ? 'Audiência registrada' : 'Prazo registrado'];
        });
    }

    #[Route('/{id}/prazos/{prazoId}/cumprir', name: 'pasta_prazo_cumprir', requirements: ['id' => '\d+', 'prazoId' => '\d+'], methods: ['POST'])]
    public function cumprir(Pasta $pasta, int $prazoId, Request $request): JsonResponse
    {
        return $this->responder($pasta, $request, function (User $user) use ($pasta, $prazoId): array {
            $this->encerrar->executar($pasta, $prazoId, $user, $this->tenantContext->getCurrentTenant());

            return ['aviso' => 'Prazo marcado como cumprido'];
        });
    }

    #[Route('/{id}/prazos/{prazoId}/excluir', name: 'pasta_prazo_excluir', requirements: ['id' => '\d+', 'prazoId' => '\d+'], methods: ['POST'])]
    public function excluir(Pasta $pasta, int $prazoId, Request $request): JsonResponse
    {
        return $this->responder($pasta, $request, function (User $user) use ($pasta, $prazoId): array {
            $this->encerrar->executar($pasta, $prazoId, $user, $this->tenantContext->getCurrentTenant(), excluir: true);

            return ['aviso' => 'Prazo excluído'];
        });
    }

    /** @param callable(User): array<string, mixed> $acao */
    private function responder(Pasta $pasta, Request $request, callable $acao): JsonResponse
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
            return $this->json(['ok' => true] + $acao($user));
        } catch (PastaDeOutroEscritorioException) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaEditarPastaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
