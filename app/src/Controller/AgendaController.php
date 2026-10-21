<?php

namespace App\Controller;

use App\Agenda\Entity\Evento;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Form\EventoType;
use App\Repository\EventoRepository;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * AgendaController - Gerencia eventos do calendário.
 * 
 * Estrutura de rotas:
 * - GET  /agenda                  → tela nova (App\Agenda\Controller\AgendaNovaController, entrega 3b)
 * - GET  /agenda/lista            → Exibe lista de eventos
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
        private readonly UserRepository $userRepository,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * Exibe a lista de eventos
     */
    #[Route('/lista', name: 'agenda_lista', methods: ['GET'])]
    public function lista(EventoRepository $eventoRepository, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$this->permissionChecker->canAccessModule($user, $tenant, 'agenda')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de agenda.');
            return $this->redirectToRoute('homepage');
        }

        $status = $request->query->get('status');

        // DASH-009: `responsavel` (vindo do número "Eventos na agenda" do Dashboard) troca a lista
        // pela agenda daquela pessoa, na régua do contador. A pessoa precisa ser do escritório
        // atual: id desconhecido, de outro escritório ou não numérico devolve lista VAZIA — nunca
        // a lista sem filtro, que mostraria eventos que o número não conta.
        $responsavel = trim((string) $request->query->get('responsavel', ''));
        if ($responsavel === '') {
            return $this->render('agenda/lista.html.twig', [
                'eventos' => $eventoRepository->findForCalendar($status),
                'statusFiltro' => $status,
            ]);
        }

        // Data fora do 'Y-m-d' estrito é ignorada, como no contador do Dashboard.
        $dataValida = static function (string $valor): string {
            $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

            return $data !== false && $data->format('Y-m-d') === $valor ? $valor : '';
        };
        $periodo = [
            'data_de'  => $dataValida(trim((string) $request->query->get('data_de', ''))),
            'data_ate' => $dataValida(trim((string) $request->query->get('data_ate', ''))),
        ];
        $pessoa = $tenant !== null && ctype_digit($responsavel)
            ? ($this->userRepository->findPorIdsETenant([(int) $responsavel], $tenant)[0] ?? null)
            : null;
        // SEC-016: a agenda da pessoa passa ainda pela visibilidade de quem está olhando ($user) —
        // "Para Todos" com participantes só aparece para o criador e os participantes.
        $eventos = $pessoa instanceof User && $tenant !== null
            ? $eventoRepository->findNaAgendaDaPessoa($tenant, $pessoa, $user, $status, $periodo)
            : [];

        return $this->render('agenda/lista.html.twig', [
            'eventos' => $eventos,
            'statusFiltro' => $status,
            'filtroPessoa' => [
                'responsavel' => $responsavel,
                'nome' => $pessoa?->getFullName(),
                'data_de' => $periodo['data_de'],
                'data_ate' => $periodo['data_ate'],
            ],
        ]);
    }

    /**
     * Formulário de criação de evento
     */
    #[Route('/novo', name: 'agenda_novo', methods: ['GET', 'POST'])]
    public function novo(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$this->permissionChecker->canAccessModule($user, $tenant, 'agenda')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de agenda.');
            return $this->redirectToRoute('homepage');
        }

        $evento = new Evento();
        $evento->setCriador($user);
        $evento->setTenant($tenant);

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

        $form = $this->createForm(EventoType::class, $evento, ['tenant' => $tenant]);
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
            foreach ($this->participantesAvisaveis($evento) as $participante) {
                $this->notificacaoService->criarNotificacao(
                    $participante,
                    $evento->getTenant(),
                    Notificacao::TIPO_EVENTO_CRIADO,
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
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$this->permissionChecker->canAccessModule($currentUser, $tenant, 'agenda')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de agenda.');
            return $this->redirectToRoute('homepage');
        }

        return $this->render('agenda/show.html.twig', [
            'evento' => $evento,
        ]);
    }

    /**
     * Formulário de edição
     */
    #[Route('/{id}/editar', name: 'agenda_editar', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editar(Evento $evento, Request $request, EntityManagerInterface $em, PermissionChecker $permissionChecker): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        // Apenas admin ou criador pode editar
        if (!$permissionChecker->canAdminister($user, $tenant, 'admin.users.manage') && $evento->getCriador() !== $user) {
            $this->addFlash('danger', 'Você não tem permissão para editar este evento.');
            return $this->redirectToRoute('agenda_index');
        }

        // Calcular duração atual em horas
        $dataFimTs = $evento->getDataFim()?->getTimestamp() ?? time();
        $dataInicioTs = $evento->getDataInicio()?->getTimestamp() ?? time();
        $duracaoAtual = ($dataFimTs - $dataInicioTs) / 3600;

        $form = $this->createForm(EventoType::class, $evento, [
            'duracao_inicial' => max(0.5, $duracaoAtual),
            'tenant' => $tenant,
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
    public function excluir(Evento $evento, Request $request, EntityManagerInterface $em, PermissionChecker $permissionChecker): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        // Apenas admin ou criador pode excluir
        if (!$permissionChecker->canAdminister($user, $tenant, 'admin.users.manage') && $evento->getCriador() !== $user) {
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
    public function cancelar(Evento $evento, Request $request, EntityManagerInterface $em, PermissionChecker $permissionChecker): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        // Apenas admin ou criador pode cancelar
        if (!$permissionChecker->canAdminister($user, $tenant, 'admin.users.manage') && $evento->getCriador() !== $user) {
            $this->addFlash('danger', 'Você não tem permissão para cancelar este evento.');
            return $this->redirectToRoute('agenda_index');
        }

        if ($this->isCsrfTokenValid('cancelar' . $evento->getId(), $request->request->get('_token'))) {
            $evento->setStatus(Evento::STATUS_CANCELADO);
            $em->flush();

            // Notificar participantes sobre cancelamento
            foreach ($this->participantesAvisaveis($evento) as $participante) {
                $this->notificacaoService->criarNotificacao(
                    $participante,
                    $evento->getTenant(),
                    Notificacao::TIPO_EVENTO_CANCELADO,
                    'O evento foi cancelado: ' . $evento->getTitulo(),
                    $this->generateUrl('agenda_show', ['id' => $evento->getId()])
                );
            }

            $this->addFlash('warning', 'Evento cancelado.');
        }

        return $this->redirectToRoute('agenda_index');
    }

    /**
     * "Só eu" só a pessoa vê: os participantes não enxergam o compromisso, e o aviso (com o título) ainda
     * iria para a auditoria pela Notificacao. Então "Só eu" não avisa ninguém.
     *
     * @return iterable<User>
     */
    private function participantesAvisaveis(Evento $evento): iterable
    {
        return Evento::VISIBILIDADE_SOMENTE_EU === $evento->getVisibilidade() ? [] : $evento->getParticipantes();
    }
}
