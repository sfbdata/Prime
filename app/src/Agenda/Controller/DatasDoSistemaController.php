<?php

declare(strict_types=1);

namespace App\Agenda\Controller;

use App\Agenda\DTO\DataDoSistemaOutput;
use App\Agenda\UseCase\ListarDatasDoSistemaUseCase;
use App\Entity\Auth\User;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Datas que a Agenda mostra sem cadastro (feriados, recesso, tribunais, comemorativas), já
 * cruzadas com os feriados do Ponto do escritório. Só leitura.
 */
final class DatasDoSistemaController extends AbstractController
{
    private const MAX_DIAS = 400;

    public function __construct(
        private readonly ListarDatasDoSistemaUseCase $listarDatas,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/agenda/datas', name: 'app_agenda_datas', methods: ['GET'])]
    public function listar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (null === $tenant || !$this->permissionChecker->canAccessModule($user, $tenant, 'agenda')) {
            return $this->json(['erro' => 'Sem permissão'], 403);
        }
        $de = $this->data($request->query->getString('de'));
        $ate = $this->data($request->query->getString('ate'));
        if (null === $de || null === $ate || $ate < $de || $de->diff($ate)->days > self::MAX_DIAS) {
            return $this->json(['erro' => 'Informe de e ate (AAAA-MM-DD), com até 400 dias de intervalo.'], 400);
        }
        $lotacao = $this->tenantContext->getCurrentUserTenant()?->getLotacao()?->getNome();
        $r = $this->listarDatas->executar($tenant, $lotacao, $de, $ate);

        return $this->json([
            'podeAjustar' => $this->permissionChecker->canAdminister($user, $tenant, 'admin.tenant.settings.manage'),
            'temAjustes'  => $r['temAjustes'],
            'ufs'   => $r['ufs'],
            'datas' => array_map(static fn (DataDoSistemaOutput $d): array => $d->toArray(), $r['datas']),
        ]);
    }

    private function data(string $valor): ?\DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return false !== $d && $d->format('Y-m-d') === $valor ? $d : null;
    }
}
