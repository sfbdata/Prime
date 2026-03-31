<?php

namespace App\Controller;

use App\Entity\Auth\User;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\ServiceDesk\ChamadoAnexo;
use App\Entity\ServiceDesk\ChamadoInteracao;
use App\Form\ChamadoInteracaoType;
use App\Form\ChamadoType;
use App\Repository\ChamadoRepository;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * ServiceDeskController - Gerencia chamados de TI (Help Desk)
 *
 * FLUXO DE STATUS DO CHAMADO:
 * ┌─────────────┐
 * │   ABERTO    │ Usuário cria chamado
 * └──────┬──────┘
 *        ↓ TI atribui responsável
 * ┌─────────────────┐
 * │  EM_ANDAMENTO   │ Técnico trabalhando
 * └──────┬──────────┘
 *        ↓ Técnico resolve
 * ┌─────────────┐
 * │  RESOLVIDO  │ Aguardando confirmação do usuário
 * └──────┬──────┘
 *        ↓ Usuário confirma ou é fechado automaticamente
 * ┌─────────────┐
 * │   FECHADO   │ Chamado encerrado
 * └─────────────┘
 *
 * Rotas:
 * - GET  /servicedesk                     → Dashboard (TI)
 * - GET  /servicedesk/meus-chamados       → Lista chamados do usuário
 * - GET  /servicedesk/novo                → Formulário de abertura
 * - POST /servicedesk/novo                → Cria novo chamado
 * - GET  /servicedesk/{id}                → Visualiza chamado
 * - POST /servicedesk/{id}/interacao      → Adiciona interação
 * - POST /servicedesk/{id}/atribuir       → Atribui responsável (TI)
 * - POST /servicedesk/{id}/status         → Altera status (TI)
 */
