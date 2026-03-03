<?php

namespace App\Controller;

use App\Entity\Agenda\Evento;
use App\Entity\Auth\User;
use App\Form\EventoType;
use App\Repository\EventoRepository;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * AgendaController - Gerencia eventos do calendário.
 * 
 * Estrutura de rotas:
 * - GET  /agenda                  → Exibe calendário
 * - GET  /agenda/lista            → Exibe lista de eventos
 * - GET  /agenda/eventos          → API JSON para FullCalendar
 * - GET  /agenda/novo             → Formulário de criação
 * - POST /agenda/novo             → Cria novo evento
 * - GET  /agenda/{id}             → Exibe detalhes do evento
 * - GET  /agenda/{id}/editar      → Formulário de edição
 * - POST /agenda/{id}/editar      → Atualiza evento
 * - POST /agenda/{id}/excluir     → Exclui evento
 * - POST /agenda/{id}/cancelar    → Cancela evento
 */
#[Route('/agenda')]
class AgendaController extends AbstractController
{
    public function __construct(
        private readonly NotificacaoService $notificacaoService,
        private readonly UserRepository $userRepository
    ) {
    }

    /**
     * Exibe o calendário
     */
    #[Route('', name: 'agenda_index', methods: ['GET'])]
    public function index(): Response
    {
        $usuarios = $this->userRepository->findBy(['isActive' => true], ['fullName' => 'ASC']);
        
        return $this->render('agenda/index.html.twig', [
            'usuarios' => $usuarios,
        ]);
    }

    /**
     * Exibe a lista de eventos
     */
    #[Route('/lista', name: 'agenda_lista', methods: ['GET'])]
    public function lista(EventoRepository $eventoRepository, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $status = $request->query->get('status');
        $eventos = $eventoRepository->findForCalendar($status);

        return $this->render('agenda/lista.html.twig', [
            'eventos' => $eventos,
            'statusFiltro' => $status,
        ]);
    }

    /**
     * API JSON para FullCalendar
     */
    #[Route('/eventos', name: 'agenda_eventos', methods: ['GET'])]
    public function eventos(EventoRepository $eventoRepository, Request $request): JsonResponse
    {
        $start = $request->query->get('start');
        $end = $request->query->get('end');

        if ($start && $end) {
            $startDate = new \DateTimeImmutable($start);
            $endDate = new \DateTimeImmutable($end);
            $eventos = $eventoRepository->findByDateRange($startDate, $endDate);
        } else {
            $eventos = $eventoRepository->findForCalendar();
        }

        // Processar eventos (podem ser entidades Evento ou arrays de ocorrências recorrentes)
        $data = array_map(function($item) {
            if ($item instanceof Evento) {
                return $item->toFullCalendarArray();
            }
            // Já é um array (ocorrência de evento recorrente)
            return $item;
        }, $eventos);

        return $this->json($data);
    }

