<?php
declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Ponto\Entity\BlocoJornada;
use App\Ponto\Entity\JornadaTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\JornadaTenantRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/tenant')]
final class JornadaTenantController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    #[Route('/{id}/jornada', name: 'app_jornada_tenant_get', methods: ['GET'])]
    public function get(
        Tenant $tenant,
        PermissionChecker $permissionChecker,
        JornadaTenantRepository $jornadaTenantRepository
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
        $tenantCtx    = $this->tenantContext->getCurrentTenant();
        $isOwnTenant  = $tenantCtx?->getId() === $tenant->getId();

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($user, $tenantCtx, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para configurar jornada.');
        }

        $jornada = $jornadaTenantRepository->findOneBy(['tenant' => $tenant]);

        if ($jornada === null) {
            return $this->json([
                'cargaHorariaSemanal'             => 2400,
                'alertaHabilitado'                => true,
                'validacaoRepousoHabilitada'      => true,
                'minimoMinutosRepouso'            => 60,
                'validacaoInterjornadaHabilitada' => true,
                'minimoMinutosInterjornada'       => 660,
                'blocos'                          => [],
            ]);
        }

        return $this->json([
            'cargaHorariaSemanal'             => $jornada->getCargaHorariaSemanal(),
            'alertaHabilitado'                => $jornada->isAlertaHabilitado(),
            'validacaoRepousoHabilitada'      => $jornada->isValidacaoRepousoHabilitada(),
            'minimoMinutosRepouso'            => $jornada->getMinimoMinutosRepouso(),
            'validacaoInterjornadaHabilitada' => $jornada->isValidacaoInterjornadaHabilitada(),
            'minimoMinutosInterjornada'       => $jornada->getMinimoMinutosInterjornada(),
            'blocos'                          => array_map(
                fn(BlocoJornada $b) => [
                    'diasSemana'   => $b->getDiasSemana(),
                    'entrada'      => $b->getEntrada(),
                    'repouso'      => $b->getRepouso(),
                    'retorno'      => $b->getRetorno(),
                    'saida'        => $b->getSaida(),
                    'minutosBloco' => $b->getMinutosBloco(),
                ],
                $jornada->getBlocos()->toArray()
            ),
        ]);
    }

    #[Route('/{id}/jornada', name: 'app_jornada_tenant_save', methods: ['POST'])]
    public function save(
        Tenant $tenant,
        Request $request,
        PermissionChecker $permissionChecker,
        JornadaTenantRepository $jornadaTenantRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
        $tenantCtx    = $this->tenantContext->getCurrentTenant();
        $isOwnTenant  = $tenantCtx?->getId() === $tenant->getId();

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($user, $tenantCtx, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para configurar jornada.');
        }

        $data = json_decode((string) $request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['erro' => 'Payload inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $carga              = (int) ($data['cargaHorariaSemanal'] ?? 0);
        $alerta             = (bool) ($data['alertaHabilitado'] ?? true);
        $blocos             = $data['blocos'] ?? [];
        $validaRepouso      = (bool) ($data['validacaoRepousoHabilitada'] ?? true);
        $minimoRepouso      = (int) ($data['minimoMinutosRepouso'] ?? 60);
        $validaInterjornada = (bool) ($data['validacaoInterjornadaHabilitada'] ?? true);
        $minimoInterjornada = (int) ($data['minimoMinutosInterjornada'] ?? 660);

        if ($carga < 60 || $carga > 2640) {
            return $this->json(['erro' => 'Carga horária semanal deve estar entre 1h e 44h.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($minimoRepouso < 1 || $minimoRepouso > 480) {
            return $this->json(['erro' => 'Intervalo mínimo de repouso deve estar entre 1 e 480 minutos.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($minimoInterjornada < 60 || $minimoInterjornada > 1440) {
            return $this->json(['erro' => 'Interjornada mínima deve estar entre 1 e 24 horas.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!is_array($blocos) || count($blocos) > 7) {
            return $this->json(['erro' => 'Máximo de 7 blocos de jornada.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $erros = $this->validarBlocos($blocos, $carga);
        if ($erros !== null) {
            return $this->json(['erro' => $erros], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $jornada = $jornadaTenantRepository->findOneBy(['tenant' => $tenant]);

        if ($jornada === null) {
            $jornada = new JornadaTenant();
            $jornada->setTenant($tenant);
            $entityManager->persist($jornada);
        }

        $jornada->setCargaHorariaSemanal($carga);
        $jornada->setAlertaHabilitado($alerta);
        $jornada->setValidacaoRepousoHabilitada($validaRepouso);
        $jornada->setMinimoMinutosRepouso($minimoRepouso);
        $jornada->setValidacaoInterjornadaHabilitada($validaInterjornada);
        $jornada->setMinimoMinutosInterjornada($minimoInterjornada);

        // orphanRemoval cuida da deleção dos blocos antigos
        foreach ($jornada->getBlocos()->toArray() as $blocoAntigo) {
            $jornada->removeBloco($blocoAntigo);
        }

        foreach ($blocos as $blocoData) {
            $bloco = new BlocoJornada();
            $bloco->setDiasSemana(array_map('intval', (array) ($blocoData['diasSemana'] ?? [])));
            $bloco->setEntrada((string) ($blocoData['entrada'] ?? '09:00'));
            $bloco->setRepouso($blocoData['repouso'] !== '' ? (string) ($blocoData['repouso'] ?? null) : null);
            $bloco->setRetorno($blocoData['retorno'] !== '' ? (string) ($blocoData['retorno'] ?? null) : null);
            $bloco->setSaida((string) ($blocoData['saida'] ?? '18:00'));
            $bloco->setMinutosBloco($this->calcularMinutosBloco($bloco));
            $jornada->addBloco($bloco);
        }

        $entityManager->flush();

        return $this->json(['ok' => true]);
    }

    private function validarBlocos(array $blocos, int $cargaSemanal): ?string
    {
        $diasUsados = [];
        $totalMinutos = 0;

        foreach ($blocos as $i => $b) {
            $dias = array_map('intval', (array) ($b['diasSemana'] ?? []));

            if (empty($dias)) {
                return sprintf('Bloco %d não tem dias da semana definidos.', $i + 1);
            }

            foreach ($dias as $dia) {
                if ($dia < 1 || $dia > 7) {
                    return sprintf('Dia inválido no bloco %d: %d.', $i + 1, $dia);
                }
                if (in_array($dia, $diasUsados, true)) {
                    return sprintf('Dia %d aparece em mais de um bloco.', $dia);
                }
                $diasUsados[] = $dia;
            }

            $bloco = new BlocoJornada();
            $bloco->setEntrada((string) ($b['entrada'] ?? '09:00'));
            $bloco->setRepouso($b['repouso'] !== '' ? (string) ($b['repouso'] ?? null) : null);
            $bloco->setRetorno($b['retorno'] !== '' ? (string) ($b['retorno'] ?? null) : null);
            $bloco->setSaida((string) ($b['saida'] ?? '18:00'));

            $minutos = $this->calcularMinutosBloco($bloco);

            if ($minutos <= 0) {
                return sprintf('Bloco %d tem carga horária inválida (deve ser positiva).', $i + 1);
            }

            $totalMinutos += $minutos * count($dias);
        }

        if (!empty($blocos) && $totalMinutos !== $cargaSemanal) {
            return sprintf(
                'Soma dos blocos (%dh%02dm) não bate com a carga semanal (%dh%02dm).',
                intdiv($totalMinutos, 60), $totalMinutos % 60,
                intdiv($cargaSemanal, 60), $cargaSemanal % 60
            );
        }

        return null;
    }

    private function calcularMinutosBloco(BlocoJornada $bloco): int
    {
        [$hE, $mE] = array_map('intval', explode(':', $bloco->getEntrada()));
        [$hS, $mS] = array_map('intval', explode(':', $bloco->getSaida()));

        $total = ($hS * 60 + $mS) - ($hE * 60 + $mE);

        if ($bloco->getRepouso() !== null && $bloco->getRetorno() !== null) {
            [$hR, $mR] = array_map('intval', explode(':', $bloco->getRepouso()));
            [$hRt, $mRt] = array_map('intval', explode(':', $bloco->getRetorno()));
            $total -= ($hRt * 60 + $mRt) - ($hR * 60 + $mR);
        }

        return max(0, $total);
    }
}
