<?php

namespace App\Controller;

use App\Entity\Auth\User;
use App\Entity\Processo\Processo;
use App\Entity\Tarefa\AtribuicaoTarefa;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Repository\AtribuicaoTarefaRepository;
use App\Repository\ProcessoRepository;
use App\Repository\TarefaRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[Route('/tarefa')]
class TarefaController extends AbstractController
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag
    ) {
    }

    #[Route('/admin', name: 'tarefa_admin_index', methods: ['GET'])]
    public function adminIndex(TarefaRepository $tarefaRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        /** @var User $usuario */
        $usuario = $this->getUser();

        return $this->render('tarefa/admin_index.html.twig', [
            'tarefas' => $tarefaRepository->findByTenantForAdmin($usuario->getTenant()),
        ]);
    }

    #[Route('/nova', name: 'tarefa_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        UserRepository $userRepository,
        ProcessoRepository $processoRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        /** @var User $admin */
        $admin = $this->getUser();

        $usuarios = $userRepository->createQueryBuilder('u')
            ->where('u.tenant = :tenant')
            ->setParameter('tenant', $admin->getTenant())
            ->orderBy('u.fullName', 'ASC')
            ->getQuery()
            ->getResult();

        $processos = $processoRepository->findBy([], ['numeroProcesso' => 'DESC']);

        if ($request->isMethod('POST')) {
            $tarefa = new Tarefa();
            $tarefa->setTitulo((string) $request->request->get('titulo', ''));
            $tarefa->setDescricao((string) $request->request->get('descricao', ''));
            $tarefa->setStatus(Tarefa::STATUS_PENDENTE);

            $prazo = (string) $request->request->get('prazo', '');
            if ($prazo !== '') {
                $tarefa->setPrazo(new \DateTimeImmutable($prazo));
            }

            $processoInput = $request->request->get('processo_id');
            $processoId = null;

            if (is_string($processoInput) && trim($processoInput) !== '') {
                $processoId = filter_var($processoInput, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);

                if ($processoId === false) {
                    $this->addFlash('error', 'Processo selecionado é inválido. Escolha um processo válido.');
                    $processoId = null;
                }
            }

            if (is_int($processoId)) {
                $processo = $processoRepository->find($processoId);
                if ($processo instanceof Processo) {
                    $tarefa->setProcesso($processo);
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

                return $this->redirectToRoute('tarefa_admin_index');
            }
        }

        return $this->render('tarefa/new.html.twig', [
            'usuarios' => $usuarios,
            'processos' => $processos,
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
    public function show(Tarefa $tarefa): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $isAdmin = in_array('ROLE_ADMIN', $usuario->getRoles(), true);

        if (!$isAdmin && !$this->usuarioTemAtribuicaoNaTarefa($usuario, $tarefa)) {
            throw $this->createAccessDeniedException('Você não tem acesso a esta tarefa.');
        }

        return $this->render('tarefa/show.html.twig', [
            'tarefa' => $tarefa,
            'isAdmin' => $isAdmin,
            'atribuicaoDoUsuario' => $this->getAtribuicaoDoUsuario($usuario, $tarefa),
        ]);
    }

    #[Route('/{id}/enviar-revisao', name: 'tarefa_enviar_revisao', methods: ['POST'])]
    public function enviarRevisao(Tarefa $tarefa, Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $atribuicao = $this->getAtribuicaoDoUsuario($usuario, $tarefa);

        if (!$atribuicao instanceof AtribuicaoTarefa) {
            throw $this->createAccessDeniedException('Você não está atribuído a esta tarefa.');
        }

        if (!$this->isCsrfTokenValid('enviar_revisao_'.$tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $arquivosUsuario = $this->uploadFiles(
            $request->files->all('arquivos_usuario'),
            'public/uploads/tarefas/usuario'
        );
        if ($arquivosUsuario !== []) {
            $atribuicao->setArquivosUsuario(array_merge($atribuicao->getArquivosUsuario(), $arquivosUsuario));
        }

        $atribuicao->setStatus(AtribuicaoTarefa::STATUS_EM_REVISAO);
        $atribuicao->setDataEnvioRevisao(new \DateTimeImmutable());
        $tarefa->setStatus(Tarefa::STATUS_EM_REVISAO);

        $entityManager->flush();

        return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
    }

    #[Route('/{id}/mensagem', name: 'tarefa_mensagem', methods: ['POST'])]
    public function mensagem(Tarefa $tarefa, Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $isAdmin = in_array('ROLE_ADMIN', $usuario->getRoles(), true);

        if (!$isAdmin && !$this->usuarioTemAtribuicaoNaTarefa($usuario, $tarefa)) {
            throw $this->createAccessDeniedException('Você não tem acesso a esta tarefa.');
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

    #[Route('/{id}/reabrir', name: 'tarefa_reabrir', methods: ['POST'])]
    public function reabrir(Tarefa $tarefa, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('reabrir_tarefa_'.$tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $complemento = trim((string) $request->request->get('instrucoes_complementares', ''));
        if ($complemento !== '') {
            $descricao = $tarefa->getDescricao();
            $tarefa->setDescricao(trim($descricao."\n\n[Complemento Admin] " . $complemento));
        }

        $tarefa->setStatus(Tarefa::STATUS_REABERTA);
        $tarefa->setDataConclusao(null);

        foreach ($tarefa->getAtribuicoes() as $atribuicao) {
            $atribuicao->setStatus(AtribuicaoTarefa::STATUS_PENDENTE);
            $atribuicao->setDataEnvioRevisao(null);
        }

        $entityManager->flush();

        return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
    }

    #[Route('/{id}/status', name: 'tarefa_atualizar_status', methods: ['POST'])]
    public function atualizarStatus(Tarefa $tarefa, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('atualizar_status_tarefa_'.$tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $status = (string) $request->request->get('status', '');
        $statusPermitidos = [
            Tarefa::STATUS_PENDENTE,
            Tarefa::STATUS_EM_REVISAO,
            Tarefa::STATUS_CONCLUIDA,
            Tarefa::STATUS_REABERTA,
        ];

        if (!in_array($status, $statusPermitidos, true)) {
            throw $this->createAccessDeniedException('Status inválido.');
        }

        $tarefa->setStatus($status);

        if ($status === Tarefa::STATUS_CONCLUIDA) {
            $tarefa->setDataConclusao(new \DateTimeImmutable());
        } else {
            $tarefa->setDataConclusao(null);
        }

        foreach ($tarefa->getAtribuicoes() as $atribuicao) {
            if ($status === Tarefa::STATUS_CONCLUIDA) {
                $atribuicao->setStatus(AtribuicaoTarefa::STATUS_CONCLUIDA);
                continue;
            }

            if ($status === Tarefa::STATUS_EM_REVISAO) {
                $atribuicao->setStatus(AtribuicaoTarefa::STATUS_EM_REVISAO);
                continue;
            }

            $atribuicao->setStatus(AtribuicaoTarefa::STATUS_PENDENTE);

            if ($status === Tarefa::STATUS_PENDENTE || $status === Tarefa::STATUS_REABERTA) {
                $atribuicao->setDataEnvioRevisao(null);
            }
        }

        $entityManager->flush();

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
