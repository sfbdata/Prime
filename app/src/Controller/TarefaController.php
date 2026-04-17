<?php

namespace App\Controller;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Repository\PastaRepository;
use App\Repository\TarefaRepository;
use App\Repository\UserRepository;
use App\Tarefa\Service\TarefaTimelineAssembler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[Route('/tarefas')]
class TarefaController extends AbstractController
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly TarefaTimelineAssembler $timelineAssembler,
    ) {
    }

    /**
     * Lista de tarefas atribuídas ao usuário logado.
     */
    #[Route('/minhas', name: 'tarefa_minhas', methods: ['GET'])]
    public function minhas(TarefaRepository $tarefaRepository): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        $tarefas = $tarefaRepository->findByResponsavel($usuario);

        return $this->render('tarefa/minhas.html.twig', [
            'tarefas' => $tarefas,
        ]);
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

        $pasta = $pastaRepository->find($pastaId);
        if ($pasta === null || $pasta->getTenant()?->getId() !== $usuario->getTenant()?->getId()) {
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
        $tarefa->setCriadoPor($usuario);

        $prazo = trim((string) $request->request->get('prazo', ''));
        if ($prazo !== '') {
            try {
                $tarefa->setPrazo(new \DateTimeImmutable($prazo));
            } catch (\Throwable) {
                // prazo inválido é ignorado
            }
        }

        $responsavelId = (int) $request->request->get('responsavel_id', 0);
        if ($responsavelId > 0) {
            $responsavel = $userRepository->find($responsavelId);
            if ($responsavel instanceof User && $responsavel->getTenant()?->getId() === $usuario->getTenant()?->getId()) {
                $tarefa->setResponsavel($responsavel);
            }
        }

        $entityManager->persist($tarefa);
        $entityManager->flush();

        return $this->json(['sucesso' => true, 'id' => $tarefa->getId()]);
    }

    /**
     * Detalhe da tarefa.
     */
    #[Route('/{id}', name: 'tarefa_show', methods: ['GET'])]
    public function show(Tarefa $tarefa): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        $this->verificarAcessoTarefa($usuario, $tarefa);

        $tenantId = (int) $usuario->getTenant()?->getId();
        $timelineItems = $this->timelineAssembler->montar($tarefa, $tenantId);

        return $this->render('tarefa/show.html.twig', [
            'tarefa'        => $tarefa,
            'statusLabels'  => Tarefa::STATUS_LABELS,
            'timelineItems' => $timelineItems,
            'usuarioAtual'  => $usuario,
        ]);
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
        $this->verificarAcessoTarefa($usuario, $tarefa);

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
            $tarefa->addMensagem($mensagem);
            $entityManager->persist($mensagem);
        }

        // Transições de status pelo ciclo de vida
        if ($acao === 'enviar' && $tarefa->getStatus() === Tarefa::STATUS_PENDENTE) {
            $tarefa->setStatus(Tarefa::STATUS_EM_REVISAO);
        } elseif ($acao === 'devolver' && $tarefa->getStatus() === Tarefa::STATUS_EM_REVISAO) {
            $tarefa->setStatus(Tarefa::STATUS_PENDENTE);
        }

        $entityManager->flush();

        return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
    }

    /**
     * Conclui a tarefa (qualquer usuário com acesso pode concluir).
     */
    #[Route('/{id}/concluir', name: 'tarefa_concluir', methods: ['POST'])]
    public function concluir(Tarefa $tarefa, Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $this->verificarAcessoTarefa($usuario, $tarefa);

        if (!$this->isCsrfTokenValid('concluir_tarefa_'.$tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        if ($tarefa->getStatus() !== Tarefa::STATUS_CONCLUIDA) {
            $tarefa->setStatus(Tarefa::STATUS_CONCLUIDA);
            $tarefa->setDataConclusao(new \DateTimeImmutable());
            $entityManager->flush();

            $this->addFlash('success', 'Tarefa concluída.');
        }

        $pastaId = $tarefa->getPasta()->getId();

        return $this->redirectToRoute('pasta_show', ['id' => $pastaId, '_fragment' => 'tarefas']);
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

    private function verificarAcessoTarefa(User $usuario, Tarefa $tarefa): void
    {
        // Verifica acesso via tenant do criador ou responsável da pasta
        $pasta = $tarefa->getPasta();
        $tenantId = $usuario->getTenant()?->getId();

        $criadorTenant = $pasta->getCriadoPor()?->getTenant()?->getId();
        $responsavelTenant = $pasta->getResponsavel()?->getTenant()?->getId();

        $pastaAcessivel = ($criadorTenant !== null && $criadorTenant === $tenantId)
            || ($responsavelTenant !== null && $responsavelTenant === $tenantId);

        if (!$pastaAcessivel) {
            throw $this->createAccessDeniedException('Acesso negado.');
        }
    }
}
