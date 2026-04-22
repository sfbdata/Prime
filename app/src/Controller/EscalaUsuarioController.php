<?php

namespace App\Controller;

use App\Entity\Ponto\BlocoEscalaUsuario;
use App\Entity\Ponto\EscalaTrabalho;
use App\Entity\Auth\User;
use App\Repository\Ponto\EscalaTrabalhoRepository;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/tenant/{tenantId}/user/{id}')]
final class EscalaUsuarioController extends AbstractController
{
    #[Route('/escala', name: 'app_escala_usuario_get', methods: ['GET'])]
    public function get(
        int $tenantId,
        User $user,
        PermissionChecker $permissionChecker
    ): JsonResponse {
        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        $isOwnTenant  = $currentUser->getTenant()?->getId() === $tenantId;

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($currentUser, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para gerenciar escalas.');
        }

        if ($user->getTenant()?->getId() !== $tenantId && !$isSuperAdmin) {
            throw $this->createAccessDeniedException('Usuário não pertence a este tenant.');
        }

        $escala      = $user->getEscalaTrabalho();
        $jornadaTenant = $user->getTenant()?->getJornadaTenant();

        $blocosTenant = [];
        if ($jornadaTenant !== null) {
            foreach ($jornadaTenant->getBlocos() as $b) {
                $blocosTenant[] = [
                    'diasSemana'   => $b->getDiasSemana(),
                    'entrada'      => $b->getEntrada(),
                    'repouso'      => $b->getRepouso(),
                    'retorno'      => $b->getRetorno(),
                    'saida'        => $b->getSaida(),
                    'minutosBloco' => $b->getMinutosBloco(),
                ];
            }
        }

        $cargaUsuario = $escala?->getCargaHorariaSemanal();
        $cargaEfetiva = $cargaUsuario ?? $jornadaTenant?->getCargaHorariaSemanal() ?? 2400;

        $temBlocosUsuario = $escala !== null && !$escala->getBlocos()->isEmpty();
        $temCamposLegados = $escala !== null && !$temBlocosUsuario && (
            $escala->getEntrada1() !== null || $escala->getSaida1() !== null
        );

        $blocosUsuario = [];
        if ($temBlocosUsuario) {
            foreach ($escala->getBlocos() as $b) {
                $blocosUsuario[] = [
                    'diasSemana'   => $b->getDiasSemana(),
                    'entrada'      => $b->getEntrada(),
                    'repouso'      => $b->getRepouso(),
                    'retorno'      => $b->getRetorno(),
                    'saida'        => $b->getSaida(),
                    'minutosBloco' => $b->getMinutosBloco(),
                ];
            }
        }

        return $this->json([
            'cargaHorariaSemanal'  => $cargaEfetiva,
            'alertaHabilitado'     => $escala?->isAlertaHabilitado() ?? true,
            'blocos'               => $blocosUsuario,
            'temBlocosUsuario'     => $temBlocosUsuario,
            'temCamposLegados'     => $temCamposLegados,
            'jornadaTenant'        => [
                'cargaHorariaSemanal' => $jornadaTenant?->getCargaHorariaSemanal(),
                'alertaHabilitado'    => $jornadaTenant?->isAlertaHabilitado() ?? true,
                'blocos'              => $blocosTenant,
            ],
        ]);
    }

    #[Route('/escala', name: 'app_escala_usuario_save', methods: ['POST'])]
    public function save(
        int $tenantId,
        User $user,
        Request $request,
        PermissionChecker $permissionChecker,
        EscalaTrabalhoRepository $escalaTrabalhoRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        $isOwnTenant  = $currentUser->getTenant()?->getId() === $tenantId;

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($currentUser, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para gerenciar escalas.');
        }

        if ($user->getTenant()?->getId() !== $tenantId && !$isSuperAdmin) {
            throw $this->createAccessDeniedException('Usuário não pertence a este tenant.');
        }

        $data = json_decode((string) $request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['erro' => 'Payload inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $carga  = (int) ($data['cargaHorariaSemanal'] ?? 0);
        $alerta = (bool) ($data['alertaHabilitado'] ?? true);
        $blocos = $data['blocos'] ?? [];

        if ($carga < 60 || $carga > 2640) {
            return $this->json(['erro' => 'Carga horária semanal deve estar entre 1h e 44h.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!is_array($blocos) || count($blocos) > 7) {
            return $this->json(['erro' => 'Máximo de 7 blocos de jornada.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $erros = $this->validarBlocos($blocos, $carga);
        if ($erros !== null) {
            return $this->json(['erro' => $erros], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $escala = $user->getEscalaTrabalho();
        if ($escala === null) {
            $escala = new EscalaTrabalho();
            $escala->setUser($user);
            $entityManager->persist($escala);
        }

        $escala->setCargaHorariaSemanal($carga);
        $escala->setAlertaHabilitado($alerta);

        foreach ($escala->getBlocos()->toArray() as $blocoAntigo) {
            $escala->removeBloco($blocoAntigo);
        }

        foreach ($blocos as $blocoData) {
            $bloco = new BlocoEscalaUsuario();
            $bloco->setDiasSemana(array_map('intval', (array) ($blocoData['diasSemana'] ?? [])));
            $bloco->setEntrada((string) ($blocoData['entrada'] ?? '09:00'));
            $bloco->setRepouso($blocoData['repouso'] !== '' ? (string) ($blocoData['repouso'] ?? null) : null);
            $bloco->setRetorno($blocoData['retorno'] !== '' ? (string) ($blocoData['retorno'] ?? null) : null);
            $bloco->setSaida((string) ($blocoData['saida'] ?? '18:00'));
            $bloco->setMinutosBloco($this->calcularMinutosBloco($bloco));
            $escala->addBloco($bloco);
        }

        $entityManager->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/escala', name: 'app_escala_usuario_delete', methods: ['DELETE'])]
    public function delete(
        int $tenantId,
        User $user,
        Request $request,
        PermissionChecker $permissionChecker,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        $isOwnTenant  = $currentUser->getTenant()?->getId() === $tenantId;

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($currentUser, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para gerenciar escalas.');
        }

        if ($user->getTenant()?->getId() !== $tenantId && !$isSuperAdmin) {
            throw $this->createAccessDeniedException('Usuário não pertence a este tenant.');
        }

        $escala = $user->getEscalaTrabalho();
        if ($escala === null) {
            return $this->json(['ok' => true]);
        }

        foreach ($escala->getBlocos()->toArray() as $bloco) {
            $escala->removeBloco($bloco);
        }

        $escala->setCargaHorariaSemanal(null);
        $entityManager->flush();

        return $this->json(['ok' => true]);
    }

    private function validarBlocos(array $blocos, int $cargaSemanal): ?string
    {
        $diasUsados   = [];
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

            $bloco = new BlocoEscalaUsuario();
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

    private function calcularMinutosBloco(BlocoEscalaUsuario $bloco): int
    {
        [$hE, $mE] = array_map('intval', explode(':', $bloco->getEntrada()));
        [$hS, $mS] = array_map('intval', explode(':', $bloco->getSaida()));

        $total = ($hS * 60 + $mS) - ($hE * 60 + $mE);

        if ($bloco->getRepouso() !== null && $bloco->getRetorno() !== null) {
            [$hR, $mR]   = array_map('intval', explode(':', $bloco->getRepouso()));
            [$hRt, $mRt] = array_map('intval', explode(':', $bloco->getRetorno()));
            $total -= ($hRt * 60 + $mRt) - ($hR * 60 + $mR);
        }

        return max(0, $total);
    }
}
