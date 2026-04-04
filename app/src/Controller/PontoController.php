<?php

namespace App\Controller;

use App\Entity\Ponto\EscalaTrabalho;
use App\Entity\Ponto\RegistroPonto;
use App\Repository\Ponto\FeriadoRepository;
use App\Repository\Ponto\RegistroPontoRepository;
use App\Repository\SedeRepository;
use App\Repository\UserRepository;
use App\Service\PermissionChecker;
use App\Service\Ponto\FolhaPontoBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route('/ponto')]
final class PontoController extends AbstractController
{
    #[Route('/', name: 'ponto_index')]
    public function index(
        Request $request,
        RegistroPontoRepository $repository,
        FeriadoRepository $feriadoRepository,
        PermissionChecker $permissionChecker,
        FolhaPontoBuilder $folhaPontoBuilder
    ): Response {
        /** @var \App\Entity\Auth\User $user */
        $user = $this->getUser();

        if (!$permissionChecker->canAccessModule($user, 'ponto')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Ponto Eletrônico.');
        }

        $agora = new \DateTimeImmutable();
        $mes = (int) $agora->format('m');
        $ano = (int) $agora->format('Y');
        $competenciaAtual = $agora->format('Y-m');

        $limiteMinimo = $agora->modify('first day of last month')->format('Y-m');

        $competenciasPonto = array_values(array_filter(
            $repository->findCompetenciasComRegistroPorUsuario($user),
            fn($c) => $c['valor'] >= $limiteMinimo
        ));
        $competenciasDisponiveis = array_column($competenciasPonto, 'valor');

        if (!in_array($competenciaAtual, $competenciasDisponiveis, true)) {
            array_unshift($competenciasPonto, [
                'valor' => $competenciaAtual,
                'label' => $agora->format('m/Y'),
                'ano' => $ano,
                'mes' => $mes,
            ]);
            $competenciasDisponiveis[] = $competenciaAtual;
        }

        $competenciaSelecionada = (string) $request->query->get('competencia', $competenciaAtual);
        if (!in_array($competenciaSelecionada, $competenciasDisponiveis, true)) {
            $competenciaSelecionada = $competenciaAtual;
        }

        [$anoSelecionado, $mesSelecionado] = array_map('intval', explode('-', $competenciaSelecionada));

        $inicioMes = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $anoSelecionado, $mesSelecionado));
        $fimMes = $inicioMes->modify('last day of this month')->setTime(23, 59, 59);

        /** @var RegistroPonto[] $batidas */
        $batidas = $repository->findByUserAndCompetencia($user, $anoSelecionado, $mesSelecionado);
        $escala = $user->getEscalaTrabalho();
        if ($escala === null) {
            $escala = new EscalaTrabalho();
            $escala->setUser($user);
        }
        $feriados = $user->getTenant() !== null ? $feriadoRepository->findByTenant($user->getTenant()) : [];
        $folhaRows = $folhaPontoBuilder->buildRows($inicioMes, $fimMes, $batidas, false, true, $escala, $feriados);

        $hojeStr = $agora->format('Y-m-d');
        $batidasParaHoje = ($competenciaSelecionada === $competenciaAtual)
            ? $batidas
            : $repository->findByUserAndCompetencia($user, $ano, $mes);
        $batidasHoje = array_filter(
            $batidasParaHoje,
            fn($b) => $b->getDataHora()->format('Y-m-d') === $hojeStr
        );

        $pontoHoje = ['entrada' => null, 'repouso' => null, 'retorno' => null, 'saida' => null];
        foreach ($batidasHoje as $batida) {
            $tipo = $batida->getTipo();
            if (array_key_exists($tipo, $pontoHoje)) {
                $pontoHoje[$tipo] = $batida->getDataHora()->format('H:i:s');
            }
        }

        $ultimaLinha = !empty($folhaRows) ? end($folhaRows) : null;
        $saldoMes = $ultimaLinha !== null ? ($ultimaLinha['saldoAcumulado'] ?? null) : null;

        return $this->render('ponto/index.html.twig', [
            'folhaRows' => $folhaRows,
            'mesAtual' => $mesSelecionado,
            'anoAtual' => $anoSelecionado,
            'competenciasPonto' => $competenciasPonto,
            'competenciaSelecionada' => $competenciaSelecionada,
            'pontoHoje' => $pontoHoje,
            'saldoMes' => $saldoMes,
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

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Payload invalido.',
            ], 400);
        }

        $latitude    = $data['latitude'] ?? null;
        $longitude   = $data['longitude'] ?? null;
        $precisaoGps = $data['precisaoGps'] ?? null;
        $tipo        = isset($data['tipo']) ? strtolower(trim((string) $data['tipo'])) : null;

        if ($tipo === null || $tipo === '') {
            return $this->json([
                'success' => false,
                'message' => 'Tipo de registro e obrigatorio (Entrada, Repouso, Retorno ou Saida).',
            ], 422);
        }

        if (!in_array($tipo, RegistroPonto::TIPOS_VALIDOS, true)) {
            return $this->json([
                'success' => false,
                'message' => 'Tipo de registro invalido. Selecione Entrada, Repouso, Retorno ou Saida.',
            ], 422);
        }

        if ($latitude === null || $longitude === null) {
            return $this->json([
                'success' => false,
                'message' => 'Geolocalização é obrigatória para registrar o ponto.',
            ], 422);
        }

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return $this->json([
                'success' => false,
                'message' => 'Coordenadas inválidas para registro do ponto.',
            ], 422);
        }

        $latitudeFloat = (float) $latitude;
        $longitudeFloat = (float) $longitude;

        if (!is_finite($latitudeFloat) || !is_finite($longitudeFloat) || $latitudeFloat < -90 || $latitudeFloat > 90 || $longitudeFloat < -180 || $longitudeFloat > 180) {
            return $this->json([
                'success' => false,
                'message' => 'Coordenadas fora da faixa válida.',
            ], 422);
        }

        if (!is_numeric($precisaoGps)) {
            return $this->json([
                'success' => false,
                'message' => 'Precisão do GPS inválida.',
            ], 422);
        }

        $precisaoGpsFloat = (float) $precisaoGps;

        if (!is_finite($precisaoGpsFloat) || $precisaoGpsFloat <= 0) {
            return $this->json([
                'success' => false,
                'message' => 'Precisão do GPS inválida.',
            ], 422);
        }

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

            $raioPermitido = (float) $sede->getRaioPermitido();
            if ($raioPermitido <= 0) {
                continue;
            }

            if ($distancia <= $raioPermitido && ($distanciaSedeEncontrada === null || $distancia < $distanciaSedeEncontrada)) {
                $sedeEncontrada = $sede;
                $distanciaSedeEncontrada = $distancia;
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
        $registro->setSedeNomeSnapshot($sedeEncontrada->getNome());
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTime());

        $registro->setLatitude((string) $latitudeFloat);
        $registro->setLongitude((string) $longitudeFloat);
        $registro->setPrecisaoGps((string) $precisaoGpsFloat);

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

    #[Route('/exportar-folha-pdf', name: 'ponto_exportar_pdf')]
    public function exportarFolhaPdf(
        Request $request,
        RegistroPontoRepository $repository,
        PermissionChecker $permissionChecker,
        FolhaPontoBuilder $folhaPontoBuilder,
        UserRepository $userRepository,
        Environment $twig
    ): Response {
        /** @var \App\Entity\Auth\User $user */
        $user = $this->getUser();

        if (!$permissionChecker->canAccessModule($user, 'ponto')) {
            throw $this->createAccessDeniedException();
        }

        $targetUser = $user;
        $targetUserId = (int) $request->query->get('userId', 0);

        if ($targetUserId > 0 && $targetUserId !== (int) $user->getId()) {
            $targetUser = $userRepository->find($targetUserId);
            if ($targetUser === null) {
                throw $this->createNotFoundException('Usuário para exportação não encontrado.');
            }

            $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
            $isSameTenant = $targetUser->getTenant()?->getId() === $user->getTenant()?->getId();

            if (!$isSuperAdmin && !($isSameTenant && $permissionChecker->canAdminister($user, 'admin.users.manage'))) {
                throw $this->createAccessDeniedException('Sem permissão para exportar folha deste usuário.');
            }
        }

        $mes = max(1, min(12, (int) $request->query->get('mes', (new \DateTimeImmutable())->format('m'))));
        $ano = max(1970, (int) $request->query->get('ano', (new \DateTimeImmutable())->format('Y')));

        /** @var \App\Entity\Ponto\RegistroPonto[] $batidas */
        $batidas = $repository->findByUserAndCompetencia($targetUser, $ano, $mes);

        $inicioMes = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));
        $fimMes = $inicioMes->modify('last day of this month')->setTime(23, 59, 59);
        $folhaRows = $folhaPontoBuilder->buildRows($inicioMes, $fimMes, $batidas);

        $nomeUsuario = trim((string) $targetUser->getFullName());
        if ($nomeUsuario === '') {
            $nomeUsuario = (string) $targetUser->getUserIdentifier();
        }

        $html = $twig->render('ponto/folha_pdf.html.twig', [
            'folhaRows' => $folhaRows,
            'mes' => $mes,
            'ano' => $ano,
            'nomeUsuario' => $nomeUsuario,
        ]);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $nomeArquivoBase = preg_replace('/[^A-Za-z0-9]+/', '', $nomeUsuario) ?: 'Usuario';
        $nomeArquivo = sprintf('folha_ponto_%s-%02d-%04d.pdf', $nomeArquivoBase, $mes, $ano);

        return new Response(
            $dompdf->output(),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $nomeArquivo),
            ]
        );
    }

    #[Route('/exportar-folha-xlsx', name: 'ponto_exportar_xlsx')]
    public function exportarFolhaXlsx(
        Request $request,
        RegistroPontoRepository $repository,
        PermissionChecker $permissionChecker,
        FolhaPontoBuilder $folhaPontoBuilder,
        UserRepository $userRepository
    ): StreamedResponse {
        /** @var \App\Entity\Auth\User $user */
        $user = $this->getUser();

        if (!$permissionChecker->canAccessModule($user, 'ponto')) {
            throw $this->createAccessDeniedException();
        }

        $targetUser = $user;
        $targetUserId = (int) $request->query->get('userId', 0);

        if ($targetUserId > 0 && $targetUserId !== (int) $user->getId()) {
            $targetUser = $userRepository->find($targetUserId);
            if ($targetUser === null) {
                throw $this->createNotFoundException('Usuário para exportação não encontrado.');
            }

            $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
            $isSameTenant = $targetUser->getTenant()?->getId() === $user->getTenant()?->getId();

            if (!$isSuperAdmin && !($isSameTenant && $permissionChecker->canAdminister($user, 'admin.users.manage'))) {
                throw $this->createAccessDeniedException('Sem permissão para exportar folha deste usuário.');
            }
        }

        $mes = max(1, min(12, (int) $request->query->get('mes', (new \DateTimeImmutable())->format('m'))));
        $ano = max(1970, (int) $request->query->get('ano', (new \DateTimeImmutable())->format('Y')));

        /** @var RegistroPonto[] $batidas */
        $batidas = $repository->findByUserAndCompetencia($targetUser, $ano, $mes);

        $inicioMes = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));
        $fimMes = $inicioMes->modify('last day of this month')->setTime(23, 59, 59);
        $folhaRows = $folhaPontoBuilder->buildRows($inicioMes, $fimMes, $batidas);

        $response = new StreamedResponse(function () use ($folhaRows, $mes, $ano) {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Folha Ponto');

            $sheet->fromArray(['Dia do Mês', 'Dia da Semana', 'Entrada', 'Repouso', 'Retorno', 'Saída'], null, 'A1');
            $sheet->getStyle('A1:F1')->getFont()->setBold(true);

            $linha = 2;
            foreach ($folhaRows as $row) {
                $sheet->setCellValueExplicit("A{$linha}", $row['diaMes'], DataType::TYPE_STRING);
                $sheet->setCellValue("B{$linha}", $row['diaSemana']);
                $sheet->setCellValue("C{$linha}", $row['entrada']);
                $sheet->setCellValue("D{$linha}", $row['repouso']);
                $sheet->setCellValue("E{$linha}", $row['retorno']);
                $sheet->setCellValue("F{$linha}", $row['saida']);

                if ($row['fimSemana']) {
                    $sheet->getStyle("A{$linha}:F{$linha}")
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('FFEAEAEA');
                }

                $linha++;
            }

            foreach (range('A', 'F') as $coluna) {
                $sheet->getColumnDimension($coluna)->setAutoSize(true);
            }

            $sheet->freezePane('A2');

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        });

        $nomeUsuario = trim((string) $targetUser->getFullName());
        if ($nomeUsuario === '') {
            $nomeUsuario = (string) $targetUser->getUserIdentifier();
        }

        $nomeUsuario = preg_replace('/[^A-Za-z0-9]+/', '', $nomeUsuario) ?? 'Usuario';
        if ($nomeUsuario === '') {
            $nomeUsuario = 'Usuario';
        }

        $nomeArquivo = sprintf('folha_ponto_%s-%02d-%04d.xlsx', $nomeUsuario, $mes, $ano);

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $nomeArquivo));

        return $response;
    }
}
