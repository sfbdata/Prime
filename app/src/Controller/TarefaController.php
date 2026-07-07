<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\Exception\MetaNaoExcluivelException;
use App\Tarefa\Exception\PrazoNaoEditavelException;
use App\Tarefa\UseCase\AtualizarPrazoTarefaUseCase;
use App\Tarefa\UseCase\EditarTarefaMensagemUseCase;
use App\Tarefa\UseCase\ExcluirTarefaUseCase;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tarefa\Service\TarefaTimelineAssembler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[Route('/tarefas')]
final class TarefaController extends AbstractController
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly TarefaTimelineAssembler $timelineAssembler,
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly UserTenantRepository $userTenantRepo,
        private readonly NotificacaoService $notificacaoService,
    ) {
    }

    /**
     * Lista de tarefas atribuídas ao usuário logado.
     */
    #[Route('/minhas', name: 'tarefa_minhas', methods: ['GET'])]
    public function minhas(Request $request, TarefaRepository $tarefaRepository): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $this->assertAccess($usuario);

        $filtros = [
            'busca'      => trim((string) $request->query->get('busca', '')),
            'status'     => (string) $request->query->get('status', ''),
            'prioridade' => (string) $request->query->get('prioridade', ''),
            'prazo'      => (string) $request->query->get('prazo', ''),
        ];

        $dados = [
            'tarefas' => $tarefaRepository->findByResponsavelComFiltros($usuario, $filtros),
            'filtros' => $filtros,
        ];

        if ($request->isXmlHttpRequest()) {
            return $this->render('tarefa/_resultado.html.twig', $dados);
        }

        return $this->render('tarefa/minhas.html.twig', $dados);
    }

    /**
     * Cria tarefa vinculada a uma pasta (chamada via modal AJAX).
     */
    #[Route('/pasta/{pastaId}/criar', name: 'tarefa_criar_para_pasta', methods: ['POST'])]
    public function criarParaPasta(
        int $pastaId,
        Request $request,
        PastaRepository $pastaRepository,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->assertAccess($usuario);

        $pasta = $pastaRepository->find($pastaId);
        if ($pasta === null) {
            return $this->json(['erro' => 'Pasta não encontrada.'], 404);
        }

        $criadorPasta = $pasta->getCriadoPor();
        $responsavelPasta = $pasta->getResponsavel();
        $criadorPertence = $criadorPasta !== null
            && $this->userTenantRepo->existeVinculoAtivo($criadorPasta, $tenant);
        $responsavelPertence = $responsavelPasta !== null
            && $this->userTenantRepo->existeVinculoAtivo($responsavelPasta, $tenant);

        if (!$criadorPertence && !$responsavelPertence) {
            return $this->json(['erro' => 'Pasta não encontrada.'], 404);
        }

        if (!$this->isCsrfTokenValid('tarefa_criar_'.$pastaId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], 403);
        }

        $titulo = trim((string) $request->request->get('titulo', ''));
        $descricao = trim((string) $request->request->get('descricao', ''));

        if ($titulo === '' || $descricao === '') {
            return $this->json(['erro' => 'Título e descrição são obrigatórios.'], 422);
        }

        $tarefa = new Tarefa();
        $tarefa->setTitulo($titulo);
        $tarefa->setDescricao($descricao);
        $tarefa->setStatus(Tarefa::STATUS_PENDENTE);
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setCriadoPor($usuario);

        $prazo = trim((string) $request->request->get('prazo', ''));
        if ($prazo !== '') {
            try {
                $tarefa->setPrazo(new \DateTimeImmutable($prazo));
            } catch (\Throwable) {
                // prazo inválido é ignorado
            }
        }

        $responsavelIds = array_map('intval', $request->request->all('responsaveis'));
        foreach ($userRepository->findPorIdsETenant($responsavelIds, $tenant) as $responsavel) {
            $tarefa->addResponsavel($responsavel);
        }

        $entityManager->persist($tarefa);
        $entityManager->flush();

        $this->notificacaoService->notificarTarefaCriada($tarefa);

        return $this->json(['sucesso' => true, 'id' => $tarefa->getId()]);
    }

    /**
     * Detalhe da tarefa.
     */
    #[Route('/{id}', name: 'tarefa_show', methods: ['GET'])]
    public function show(Tarefa $tarefa, UserRepository $userRepository, ExcluirTarefaUseCase $excluirUseCase, AtualizarPrazoTarefaUseCase $prazoUseCase): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($usuario, $tarefa, $tenant);

        $tenantId = (int) $tenant->getId();
        $timelineItems = $this->timelineAssembler->montar($tarefa, $tenantId);

        $users = $userRepository->findTodosPorTenant($tenant);

        return $this->render('tarefa/show.html.twig', [
            'tarefa'         => $tarefa,
            'statusLabels'   => Tarefa::STATUS_LABELS,
            'timelineItems'  => $timelineItems,
            'usuarioAtual'   => $usuario,
            'users'          => $users,
            'podeExcluirMeta' => $excluirUseCase->podeExcluir($tarefa, $usuario, $tenant),
            'podeEditarPrazo' => $prazoUseCase->podeEditarPrazo($tarefa, $usuario, $tenant),
        ]);
    }

    /**
     * Atualiza os responsáveis da tarefa.
     */
    #[Route('/{id}/responsaveis', name: 'tarefa_update_responsaveis', methods: ['POST'])]
    public function updateResponsaveis(Tarefa $tarefa, Request $request, EntityManagerInterface $entityManager, UserRepository $userRepository): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($usuario, $tarefa, $tenant);

        if (!$this->isCsrfTokenValid('update_responsaveis_' . $tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $userIds = array_map('intval', $request->request->all('responsaveis', []));
        $users = $userRepository->findPorIdsETenant($userIds, $tenant);

        $idsAnteriores = array_map(
            static fn(User $u): int => (int) $u->getId(),
            $tarefa->getResponsaveis()->toArray()
        );

        $tarefa->getResponsaveis()->clear();
        $novosResponsaveis = [];
        foreach ($users as $user) {
            $tarefa->addResponsavel($user);
            if (!in_array((int) $user->getId(), $idsAnteriores, true)) {
                $novosResponsaveis[] = $user;
            }
        }

        $entityManager->flush();

        if ($novosResponsaveis !== []) {
            $this->notificacaoService->notificarResponsaveisAdicionados($tarefa, $novosResponsaveis);
        }

        $this->addFlash('success', 'Responsáveis atualizados com sucesso.');

        return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
    }

    /**
     * Atualiza o prazo da meta — restrito ao criador (sem janela de tempo).
     */
    #[Route('/{id}/prazo', name: 'tarefa_atualizar_prazo', methods: ['POST'])]
    public function atualizarPrazo(Tarefa $tarefa, Request $request, AtualizarPrazoTarefaUseCase $useCase): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($usuario, $tarefa, $tenant);

        if (!$this->isCsrfTokenValid('atualizar_prazo_tarefa_'.$tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $prazoInput = trim((string) $request->request->get('prazo', ''));
        $prazo = null;
        if ($prazoInput !== '') {
            try {
                $prazo = new \DateTimeImmutable($prazoInput);
            } catch (\Throwable) {
                $this->addFlash('danger', 'Prazo inválido.');

                return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
            }
        }

        try {
            $useCase->executar($tarefa, $usuario, $tenant, $prazo);
        } catch (PrazoNaoEditavelException) {
            $this->addFlash('danger', 'Apenas o criador da meta pode alterar o prazo.');

            return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
        }

        $this->addFlash('success', 'Prazo atualizado.');

        return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
    }

    /**
     * Envia mensagem e avança status conforme o ciclo de vida.
     * Responsável envia → Para Revisão. Gestor devolve → Pendente.
     */
    #[Route('/{id}/mensagem', name: 'tarefa_mensagem', methods: ['POST'])]
    public function mensagem(Tarefa $tarefa, Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($usuario, $tarefa, $tenant);

        if (!$this->isCsrfTokenValid('mensagem_tarefa_'.$tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $texto = trim((string) $request->request->get('mensagem', ''));
        $acao  = (string) $request->request->get('acao', '');

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
            $mensagem->setTenant($tarefa->getTenant());
            $tarefa->addMensagem($mensagem);
            $entityManager->persist($mensagem);
        }

        // Transições de status pelo ciclo de vida
        $enviada   = false;
        $devolvida = false;
        if ($acao === 'enviar' && $tarefa->getStatus() === Tarefa::STATUS_PENDENTE) {
            $tarefa->setStatus(Tarefa::STATUS_EM_REVISAO);
            $enviada = true;
        } elseif ($acao === 'devolver' && $tarefa->getStatus() === Tarefa::STATUS_EM_REVISAO) {
            $tarefa->setStatus(Tarefa::STATUS_PENDENTE);
            $devolvida = true;
        }

        $entityManager->flush();

        if ($enviada) {
            $this->notificacaoService->notificarTarefaEmRevisao($tarefa);
        }

        if ($devolvida) {
            $this->notificacaoService->notificarTarefaPendente($tarefa);
        }

        return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
    }

    /**
     * Edita o texto de uma mensagem do histórico. Apenas o autor pode editar.
     */
    #[Route('/mensagem/{id}/editar', name: 'tarefa_mensagem_editar', methods: ['POST'])]
    public function editarMensagem(TarefaMensagem $mensagem, Request $request, EditarTarefaMensagemUseCase $useCase): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($usuario, $mensagem->getTarefa(), $tenant);

        if ($mensagem->getUsuario()?->getId() !== $usuario->getId()) {
            return $this->json(['erro' => 'Apenas o autor pode editar esta mensagem.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('editar_mensagem_tarefa_'.$mensagem->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $useCase->executar($mensagem, (string) $request->request->get('conteudo', ''));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'conteudo'  => $mensagem->getMensagem(),
            'editadoEm' => $mensagem->getEditadoEm()?->format('d/m/Y H:i'),
        ]);
    }

    /**
     * Conclui a tarefa (qualquer usuário com acesso pode concluir).
     */
    #[Route('/{id}/concluir', name: 'tarefa_concluir', methods: ['POST'])]
    public function concluir(Tarefa $tarefa, Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($usuario, $tarefa, $tenant);

        if (!$this->isCsrfTokenValid('concluir_tarefa_'.$tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        if ($tarefa->getStatus() !== Tarefa::STATUS_CONCLUIDA) {
            $tarefa->setStatus(Tarefa::STATUS_CONCLUIDA);
            $tarefa->setDataConclusao(new \DateTimeImmutable());
            $entityManager->flush();

            $this->notificacaoService->notificarTarefaConcluida($tarefa);

            $this->addFlash('success', 'Tarefa concluída.');
        }

        $pastaId = $tarefa->getPasta()->getId();

        return $this->redirectToRoute('pasta_show', ['id' => $pastaId, '_fragment' => 'tarefas']);
    }

    /**
     * Exclui uma meta — restrita ao criador e à janela de tempo após a criação.
     */
    #[Route('/{id}/excluir', name: 'tarefa_excluir', methods: ['POST'])]
    public function excluir(Tarefa $tarefa, Request $request, ExcluirTarefaUseCase $useCase): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($usuario, $tarefa, $tenant);

        if (!$this->isCsrfTokenValid('excluir_tarefa_'.$tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $pastaId = $tarefa->getPasta()->getId();

        try {
            $useCase->executar($tarefa, $usuario, $tenant);
        } catch (MetaNaoExcluivelException) {
            $this->addFlash('danger', 'Esta meta não pode ser excluída.');

            return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
        }

        $this->addFlash('success', 'Meta excluída.');

        return $this->redirectToRoute('pasta_show', ['id' => $pastaId, '_fragment' => 'tarefas']);
    }

    /**
     * Serve o arquivo de uma mensagem de tarefa inline (para preview).
     */
    #[Route('/mensagem/{id}/arquivo/visualizar', name: 'tarefa_mensagem_arquivo_view', methods: ['GET'])]
    public function viewArquivoMensagem(TarefaMensagem $mensagem): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($usuario, $mensagem->getTarefa(), $tenant);

        $caminho = $this->resolverCaminhoArquivo((string) $mensagem->getArquivoAnexo());

        if ($caminho === null || !file_exists($caminho)) {
            throw $this->createNotFoundException('Arquivo não encontrado.');
        }

        $response = new BinaryFileResponse($caminho);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($caminho));
        $mimeType = mime_content_type($caminho) ?: 'application/octet-stream';
        $response->headers->set('Content-Type', $mimeType);

        return $response;
    }

    /**
     * Serve o arquivo de uma mensagem de tarefa como download.
     */
    #[Route('/mensagem/{id}/arquivo/download', name: 'tarefa_mensagem_arquivo_download', methods: ['GET'])]
    public function downloadArquivoMensagem(TarefaMensagem $mensagem): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($usuario, $mensagem->getTarefa(), $tenant);

        $caminho = $this->resolverCaminhoArquivo((string) $mensagem->getArquivoAnexo());

        if ($caminho === null || !file_exists($caminho)) {
            throw $this->createNotFoundException('Arquivo não encontrado.');
        }

        return $this->file($caminho, basename($caminho), ResponseHeaderBag::DISPOSITION_ATTACHMENT);
    }

    private function assertAccess(User $user): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('Sem tenant selecionado.');
        }
        if (!$this->permissionChecker->canAccessModule($user, $tenant, 'tarefas')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Tarefas.');
        }

        return $tenant;
    }

    private function verificarAcessoTarefa(User $usuario, Tarefa $tarefa, Tenant $tenant): void
    {
        $pasta = $tarefa->getPasta();
        $criador = $pasta->getCriadoPor();
        $responsavel = $pasta->getResponsavel();
        $criadorPertence = $criador !== null
            && $this->userTenantRepo->existeVinculoAtivo($criador, $tenant);
        $responsavelPertence = $responsavel !== null
            && $this->userTenantRepo->existeVinculoAtivo($responsavel, $tenant);

        if (!$criadorPertence && !$responsavelPertence) {
            throw $this->createAccessDeniedException('Acesso negado.');
        }
    }

    private function resolverCaminhoArquivo(string $caminhoPublico): ?string
    {
        if ($caminhoPublico === '') {
            return null;
        }

        $projectDir = rtrim((string) $this->parameterBag->get('kernel.project_dir'), '/');

        return $projectDir . '/public' . $caminhoPublico;
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
            $filename  = uniqid('arquivo_', true) . '.' . $extension;
            $file->move($targetDir, $filename);
            $publicBase = $projectDir . '/public';
            $paths[] = str_replace($publicBase, '', $targetDir) . '/' . $filename;
        }

        return $paths;
    }
}
