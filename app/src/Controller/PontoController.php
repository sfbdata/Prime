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

        $latitude    = $data['latitude'] ?? null;
        $longitude   = $data['longitude'] ?? null;
        $precisaoGps = $data['precisaoGps'] ?? null;
        $tipo        = $data['tipo'] ?? 'entrada';

        if ($latitude === null || $longitude === null) {
            return $this->json([
                'success' => false,
                'message' => 'Geolocalização é obrigatória para registrar o ponto.',
            ], 422);
        }

        $latitudeFloat = (float) $latitude;
        $longitudeFloat = (float) $longitude;

        $sedes = $sedeRepository->findBy(['tenant' => $user->getTenant()]);
        if (empty($sedes)) {
            return $this->json([
                'success' => false,
                'message' => 'Nenhuma sede configurada para o tenant.',
            ], 403);
        }

        $sedeEncontrada = null;
        $distanciaSedeEncontrada = null;

        foreach ($sedes as $sede) {
            if ($sede->getLatitude() === null || $sede->getLongitude() === null) {
                continue;
            }

            $distancia = $this->calcularDistanciaMetros(
                $latitudeFloat,
                $longitudeFloat,
                (float) $sede->getLatitude(),
                (float) $sede->getLongitude()
            );

            if ($distancia <= (float) $sede->getRaioPermitido()) {
                $sedeEncontrada = $sede;
                $distanciaSedeEncontrada = $distancia;
                break;
            }
        }

        if ($sedeEncontrada === null) {
            return $this->json([
                'success' => false,
                'message' => 'Você está fora da área permitida das sedes para registrar o ponto.',
            ], 403);
        }

        // Cria o registro de ponto
        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setSede($sedeEncontrada);
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTime());

        $registro->setLatitude((string) $latitudeFloat);
        $registro->setLongitude((string) $longitudeFloat);
        $registro->setPrecisaoGps($precisaoGps !== null ? (string) $precisaoGps : '0');

        $entityManager->persist($registro);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Ponto registrado com sucesso!',
            'data'    => [
                'hora' => $registro->getDataHora()->format('H:i:s'),
                'tipo' => $tipo,
                'sede' => $sedeEncontrada->getNome(),
                'distancia' => $distanciaSedeEncontrada !== null ? round($distanciaSedeEncontrada, 2) : null,
            ],
        ]);
    }

    private function calcularDistanciaMetros(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $raioTerra = 6371000;

        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLon = deg2rad($lon2 - $lon1);

        $a = sin($deltaLat / 2) * sin($deltaLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($deltaLon / 2) * sin($deltaLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $raioTerra * $c;
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
