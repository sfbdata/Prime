<?php

namespace App\Controller;

use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Entity\Tarefa\AtribuicaoTarefa;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Repository\AtribuicaoTarefaRepository;
use App\Repository\PastaRepository;
use App\Repository\TarefaRepository;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * TarefaController - Gerencia tarefas e atribuições.
 *
 * FLUXO DE STATUS DA TAREFA:
 * ┌─────────────┐
 * │   CRIAR     │ Admin cria tarefa → Status: PENDENTE
 * └──────┬──────┘
 *        ↓
 * ┌─────────────┐
 * │  PENDENTE   │ Funcionário trabalha na tarefa
 * │  (REABERTA) │
 * └──────┬──────┘
 *        ↓ Funcionário clica "Enviar para Revisão"
 * ┌─────────────┐
 * │ EM_REVISAO  │ Admin revisa a tarefa
 * └──────┬──────┘
 *        ├─── Admin clica "Enviar Pendência" → volta para PENDENTE
 *        └─── Admin clica "Encerrar Tarefa" → vai para CONCLUIDA
 * ┌─────────────┐
 * │  CONCLUIDA  │ Tarefa bloqueada (somente leitura)
 * └──────┬──────┘
 *        ↓ Admin clica "Reabrir Tarefa"
 * ┌─────────────┐
 * │  PENDENTE   │ Volta ao fluxo normal
 * └─────────────┘
 *
 * Estrutura de rotas REST:
 * - GET  /tarefas/admin                   → Lista todas as tarefas (admin)
 * - GET  /tarefas/nova                    → Formulário de criação
 * - POST /tarefas/nova                    → Cria nova tarefa (status: PENDENTE)
 * - GET  /tarefas/minhas                  → Lista tarefas atribuídas ao usuário
 * - GET  /tarefas/{id}                    → Exibe detalhes da tarefa
 * - POST /tarefas/{id}/mensagem           → Envia mensagem no chat
 * - POST /tarefas/{id}/enviar-revisao     → Funcionário envia para revisão (PENDENTE/REABERTA → EM_REVISAO)
 * - POST /tarefas/{id}/enviar-pendencia   → Admin devolve pendência (EM_REVISAO → PENDENTE)
 * - POST /tarefas/{id}/encerrar           → Admin encerra tarefa (EM_REVISAO → CONCLUIDA)
 * - POST /tarefas/{id}/reabrir            → Admin reabre tarefa (CONCLUIDA → PENDENTE)
 *
 * REGRAS:
 * - Seleção manual de status foi REMOVIDA
 * - Transições ocorrem AUTOMATICAMENTE via botões contextuais
 * - Todas as mudanças são AUDITADAS automaticamente pelo AuditLogSubscriber
 */