    /**
     * API JSON para criar evento via AJAX (modal)
     */
    #[Route('/criar-ajax', name: 'agenda_criar_ajax', methods: ['POST'])]
    public function criarAjax(Request $request, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $data = json_decode($request->getContent(), true);

            if (empty($data['titulo'])) {
                return $this->json(['success' => false, 'error' => 'Título é obrigatório']);
            }

            $evento = new Evento();
            $evento->setCriador($user);
            $evento->setTitulo($data['titulo']);
            $evento->setDescricao($data['descricao'] ?? null);
            $evento->setLocal($data['local'] ?? null);
            $evento->setCor($data['cor'] ?? Evento::COR_AZUL);
            $evento->setStatus($data['status'] ?? Evento::STATUS_AGENDADO);
            $evento->setDiaInteiro($data['diaInteiro'] ?? false);

            $dataInicio = new \DateTimeImmutable($data['dataInicio']);
            $evento->setDataInicio($dataInicio);

            $duracao = (float) ($data['duracao'] ?? 1);
            $minutos = (int) ($duracao * 60);
            $dataFim = $dataInicio->modify("+{$minutos} minutes");
            $evento->setDataFim($dataFim);

            // Recorrência
            if (!empty($data['recorrente'])) {
                $evento->setRecorrente(true);
                $evento->setTipoRecorrencia($data['tipoRecorrencia'] ?? null);
                if (!empty($data['fimRecorrencia'])) {
                    $evento->setFimRecorrencia(new \DateTimeImmutable($data['fimRecorrencia']));
                }
            }

            // Participantes
            if (!empty($data['participantes']) && is_array($data['participantes'])) {
                foreach ($data['participantes'] as $userId) {
                    $participante = $this->userRepository->find($userId);
                    if ($participante) {
                        $evento->addParticipante($participante);
                    }
                }
            }

            $em->persist($evento);
            $em->flush();

            // Notificar participantes
            foreach ($evento->getParticipantes() as $participante) {
                $this->notificacaoService->criarNotificacao(
                    $participante,
                    'EVENTO_CRIADO',
                    'Você foi convidado para o evento: ' . $evento->getTitulo(),
                    $this->generateUrl('agenda_show', ['id' => $evento->getId()])
                );
            }

            return $this->json([
                'success' => true,
                'evento' => $evento->toFullCalendarArray()
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Formulário de criação de evento
     */
    #[Route('/novo', name: 'agenda_novo', methods: ['GET', 'POST'])]
    public function novo(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $evento = new Evento();
        $evento->setCriador($user);

        // Se veio data do clique no calendário
        if ($request->query->has('data')) {
            $data = new \DateTimeImmutable($request->query->get('data'));
            $evento->setDataInicio($data);
            $evento->setDataFim($data->modify('+1 hour'));
        } else {
            $now = new \DateTimeImmutable();
            $evento->setDataInicio($now);
            $evento->setDataFim($now->modify('+1 hour'));
        }

        $form = $this->createForm(EventoType::class, $evento);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Calcular dataFim baseado na duração
            $duracao = (float) $form->get('duracao')->getData();
            $minutos = (int) ($duracao * 60);
            $dataInicio = $evento->getDataInicio() ?? new \DateTimeImmutable();
            $dataFim = $dataInicio->modify("+{$minutos} minutes");
            $evento->setDataFim($dataFim);

            $em->persist($evento);
            $em->flush();

            // Notificar participantes
            foreach ($evento->getParticipantes() as $participante) {
                $this->notificacaoService->criarNotificacao(
                    $participante,
                    'EVENTO_CRIADO',
                    'Você foi convidado para o evento: ' . $evento->getTitulo(),
                    $this->generateUrl('agenda_show', ['id' => $evento->getId()])
                );
            }

            $this->addFlash('success', 'Evento criado com sucesso!');
            return $this->redirectToRoute('agenda_index');
        }

        return $this->render('agenda/novo.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Exibe detalhes do evento
     */
    #[Route('/{id}', name: 'agenda_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Evento $evento): Response
    {
        return $this->render('agenda/show.html.twig', [
            'evento' => $evento,
        ]);
    }

    /**
     * Formulário de edição
     */
    #[Route('/{id}/editar', name: 'agenda_editar', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editar(Evento $evento, Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Apenas admin ou criador pode editar
        if (!$this->isGranted('ROLE_ADMIN') && $evento->getCriador() !== $user) {
            $this->addFlash('danger', 'Você não tem permissão para editar este evento.');
            return $this->redirectToRoute('agenda_index');
        }

        // Calcular duração atual em horas
        $dataFimTs = $evento->getDataFim()?->getTimestamp() ?? time();
        $dataInicioTs = $evento->getDataInicio()?->getTimestamp() ?? time();
        $duracaoAtual = ($dataFimTs - $dataInicioTs) / 3600;

        $form = $this->createForm(EventoType::class, $evento, [
            'duracao_inicial' => max(0.5, $duracaoAtual),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Calcular dataFim baseado na duração
            $duracao = (float) $form->get('duracao')->getData();
            $minutos = (int) ($duracao * 60);
            $dataInicio = $evento->getDataInicio() ?? new \DateTimeImmutable();
            $dataFim = $dataInicio->modify("+{$minutos} minutes");
            $evento->setDataFim($dataFim);

            $em->flush();

            $this->addFlash('success', 'Evento atualizado com sucesso!');
            return $this->redirectToRoute('agenda_show', ['id' => $evento->getId()]);
        }

        return $this->render('agenda/editar.html.twig', [
            'form' => $form->createView(),
            'evento' => $evento,
        ]);
    }

    /**
     * Exclui evento
     */
    #[Route('/{id}/excluir', name: 'agenda_excluir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function excluir(Evento $evento, Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Apenas admin ou criador pode excluir
        if (!$this->isGranted('ROLE_ADMIN') && $evento->getCriador() !== $user) {
            $this->addFlash('danger', 'Você não tem permissão para excluir este evento.');
            return $this->redirectToRoute('agenda_index');
        }

        if ($this->isCsrfTokenValid('delete' . $evento->getId(), $request->request->get('_token'))) {
            $em->remove($evento);
            $em->flush();
            $this->addFlash('success', 'Evento excluído com sucesso!');
        }

        return $this->redirectToRoute('agenda_index');
    }

    /**
     * Cancela evento
     */
    #[Route('/{id}/cancelar', name: 'agenda_cancelar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancelar(Evento $evento, Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Apenas admin ou criador pode cancelar
        if (!$this->isGranted('ROLE_ADMIN') && $evento->getCriador() !== $user) {
            $this->addFlash('danger', 'Você não tem permissão para cancelar este evento.');
            return $this->redirectToRoute('agenda_index');
        }

        if ($this->isCsrfTokenValid('cancelar' . $evento->getId(), $request->request->get('_token'))) {
            $evento->setStatus(Evento::STATUS_CANCELADO);
            $em->flush();

            // Notificar participantes sobre cancelamento
            foreach ($evento->getParticipantes() as $participante) {
                $this->notificacaoService->criarNotificacao(
                    $participante,
                    'EVENTO_CANCELADO',
                    'O evento foi cancelado: ' . $evento->getTitulo(),
                    $this->generateUrl('agenda_show', ['id' => $evento->getId()])
                );
            }

            $this->addFlash('warning', 'Evento cancelado.');
        }

        return $this->redirectToRoute('agenda_index');
    }

    /**
     * API para atualizar evento via drag & drop no calendário
     */
    #[Route('/{id}/atualizar-datas', name: 'agenda_atualizar_datas', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function atualizarDatas(Evento $evento, Request $request, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Apenas admin ou criador pode atualizar
        if (!$this->isGranted('ROLE_ADMIN') && $evento->getCriador() !== $user) {
            return $this->json(['success' => false, 'message' => 'Sem permissão'], 403);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['start'])) {
            $evento->setDataInicio(new \DateTimeImmutable($data['start']));
        }
        if (isset($data['end'])) {
            $evento->setDataFim(new \DateTimeImmutable($data['end']));
        }
        if (isset($data['allDay'])) {
            $evento->setDiaInteiro($data['allDay']);
        }

        $em->flush();

        return $this->json(['success' => true]);
    }
}
