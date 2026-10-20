<?php

declare(strict_types=1);

namespace App\Agenda\Controller;

use App\Agenda\DTO\CompromissoInput;
use App\Agenda\Exception\CompromissoNaoEncontradoException;
use App\Agenda\Exception\SemPermissaoNoCompromissoException;
use App\Agenda\UseCase\AvisarNoChatUseCase;
use App\Agenda\UseCase\ExcluirCompromissoUseCase;
use App\Agenda\UseCase\SalvarCompromissoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Ações do modal da Agenda nova: criar, editar, excluir e avisar no Chat. JSON, CSRF no cabeçalho
 * X-CSRF-Token (injetado pelo base.html.twig).
 */
final class CompromissoController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly SalvarCompromissoUseCase $salvar,
        private readonly ExcluirCompromissoUseCase $excluir,
        private readonly AvisarNoChatUseCase $avisarNoChat,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/agenda/compromissos/novo', name: 'app_agenda_compromisso_criar', methods: ['POST'])]
    public function criar(Request $request): JsonResponse
    {
        return $this->gravar($request, null);
    }

    #[Route('/agenda/compromissos/{id}/salvar', name: 'app_agenda_compromisso_salvar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function editar(Request $request, int $id): JsonResponse
    {
        return $this->gravar($request, $id);
    }

    #[Route('/agenda/compromissos/{id}/excluir', name: 'app_agenda_compromisso_excluir', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function excluir(Request $request, int $id): JsonResponse
    {
        return $this->comAcesso($request, function (User $eu, Tenant $tenant) use ($id): JsonResponse {
            $this->excluir->executar($id, $eu, $tenant);

            return $this->json(['ok' => true]);
        });
    }

    #[Route('/agenda/compromissos/{id}/avisar', name: 'app_agenda_compromisso_avisar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function avisar(Request $request, int $id): JsonResponse
    {
        return $this->comAcesso($request, function (User $eu, Tenant $tenant) use ($request, $id): JsonResponse {
            $ids = CompromissoInput::doJson(['avisados' => $request->toArray()['ids'] ?? []])->avisados;

            return $this->json(['ok' => true, 'avisados' => $this->avisarNoChat->executar($id, $ids, $eu, $tenant)]);
        });
    }

    private function gravar(Request $request, ?int $id): JsonResponse
    {
        return $this->comAcesso($request, function (User $eu, Tenant $tenant) use ($request, $id): JsonResponse {
            $input = CompromissoInput::doJson($request->toArray());
            $erros = $this->validator->validate($input);
            if (count($erros) > 0) {
                return $this->json(['erro' => (string) $erros->get(0)->getMessage()], 422);
            }
            $verPastas = $this->permissionChecker->canAccessModule($eu, $tenant, 'pastas');
            $salvo = $this->salvar->executar($input, $id, $eu, $tenant, $verPastas);

            return $this->json(['ok' => true, 'id' => $salvo->id, 'data' => $salvo->data, 'avisados' => $salvo->avisados]);
        });
    }

    /** Guarda comum: módulo agenda, CSRF e tradução das exceções do domínio para HTTP. */
    private function comAcesso(Request $request, callable $acao): JsonResponse
    {
        /** @var User $eu */
        $eu = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (null === $tenant || !$this->permissionChecker->canAccessModule($eu, $tenant, 'agenda')) {
            return $this->json(['erro' => 'Sem permissão'], 403);
        }
        $this->validarCsrfAjax($request);
        try {
            return $acao($eu, $tenant);
        } catch (CompromissoNaoEncontradoException $e) {
            return $this->json(['erro' => $e->getMessage()], 404);
        } catch (SemPermissaoNoCompromissoException $e) {
            return $this->json(['erro' => $e->getMessage()], 403);
        } catch (\DomainException|JsonException $e) {
            return $this->json(['erro' => $e->getMessage()], 422);
        }
    }
}