#[Route('/servicedesk')]
class ServiceDeskController extends AbstractController
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly NotificacaoService $notificacaoService,
        private readonly SluggerInterface $slugger
    ) {
    }

    /**
     * Dashboard do Service Desk (apenas para equipe de TI)
     */
    #[Route('', name: 'servicedesk_index', methods: ['GET'])]
    public function index(Request $request, ChamadoRepository $chamadoRepository, UserRepository $userRepository, PermissionChecker $permissionChecker): Response
    {
        /** @var \App\Entity\Auth\User $usuario */
        $usuario = $this->getUser();
        if (!$permissionChecker->canAdminister($usuario, 'admin.servicedesk.manage')) {
            throw $this->createAccessDeniedException('Você não tem permissão para acessar o painel do Service Desk.');
        }

        // Filtros
        $responsavelId = $request->query->get('responsavel');
        $responsavel = $responsavelId ? $userRepository->find($responsavelId) : null;

        $filtros = [
            'status' => $request->query->get('status'),
            'categoria' => $request->query->get('categoria'),
            'prioridade' => $request->query->get('prioridade'),
            'responsavel' => $responsavelId,
            'busca' => $request->query->get('busca'),
        ];

        $chamados = $chamadoRepository->findAllFiltered(
            $filtros['status'],
            $filtros['categoria'],
            $filtros['prioridade'],
            $responsavel,
            $filtros['busca']
        );

        // Lista de técnicos para filtro
        $tecnicos = $userRepository->findBy(['isActive' => true], ['fullName' => 'ASC']);

        // Estatísticas
        $countsByStatus = $chamadoRepository->countByStatus();
        $stats = [
            'total' => count($chamados),
            'abertos' => $countsByStatus[Chamado::STATUS_ABERTO] ?? 0,
            'em_andamento' => $countsByStatus[Chamado::STATUS_EM_ANDAMENTO] ?? 0,
            'resolvidos' => $countsByStatus[Chamado::STATUS_RESOLVIDO] ?? 0,
            'nao_atribuidos' => count($chamadoRepository->findAbertosNaoAtribuidos()),
            'urgentes' => count($chamadoRepository->findUrgentes()),
            'tempo_medio' => $chamadoRepository->getTempoMedioResolucao(),
            'por_categoria' => $chamadoRepository->countByCategoria(),
        ];

        return $this->render('servicedesk/index.html.twig', [
            'chamados' => $chamados,
            'stats' => $stats,
            'filtros' => $filtros,
            'categorias' => $this->getCategorias(),
            'prioridades' => $this->getPrioridades(),
            'statusList' => $this->getStatusList(),
            'tecnicos' => $tecnicos,
        ]);
    }

    /**
     * Lista chamados do usuário logado
     */
    #[Route('/meus-chamados', name: 'servicedesk_meus_chamados', methods: ['GET'])]
    public function meusChamados(ChamadoRepository $chamadoRepository, PermissionChecker $permissionChecker): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        if (!$permissionChecker->canAccessModule($usuario, 'servicedesk')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de service desk.');
            return $this->redirectToRoute('homepage');
        }

        $chamados = $chamadoRepository->findBySolicitante($usuario);

        return $this->render('servicedesk/meus_chamados.html.twig', [
            'chamados' => $chamados,
        ]);
    }

    /**
     * Formulário para novo chamado
     */
    #[Route('/novo', name: 'servicedesk_novo', methods: ['GET', 'POST'])]
    public function novo(Request $request, EntityManagerInterface $em, PermissionChecker $permissionChecker): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        if (!$permissionChecker->canAccessModule($usuario, 'servicedesk')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de service desk.');
            return $this->redirectToRoute('homepage');
        }

        $chamado = new Chamado();
        $chamado->setSolicitante($usuario);

        $form = $this->createForm(ChamadoType::class, $chamado, [
            'is_edit' => false,
            'is_admin' => false,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Processar anexos
            $anexos = $form->get('anexos')->getData();
            if ($anexos) {
                $this->processarAnexos($chamado, $anexos, $usuario);
            }

            // Criar interação de abertura
            $interacao = new ChamadoInteracao();
            $interacao->setChamado($chamado);
            $interacao->setUsuario($usuario);
            $interacao->setTipo(ChamadoInteracao::TIPO_SISTEMA);
            $interacao->setMensagem("Chamado aberto por {$usuario->getFullName()}");

            $em->persist($chamado);
            $em->persist($interacao);
            $em->flush();

            // Notificar equipe de TI
            $this->notificarNovoChamado($chamado);

            $this->addFlash('success', 'Chamado #' . $chamado->getId() . ' aberto com sucesso!');
            return $this->redirectToRoute('servicedesk_meus_chamados');
        }

        return $this->render('servicedesk/novo.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Visualiza um chamado
     */
    #[Route('/{id}', name: 'servicedesk_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Chamado $chamado, UserRepository $userRepository, PermissionChecker $permissionChecker): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $isAdmin = $permissionChecker->canAdminister($usuario, 'admin.servicedesk.manage');

        // Verificar permissão
        if (!$isAdmin && $chamado->getSolicitante() !== $usuario) {
            throw $this->createAccessDeniedException('Você não tem permissão para ver este chamado.');
        }

        // Formulário de interação
        $interacaoForm = $this->createForm(ChamadoInteracaoType::class, new ChamadoInteracao(), [
            'is_admin' => $isAdmin,
            'action' => $this->generateUrl('servicedesk_interacao', ['id' => $chamado->getId()]),
        ]);

        // Lista de técnicos para atribuição (apenas para admin)
        $tecnicos = [];
        if ($isAdmin) {
            $tecnicos = $userRepository->findBy(['isActive' => true], ['fullName' => 'ASC']);
        }

        return $this->render('servicedesk/show.html.twig', [
            'chamado' => $chamado,
            'interacaoForm' => $interacaoForm->createView(),
            'isAdmin' => $isAdmin,
            'tecnicos' => $tecnicos,
            'statusList' => $this->getStatusList(),
        ]);
    }

    /**
     * Adiciona interação ao chamado
     */
    #[Route('/{id}/interacao', name: 'servicedesk_interacao', methods: ['POST'])]
    public function interacao(Chamado $chamado, Request $request, EntityManagerInterface $em, PermissionChecker $permissionChecker): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $isAdmin = $permissionChecker->canAdminister($usuario, 'admin.servicedesk.manage');

        // Verificar permissão
        if (!$isAdmin && $chamado->getSolicitante() !== $usuario) {
            throw $this->createAccessDeniedException('Você não tem permissão para interagir neste chamado.');
        }

        $interacao = new ChamadoInteracao();
        $form = $this->createForm(ChamadoInteracaoType::class, $interacao, [
            'is_admin' => $isAdmin,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $interacao->setChamado($chamado);
            $interacao->setUsuario($usuario);
            $interacao->setTipo($isAdmin ? ChamadoInteracao::TIPO_RESPOSTA : ChamadoInteracao::TIPO_COMENTARIO);

            $em->persist($interacao);
            $em->flush();

            // Notificar
            $this->notificarNovaInteracao($chamado, $interacao);

            $this->addFlash('success', 'Mensagem enviada com sucesso!');
        }

        return $this->redirectToRoute('servicedesk_show', ['id' => $chamado->getId()]);
    }

    /**
     * Atribui ou reatribui responsável ao chamado
     */
    #[Route('/{id}/atribuir', name: 'servicedesk_atribuir', methods: ['POST'])]
    public function atribuir(Chamado $chamado, Request $request, EntityManagerInterface $em, UserRepository $userRepository, PermissionChecker $permissionChecker): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        if (!$permissionChecker->canAdminister($usuario, 'admin.servicedesk.manage')) {
            throw $this->createAccessDeniedException('Você não tem permissão para atribuir chamados.');
        }

        $responsavelId = $request->request->get('responsavel_id');
        $responsavel = $responsavelId ? $userRepository->find($responsavelId) : null;

        $responsavelAnterior = $chamado->getResponsavel();
        $chamado->setResponsavel($responsavel);

        // Se estava aberto e foi atribuído, mudar para em andamento
        if ($chamado->getStatus() === Chamado::STATUS_ABERTO && $responsavel) {
            $chamado->setStatus(Chamado::STATUS_EM_ANDAMENTO);
        }

        // Criar interação de atribuição
        $mensagem = $responsavel
            ? "Chamado atribuído para {$responsavel->getFullName()} por {$usuario->getFullName()}"
            : "Responsável removido por {$usuario->getFullName()}";

        $interacao = new ChamadoInteracao();
        $interacao->setChamado($chamado);
        $interacao->setUsuario($usuario);
        $interacao->setTipo(ChamadoInteracao::TIPO_ATRIBUICAO);
        $interacao->setMensagem($mensagem);

        $em->persist($interacao);
        $em->flush();

        // Notificar novo responsável
        if ($responsavel && $responsavel !== $responsavelAnterior) {
            $this->notificarAtribuicao($chamado, $responsavel);
        }

        $this->addFlash('success', $responsavel ? 'Chamado atribuído com sucesso!' : 'Responsável removido.');
        return $this->redirectToRoute('servicedesk_show', ['id' => $chamado->getId()]);
    }

    /**
     * Altera status do chamado
     */
    #[Route('/{id}/status', name: 'servicedesk_status', methods: ['POST'])]
    public function status(Chamado $chamado, Request $request, EntityManagerInterface $em, PermissionChecker $permissionChecker): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        if (!$permissionChecker->canAdminister($usuario, 'admin.servicedesk.manage')) {
            throw $this->createAccessDeniedException('Você não tem permissão para alterar o status de chamados.');
        }

        $novoStatus = $request->request->get('status');
        $statusAntigo = $chamado->getStatus();

        if (!in_array($novoStatus, [
            Chamado::STATUS_ABERTO,
            Chamado::STATUS_EM_ANDAMENTO,
            Chamado::STATUS_RESOLVIDO,
            Chamado::STATUS_FECHADO
        ])) {
            $this->addFlash('error', 'Status inválido.');
            return $this->redirectToRoute('servicedesk_show', ['id' => $chamado->getId()]);
        }

        $chamado->setStatus($novoStatus);

        // Criar interação de mudança de status
        $interacao = new ChamadoInteracao();
        $interacao->setChamado($chamado);
        $interacao->setUsuario($usuario);
        $interacao->setTipo(ChamadoInteracao::TIPO_STATUS);
        $interacao->setMensagem("Status alterado de \"{$chamado->getStatusLabel()}\" para \"{$this->getStatusLabel($novoStatus)}\" por {$usuario->getFullName()}");

        $em->persist($interacao);
        $em->flush();

        // Notificar solicitante sobre mudança de status
        $this->notificarMudancaStatus($chamado, $statusAntigo, $novoStatus);

        $this->addFlash('success', 'Status alterado com sucesso!');
        return $this->redirectToRoute('servicedesk_show', ['id' => $chamado->getId()]);
    }

    /**
     * Processa e salva os anexos do chamado
     */
    private function processarAnexos(Chamado $chamado, array $arquivos, User $usuario): void
    {
        $uploadDir = $this->parameterBag->get('kernel.project_dir') . '/public/uploads/chamados';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        foreach ($arquivos as $arquivo) {
            if (!$arquivo instanceof UploadedFile) {
                continue;
            }

            $nomeOriginal = pathinfo($arquivo->getClientOriginalName(), PATHINFO_FILENAME);
            $extensao = $arquivo->guessExtension() ?? 'bin';
            $nomeArquivo = $this->slugger->slug($nomeOriginal) . '-' . uniqid() . '.' . $extensao;

            $arquivo->move($uploadDir, $nomeArquivo);

            $anexo = new ChamadoAnexo();
            $anexo->setChamado($chamado);
            $anexo->setUsuario($usuario);
            $anexo->setNomeOriginal($arquivo->getClientOriginalName());
            $anexo->setNomeArquivo($nomeArquivo);
            $anexo->setMimeType($arquivo->getClientMimeType() ?? 'application/octet-stream');
            $anexo->setTamanho($arquivo->getSize() ?? 0);

            $chamado->addAnexo($anexo);
        }
    }

    /**
     * Notifica equipe de TI sobre novo chamado
     */
    private function notificarNovoChamado(Chamado $chamado): void
    {
        // TODO: Implementar notificação para admins/equipe TI
        // Por enquanto apenas log
    }

    /**
     * Notifica sobre nova interação
     */
    private function notificarNovaInteracao(Chamado $chamado, ChamadoInteracao $interacao): void
    {
        // Se é comentário interno, não notifica solicitante
        if ($interacao->isInterno()) {
            return;
        }

        $solicitante = $chamado->getSolicitante();
        $autor = $interacao->getUsuario();

        // Se o autor não é o solicitante, notifica o solicitante
        if ($solicitante && $autor !== $solicitante) {
            $this->notificacaoService->criarNotificacao(
                $solicitante,
                'servicedesk',
                "Nova resposta no chamado #{$chamado->getId()}: {$chamado->getTitulo()}",
                $this->generateUrl('servicedesk_show', ['id' => $chamado->getId()])
            );
        }

        // Se o autor é o solicitante e há responsável, notifica responsável
        $responsavel = $chamado->getResponsavel();
        if ($responsavel && $autor === $solicitante) {
            $this->notificacaoService->criarNotificacao(
                $responsavel,
                'servicedesk',
                "Nova mensagem no chamado #{$chamado->getId()}: {$chamado->getTitulo()}",
                $this->generateUrl('servicedesk_show', ['id' => $chamado->getId()])
            );
        }
    }

    /**
     * Notifica técnico sobre atribuição
     */
    private function notificarAtribuicao(Chamado $chamado, User $responsavel): void
    {
        $this->notificacaoService->criarNotificacao(
            $responsavel,
            'servicedesk',
            "Chamado #{$chamado->getId()} atribuído a você: {$chamado->getTitulo()}",
            $this->generateUrl('servicedesk_show', ['id' => $chamado->getId()])
        );
    }

    /**
     * Notifica solicitante sobre mudança de status
     */
    private function notificarMudancaStatus(Chamado $chamado, string $statusAntigo, string $novoStatus): void
    {
        $solicitante = $chamado->getSolicitante();
        if (!$solicitante) {
            return;
        }

        $mensagens = [
            Chamado::STATUS_EM_ANDAMENTO => "Seu chamado #{$chamado->getId()} está sendo analisado",
            Chamado::STATUS_RESOLVIDO => "Seu chamado #{$chamado->getId()} foi resolvido",
            Chamado::STATUS_FECHADO => "Seu chamado #{$chamado->getId()} foi encerrado",
        ];

        if (isset($mensagens[$novoStatus])) {
            $this->notificacaoService->criarNotificacao(
                $solicitante,
                'servicedesk',
                $mensagens[$novoStatus],
                $this->generateUrl('servicedesk_show', ['id' => $chamado->getId()])
            );
        }
    }

    private function getCategorias(): array
    {
        return [
            Chamado::CATEGORIA_SOFTWARE => 'Software',
            Chamado::CATEGORIA_HARDWARE => 'Hardware',
            Chamado::CATEGORIA_IMPRESSORA => 'Impressora',
            Chamado::CATEGORIA_REDE => 'Rede/Internet',
            Chamado::CATEGORIA_ACESSO => 'Acesso/Permissões',
            Chamado::CATEGORIA_EMAIL => 'E-mail',
            Chamado::CATEGORIA_OUTROS => 'Outros',
        ];
    }

    private function getPrioridades(): array
    {
        return [
            Chamado::PRIORIDADE_BAIXA => 'Baixa',
            Chamado::PRIORIDADE_MEDIA => 'Média',
            Chamado::PRIORIDADE_ALTA => 'Alta',
            Chamado::PRIORIDADE_CRITICA => 'Crítica',
        ];
    }

    private function getStatusList(): array
    {
        return [
            Chamado::STATUS_ABERTO => 'Aberto',
            Chamado::STATUS_EM_ANDAMENTO => 'Em Andamento',
            Chamado::STATUS_RESOLVIDO => 'Resolvido',
            Chamado::STATUS_FECHADO => 'Fechado',
        ];
    }

    private function getStatusLabel(string $status): string
    {
        return $this->getStatusList()[$status] ?? $status;
    }
}
