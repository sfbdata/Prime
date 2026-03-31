<?php

namespace App\Controller;

use App\Entity\Ponto\RegistroPonto;
use App\Repository\Ponto\RegistroPontoRepository;
use App\Repository\SedeRepository;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/ponto')]
final class PontoController extends AbstractController
{
    #[Route('/', name: 'ponto_index')]
    public function index(
        RegistroPontoRepository $repository,
        PermissionChecker $permissionChecker
    ): Response {
        /** @var \App\Entity\Auth\User $user */
        $user = $this->getUser();

        if (!$permissionChecker->canAccessModule($user, 'ponto')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Ponto Eletrônico.');
        }

        $batidas = $repository->findBy(['user' => $user], ['dataHora' => 'DESC'], 20);

        return $this->render('ponto/index.html.twig', [
            'batidas' => $batidas,
        ]);
    }

    #[Route('/batida', name: 'ponto_batida', methods: ['POST'])]
    public function batida(
        Request $request,
        EntityManagerInterface $entityManager,
        SedeRepository $sedeRepository,
        RegistroPontoRepository $registroRepository,
        PermissionChecker $permissionChecker
    ): JsonResponse {
        /** @var \App\Entity\Auth\User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['success' => false, 'message' => 'Usuário não autenticado.'], 401);
        }

        if (!$permissionChecker->canAccessModule($user, 'ponto')) {
            return $this->json(['success' => false, 'message' => 'Sem permissão para registrar ponto.'], 403);
        }

        if ($user->getTenant() === null) {
            return $this->json(['success' => false, 'message' => 'Usuário sem tenant configurado.'], 403);
        }

        $data = json_decode($request->getContent(), true);

        $ssid        = $data['ssid'] ?? null;
        $latitude    = $data['latitude'] ?? null;
        $longitude   = $data['longitude'] ?? null;
        $precisaoGps = $data['precisaoGps'] ?? null;
        $tipo        = $data['tipo'] ?? 'entrada';

        // Validação de SSID contra sedes do tenant
        $sedes = $sedeRepository->findBy(['tenant' => $user->getTenant()]);
        $ssidValido    = false;
        $sedeEncontrada = null;

        foreach ($sedes as $sede) {
            $ssids = $sede->getSsidsAutorizados();
            if ($ssids && in_array($ssid, $ssids)) {
                $ssidValido     = true;
                $sedeEncontrada = $sede;
                break;
            }
        }

        if (!$ssidValido) {
            return $this->json([
                'success'          => false,
                'message'          => 'Rede Wi-Fi não autorizada. SSID informado: ' . ($ssid ?: 'não informado'),
                'ssids_permitidos' => !empty($sedes) ? ($sedes[0]->getSsidsAutorizados() ?? []) : [],
            ], 403);
        }

        // Cria o registro de ponto
        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setSede($sedeEncontrada);
        $registro->setSsid($ssid);
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTime());

        if ($latitude !== null && $longitude !== null) {
            $registro->setLatitude((string)$latitude);
            $registro->setLongitude((string)$longitude);
            $registro->setPrecisaoGps($precisaoGps !== null ? (string)$precisaoGps : '0');
        } else {
            $registro->setLatitude('0');
            $registro->setLongitude('0');
            $registro->setPrecisaoGps('0');
        }

        $entityManager->persist($registro);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Ponto registrado com sucesso!',
            'data'    => [
                'hora' => $registro->getDataHora()->format('H:i:s'),
                'tipo' => $tipo,
                'sede' => $sedeEncontrada->getNome(),
            ],
        ]);
    }

    #[Route('/exportar-csv', name: 'ponto_exportar_csv')]
    public function exportarCsv(
        RegistroPontoRepository $repository,
        PermissionChecker $permissionChecker
    ): StreamedResponse {
        /** @var \App\Entity\Auth\User $user */
        $user = $this->getUser();

        if (!$permissionChecker->canAccessModule($user, 'ponto')) {
            throw $this->createAccessDeniedException();
        }

        $batidas = $repository->findBy(['user' => $user], ['dataHora' => 'DESC']);

        $response = new StreamedResponse(function () use ($batidas) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Data', 'Hora', 'Tipo', 'Sede', 'Latitude', 'Longitude', 'Precisão GPS', 'Observação']);

            foreach ($batidas as $batida) {
                fputcsv($handle, [
                    $batida->getDataHora()->format('d/m/Y'),
                    $batida->getDataHora()->format('H:i:s'),
                    $batida->getTipo(),
                    $batida->getSede() ? $batida->getSede()->getNome() : 'N/A',
                    $batida->getLatitude(),
                    $batida->getLongitude(),
                    $batida->getPrecisaoGps() . 'm',
                    $batida->getObservacao(),
                ]);
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="ponto_exportacao.csv"');

        return $response;
    }
}
