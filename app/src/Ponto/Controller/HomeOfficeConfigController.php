<?php

declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Repository\HomeOfficeConfigRepository;
use App\Repository\TenantRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Admin: configura a regra de home office de um colaborador em um escritório (tenant) — em quais dias
 * ele pode bater ponto fora do raio das sedes. Guards espelham o JornadaColaboradorController
 * (permissão admin.users.manage / super-admin, escopo pela URL, vínculo ativo obrigatório, CSRF).
 */
#[Route('/tenant/{tenantId}/user/{id}')]
final class HomeOfficeConfigController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly TenantRepository $tenantRepository,
        private readonly PermissionChecker $permissionChecker,
        private readonly HomeOfficeConfigRepository $configRepository,
    ) {
    }

    #[Route('/home-office', name: 'app_home_office_get', methods: ['GET'])]
    public function get(int $tenantId, User $user): JsonResponse
    {
        $urlTenant = $this->autorizar($tenantId, $user);
        $config = $this->configRepository->findByUserETenant($user, $urlTenant);

        return $this->json([
            'diasSemana'     => $config?->getDiasSemana() ?? [],
            'datasAvulsas'   => $config?->getDatasAvulsas() ?? [],
            'vigenciaInicio' => $config?->getVigenciaInicio()?->format('Y-m-d'),
            'vigenciaFim'    => $config?->getVigenciaFim()?->format('Y-m-d'),
            'ativo'          => $config?->isAtivo() ?? true,
        ]);
    }

    #[Route('/home-office', name: 'app_home_office_save', methods: ['POST'])]
    public function save(int $tenantId, User $user, Request $request): JsonResponse
    {
        $urlTenant = $this->autorizar($tenantId, $user);
        $this->validarCsrfAjax($request);

        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['erro' => 'Payload inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $dias = $this->normalizarDias($data['diasSemana'] ?? []);
        if ($dias === null) {
            return $this->json(['erro' => 'Dias da semana inválidos (use inteiros de 1 a 7).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $datas = $this->normalizarDatas($data['datasAvulsas'] ?? []);
        if ($datas === null) {
            return $this->json(['erro' => 'Datas avulsas inválidas (use o formato AAAA-MM-DD).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $inicio = $this->parseData($data['vigenciaInicio'] ?? null);
        $fim    = $this->parseData($data['vigenciaFim'] ?? null);
        if ($inicio === false || $fim === false) {
            return $this->json(['erro' => 'Vigência inválida (use o formato AAAA-MM-DD).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($inicio !== null && $fim !== null && $inicio > $fim) {
            return $this->json(['erro' => 'A data inicial da vigência não pode ser maior que a final.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $config = $this->configRepository->findByUserETenant($user, $urlTenant);
        if ($config === null) {
            $config = new HomeOfficeConfig();
            $config->setUser($user);
            $config->setTenant($urlTenant); // sempre o tenant da URL, nunca do payload
        }

        $config->setDiasSemana($dias);
        $config->setDatasAvulsas($datas);
        $config->setVigenciaInicio($inicio);
        $config->setVigenciaFim($fim);
        $config->setAtivo((bool) ($data['ativo'] ?? true));

        $this->configRepository->salvar($config, true);

        return $this->json(['ok' => true]);
    }

    #[Route('/home-office', name: 'app_home_office_delete', methods: ['DELETE'])]
    public function delete(int $tenantId, User $user, Request $request): JsonResponse
    {
        $urlTenant = $this->autorizar($tenantId, $user);
        $this->validarCsrfAjax($request);

        $config = $this->configRepository->findByUserETenant($user, $urlTenant);
        if ($config !== null) {
            $this->configRepository->remover($config, true);
        }

        return $this->json(['ok' => true]);
    }

    /**
     * Guards de autorização (permissão + escopo por tenant + vínculo). Retorna o tenant da URL.
     */
    private function autorizar(int $tenantId, User $user): Tenant
    {
        $currentUser = $this->getUser();
        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        $tenant       = $this->tenantContext->getCurrentTenant();
        $isOwnTenant  = $tenant?->getId() === $tenantId;

        if (!$isSuperAdmin && !($isOwnTenant && $this->permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para gerenciar home office.');
        }

        $urlTenant = $this->tenantRepository->find($tenantId);
        if ($urlTenant === null || !$this->userTenantRepository->existeVinculoAtivo($user, $urlTenant)) {
            throw $this->createNotFoundException('Usuário não pertence a este tenant.');
        }

        return $urlTenant;
    }

    /**
     * @return int[]|null Dias 1-7 sem repetição; null se algum valor for inválido.
     */
    private function normalizarDias(mixed $valor): ?array
    {
        if (!is_array($valor)) {
            return null;
        }

        $dias = [];
        foreach ($valor as $d) {
            if (!is_numeric($d)) {
                return null;
            }
            $dia = (int) $d;
            if ($dia < 1 || $dia > 7) {
                return null;
            }
            if (!in_array($dia, $dias, true)) {
                $dias[] = $dia;
            }
        }
        sort($dias);

        return $dias;
    }

    /**
     * @return string[]|null Datas 'Y-m-d' válidas, sem repetição e ordenadas; null se alguma for inválida.
     */
    private function normalizarDatas(mixed $valor): ?array
    {
        if (!is_array($valor)) {
            return null;
        }

        $datas = [];
        foreach ($valor as $d) {
            if (!is_string($d)) {
                return null;
            }
            $parsed = $this->parseData($d);
            if ($parsed === false || $parsed === null) {
                return null;
            }
            $str = $parsed->format('Y-m-d');
            if (!in_array($str, $datas, true)) {
                $datas[] = $str;
            }
        }
        sort($datas);

        return $datas;
    }

    /**
     * @return \DateTimeImmutable|false|null null se vazio, false se inválida, DateTimeImmutable se válida.
     */
    private function parseData(mixed $valor): \DateTimeImmutable|false|null
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (!is_string($valor)) {
            return false;
        }

        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            return false;
        }

        return $data;
    }
}
