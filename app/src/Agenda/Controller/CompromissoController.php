<?php

declare(strict_types=1);

namespace App\Agenda\Controller;

use App\Agenda\DTO\CompromissoInput;
use App\Agenda\Exception\CompromissoNaoEncontradoException;
use App\Agenda\Exception\SemPermissaoNoCompromissoException;
use App\Agenda\UseCase\AlterarStatusDoCompromissoUseCase;
use App\Agenda\UseCase\AvisarNoChatUseCase;
use App\Agenda\UseCase\AvisarPrazoNoChatUseCase;
use App\Agenda\UseCase\ExcluirCompromissoUseCase;
use App\Agenda\UseCase\MoverCompromissoUseCase;
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
 * Ações do modal da Agenda nova: criar, editar, excluir e avisar no Chat (compromisso e prazo de pasta). JSON, CSRF no cabeçalho
 * X-CSRF-Token (injetado pelo base.html.twig).
 */
final class CompromissoController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly SalvarCompromissoUseCase $salvar,
        private readonly ExcluirCompromissoUseCase $excluir,
        private readonly AvisarNoChatUseCase $avisarNoChat,
        private readonly MoverCompromissoUseCase $mover,
        private readonly AlterarStatusDoCompromissoUseCase $alterarStatus,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly ValidatorInterface $validator,
        private readonly AvisarPrazoNoChatUseCase $avisarPrazoNoChat,
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

    /** Prazo ou audiência de pasta (desenho 09, L238): só para quem vê as pastas, como na grade. */
    #[Route('/agenda/prazos/{id}/avisar', name: 'app_agenda_prazo_avisar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function avisarPrazo(Request $request, int $id): JsonResponse
    {
        return $this->comAcesso($request, function (User $eu, Tenant $tenant) use ($request, $id): JsonResponse {
            if (!$this->permissionChecker->canAccessModule($eu, $tenant, 'pastas')) {
                throw new CompromissoNaoEncontradoException();
            }
            $ids = CompromissoInput::doJson(['avisados' => $request->toArray()['ids'] ?? []])->avisados;

            return $this->json(['ok' => true, 'avisados' => $this->avisarPrazoNoChat->executar($id, $ids, $eu, $tenant)]);
        });
    }

    /** Arrastar e soltar na grade: {data: AAAA-MM-DD, hora?: HH:MM}. */
    #[Route('/agenda/compromissos/{id}/mover', name: 'app_agenda_compromisso_mover', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moverPara(Request $request, int $id): JsonResponse
    {
        return $this->comAcesso($request, function (User $eu, Tenant $tenant) use ($request, $id): JsonResponse {
            $j = $request->toArray();
            $dia = is_string($j['data'] ?? null) ? $j['data'] : '';
            $hora = is_string($j['hora'] ?? null) && '' !== $j['hora'] ? $j['hora'] : null;
            $lido = \DateTimeImmutable::createFromFormat('!Y-m-d', $dia);
            $diaOk = false !== $lido && $lido->format('Y-m-d') === $dia;
            if (!$diaOk || (null !== $hora && 1 !== preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora))) {
                return $this->json(['erro' => 'Dia ou hora inválidos.'], 422);
            }

            return $this->json(['ok' => true, 'data' => $this->mover->executar($id, $dia, $hora, $eu, $tenant)]);
        });
    }

    /** Concluir, cancelar (avisa os participantes) ou reabrir: {status: agendado|concluido|cancelado}. */
    #[Route('/agenda/compromissos/{id}/status', name: 'app_agenda_compromisso_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function status(Request $request, int $id): JsonResponse
    {
        return $this->comAcesso($request, function (User $eu, Tenant $tenant) use ($request, $id): JsonResponse {
            $status = $request->toArray()['status'] ?? '';
            $this->alterarStatus->executar($id, is_string($status) ? $status : '', $eu, $tenant);

            return $this->json(['ok' => true]);
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