#[Route('/tarefas')]
class TarefaController extends AbstractController
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly NotificacaoService $notificacaoService
    ) {
    }

    #[Route('/admin', name: 'tarefa_admin_index', methods: ['GET'])]
    public function adminIndex(TarefaRepository $tarefaRepository, PermissionChecker $permissionChecker): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        if (!$permissionChecker->canAdminister($usuario, 'admin.tarefas.manage')) {
            throw $this->createAccessDeniedException('Você não tem permissão para acessar a gestão de tarefas.');
        }

        return $this->render('tarefa/admin_index.html.twig', [
            'tarefas' => $tarefaRepository->findByTenantForAdmin($usuario->getTenant()),
        ]);
    }

    #[Route('/nova', name: 'tarefa_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        UserRepository $userRepository,
        PastaRepository $pastaRepository,
        EntityManagerInterface $entityManager,
        PermissionChecker $permissionChecker
    ): Response {
        /** @var User $admin */
        $admin = $this->getUser();
        if (!$permissionChecker->canAdminister($admin, 'admin.tarefas.manage')) {
            throw $this->createAccessDeniedException('Você não tem permissão para criar tarefas.');
        }

        $usuarios = $userRepository->createQueryBuilder('u')
            ->where('u.tenant = :tenant AND u.isActive = :active')
            ->setParameter('tenant', $admin->getTenant())
            ->setParameter('active', true)
            ->orderBy('u.fullName', 'ASC')
            ->getQuery()
            ->getResult();

        $pastas = $pastaRepository->findBy([], ['nup' => 'ASC']);

        if ($request->isMethod('POST')) {
            $tarefa = new Tarefa();
            $tarefa->setTitulo((string) $request->request->get('titulo', ''));
            $tarefa->setDescricao((string) $request->request->get('descricao', ''));
            $tarefa->setStatus(Tarefa::STATUS_PENDENTE);

            $prazo = (string) $request->request->get('prazo', '');
            if ($prazo !== '') {
                $tarefa->setPrazo(new \DateTimeImmutable($prazo));
            }

            $pastaInput = $request->request->get('pasta_id');
            $pastaId = null;

            if (is_string($pastaInput) && trim($pastaInput) !== '') {
                $pastaId = filter_var($pastaInput, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);

                if ($pastaId === false) {
                    $this->addFlash('error', 'Pasta selecionada é inválida. Escolha uma pasta válida.');
                    $pastaId = null;
                }
            }

            if (is_int($pastaId)) {
                $pasta = $pastaRepository->find($pastaId);
                if ($pasta instanceof Pasta) {
                    $tarefa->setPasta($pasta);
                }
            }

            $arquivosAdmin = $this->uploadFiles(
                $request->files->all('arquivos_admin'),
                'public/uploads/tarefas/admin'
            );
            $tarefa->setArquivosAdmin($arquivosAdmin);

            $usuariosSelecionados = (array) $request->request->all('usuarios');
            foreach ($usuariosSelecionados as $usuarioId) {
                $usuario = $userRepository->find((int) $usuarioId);
                if (!$usuario instanceof User) {
                    continue;
                }

                if ($usuario->getTenant() !== $admin->getTenant()) {
                    continue;
                }

                $atribuicao = new AtribuicaoTarefa();
                $atribuicao->setUsuario($usuario);
                $atribuicao->setStatus(AtribuicaoTarefa::STATUS_PENDENTE);
                $tarefa->addAtribuicao($atribuicao);
            }

            if ($tarefa->getAtribuicoes()->count() === 0) {
                $this->addFlash('error', 'Selecione ao menos um usuário para atribuição.');
            } else {
                $entityManager->persist($tarefa);
                $entityManager->flush();

                // Notificar usuários atribuídos sobre a nova tarefa
                $this->notificacaoService->notificarTarefaCriada($tarefa);

                return $this->redirectToRoute('tarefa_admin_index');
            }
        }

        return $this->render('tarefa/new.html.twig', [
            'usuarios' => $usuarios,
            'pastas' => $pastas,
        ]);
    }

    #[Route('/minhas', name: 'tarefa_minhas', methods: ['GET'])]
    public function minhas(AtribuicaoTarefaRepository $atribuicaoRepository): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        return $this->render('tarefa/minhas.html.twig', [
            'atribuicoes' => $atribuicaoRepository->findByUsuario($usuario),
        ]);
    }

    #[Route('/{id}', name: 'tarefa_show', methods: ['GET'])]
    public function show(Tarefa $tarefa, PermissionChecker $permissionChecker): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $isAdmin = $permissionChecker->canAdminister($usuario, 'admin.tarefas.manage');

        if (!$isAdmin && !$this->usuarioTemAtribuicaoNaTarefa($usuario, $tarefa)) {
            throw $this->createAccessDeniedException('Você não tem acesso a esta tarefa.');
        }

        return $this->render('tarefa/show.html.twig', [
            'tarefa' => $tarefa,
            'isAdmin' => $isAdmin,
            'atribuicaoDoUsuario' => $this->getAtribuicaoDoUsuario($usuario, $tarefa),
        ]);
    }

    /**
     * Funcionário envia tarefa para revisão do admin.
     * 
     * Transição: PENDENTE/REABERTA → EM_REVISAO
     * Permissão: Usuário atribuído à tarefa
     */
    #[Route('/{id}/enviar-revisao', name: 'tarefa_enviar_revisao', methods: ['POST'])]
    public function enviarRevisao(Tarefa $tarefa, Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $atribuicao = $this->getAtribuicaoDoUsuario($usuario, $tarefa);

        if (!$atribuicao instanceof AtribuicaoTarefa) {
            throw $this->createAccessDeniedException('Você não está atribuído a esta tarefa.');
        }

        // Validação: só pode enviar para revisão se a tarefa estiver PENDENTE ou REABERTA
        $statusAtual = $tarefa->getStatus();
        if (!in_array($statusAtual, [Tarefa::STATUS_PENDENTE, Tarefa::STATUS_REABERTA], true)) {
            $this->addFlash('error', 'Esta tarefa não pode ser enviada para revisão no status atual.');
            return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
        }

        if (!$this->isCsrfTokenValid('enviar_revisao_'.$tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Upload de arquivos do usuário
        $arquivosUsuario = $this->uploadFiles(
            $request->files->all('arquivos_usuario'),
            'public/uploads/tarefas/usuario'
        );
        if ($arquivosUsuario !== []) {
            $atribuicao->setArquivosUsuario(array_merge($atribuicao->getArquivosUsuario(), $arquivosUsuario));
        }

        // Salvar mensagem/resposta no histórico (com arquivo anexo se houver)
        $textoMensagem = trim((string) $request->request->get('mensagem', ''));
        $primeiroArquivo = $arquivosUsuario[0] ?? null;

        // Criar mensagem se houver texto ou arquivo
        if ($textoMensagem !== '' || $primeiroArquivo !== null) {
            $mensagem = new TarefaMensagem();
            $mensagem->setUsuario($usuario);
            $mensagem->setMensagem($textoMensagem !== '' ? $textoMensagem : '[Resposta] Arquivo anexado');
            if ($primeiroArquivo !== null) {
                $mensagem->setArquivoAnexo($primeiroArquivo);
            }
            $tarefa->addMensagem($mensagem);
            $entityManager->persist($mensagem);

            // Se houver mais arquivos, criar mensagens adicionais para cada um
            for ($i = 1; $i < count($arquivosUsuario); $i++) {
                $msgArquivo = new TarefaMensagem();
                $msgArquivo->setUsuario($usuario);
                $msgArquivo->setMensagem('[Resposta] Arquivo anexado');
                $msgArquivo->setArquivoAnexo($arquivosUsuario[$i]);
                $tarefa->addMensagem($msgArquivo);
                $entityManager->persist($msgArquivo);
            }
        }

        // Atualiza status da atribuição e da tarefa
        $atribuicao->setStatus(AtribuicaoTarefa::STATUS_EM_REVISAO);
        $atribuicao->setDataEnvioRevisao(new \DateTimeImmutable());
        $tarefa->setStatus(Tarefa::STATUS_EM_REVISAO);

        $entityManager->flush();

        // Notificar admins que a tarefa está aguardando revisão
        $this->notificacaoService->notificarTarefaEmRevisao($tarefa, $usuario);

        $this->addFlash('success', 'Tarefa enviada para revisão.');

        return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
    }

    /**
     * Admin devolve tarefa como pendência para o funcionário.
     * 
     * Transição: EM_REVISAO → PENDENTE
     * Permissão: admin.tarefas.manage
     */
    #[Route('/{id}/enviar-pendencia', name: 'tarefa_enviar_pendencia', methods: ['POST'])]
    public function enviarPendencia(Tarefa $tarefa, Request $request, EntityManagerInterface $entityManager, PermissionChecker $permissionChecker): Response
    {
        /** @var User $admin */
        $admin = $this->getUser();
        if (!$permissionChecker->canAdminister($admin, 'admin.tarefas.manage')) {
            throw $this->createAccessDeniedException('Você não tem permissão para devolver tarefas como pendência.');
        }

        if (!$this->isCsrfTokenValid('enviar_pendencia_'.$tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Validação: só pode enviar pendência se a tarefa estiver EM_REVISAO
        if ($tarefa->getStatus() !== Tarefa::STATUS_EM_REVISAO) {
            $this->addFlash('error', 'Esta tarefa não está em revisão.');
            return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
        }

        // Adiciona instruções como mensagem no histórico
        $instrucoes = trim((string) $request->request->get('instrucoes', ''));
        $arquivoAnexoPath = null;

        // Upload de arquivo se fornecido
        $arquivoPendencia = $request->files->get('arquivo_pendencia');
        if ($arquivoPendencia instanceof \Symfony\Component\HttpFoundation\File\UploadedFile && $arquivoPendencia->isValid()) {
            $uploaded = $this->uploadFiles([$arquivoPendencia], 'public/uploads/tarefas/admin');
            $arquivoAnexoPath = $uploaded[0] ?? null;
        }

        // Criar mensagem no histórico se houver instruções ou arquivo
        if ($instrucoes !== '' || $arquivoAnexoPath !== null) {
            $mensagem = new TarefaMensagem();
            $mensagem->setUsuario($admin);
            $mensagem->setMensagem($instrucoes !== '' ? "[Pendência] " . $instrucoes : '[Pendência] Arquivo anexado');
            $mensagem->setArquivoAnexo($arquivoAnexoPath);
            $tarefa->addMensagem($mensagem);
            $entityManager->persist($mensagem);
        }

        // Atualiza status da tarefa e das atribuições
        $tarefa->setStatus(Tarefa::STATUS_PENDENTE);
        $tarefa->setDataConclusao(null);

        foreach ($tarefa->getAtribuicoes() as $atribuicao) {
            $atribuicao->setStatus(AtribuicaoTarefa::STATUS_PENDENTE);
            $atribuicao->setDataEnvioRevisao(null);
        }

        $entityManager->flush();

        // Notificar usuários que a tarefa voltou como pendência
        $this->notificacaoService->notificarTarefaPendente($tarefa);

        $this->addFlash('success', 'Tarefa devolvida como pendência.');

        return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
    }

    /**
     * Admin encerra/conclui a tarefa.
     * 
     * Transição: EM_REVISAO → CONCLUIDA
     * Permissão: admin.tarefas.manage
     */
    #[Route('/{id}/encerrar', name: 'tarefa_encerrar', methods: ['POST'])]
    public function encerrar(Tarefa $tarefa, Request $request, EntityManagerInterface $entityManager, PermissionChecker $permissionChecker): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        if (!$permissionChecker->canAdminister($usuario, 'admin.tarefas.manage')) {
            throw $this->createAccessDeniedException('Você não tem permissão para encerrar tarefas.');
        }

        if (!$this->isCsrfTokenValid('encerrar_'.$tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Validação: só pode encerrar se a tarefa estiver EM_REVISAO
        if ($tarefa->getStatus() !== Tarefa::STATUS_EM_REVISAO) {
            $this->addFlash('error', 'Esta tarefa não está em revisão.');
            return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
        }

        // Atualiza status da tarefa e das atribuições
        $tarefa->setStatus(Tarefa::STATUS_CONCLUIDA);
        $tarefa->setDataConclusao(new \DateTimeImmutable());

        foreach ($tarefa->getAtribuicoes() as $atribuicao) {
            $atribuicao->setStatus(AtribuicaoTarefa::STATUS_CONCLUIDA);
        }

        $entityManager->flush();

        // Notificar usuários que a tarefa foi concluída
        $this->notificacaoService->notificarTarefaConcluida($tarefa);

        $this->addFlash('success', 'Tarefa encerrada com sucesso.');

        return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
    }

    /**
     * Envia mensagem no chat da tarefa.
     * 
     * Permissão: Admin ou usuário atribuído à tarefa
     * Restrição: Não permite enviar mensagens em tarefas concluídas
     */
    #[Route('/{id}/mensagem', name: 'tarefa_mensagem', methods: ['POST'])]
    public function mensagem(Tarefa $tarefa, Request $request, EntityManagerInterface $entityManager, PermissionChecker $permissionChecker): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $isAdmin = $permissionChecker->canAdminister($usuario, 'admin.tarefas.manage');

        if (!$isAdmin && !$this->usuarioTemAtribuicaoNaTarefa($usuario, $tarefa)) {
            throw $this->createAccessDeniedException('Você não tem acesso a esta tarefa.');
        }

        // Validação: não permite mensagens em tarefas concluídas
        if ($tarefa->getStatus() === Tarefa::STATUS_CONCLUIDA) {
            $this->addFlash('error', 'Não é possível enviar mensagens em tarefas concluídas.');
            return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
        }

        if (!$this->isCsrfTokenValid('mensagem_tarefa_'.$tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $texto = trim((string) $request->request->get('mensagem', ''));
        $arquivoMensagem = $request->files->get('arquivo_mensagem');
        $arquivoAnexoPath = null;

        if ($arquivoMensagem instanceof UploadedFile && $arquivoMensagem->isValid()) {
            $uploaded = $this->uploadFiles([$arquivoMensagem], 'public/uploads/tarefas/chat');
            $arquivoAnexoPath = $uploaded[0] ?? null;
        }

        if ($texto !== '' || $arquivoAnexoPath !== null) {
            $mensagem = new TarefaMensagem();
            $mensagem->setUsuario($usuario);
            $mensagem->setMensagem($texto !== '' ? $texto : '[Arquivo anexado]');
            $mensagem->setArquivoAnexo($arquivoAnexoPath);
            $tarefa->addMensagem($mensagem);
            $entityManager->persist($mensagem);
            $entityManager->flush();
        }

        return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
    }

    /**
     * Admin reabre uma tarefa já concluída.
     * 
     * Transição: CONCLUIDA → PENDENTE
     * Permissão: admin.tarefas.manage
     */
    #[Route('/{id}/reabrir', name: 'tarefa_reabrir', methods: ['POST'])]
    public function reabrir(Tarefa $tarefa, Request $request, EntityManagerInterface $entityManager, PermissionChecker $permissionChecker): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        if (!$permissionChecker->canAdminister($usuario, 'admin.tarefas.manage')) {
            throw $this->createAccessDeniedException('Você não tem permissão para reabrir tarefas.');
        }

        if (!$this->isCsrfTokenValid('reabrir_tarefa_'.$tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Validação: só pode reabrir se a tarefa estiver CONCLUÍDA
        if ($tarefa->getStatus() !== Tarefa::STATUS_CONCLUIDA) {
            $this->addFlash('error', 'Apenas tarefas concluídas podem ser reabertas.');
            return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
        }

        $complemento = trim((string) $request->request->get('instrucoes_complementares', ''));
        if ($complemento !== '') {
            $descricao = $tarefa->getDescricao();
            $tarefa->setDescricao(trim($descricao."\n\n[Reabertura - " . date('d/m/Y H:i') . "] " . $complemento));
        }

        // Atualiza status da tarefa (volta para PENDENTE, não REABERTA)
        $tarefa->setStatus(Tarefa::STATUS_PENDENTE);
        $tarefa->setDataConclusao(null);

        foreach ($tarefa->getAtribuicoes() as $atribuicao) {
            $atribuicao->setStatus(AtribuicaoTarefa::STATUS_PENDENTE);
            $atribuicao->setDataEnvioRevisao(null);
        }

        $entityManager->flush();

        $this->addFlash('success', 'Tarefa reaberta com sucesso.');

        return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
    }

    /**
     * @param mixed[] $files
     * @return string[]
     */
    private function uploadFiles(array $files, string $relativeTargetDir): array
    {
        $projectDir = rtrim((string) $this->parameterBag->get('kernel.project_dir'), '/');
        $relativeTargetDir = ltrim($relativeTargetDir, '/');
        $targetDir = $projectDir . '/' . $relativeTargetDir;

        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                throw new \RuntimeException('Não foi possível criar o diretório de upload: ' . $targetDir);
            }
        }

        $paths = [];

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }

            $extension = $file->guessExtension() ?: 'bin';
            $filename = uniqid('arquivo_', true) . '.' . $extension;
            $file->move($targetDir, $filename);
            $publicBase = $projectDir . '/public';
            $paths[] = str_replace($publicBase, '', $targetDir) . '/' . $filename;
        }

        return $paths;
    }

    private function usuarioTemAtribuicaoNaTarefa(User $usuario, Tarefa $tarefa): bool
    {
        return $this->getAtribuicaoDoUsuario($usuario, $tarefa) instanceof AtribuicaoTarefa;
    }

    private function getAtribuicaoDoUsuario(User $usuario, Tarefa $tarefa): ?AtribuicaoTarefa
    {
        foreach ($tarefa->getAtribuicoes() as $atribuicao) {
            if ($atribuicao->getUsuario()?->getId() === $usuario->getId()) {
                return $atribuicao;
            }
        }

        return null;
    }
}
