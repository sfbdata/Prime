<?php

declare(strict_types=1);

namespace App\Agenda\Controller;

use App\Agenda\UseCase\AcrescentarDataUseCase;
use App\Agenda\UseCase\OcultarDataUseCase;
use App\Agenda\UseCase\RenomearDataUseCase;
use App\Agenda\UseCase\RestaurarDatasDoSistemaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Botão direito da Agenda nas datas do sistema: acrescentar, editar nome, ocultar e restaurar. Vale para
 * a Agenda do escritório inteiro e exige a permissão de Feriados e Folgas (admin.tenant.settings.manage,
 * convenção D2 da spec). Nada aqui toca a tabela `feriado` do Ponto.
 */
final class AjusteDeDataController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly AcrescentarDataUseCase $acrescentar,
        private readonly RenomearDataUseCase $renomear,
        private readonly OcultarDataUseCase $ocultar,
        private readonly RestaurarDatasDoSistemaUseCase $restaurar,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/agenda/datas/acrescentar', name: 'app_agenda_data_acrescentar', methods: ['POST'])]
    public function acrescentar(Request $request): JsonResponse
    {
        return $this->comPermissao($request, function (User $eu, Tenant $tenant, array $j): JsonResponse {
            $id = $this->acrescentar->executar($tenant, $eu, $this->texto($j, 'data'), $this->texto($j, 'titulo'), $this->texto($j, 'calendario'), $this->texto($j, 'fonte'));

            return $this->json(['ok' => true, 'id' => 'x-' . $id]);
        });
    }

    #[Route('/agenda/datas/renomear', name: 'app_agenda_data_renomear', methods: ['POST'])]
    public function renomear(Request $request): JsonResponse
    {
        return $this->comPermissao($request, function (User $eu, Tenant $tenant, array $j): JsonResponse {
            $this->renomear->executar($tenant, $eu, $this->texto($j, 'id'), $this->texto($j, 'titulo'));

            return $this->json(['ok' => true]);
        });
    }

    #[Route('/agenda/datas/ocultar', name: 'app_agenda_data_ocultar', methods: ['POST'])]
    public function ocultar(Request $request): JsonResponse
    {
        return $this->comPermissao($request, function (User $eu, Tenant $tenant, array $j): JsonResponse {
            $this->ocultar->executar($tenant, $eu, $this->texto($j, 'id'));

            return $this->json(['ok' => true]);
        });
    }

    #[Route('/agenda/datas/restaurar', name: 'app_agenda_data_restaurar', methods: ['POST'])]
    public function restaurar(Request $request): JsonResponse
    {
        return $this->comPermissao($request, function (User $eu, Tenant $tenant): JsonResponse {
            return $this->json(['ok' => true, 'restauradas' => $this->restaurar->executar($tenant)]);
        });
    }

    /** Guarda comum: módulo agenda, permissão de Feriados e Folgas, CSRF e erros de regra em 422. */
    private function comPermissao(Request $request, callable $acao): JsonResponse
    {
        /** @var User $eu */
        $eu = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (null === $tenant || !$this->permissionChecker->canAccessModule($eu, $tenant, 'agenda')
            || !$this->permissionChecker->canAdminister($eu, $tenant, 'admin.tenant.settings.manage')) {
            return $this->json(['erro' => 'Só quem cuida das configurações do escritório ajusta as datas da Agenda.'], 403);
        }
        $this->validarCsrfAjax($request);
        try {
            return $acao($eu, $tenant, '' === $request->getContent() ? [] : $request->toArray());
        } catch (\DomainException|JsonException $e) {
            return $this->json(['erro' => $e->getMessage()], 422);
        } catch (UniqueConstraintViolationException) {
            // Dois cliques ao mesmo tempo na mesma data: o primeiro gravou e o índice único barrou o
            // segundo. O dado já está como a pessoa pediu.
            return $this->json(['ok' => true]);
        }
    }

    /** @param array<string, mixed> $j */
    private function texto(array $j, string $chave): string
    {
        return is_scalar($j[$chave] ?? null) ? (string) $j[$chave] : '';
    }
}
