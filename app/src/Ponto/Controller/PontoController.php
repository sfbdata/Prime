<?php

declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Entity\Auth\User;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Enum\TipoJustificativa;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\JornadaResolver;
use App\Ponto\Form\JustificativaPontoType;
use App\Ponto\Repository\FeriadoRepository;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Service\CalculadoraJornada;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Repository\SedeRepository;
use App\Repository\UserRepository;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tenant\UseCase\GerarCodigoFuncionario;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\VerificadorAlertaPonto;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Ponto\Service\FolhaPontoXlsxExporter;
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
        private readonly JornadaResolver $jornadaResolver,
        private readonly GerarCodigoFuncionario $gerarCodigo,
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly UserTenantRepository $userTenantRepository,
    ) {}

    #[Route('/', name: 'ponto_index')]
    public function index(
        Request $request,
        RegistroPontoRepository $repository,
        FeriadoRepository $feriadoRepository,
        JustificativaPontoRepository $justificativaRepository,
        FolhaPontoBuilder $folhaPontoBuilder
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->assertAccess($user);
        $jornadaTenant = $tenant->getJornadaTenant();

        $agora = new \DateTimeImmutable();
        $mes = (int) $agora->format('m');
        $ano = (int) $agora->format('Y');
        $competenciaAtual = $agora->format('Y-m');

        $limiteMinimo = $agora->modify('first day of last month')->format('Y-m');

        $competenciasPonto = array_values(array_filter(
            $repository->findCompetenciasComRegistroPorUsuario($user, $tenant),
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
        $jornada = $user->getJornadaColaborador();
        if ($jornada === null) {
            $jornada = new JornadaColaborador();
            $jornada->setUser($user);
        }
        $feriados = $feriadoRepository->findByTenant($tenant);

        $justificativasDoMes = $justificativaRepository->findByUserAndCompetenciaIndexed($user, $anoSelecionado, $mesSelecionado);
        $folhaRows = $folhaPontoBuilder->buildRows($inicioMes, $fimMes, $batidas, false, false, $jornada, $feriados, $justificativasDoMes, $jornadaTenant);

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
        $saldoMes = $folhaPontoBuilder->calcularSaldoAnual($user, $anoAtual, $feriados, $jornadaTenant);

        $jornadaInfo = $this->resolverJornadaInfo($user, $jornadaTenant);
        $duracaoJornadaDiariaMinutos = $this->resolverDuracaoJornadaDiaria($user, $agora, $jornadaTenant);

        $justificativaForm = $this->createForm(JustificativaPontoType::class);

        return $this->render('ponto/index.html.twig', [
            'folhaRows' => $folhaRows,
            'mesAtual' => $mesSelecionado,
            'anoAtual' => $anoSelecionado,
            'competenciasPonto' => $competenciasPonto,
            'competenciaSelecionada' => $competenciaSelecionada,
            'pontoHoje' => $pontoHoje,
            'saldoMes' => $saldoMes,
            'jornadaInfo' => $jornadaInfo,
            'minimoMinutosRepouso'           => $jornadaTenant?->getMinimoMinutosRepouso() ?? 60,
            'validacaoRepousoHabilitada'     => $jornadaTenant?->isValidacaoRepousoHabilitada() ?? true,
            'duracaoJornadaDiariaMinutos'    => $duracaoJornadaDiariaMinutos,
            'justificativas'     => $justificativaRepository->findByUserAndCompetencia($user, $anoSelecionado, $mesSelecionado),
            'justificativaForm'  => $justificativaForm->createView(),
            'tiposJustificativa' => TipoJustificativa::asPlanarChoices(),
        ]);
    }

    #[Route('/justificativa/nova', name: 'ponto_justificativa_nova', methods: ['GET', 'POST'])]
    public function novaJustificativa(
        Request $request,
        EntityManagerInterface $entityManager,
        JustificativaPontoRepository $justificativaRepository,
        NotificacaoService $notificacaoService
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->assertAccess($user);

        $form = $this->createForm(JustificativaPontoType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $datasRaw = trim((string) $form->get('datas')->getData());
            $tipo = $form->get('tipo')->getData();
            $abonoParcial = (bool) $form->get('abonoParcial')->getData();
            $horaInicio = $form->get('horaInicioAbono')->getData();
            $horaFim = $form->get('horaFimAbono')->getData();

            $tipoRegEsquecido = null;
            $horaRegEsquecida = null;
            if ($tipo === 'esquecimento_registro') {
                $tipoRegEsquecido = $form->get('tipoRegistroEsquecido')->getData();
                $horaRegEsquecida = $form->get('horaRegistroEsquecido')->getData();
                if (!in_array($tipoRegEsquecido, RegistroPonto::TIPOS_VALIDOS, true) || $horaRegEsquecida === null) {
                    $this->addFlash('warning', 'Informe o tipo e o horário do registro esquecido.');
                    return $this->redirectToRoute('ponto_index');
                }
            }

            $datasArray = array_filter(array_map('trim', explode(',', $datasRaw)));

            if (empty($datasArray)) {
                $this->addFlash('warning', 'Selecione ao menos uma data para justificar.');
                return $this->redirectToRoute('ponto_index');
            }

            if ($abonoParcial && count($datasArray) > 1) {
                $this->addFlash('warning', 'Abono parcial só pode ser aplicado a uma única data.');
                return $this->redirectToRoute('ponto_index');
            }

            if ($tipo === 'esquecimento_registro' && count($datasArray) > 1) {
                $this->addFlash('warning', 'Esquecimento de Registro só pode ser aplicado a uma única data.');
                return $this->redirectToRoute('ponto_index');
            }

            if ($abonoParcial && ($horaInicio === null || $horaFim === null || $horaInicio >= $horaFim)) {
                $this->addFlash('warning', 'Informe os horários de saída e retorno corretamente para o abono parcial.');
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
                if ($dataObj->format('Y-m-d') > $hoje->format('Y-m-d')) {
                    $this->addFlash('warning', sprintf('A data %s é futura e foi ignorada.', $dataObj->format('d/m/Y')));
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
            $isFaltaNaoJustificada = $tipo === 'falta_nao_justificada';
            $statusInicial = $isFaltaNaoJustificada ? 'abonado' : 'pendente';
            $justificativasCriadas = [];

            foreach ($datasValidas as $dataObj) {
                $justificativa = new JustificativaPonto();
                $justificativa->setUser($user);
                $justificativa->setTenant($tenant);
                $justificativa->setData($dataObj);
                $justificativa->setTipo($tipo);
                $justificativa->setAnexoPath($anexoPath);
                $justificativa->setStatus($statusInicial);
                $justificativa->setBatchId($batchId);
                $justificativa->setAbonoParcial($abonoParcial);
                if ($abonoParcial) {
                    $justificativa->setHoraInicioAbono($horaInicio);
                    $justificativa->setHoraFimAbono($horaFim);
                }
                if ($tipo === 'esquecimento_registro') {
                    $justificativa->setTipoRegistroEsquecido($tipoRegEsquecido);
                    $justificativa->setHoraRegistroEsquecido($horaRegEsquecida);
                }

                $entityManager->persist($justificativa);
                $justificativasCriadas[] = $justificativa;
            }

            $entityManager->flush();

            if (!$isFaltaNaoJustificada) {
                // Leva o gestor direto à aba de justificativas do colaborador (aprovar/recusar)
                $urlGestor = $this->generateUrl('app_tenant_user_edit_role', [
                    'tenantId' => $tenant->getId(),
                    'id' => $user->getId(),
                    'tab' => 'justificativas',
                ]);
                foreach ($justificativasCriadas as $j) {
                    $notificacaoService->notificarJustificativaEnviada($j, $urlGestor, $tenant);
                }
            }

            if ($isFaltaNaoJustificada) {
                $this->addFlash('success', sprintf(
                    'Falta registrada como não justificada para %d dia(s).',
                    count($datasValidas)
                ));
            } else {
                $this->addFlash('success', sprintf(
                    'Justificativa enviada para %d dia(s). Aguarde análise do administrador.',
                    count($datasValidas)
                ));
            }
        } elseif ($form->isSubmitted() && !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->redirectToRoute('ponto_index');
    }

    #[Route('/justificativa/{id}/editar', name: 'ponto_justificativa_editar', methods: ['GET', 'POST'])]
    public function editarJustificativa(
        JustificativaPonto $justificativa,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertAccess($user);

        if ($justificativa->getUser()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Acesso negado a esta justificativa.');
        }

        if (!$this->isCsrfTokenValid('editar_justificativa_' . $justificativa->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');
            return $this->redirectToRoute('ponto_index');
        }

        $tipo = $request->request->get('tipo');
        $abonoParcial = (bool) $request->request->get('abonoParcial', false);
        $horaInicioRaw = $request->request->get('horaInicioAbono');
        $horaFimRaw = $request->request->get('horaFimAbono');

        if ($tipo !== null && !in_array($tipo, TipoJustificativa::valores(), true)) {
            $tipo = null;
        }

        $horaInicio = null;
        $horaFim = null;

        if ($abonoParcial) {
            $horaInicio = $horaInicioRaw !== null ? \DateTime::createFromFormat('H:i', $horaInicioRaw) ?: null : null;
            $horaFim = $horaFimRaw !== null ? \DateTime::createFromFormat('H:i', $horaFimRaw) ?: null : null;

            if ($horaInicio === null || $horaFim === null || $horaInicio >= $horaFim) {
                $this->addFlash('warning', 'Informe os horários de saída e retorno corretamente para o abono parcial.');
                return $this->redirectToRoute('ponto_index');
            }
        }

        $justificativa->setTipo($tipo ?: null);
        $justificativa->setAbonoParcial($abonoParcial);
        $justificativa->setHoraInicioAbono($abonoParcial ? $horaInicio : null);
        $justificativa->setHoraFimAbono($abonoParcial ? $horaFim : null);

        if ($tipo === 'esquecimento_registro') {
            $tipoRegEsquecido = $request->request->get('tipoRegistroEsquecido');
            $horaRegRaw = $request->request->get('horaRegistroEsquecido');
            $horaReg = ($horaRegRaw !== null && $horaRegRaw !== '') ? \DateTime::createFromFormat('H:i', $horaRegRaw) ?: null : null;
            if (!in_array($tipoRegEsquecido, RegistroPonto::TIPOS_VALIDOS, true) || $horaReg === null) {
                $this->addFlash('warning', 'Informe o tipo e o horário do registro esquecido.');
                return $this->redirectToRoute('ponto_index');
            }
            $justificativa->setTipoRegistroEsquecido($tipoRegEsquecido);
            $justificativa->setHoraRegistroEsquecido($horaReg);
        } else {
            $justificativa->setTipoRegistroEsquecido(null);
            $justificativa->setHoraRegistroEsquecido(null);
        }

        $anexoFile = $request->files->get('anexo');
        if ($anexoFile !== null) {
            $novoAnexoPath = $this->storage->salvar($anexoFile, $this->justificativasUploadsDir);
            $justificativa->setAnexoPath($novoAnexoPath);
        }

        $entityManager->flush();

        $this->addFlash('success', 'Justificativa atualizada com sucesso.');
        return $this->redirectToRoute('ponto_index');
    }

    #[Route('/justificativa/{id}/anexo', name: 'ponto_justificativa_anexo', methods: ['GET'])]
    public function downloadAnexo(
        JustificativaPonto $justificativa,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertAccess($user);

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
    public function alertaHorario(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['alertar' => false], 401);
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$this->permissionChecker->canAccessModule($user, $tenant, 'ponto')) {
            return $this->json(['alertar' => false]);
        }

        $jornadaTenant = $tenant?->getJornadaTenant();

        return $this->json($this->verificadorAlerta->verificar($user, new \DateTimeImmutable(), $jornadaTenant));
    }

    #[Route('/batida', name: 'ponto_batida', methods: ['POST'])]
    public function batida(
        Request $request,
        EntityManagerInterface $entityManager,
        SedeRepository $sedeRepository,
        RegistroPontoRepository $registroRepository,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['success' => false, 'message' => 'Usuário não autenticado.'], 401);
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$this->permissionChecker->canAccessModule($user, $tenant, 'ponto')) {
            return $this->json(['success' => false, 'message' => 'Sem permissão para registrar ponto.'], 403);
        }

        if ($tenant === null) {
            return $this->json(['success' => false, 'message' => 'Usuário sem tenant configurado.'], 403);
        }

        $jornadaTenant = $tenant->getJornadaTenant();

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

        $sedes = $sedeRepository->findBy(['tenant' => $tenant]);
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

        if ($tipo === RegistroPonto::TIPO_RETORNO && ($jornadaTenant?->isValidacaoRepousoHabilitada() ?? true)) {
            $repousoDoDia = $registroRepository->findRepousoDoDia($user, $hoje);
            if ($repousoDoDia !== null) {
                $minimoMin   = $jornadaTenant?->getMinimoMinutosRepouso() ?? 60;
                $diffMinutos = (int) round(
                    ((new \DateTime())->getTimestamp() - $repousoDoDia->getDataHora()->getTimestamp()) / 60
                );
                if ($diffMinutos < $minimoMin) {
                    return $this->json([
                        'success' => false,
                        'message' => sprintf(
                            'Intervalo mínimo de repouso é de %d minuto(s). Aguarde mais %d minuto(s).',
                            $minimoMin,
                            $minimoMin - $diffMinutos
                        ),
                    ], 422);
                }
            }
        }

        if ($tipo === RegistroPonto::TIPO_ENTRADA && ($jornadaTenant?->isValidacaoInterjornadaHabilitada() ?? true)) {
            $ultimaSaida = $registroRepository->findUltimaSaida($user);
            if ($ultimaSaida !== null) {
                $minimoInterjornada = $jornadaTenant?->getMinimoMinutosInterjornada() ?? 660;
                $diffMinutos = (int) round(
                    ((new \DateTime())->getTimestamp() - $ultimaSaida->getDataHora()->getTimestamp()) / 60
                );
                if ($diffMinutos < $minimoInterjornada) {
                    $minFalt = $minimoInterjornada - $diffMinutos;
                    return $this->json([
                        'success' => false,
                        'message' => sprintf(
                            'Interjornada mínima é de %dh. Aguarde mais %dh%02dm.',
                            intdiv($minimoInterjornada, 60),
                            intdiv($minFalt, 60),
                            $minFalt % 60
                        ),
                    ], 422);
                }
            }
        }

        // Cria o registro de ponto
        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
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

    /**
     * @return array{cargaHorariaSemanal: int, fonte: string}|null
     */
    private function resolverJornadaInfo(\App\Entity\Auth\User $user, ?JornadaTenant $jornadaTenant): ?array
    {
        $jornada = $user->getJornadaColaborador();

        if ($jornada !== null && !$jornada->getBlocos()->isEmpty()) {
            $carga = $jornada->getCargaHorariaSemanal()
                ?? $jornadaTenant?->getCargaHorariaSemanal()
                ?? 0;
            return ['cargaHorariaSemanal' => $carga, 'fonte' => 'personalizada'];
        }

        if ($jornadaTenant !== null && !$jornadaTenant->getBlocos()->isEmpty()) {
            return ['cargaHorariaSemanal' => $jornadaTenant->getCargaHorariaSemanal(), 'fonte' => 'escritorio'];
        }

        return null;
    }

    private function resolverDuracaoJornadaDiaria(
        \App\Entity\Auth\User $user,
        \DateTimeImmutable $data,
        ?JornadaTenant $jornadaTenant,
    ): ?int {
        $batidas = $this->jornadaResolver->resolverBatidasEsperadasHoje($user, $data, $jornadaTenant);

        $entradaHorario = null;
        $saidaHorario   = null;
        foreach ($batidas as $batida) {
            if ($batida['tipo'] === 'entrada') {
                $entradaHorario = $batida['horario'];
            }
            if ($batida['tipo'] === 'saida') {
                $saidaHorario = $batida['horario'];
            }
        }

        if ($entradaHorario === null || $saidaHorario === null) {
            return null;
        }

        [$hEnt, $mEnt] = array_map('intval', explode(':', $entradaHorario));
        [$hSai, $mSai] = array_map('intval', explode(':', $saidaHorario));

        return ($hSai * 60 + $mSai) - ($hEnt * 60 + $mEnt);
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
        FolhaPontoBuilder $folhaPontoBuilder,
        UserRepository $userRepository,
        Environment $twig
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->assertAccess($user);

        $targetUser = $user;
        $targetUserId = (int) $request->query->get('userId', 0);

        if ($targetUserId > 0 && $targetUserId !== (int) $user->getId()) {
            $targetUser = $userRepository->find($targetUserId);
            if ($targetUser === null) {
                throw $this->createNotFoundException('Usuário para exportação não encontrado.');
            }

            $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);

            if (!$isSuperAdmin && !(
                $this->userTenantRepository->existeVinculoAtivo($targetUser, $tenant)
                && $this->permissionChecker->canAdminister($user, $tenant, 'admin.users.manage')
            )) {
                throw $this->createAccessDeniedException('Sem permissão para exportar folha deste usuário.');
            }
        }

        $mes = max(1, min(12, (int) $request->query->get('mes', (new \DateTimeImmutable())->format('m'))));
        $ano = max(1970, (int) $request->query->get('ano', (new \DateTimeImmutable())->format('Y')));

        /** @var \App\Ponto\Entity\RegistroPonto[] $batidas */
        $batidas = $repository->findByUserAndCompetencia($targetUser, $ano, $mes);
        $jornada  = $targetUser->getJornadaColaborador();
        $feriados = $feriadoRepository->findByTenant($tenant);
        $justificativasDoMes = $justificativaRepository->findByUserAndCompetenciaIndexed($targetUser, $ano, $mes);
        $jornadaTenantPdf = $tenant->getJornadaTenant();

        $inicioMes = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));
        $fimMes = $inicioMes->modify('last day of this month')->setTime(23, 59, 59);
        $folhaRows = $folhaPontoBuilder->buildRows($inicioMes, $fimMes, $batidas, true, false, $jornada, $feriados, $justificativasDoMes, $jornadaTenantPdf);

        $nomeUsuario = trim((string) $targetUser->getFullName());
        if ($nomeUsuario === '') {
            $nomeUsuario = (string) $targetUser->getUserIdentifier();
        }

        $dados = $this->montarDadosFolha($targetUser, $tenant, $ano, $mes, $folhaRows, $feriados, $jornada, $jornadaTenantPdf, $folhaPontoBuilder);

        $html = $twig->render('ponto/folha_pdf.html.twig', $dados);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $nomeArquivoBase = preg_replace('/[^A-Za-z0-9]+/', '', $dados['nomeUsuario']) ?: 'Usuario';
        $nomeArquivo = sprintf('folha_ponto_%s-%02d-%04d.pdf', $nomeArquivoBase, $mes, $ano);

        $inline = $request->query->getBoolean('inline', false);
        $disposition = $inline
            ? sprintf('inline; filename="%s"', $nomeArquivo)
            : sprintf('attachment; filename="%s"', $nomeArquivo);

        return new Response(
            $dompdf->output(),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => $disposition,
            ]
        );
    }

    #[Route('/exportar-folha-xlsx', name: 'ponto_exportar_xlsx')]
    public function exportarFolhaXlsx(
        Request $request,
        RegistroPontoRepository $repository,
        FeriadoRepository $feriadoRepository,
        JustificativaPontoRepository $justificativaRepository,
        FolhaPontoBuilder $folhaPontoBuilder,
        FolhaPontoXlsxExporter $xlsxExporter,
        UserRepository $userRepository
    ): StreamedResponse {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->assertAccess($user);

        $targetUser = $user;
        $targetUserId = (int) $request->query->get('userId', 0);

        if ($targetUserId > 0 && $targetUserId !== (int) $user->getId()) {
            $targetUser = $userRepository->find($targetUserId);
            if ($targetUser === null) {
                throw $this->createNotFoundException('Usuário para exportação não encontrado.');
            }

            $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);

            if (!$isSuperAdmin && !(
                $this->userTenantRepository->existeVinculoAtivo($targetUser, $tenant)
                && $this->permissionChecker->canAdminister($user, $tenant, 'admin.users.manage')
            )) {
                throw $this->createAccessDeniedException('Sem permissão para exportar folha deste usuário.');
            }
        }

        $mes = max(1, min(12, (int) $request->query->get('mes', (new \DateTimeImmutable())->format('m'))));
        $ano = max(1970, (int) $request->query->get('ano', (new \DateTimeImmutable())->format('Y')));

        /** @var RegistroPonto[] $batidas */
        $batidas = $repository->findByUserAndCompetencia($targetUser, $ano, $mes);
        $jornada  = $targetUser->getJornadaColaborador();
        $feriados = $feriadoRepository->findByTenant($tenant);
        $justificativasDoMes = $justificativaRepository->findByUserAndCompetenciaIndexed($targetUser, $ano, $mes);
        $jornadaTenantXlsx = $tenant->getJornadaTenant();

        $inicioMes = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));
        $fimMes = $inicioMes->modify('last day of this month')->setTime(23, 59, 59);
        $folhaRows = $folhaPontoBuilder->buildRows($inicioMes, $fimMes, $batidas, true, false, $jornada, $feriados, $justificativasDoMes, $jornadaTenantXlsx);

        $nomeUsuario = trim((string) $targetUser->getFullName());
        if ($nomeUsuario === '') {
            $nomeUsuario = (string) $targetUser->getUserIdentifier();
        }

        $nomeUsuario = preg_replace('/[^A-Za-z0-9]+/', '', $nomeUsuario) ?? 'Usuario';
        if ($nomeUsuario === '') {
            $nomeUsuario = 'Usuario';
        }

        $nomeArquivo = sprintf('folha_ponto_%s-%02d-%04d.xlsx', $nomeUsuario, $mes, $ano);

        $dados = $this->montarDadosFolha($targetUser, $tenant, $ano, $mes, $folhaRows, $feriados, $jornada, $jornadaTenantXlsx, $folhaPontoBuilder);

        return $xlsxExporter->exportar($dados['folhaRows'], $nomeArquivo, $dados);
    }

    /**
     * Monta o array completo de dados para os exportadores de PDF e XLSX.
     *
     * @param \App\Ponto\Entity\Feriado[] $feriados
     * @param array<int, array<string, mixed>> $folhaRows
     * @return array<string, mixed>
     */
    private function montarDadosFolha(
        \App\Entity\Auth\User $targetUser,
        Tenant $tenant,
        int $ano,
        int $mes,
        array $folhaRows,
        array $feriados,
        ?\App\Ponto\Entity\JornadaColaborador $jornada,
        ?\App\Ponto\Entity\JornadaTenant $jornadaTenant,
        FolhaPontoBuilder $builder,
    ): array {
        $profile    = $targetUser->getProfile();
        $userTenant = $this->userTenantRepository->findAtivoPorUserETenant($targetUser, $tenant);
        $cargo      = $userTenant?->getCargo();
        $lotacao    = $userTenant?->getLotacao();

        $nomeUsuario = trim((string) $targetUser->getFullName());
        if ($nomeUsuario === '') {
            $nomeUsuario = (string) $targetUser->getUserIdentifier();
        }

        $horarios = $this->resolverHorariosJornada($jornada, $jornadaTenant);

        $minutosSemana = $jornada?->getCargaHorariaSemanal() ?? $jornadaTenant?->getCargaHorariaSemanal() ?? 0;
        $horasSemana = (int) round($minutosSemana / 60);

        $minimoRepouso      = $jornadaTenant?->getMinimoMinutosRepouso()      ?? 60;
        $minimoInterjornada = $jornadaTenant?->getMinimoMinutosInterjornada()  ?? 660;

        $rowsProcessados         = [];
        $totalMinutosTrabalhados = 0;
        $totalMinutosExtras      = 0;
        $feriadosTrabalhados     = 0;
        $finaisSemanasTrabalhados = 0;
        $intrajornadaConforme    = true;
        $interjornadaConforme    = true;
        $ultimaSaidaTs           = null;
        $saldoBancoAtualMinutos  = null;

        foreach ($folhaRows as $row) {
            $row['horasTrabalhadas'] = $this->formatarMinutos($row['minutosTrabalhadosDia'] ?? null);
            $row['horasExtras']      = ($row['saldoDia'] ?? 0) > 0 ? $this->formatarMinutos($row['saldoDia']) : '–';
            $row['bancoHoras']       = $this->formatarSaldo($row['saldoAcumulado'] ?? null);
            $rowsProcessados[]       = $row;

            $totalMinutosTrabalhados += ($row['minutosTrabalhadosDia'] ?? 0);

            if (($row['saldoDia'] ?? 0) > 0) {
                $totalMinutosExtras += $row['saldoDia'];
            }

            if (!empty($row['isFeriado']) && $row['entrada'] !== '') {
                $feriadosTrabalhados++;
            }

            if (!empty($row['fimSemana']) && $row['entrada'] !== '') {
                $finaisSemanasTrabalhados++;
            }

            if ($row['minutosIntervalo'] !== null && ($row['minutosTrabalhadosDia'] ?? 0) > 0) {
                if ($row['minutosIntervalo'] < $minimoRepouso) {
                    $intrajornadaConforme = false;
                }
            }

            if ($row['entrada'] !== '' && $ultimaSaidaTs !== null) {
                $entradaDt = new \DateTimeImmutable($row['chaveDia'] . ' ' . $row['entrada']);
                $diffMin = (int)(($entradaDt->getTimestamp() - $ultimaSaidaTs) / 60);
                if ($diffMin < $minimoInterjornada) {
                    $interjornadaConforme = false;
                }
            }

            if ($row['saida'] !== '') {
                $saidaDt = new \DateTimeImmutable($row['chaveDia'] . ' ' . $row['saida']);
                $ultimaSaidaTs = $saidaDt->getTimestamp();
            }

            if ($row['saldoAcumulado'] !== null) {
                $saldoBancoAtualMinutos = $row['saldoAcumulado'];
            }
        }

        $mesAnterior = $mes - 1;
        $anoAnterior = $ano;
        if ($mesAnterior < 1) {
            $mesAnterior = 12;
            $anoAnterior--;
        }
        $saldoBancoAnteriorMinutos = $jornada !== null
            ? $builder->calcularSaldoAteMes($targetUser, $anoAnterior, $mesAnterior, $feriados, $jornadaTenant)
            : 0;

        $enderecoPartes = array_filter([
            $tenant?->getLogradouro(),
            $tenant?->getNumero() ? ', ' . $tenant->getNumero() : null,
            $tenant?->getComplemento() ? ' – ' . $tenant->getComplemento() : null,
        ]);
        $cidadeEstado = trim(
            ($tenant?->getBairro() ? $tenant->getBairro() . ' – ' : '')
            . ($tenant?->getCidade() ?? '')
            . ($tenant?->getEstado() ? ' – ' . $tenant->getEstado() : '')
        );
        $cep = $tenant?->getCep() ? ' CEP ' . $tenant->getCep() : '';

        $distribuicao = $this->gerarDescricaoDiasExtenso($jornada, $jornadaTenant);
        $descrJornada = $this->gerarDescrJornada($jornada, $jornadaTenant);

        return [
            'folhaRows'               => $rowsProcessados,
            'mes'                     => $mes,
            'ano'                     => $ano,
            'anoAssinatura'           => $ano,
            'inicioMes'               => (new \DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes)))->format('d/m/Y'),
            'fimMes'                  => (new \DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes)))->modify('last day of this month')->format('d/m/Y'),
            'nomeUsuario'             => mb_strtoupper($nomeUsuario),
            'nomeEmpresa'             => mb_strtoupper($tenant?->getName() ?? ''),
            'cnpj'                    => $this->formatarCnpj($tenant?->getCnpj() ?? ''),
            'enderecoLinha1'          => implode('', $enderecoPartes),
            'enderecoLinha2'          => trim($cidadeEstado . $cep),
            'codigoFuncionario'       => $userTenant?->getCodigoFuncionario()
                ?? $this->gerarCodigo->executar($tenant),
            'cpf'                     => $this->formatarCpf($profile?->getCpf() ?? ''),
            'cargo'                   => mb_strtoupper($cargo?->getNome() ?? ''),
            'ctps'                    => $profile?->getCtps() ?? '',
            'serie'                   => $profile?->getSerie() ?? '',
            'lotacao'                 => ($lotacao !== null ? mb_strtoupper($lotacao->getNome()) : ''),
            'dataAdmissao'            => $userTenant?->getDataAdmissao()?->format('d/m/Y') ?? '',
            'jornadaSemanal'          => $horasSemana . 'h',
            'jornadaSemanalTexto'     => $horasSemana . 'h semanais',
            'horarioContratual'       => $horarios['entrada'] . ' às ' . $horarios['saida'],
            'intervaloTexto'          => $horarios['repouso'] . ' às ' . $horarios['retorno'],
            'distribuicaoJornada'     => $distribuicao,
            'escalaDescricao'         => $distribuicao . ($descrJornada !== '' ? ' (' . $descrJornada . ')' : ''),
            'minimoRepousoTexto'      => $this->formatarDuracaoHora($minimoRepouso),
            'minimoInterjornadaTexto' => $this->formatarDuracaoHora($minimoInterjornada),
            'totalHorasTrabalhadas'   => $this->formatarMinutos($totalMinutosTrabalhados ?: null),
            'totalHorasExtras'        => $this->formatarMinutos($totalMinutosExtras ?: null),
            'saldoBancoAnterior'      => $this->formatarSaldo($saldoBancoAnteriorMinutos),
            'saldoBancoAtual'         => $this->formatarSaldo($saldoBancoAtualMinutos),
            'horasACompensar'         => ($saldoBancoAtualMinutos !== null && $saldoBancoAtualMinutos < 0)
                ? $this->formatarMinutos(abs($saldoBancoAtualMinutos))
                : '–',
            'feriadosTrabalhados'     => $feriadosTrabalhados,
            'finaisSemanasTrabalhados' => $finaisSemanasTrabalhados,
            'intrajornadaConforme'    => $intrajornadaConforme,
            'interjornadaConforme'    => $interjornadaConforme,
            'responsavelAssinatura'   => $tenant?->getResponsavelAssinatura() ?? '',
            // retrocompatibilidade com campos usados pela visualização da folha na tela
            'numeroTrabalhador' => $userTenant?->getCodigoFuncionario()
                ?? $this->gerarCodigo->executar($tenant),
            'descrJornada'  => $descrJornada,
            'horaEntrada'   => $horarios['entrada'] . ' h',
            'horaRepouso'   => $horarios['repouso'] . ' h',
            'horaRetorno'   => $horarios['retorno'] . ' h',
            'horaSaida'     => $horarios['saida'] . ' h',
        ];
    }

    private function assertAccess(User $user): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('Sem tenant selecionado.');
        }
        if (!$this->permissionChecker->canAccessModule($user, $tenant, 'ponto')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Ponto Eletrônico.');
        }

        return $tenant;
    }

    private function formatarMinutos(int|null $minutos): string
    {
        if ($minutos === null || $minutos === 0) {
            return '–';
        }

        $abs = abs($minutos);

        return sprintf('%d:%02d', intdiv($abs, 60), $abs % 60);
    }

    private function formatarSaldo(int|null $minutos): string
    {
        if ($minutos === null) {
            return '–';
        }

        $abs  = abs($minutos);
        $hhmm = sprintf('%d:%02d', intdiv($abs, 60), $abs % 60);

        return $minutos >= 0 ? '+' . $hhmm : '-' . $hhmm;
    }

    private function formatarCnpj(string $cnpj): string
    {
        $raw = preg_replace('/\D/', '', $cnpj) ?? '';
        if (strlen($raw) === 14) {
            return sprintf('%s.%s.%s/%s-%s', substr($raw, 0, 2), substr($raw, 2, 3), substr($raw, 5, 3), substr($raw, 8, 4), substr($raw, 12, 2));
        }

        return $cnpj;
    }

    private function formatarCpf(string $cpf): string
    {
        $raw = preg_replace('/\D/', '', $cpf) ?? '';
        if (strlen($raw) === 11) {
            return sprintf('%s.%s.%s-%s', substr($raw, 0, 3), substr($raw, 3, 3), substr($raw, 6, 3), substr($raw, 9, 2));
        }

        return $cpf;
    }

    private function formatarDuracaoHora(int $minutos): string
    {
        $horas = $minutos / 60;
        if ($horas === floor($horas)) {
            return ((int) $horas) . 'h';
        }

        return sprintf('%dh%02d', intdiv($minutos, 60), $minutos % 60);
    }

    private function gerarDescricaoDiasExtenso(
        ?\App\Ponto\Entity\JornadaColaborador $jornada,
        ?\App\Ponto\Entity\JornadaTenant $jornadaTenant,
    ): string {
        $mapa = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
        $dias = $this->getDiasSemana($jornada, $jornadaTenant);

        if (empty($dias)) {
            return '';
        }

        sort($dias);

        if (count($dias) === 1) {
            return $mapa[$dias[0]] ?? '';
        }

        $isConsecutivo = true;
        for ($i = 1; $i < count($dias); $i++) {
            if ($dias[$i] - $dias[$i - 1] !== 1) {
                $isConsecutivo = false;
                break;
            }
        }

        if ($isConsecutivo) {
            return ($mapa[$dias[0]] ?? '') . ' a ' . ($mapa[$dias[count($dias) - 1]] ?? '');
        }

        return implode(', ', array_map(static fn(int $d) => $mapa[$d] ?? '', $dias));
    }

    /** @return int[] */
    private function getDiasSemana(
        ?\App\Ponto\Entity\JornadaColaborador $jornada,
        ?\App\Ponto\Entity\JornadaTenant $jornadaTenant,
    ): array {
        if ($jornada !== null && !$jornada->getBlocos()->isEmpty()) {
            $dias = [];
            foreach ($jornada->getBlocos() as $bloco) {
                foreach ($bloco->getDiasSemana() as $d) {
                    $dias[$d] = true;
                }
            }

            return array_keys($dias);
        }

        if ($jornadaTenant !== null && !$jornadaTenant->getBlocos()->isEmpty()) {
            $dias = [];
            foreach ($jornadaTenant->getBlocos() as $bloco) {
                foreach ($bloco->getDiasSemana() as $d) {
                    $dias[$d] = true;
                }
            }

            return array_keys($dias);
        }

        if ($jornada !== null) {
            return $jornada->getDiasSemana();
        }

        return [];
    }

    /**
     * Resolve os horários de jornada usando a mesma hierarquia do JornadaResolver:
     * 1. Blocos do usuário (primeiro dia útil encontrado)
     * 2. Blocos do tenant (primeiro dia útil encontrado)
     * 3. Campos planos da JornadaColaborador (legado)
     * 4. Defaults fixos
     *
     * @return array{entrada: string, repouso: string, retorno: string, saida: string}
     */
    private function resolverHorariosJornada(
        ?\App\Ponto\Entity\JornadaColaborador $jornada,
        ?\App\Ponto\Entity\JornadaTenant $jornadaTenant,
    ): array {
        $diasUteis = [1, 2, 3, 4, 5];

        if ($jornada !== null && !$jornada->getBlocos()->isEmpty()) {
            foreach ($jornada->getBlocos() as $bloco) {
                foreach ($diasUteis as $dia) {
                    if (in_array($dia, $bloco->getDiasSemana(), true)) {
                        return [
                            'entrada' => $bloco->getEntrada(),
                            'repouso' => $bloco->getRepouso() ?? '',
                            'retorno' => $bloco->getRetorno() ?? '',
                            'saida'   => $bloco->getSaida(),
                        ];
                    }
                }
            }
        }

        if ($jornadaTenant !== null && !$jornadaTenant->getBlocos()->isEmpty()) {
            foreach ($jornadaTenant->getBlocos() as $bloco) {
                foreach ($diasUteis as $dia) {
                    if (in_array($dia, $bloco->getDiasSemana(), true)) {
                        return [
                            'entrada' => $bloco->getEntrada(),
                            'repouso' => $bloco->getRepouso() ?? '',
                            'retorno' => $bloco->getRetorno() ?? '',
                            'saida'   => $bloco->getSaida(),
                        ];
                    }
                }
            }
        }

        if ($jornada !== null) {
            return [
                'entrada' => $jornada->getEntrada1() ?? '08:00',
                'repouso' => $jornada->getSaida1()   ?? '12:00',
                'retorno' => $jornada->getEntrada2() ?? '13:00',
                'saida'   => $jornada->getSaida2()   ?? '17:00',
            ];
        }

        return ['entrada' => '08:00', 'repouso' => '12:00', 'retorno' => '13:00', 'saida' => '17:00'];
    }

    /**
     * Gera a descrição da jornada (ex: "STQQS") a partir dos dias de trabalho.
     * Usa a mesma hierarquia do JornadaResolver: blocos do colaborador > blocos do tenant > jornada plana.
     */
    private function gerarDescrJornada(
        ?\App\Ponto\Entity\JornadaColaborador $jornada,
        ?\App\Ponto\Entity\JornadaTenant $jornadaTenant,
    ): string {
        $mapa = [1 => 'S', 2 => 'T', 3 => 'Q', 4 => 'Q', 5 => 'S', 6 => 'S', 7 => 'D'];

        if ($jornada !== null && !$jornada->getBlocos()->isEmpty()) {
            $dias = [];
            foreach ($jornada->getBlocos() as $bloco) {
                foreach ($bloco->getDiasSemana() as $d) {
                    $dias[$d] = true;
                }
            }
            ksort($dias);
            return implode('', array_map(static fn(int $d) => $mapa[$d] ?? '', array_keys($dias)));
        }

        if ($jornadaTenant !== null && !$jornadaTenant->getBlocos()->isEmpty()) {
            $dias = [];
            foreach ($jornadaTenant->getBlocos() as $bloco) {
                foreach ($bloco->getDiasSemana() as $d) {
                    $dias[$d] = true;
                }
            }
            ksort($dias);
            return implode('', array_map(static fn(int $d) => $mapa[$d] ?? '', array_keys($dias)));
        }

        if ($jornada !== null) {
            $dias = $jornada->getDiasSemana();
            sort($dias);
            return implode('', array_map(static fn(int $d) => $mapa[$d] ?? '', $dias));
        }

        return '';
    }
}
