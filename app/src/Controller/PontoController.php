<?php

namespace App\Controller;

use App\Entity\Ponto\EscalaTrabalho;
use App\Entity\Ponto\JustificativaPonto;
use App\Entity\Ponto\RegistroPonto;
use App\Form\JustificativaPontoType;
use App\Repository\Ponto\FeriadoRepository;
use App\Repository\Ponto\JustificativaPontoRepository;
use App\Service\Ponto\CalculadoraJornada;
use App\Repository\Ponto\RegistroPontoRepository;
use App\Repository\SedeRepository;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\Service\Ponto\FolhaPontoBuilder;
use App\Service\Ponto\VerificadorAlertaPonto;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Service\Ponto\FolhaPontoXlsxExporter;
use App\Shared\Service\ArquivoStorageService;
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
    public function __construct(
        private readonly string $justificativasUploadsDir,
        private readonly VerificadorAlertaPonto $verificadorAlerta,
        private readonly ArquivoStorageService $storage,
    ) {}

    #[Route('/', name: 'ponto_index')]
    public function index(
        Request $request,
        RegistroPontoRepository $repository,
        FeriadoRepository $feriadoRepository,
        JustificativaPontoRepository $justificativaRepository,
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

        $justificativasDoMes = $justificativaRepository->findByUserAndCompetenciaIndexed($user, $anoSelecionado, $mesSelecionado);
        $folhaRows = $folhaPontoBuilder->buildRows($inicioMes, $fimMes, $batidas, false, false, $escala, $feriados, $justificativasDoMes);

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

        $anoAtual = (int) $agora->format('Y');
        $saldoMes = $folhaPontoBuilder->calcularSaldoAnual($user, $anoAtual, $feriados);

        $justificativaForm = $this->createForm(JustificativaPontoType::class);

        return $this->render('ponto/index.html.twig', [
            'folhaRows' => $folhaRows,
            'mesAtual' => $mesSelecionado,
            'anoAtual' => $anoSelecionado,
            'competenciasPonto' => $competenciasPonto,
            'competenciaSelecionada' => $competenciaSelecionada,
            'pontoHoje' => $pontoHoje,
            'saldoMes' => $saldoMes,
            'justificativas' => $justificativaRepository->findByUserAndCompetencia($user, $anoSelecionado, $mesSelecionado),
            'justificativaForm' => $justificativaForm->createView(),
        ]);
    }

    #[Route('/justificativa/nova', name: 'ponto_justificativa_nova', methods: ['GET', 'POST'])]
    public function novaJustificativa(
        Request $request,
        EntityManagerInterface $entityManager,
        JustificativaPontoRepository $justificativaRepository,
        PermissionChecker $permissionChecker,
        NotificacaoService $notificacaoService
    ): Response {
        /** @var \App\Entity\Auth\User $user */
        $user = $this->getUser();

        if (!$permissionChecker->canAccessModule($user, 'ponto')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Ponto Eletrônico.');
        }

        $form = $this->createForm(JustificativaPontoType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $datasRaw = trim((string) $form->get('datas')->getData());
            $descricao = trim((string) $form->get('descricao')->getData());

            $datasArray = array_filter(array_map('trim', explode(',', $datasRaw)));

            if (empty($datasArray)) {
                $this->addFlash('warning', 'Selecione ao menos uma data para justificar.');
                return $this->redirectToRoute('ponto_index');
            }

            $hoje = new \DateTimeImmutable('today');
            $datasValidas = [];

            foreach ($datasArray as $dataStr) {
                $dataObj = \DateTime::createFromFormat('Y-m-d', $dataStr);
                if ($dataObj === false) {
                    continue;
                }
                // Não permitir datas futuras
                if ($dataObj > $hoje) {
                    $this->addFlash('warning', sprintf('A data %s é futura e foi ignorada.', $dataObj->format('d/m/Y')));
                    continue;
                }
                // Não permitir domingo
                if ((int) $dataObj->format('N') === 7) {
                    $this->addFlash('warning', sprintf('A data %s é domingo e foi ignorada.', $dataObj->format('d/m/Y')));
                    continue;
                }
                $datasValidas[] = $dataObj;
            }

            if (empty($datasValidas)) {
                return $this->redirectToRoute('ponto_index');
            }

            // Upload do atestado
            $anexoPath = null;
            $anexoFile = $form->get('anexo')->getData();
            if ($anexoFile !== null) {
                $anexoPath = $this->storage->salvar($anexoFile, $this->justificativasUploadsDir);
            }

            $batchId = bin2hex(random_bytes(16));
            $justificativasCriadas = [];

            foreach ($datasValidas as $dataObj) {
                $justificativa = new JustificativaPonto();
                $justificativa->setUser($user);
                $justificativa->setData($dataObj);
                $justificativa->setDescricao($descricao);
                $justificativa->setAnexoPath($anexoPath);
                $justificativa->setStatus('pendente');
                $justificativa->setBatchId($batchId);

                $entityManager->persist($justificativa);
                $justificativasCriadas[] = $justificativa;
            }

            $entityManager->flush();

            $urlGestor = $this->generateUrl('app_tenant_users', ['id' => $user->getTenant()->getId()]);
            foreach ($justificativasCriadas as $j) {
                $notificacaoService->notificarJustificativaEnviada($j, $urlGestor);
            }

            $this->addFlash('success', sprintf(
                'Justificativa enviada para %d dia(s). Aguarde análise do administrador.',
                count($datasValidas)
            ));
        } elseif ($form->isSubmitted() && !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->redirectToRoute('ponto_index');
    }

    #[Route('/justificativa/{id}/anexo', name: 'ponto_justificativa_anexo', methods: ['GET'])]
    public function downloadAnexo(
        JustificativaPonto $justificativa,
        PermissionChecker $permissionChecker
    ): Response {
        /** @var \App\Entity\Auth\User $user */
        $user = $this->getUser();

        if (!$permissionChecker->canAccessModule($user, 'ponto')) {
            throw $this->createAccessDeniedException();
        }

        if ($justificativa->getUser()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Acesso negado a este atestado.');
        }

        if ($justificativa->getAnexoPath() === null) {
            throw $this->createNotFoundException('Esta justificativa não possui atestado.');
        }

        $filePath = $this->storage->caminho($this->justificativasUploadsDir, $justificativa->getAnexoPath());

        if (!$this->storage->existe($filePath)) {
            throw $this->createNotFoundException('Arquivo não encontrado.');
        }

        return $this->storage->servir($filePath, $justificativa->getAnexoPath(), inline: true);
    }

    #[Route('/alerta-horario', name: 'ponto_alerta_horario', methods: ['GET'])]
    public function alertaHorario(PermissionChecker $permissionChecker): JsonResponse
    {
        /** @var \App\Entity\Auth\User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['alertar' => false], 401);
        }

        if (!$permissionChecker->canAccessModule($user, 'ponto')) {
            return $this->json(['alertar' => false]);
        }

        $escala = $user->getEscalaTrabalho();
        if ($escala === null) {
            return $this->json(['alertar' => false]);
        }

        return $this->json($this->verificadorAlerta->verificar($escala, new \DateTimeImmutable()));
    }

    #[Route('/batida', name: 'ponto_batida', methods: ['POST'])]
    public function batida(
        Request $request,
        EntityManagerInterface $entityManager,
        SedeRepository $sedeRepository,
        RegistroPontoRepository $registroRepository,
        PermissionChecker $permissionChecker,
        FeriadoRepository $feriadoRepository,
        CalculadoraJornada $calculadora
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

        $hoje = new \DateTimeImmutable();
        $diaSemanaHoje = (int) $hoje->format('N');

        if ($diaSemanaHoje === 7) {
            return $this->json([
                'success' => false,
                'message' => 'Não é permitido registrar ponto aos domingos.',
            ], 422);
        }

        $feriados = $feriadoRepository->findByTenant($user->getTenant());
        $feriadoHoje = $calculadora->getFeriadoDoDia($hoje, $feriados);
        if ($feriadoHoje !== null) {
            return $this->json([
                'success' => false,
                'message' => sprintf('Hoje é feriado (%s). Não é permitido registrar ponto.', $feriadoHoje->getNome()),
            ], 422);
        }

        $escala = $user->getEscalaTrabalho();

        if ($diaSemanaHoje === 6) {
            $trabalhaNoSabado = $escala !== null
                && in_array(6, $escala->getDiasSemana(), true)
                && $escala->getCargaHorariaSabado() !== null;

            if (!$trabalhaNoSabado) {
                return $this->json([
                    'success' => false,
                    'message' => 'Você não possui escala de trabalho configurada para sábados.',
                ], 422);
            }
        } elseif ($escala !== null && !in_array($diaSemanaHoje, $escala->getDiasSemana(), true)) {
            $nomeDia = ['', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'][$diaSemanaHoje];
            return $this->json([
                'success' => false,
                'message' => sprintf('%s-feira não está configurada na sua escala de trabalho.', $nomeDia),
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
        FeriadoRepository $feriadoRepository,
        JustificativaPontoRepository $justificativaRepository,
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
        $escala  = $targetUser->getEscalaTrabalho();
        $feriados = $targetUser->getTenant() !== null ? $feriadoRepository->findByTenant($targetUser->getTenant()) : [];
        $justificativasDoMes = $justificativaRepository->findByUserAndCompetenciaIndexed($targetUser, $ano, $mes);

        $inicioMes = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));
        $fimMes = $inicioMes->modify('last day of this month')->setTime(23, 59, 59);
        $folhaRows = $folhaPontoBuilder->buildRows($inicioMes, $fimMes, $batidas, true, false, $escala, $feriados, $justificativasDoMes);

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
        FeriadoRepository $feriadoRepository,
        JustificativaPontoRepository $justificativaRepository,
        PermissionChecker $permissionChecker,
        FolhaPontoBuilder $folhaPontoBuilder,
        FolhaPontoXlsxExporter $xlsxExporter,
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
        $escala  = $targetUser->getEscalaTrabalho();
        $feriados = $targetUser->getTenant() !== null ? $feriadoRepository->findByTenant($targetUser->getTenant()) : [];
        $justificativasDoMes = $justificativaRepository->findByUserAndCompetenciaIndexed($targetUser, $ano, $mes);

        $inicioMes = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));
        $fimMes = $inicioMes->modify('last day of this month')->setTime(23, 59, 59);
        $folhaRows = $folhaPontoBuilder->buildRows($inicioMes, $fimMes, $batidas, true, false, $escala, $feriados, $justificativasDoMes);

        $nomeUsuario = trim((string) $targetUser->getFullName());
        if ($nomeUsuario === '') {
            $nomeUsuario = (string) $targetUser->getUserIdentifier();
        }

        $nomeUsuario = preg_replace('/[^A-Za-z0-9]+/', '', $nomeUsuario) ?? 'Usuario';
        if ($nomeUsuario === '') {
            $nomeUsuario = 'Usuario';
        }

        $nomeArquivo = sprintf('folha_ponto_%s-%02d-%04d.xlsx', $nomeUsuario, $mes, $ano);

        return $xlsxExporter->exportar($folhaRows, $nomeArquivo);
    }
}
