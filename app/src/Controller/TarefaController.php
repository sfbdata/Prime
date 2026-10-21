<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Attribute\PastaPorId;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Tarefa\Enum\AbaMetas;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\Exception\DesfazerReaberturaRecusadoException;
use App\Tarefa\Exception\MetaNaoExcluivelException;
use App\Tarefa\Exception\PrazoNaoEditavelException;
use App\Tarefa\UseCase\AtualizarPrazoTarefaUseCase;
use App\Tarefa\UseCase\DesfazerReaberturaDaMetaUseCase;
use App\Shared\Http\EntregaDeArquivo;
use App\Shared\Service\SanitizadorTextoRico;
use App\Tarefa\UseCase\EditarTarefaMensagemUseCase;
use App\Tarefa\UseCase\ExcluirTarefaUseCase;
use App\Tarefa\UseCase\ListarMinhasMetasUseCase;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tarefa\Service\CaminhoDeAnexoDeTarefa;
use App\Tarefa\Service\ListaDeMetasDaPastaEmJson;
use App\Tarefa\Service\ReaberturaDesfazivelDaMeta;
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
        private readonly CaminhoDeAnexoDeTarefa $caminhoDeAnexo,
        private readonly TarefaTimelineAssembler $timelineAssembler,
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly UserTenantRepository $userTenantRepo,
        private readonly NotificacaoService $notificacaoService,
    ) {
    }

    /**
     * Tela "Minhas Metas": as metas do usuário logado, separadas pelo PAPEL dele (a aba).
     *
     * A aba e o modo vêm da query string porque precisam ser linkáveis — os KPIs do topo são
     * atalhos que apontam para uma aba já filtrada, e o usuário precisa poder guardar o link.
     */
    #[Route('/minhas', name: 'tarefa_minhas', methods: ['GET'])]
    public function minhas(Request $request, ListarMinhasMetasUseCase $useCase, AcessoAPasta $acessoAPasta): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->assertAccess($usuario);

        $filtros = [
            'busca'      => trim((string) $request->query->get('busca', '')),
            'status'     => (string) $request->query->get('status', ''),
            'prioridade' => (string) $request->query->get('prioridade', ''),
            'prazo'      => (string) $request->query->get('prazo', ''),
        ];

        $aba        = AbaMetas::deQueryString($request->query->get('aba'));
        $soAListagem = $request->isXmlHttpRequest();

        $dados = [
            'painel'  => $useCase->executar(
                $usuario,
                $aba,
                $filtros,
                $request->query->get('modo') === 'lista',
                // Na recarga parcial só o fragmento da lista é redesenhado; calcular as
                // contagens do topo ali seria pagar 8 consultas por tecla digitada na busca.
                $soAListagem,
                $acessoAPasta->idsOcultosPara($usuario, (int) $tenant->getId()),
            ),
            'filtros' => $filtros,
        ];

        if ($soAListagem) {
            return $this->render('tarefa/_resultado.html.twig', $dados);
        }

        return $this->render('tarefa/minhas.html.twig', $dados);
    }

    /**
     * Liga/desliga o acompanhamento pessoal de uma meta.
     *
     * É marcação PRIVADA de quem clica: não muda a meta para os colegas, não notifica ninguém
     * e não concede permissão nenhuma. Só coloca a meta na aba "Em acompanhamento" de quem
     * marcou.
     */
    #[Route('/{id}/acompanhar', name: 'tarefa_acompanhar', methods: ['POST'])]
    public function acompanhar(
        Tarefa $tarefa,
        Request $request,
        EntityManagerInterface $entityManager,
        TarefaRepository $tarefaRepository,
    ): JsonResponse {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($usuario, $tarefa, $tenant);

        if (!$this->isCsrfTokenValid('acompanhar_tarefa_' . $tarefa->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], 403);
        }

        $acompanhando = $tarefa->alternarAcompanhamento($usuario);
        $entityManager->flush();

        // As contagens voltam na MESMA resposta porque o clique muda o conteúdo de outra aba:
        // sem elas o usuário marcava, via o ícone pintar e o badge de "Em acompanhamento"
        // continuar em zero até apertar F5. Vem do banco, e não de um +1 no cliente, para o
        // número não dessincronizar com o que a próxima carga da tela vai mostrar.
        return $this->json([
            'sucesso'      => true,
            'acompanhando' => $acompanhando,
            'contagens'    => $tarefaRepository->contarPorAba($usuario),
        ]);
    }

    /**
     * Cria tarefa vinculada a uma pasta (chamada via modal AJAX). Na pasta excluída (lápide) o
     * PastaSomenteLeituraListener recusa antes de chegar aqui.
     */
    #[Route('/pasta/{pastaId}/criar', name: 'tarefa_criar_para_pasta', methods: ['POST'])]
    #[PastaPorId('pastaId')]
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

        // C6-SEC-03: a POSSE da pasta, e não o vínculo do criador/responsável dela. Perguntar só se
        // essa pessoa tem vínculo com o escritório da sessão deixava passar a pasta de OUTRO
        // escritório quando ela está nos dois (e o TenantFilter falhou) — a meta nasceria com o
        // meu tenant pendurada na pasta alheia. Mesma correção do SEC-013. Fora do escritório:
        // 404, como todo recurso cross-tenant — 403 confirmaria que o id existe em algum lugar.
        $pasta = $pastaRepository->find($pastaId);
        if ($pasta === null || $pasta->getTenant()?->getId() !== $tenant->getId()) {
            return $this->json(['erro' => 'Pasta não encontrada.'], 404);
        }

        // A meta é conteúdo da pasta: quem não VÊ a pasta não cria meta nela (nem escolhe
        // responsáveis, nem dispara o aviso de meta criada). VER é o mínimo; exigir EDITAR, como
        // as ações irmãs do `MetaNaListaController`, é decisão em aberto (C6-SEC-03, parte C).
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_VIEW)) {
            return $this->json(['erro' => 'Sem permissão para acessar esta pasta.'], 403);
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

        // PMC-044: esta rota só é chamada pelo modal da pasta, então quem criou fica NA pasta — na
        // aba Metas, com a meta nova realçada (`?meta=`, lido pelo pasta-metas.js). A criação pela
        // tela /tarefas é outra rota e não muda.
        return $this->json([
            'sucesso' => true,
            'id'      => $tarefa->getId(),
            'destino' => $this->generateUrl('pasta_show', ['id' => $pasta->getId(), 'meta' => $tarefa->getId()]) . '#tarefas',
        ]);
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
    public function mensagem(Tarefa $tarefa, Request $request, EntityManagerInterface $entityManager, SanitizadorTextoRico $sanitizador): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($usuario, $tarefa, $tenant);

        if (!$this->isCsrfTokenValid('mensagem_tarefa_'.$tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Vem do editor rico (HTML): limpo ANTES de persistir. Texto puro atravessa intacto.
        $texto = $sanitizador->limpar(trim((string) $request->request->get('mensagem', ''))) ?? '';
        $acao  = (string) $request->request->get('acao', '');

        $arquivoMensagem = $request->files->get('arquivo_mensagem');
        $arquivoAnexoPath = null;

        if ($arquivoMensagem instanceof UploadedFile && $arquivoMensagem->isValid()) {
            $uploaded = $this->uploadFiles([$arquivoMensagem], 'public/uploads/tarefas/chat');
            $arquivoAnexoPath = $uploaded[0] ?? null;
        }

        // `estaVazio` e não `!== ''`: o editor entrega `<p><br></p>` quando nada foi digitado.
        $temTexto = !$sanitizador->estaVazio($texto);

        if ($temTexto || $arquivoAnexoPath !== null) {
            $mensagem = new TarefaMensagem();
            $mensagem->setUsuario($usuario);
            $mensagem->setMensagem($temTexto ? $texto : '[Arquivo anexado]');
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
    public function editarMensagem(TarefaMensagem $mensagem, Request $request, EditarTarefaMensagemUseCase $useCase, SanitizadorTextoRico $sanitizador): JsonResponse
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
            // cru volta ao editor; conteudoHtml é o que a tela exibe (sanitizado).
            'conteudo'     => $mensagem->getMensagem(),
            'conteudoHtml' => $sanitizador->paraExibicao($mensagem->getMensagem()),
            'editadoEm'    => $mensagem->getEditadoEm()?->format('d/m/Y H:i'),
        ]);
    }

    /**
     * Conclui a tarefa (qualquer usuário com acesso pode concluir).
     */
    #[Route('/{id}/concluir', name: 'tarefa_concluir', methods: ['POST'])]
    public function concluir(
        Tarefa $tarefa,
        Request $request,
        EntityManagerInterface $entityManager,
        ListaDeMetasDaPastaEmJson $listaEmJson,
        ReaberturaDesfazivelDaMeta $reaberturaDesfazivel,
        DesfazerReaberturaDaMetaUseCase $desfazerReabertura,
    ): Response {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($usuario, $tarefa, $tenant);

        if (!$this->isCsrfTokenValid('concluir_tarefa_'.$tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $agora = new \DateTimeImmutable();

        // PMC-061: "Desfazer" do aviso "Meta reaberta". A conclusão volta com a data ORIGINAL,
        // que o servidor guardou na sessão no reabrir — o navegador só pede `desfazer=1`, não
        // manda data. Sem data lembrada e válida, recusa (não conclui com "agora"). Sem
        // notificação: a conclusão original já notificou, e o reabrir não avisou ninguém.
        if ($request->request->getBoolean('desfazer')) {
            $anterior = $reaberturaDesfazivel->consumir($request->getSession(), $tarefa, $tenant, $agora);

            try {
                $restaurou = $desfazerReabertura->executar($tarefa, $tenant, $anterior, $agora);
            } catch (DesfazerReaberturaRecusadoException $e) {
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(['sucesso' => false, 'mensagem' => $e->getMessage()], Response::HTTP_CONFLICT);
                }
                $this->addFlash('danger', $e->getMessage());

                return $this->redirectToRoute('pasta_show', ['id' => $tarefa->getPasta()->getId(), '_fragment' => 'tarefas']);
            }

            if ($request->isXmlHttpRequest()) {
                return $listaEmJson->responder($tarefa, $restaurou, $restaurou ? 'Meta concluída' : 'A meta já estava concluída');
            }
            if ($restaurou) {
                $this->addFlash('success', 'Tarefa concluída.');
            }

            return $this->redirectToRoute('pasta_show', ['id' => $tarefa->getPasta()->getId(), '_fragment' => 'tarefas']);
        }

        // Concluir de verdade encerra qualquer "Desfazer" pendente desta meta.
        $reaberturaDesfazivel->esquecer($request->getSession(), $tarefa, $tenant, $agora);

        $concluiu = false;
        if ($tarefa->getStatus() !== Tarefa::STATUS_CONCLUIDA) {
            $tarefa->setStatus(Tarefa::STATUS_CONCLUIDA);
            $tarefa->setDataConclusao($agora);
            $entityManager->flush();
            $concluiu = true;

            $this->notificacaoService->notificarTarefaConcluida($tarefa);
        }

        // PMC-031: a lista da aba Metas conclui por XHR, sem recarregar. Mesma guarda e mesmo
        // CSRF acima; a resposta traz a aba renderizada de novo pelo servidor (linha, filtros,
        // trilho e drawer), para nada ser recontado no navegador. Sem JS, segue o POST + redirect.
        if ($request->isXmlHttpRequest()) {
            return $listaEmJson->responder($tarefa, $concluiu, $concluiu ? 'Meta concluída' : 'A meta já estava concluída');
        }

        if ($concluiu) {
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
     *
     * SEC-012: o upload do chat não tem lista de tipos, então um `.html` (ou SVG, XML…) enviado
     * por alguém do escritório abria aqui como documento ativo na origem do BlueJus, com a sessão
     * de quem clicasse. A resposta segue a MESMA política da `EntregaDeArquivo` (SEC-003): nosniff
     * sempre e `CSP: sandbox` para todo tipo que não seja PDF, imagem raster, áudio ou vídeo.
     *
     * Por que não chama `EntregaDeArquivo::resposta()`: o anexo de tarefa guarda caminho público
     * com `/`, que `ChaveDeArquivo` recusa, e a conversão para chave é a E2.7 (D5 da spec de
     * storage). Até lá a decisão de sandbox vem de `EntregaDeArquivo::exigeSandbox()` — uma regra
     * só, sem cópia — e o `Content-Type` é o palpite da própria `BinaryFileResponse` no
     * `prepare()`, o mesmo tipo que a decisão de sandbox consultou.
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
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if (EntregaDeArquivo::exigeSandbox($response->getFile()->getMimeType() ?: 'application/octet-stream')) {
            $response->headers->set('Content-Security-Policy', EntregaDeArquivo::CSP_SANDBOX);
        }

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
        // SEC-013: a posse vem ANTES do vínculo. Conferir só se o criador ou o responsável da
        // pasta tem vínculo com o escritório da sessão deixa passar a meta de OUTRO escritório
        // quando essa pessoa está nos dois (e o TenantFilter falhou). Fora do escritório: 404,
        // como todo recurso cross-tenant — 403 confirmaria que o id existe em algum lugar.
        if ($tarefa->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Meta não encontrada.');
        }

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

    /**
     * Delegado a `CaminhoDeAnexoDeTarefa`, que aplica allowlist + normalização + confinamento.
     * A lógica vive num serviço porque a guarda contra travessia não é testável pela rota: o
     * roteador do Symfony normaliza `../..` antes do controller (ver o comentário em
     * `ServirFotoControllerTest::testServirFotoComPathTraversalRetorna404`).
     */
    private function resolverCaminhoArquivo(string $caminhoPublico): ?string
    {
        return $this->caminhoDeAnexo->resolver($caminhoPublico);
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
