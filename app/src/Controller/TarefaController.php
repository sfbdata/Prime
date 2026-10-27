<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Attribute\PastaPorId;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Tarefa\Enum\AbaMetas;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\PrioridadeDaMeta;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\Exception\DesfazerReaberturaRecusadoException;
use App\Tarefa\Exception\MetaComDespachoSemConfirmacaoException;
use App\Tarefa\Exception\MetaNaoExcluivelException;
use App\Tarefa\Exception\PrazoNaoEditavelException;
use App\Tarefa\UseCase\AtualizarPrazoTarefaUseCase;
use App\Tarefa\UseCase\DesfazerReaberturaDaMetaUseCase;
use App\Tarefa\DTO\DespacharNaMetaInput;
use App\Tarefa\Exception\DespachoRecusadoException;
use App\Tarefa\Service\TextoDoDespacho;
use App\Tarefa\UseCase\DarCienciaDosDespachosUseCase;
use App\Tarefa\UseCase\DespacharNaMetaUseCase;
use App\Tarefa\UseCase\MontarDespachosDaMetaUseCase;
use App\Tarefa\UseCase\MontarSugestoesDaMetaUseCase;
use App\Tarefa\UseCase\AprenderComAMetaUseCase;
use App\Tarefa\UseCase\MontarEtapaDaMetaUseCase;
use App\Tarefa\UseCase\MontarLinhaDoTempoDaMetaUseCase;
use App\Tarefa\UseCase\MontarSituacaoDaMetaUseCase;
use App\Shared\Http\EntregaDeArquivo;
use App\Tarefa\UseCase\ExcluirTarefaUseCase;
use App\Tarefa\DTO\AnalisarPrazoDaMetaInput;
use App\Tarefa\UseCase\GravarMetaNovaUseCase;
use App\Tarefa\UseCase\RegistrarAnaliseDoPrazoNaMetaUseCase;
use App\Tarefa\UseCase\ListarMinhasMetasUseCase;
use App\Tarefa\UseCase\MontarEsteiraDaPastaUseCase;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tarefa\Service\AcessoAMeta;
use App\Tarefa\Service\AcessoAoModuloTarefas;
use App\Tarefa\Armazenamento\ChavesDeTarefa;
use App\Tarefa\Service\ListaDeMetasDaPastaEmJson;
use App\Tarefa\Service\ReaberturaDesfazivelDaMeta;
use App\Tarefa\Service\QuemCuidaDaMeta;
use App\Tarefa\Service\TarefaTimelineAssembler;
use App\Tarefa\Controller\RecusaDeQuemNaoCuidaDaMeta;
use App\Tarefa\UseCase\ConcluirMetaUseCase;
use App\Tenant\Service\ColaboradoresAtribuiveis;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/tarefas')]
final class TarefaController extends AbstractController
{
    public function __construct(
        private readonly EntregaDeArquivo $entrega,
        private readonly TarefaTimelineAssembler $timelineAssembler,
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly NotificacaoService $notificacaoService,
        private readonly AcessoAMeta $acessoAMeta,
        private readonly AcessoAoModuloTarefas $moduloTarefas,
    ) {
    }

    /**
     * Tela "Minhas Metas": as metas do usuário logado, separadas pelo PAPEL dele (a aba).
     *
     * A aba e os filtros vêm da query string porque precisam ser linkáveis — os KPIs do topo são
     * atalhos que apontam para uma aba já filtrada, e o usuário precisa poder guardar o link.
     */
    #[Route('/minhas', name: 'tarefa_minhas', methods: ['GET'])]
    public function minhas(Request $request, ListarMinhasMetasUseCase $useCase, MontarEsteiraDaPastaUseCase $esteira, AcessoAPasta $acessoAPasta): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->assertAccess($usuario);

        $filtros     = $this->filtrosDeMinhasMetas($request);
        $aba         = AbaMetas::deQueryString($request->query->get('aba'));
        $soAListagem = $request->isXmlHttpRequest();
        $ocultas     = $acessoAPasta->idsOcultosPara($usuario, (int) $tenant->getId());

        $dados = [
            'painel'  => $useCase->executar(
                $usuario,
                $aba,
                $filtros,
                // Na recarga parcial só o fragmento da lista é redesenhado; calcular as
                // contagens do topo ali seria pagar 8 consultas por tecla digitada na busca.
                $soAListagem,
                $ocultas,
                $tenant,
            ),
            'filtros' => $filtros,
        ];

        if ($soAListagem) {
            return $this->render('tarefa/_resultado.html.twig', $dados);
        }

        // A esteira é GERAL DO ESCRITÓRIO (desenho 04 de 22/10) e fica fora do fragmento que o filtro recarrega.
        $dados['esteira'] = $esteira->executar($usuario, $tenant, new \DateTimeImmutable('today'), $ocultas);

        return $this->render('tarefa/minhas.html.twig', $dados);
    }

    /** @return array{busca: string, prioridade: string, prazo: string, etapa: string} */
    private function filtrosDeMinhasMetas(Request $request): array
    {
        // Prioridade da meta (desenho 04); valor fora do enum (o antigo `prioridade` da pasta) vira vazio.
        $prioridade = PrioridadeDaMeta::tryFrom((string) $request->query->get('prioridade', ''));
        // Caixa da esteira clicada ("Só a etapa X", desenho 04 de 22/10); valor fora do enum vira vazio.
        $etapa = EtapaDaPasta::tryFrom((string) $request->query->get('etapa', ''));

        return [
            // Sem `status`: o desenho 04 (P:69-80) não tem o filtro Status (dono, 21/10).
            'busca'      => trim((string) $request->query->get('busca', '')),
            'prioridade' => $prioridade === null ? '' : $prioridade->value,
            'prazo'      => (string) $request->query->get('prazo', ''),
            'etapa'      => $etapa === null ? '' : $etapa->value,
        ];
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
        ColaboradoresAtribuiveis $atribuiveis,
        GravarMetaNovaUseCase $gravarMeta,
        RegistrarAnaliseDoPrazoNaMetaUseCase $registrarAnalise
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

        // D-META (desenho 02, PZ-MOD-04): prazo e ao menos um responsável também são obrigatórios.
        $prazo = $this->prazoDaMetaNova($request);
        $responsaveis = $atribuiveis->resolverVarios(array_map('intval', $request->request->all('responsaveis')), $tenant);
        if ($prazo === null || $responsaveis === []) {
            return $this->json(['erro' => $prazo === null ? 'Informe um prazo válido para a meta.' : 'Escolha ao menos um responsável do escritório para a meta.'], 422);
        }

        // Desenho 02 (L179 e L4048 `inval`): a caixa / etapa é escolhida no modal, obrigatória. Só um valor do enum
        // vale; a dedução pelo título (PrePersist) continua como rede para as outras rotas, não para esta.
        $etapa = EtapaDaPasta::tryFrom(trim((string) $request->request->get('etapa', '')));
        if ($etapa === null) {
            return $this->json(['erro' => 'Escolha a caixa / etapa da meta.'], 422);
        }

        $tarefa = $this->novaMetaDaPasta($titulo, $descricao, $pasta, $tenant, $usuario, $prazo, $etapa, $responsaveis);

        // Análise do prazo (desenho 02): estimado ou a conferir não grava.
        if (($recusa = $this->registrarAnaliseDoPrazo($request, $registrarAnalise, $tarefa, $titulo, $descricao)) !== null) {
            return $recusa;
        }

        // Grava já com o número da meta no escritório ("Nº 0001"), sob a trava da sequência.
        $gravarMeta->executar($tarefa);

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
     * A meta nova da pasta, ainda sem gravar (a gravação é do `GravarMetaNovaUseCase`).
     *
     * @param iterable<User> $responsaveis
     */
    private function novaMetaDaPasta(string $titulo, string $descricao, Pasta $pasta, Tenant $tenant, User $usuario, \DateTimeImmutable $prazo, EtapaDaPasta $etapa, iterable $responsaveis): Tarefa
    {
        $tarefa = new Tarefa();
        $tarefa->setTitulo($titulo);
        $tarefa->setDescricao($descricao);
        $tarefa->setStatus(Tarefa::STATUS_PENDENTE);
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setCriadoPor($usuario);
        $tarefa->setPrazo($prazo);
        $tarefa->setEtapa($etapa);
        foreach ($responsaveis as $responsavel) {
            $tarefa->addResponsavel($responsavel);
        }

        return $tarefa;
    }

    /**
     * Análise do prazo da Nova meta (desenho 02): o modal manda `analise_prazo=1` quando a mostrou. Sem o campo (chamadas
     * antigas) a meta nasce como sempre nasceu. Devolve a recusa 422 quando o prazo jurídico ainda é só uma estimativa.
     */
    private function registrarAnaliseDoPrazo(Request $request, RegistrarAnaliseDoPrazoNaMetaUseCase $registrar, Tarefa $tarefa, string $titulo, string $descricao): ?JsonResponse
    {
        if ('1' !== (string) $request->request->get('analise_prazo')) {
            return null;
        }

        try {
            $registrar->executar($tarefa, new AnalisarPrazoDaMetaInput(
                $titulo,
                $descricao,
                AnalisarPrazoDaMetaInput::dataDoCampo($request->request->get('data_publicacao')),
                AnalisarPrazoDaMetaInput::dataDoCampo($request->request->get('data_ciencia')),
                AnalisarPrazoDaMetaInput::dataDoCampo($request->request->get('prazo_juridico')),
                '1' === (string) $request->request->get('analise_confirmada'),
            ), new \DateTimeImmutable('today'));
        } catch (\DomainException $recusa) {
            return $this->json(['erro' => $recusa->getMessage(), 'codigo' => 'analise_do_prazo'], 422);
        }

        return null;
    }

    /**
     * O prazo da meta nova, no formato do campo do modal (`<input type="date">`, ISO `aaaa-mm-dd`). Vazio ou fora
     * do formato (texto solto, data impossível como 31/02) vira null, e o `criarParaPasta` responde 422.
     */
    private function prazoDaMetaNova(Request $request): ?\DateTimeImmutable
    {
        $valor = trim((string) $request->request->get('prazo', ''));
        $prazo = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return $prazo !== false && $prazo->format('Y-m-d') === $valor ? $prazo : null;
    }

    /**
     * Detalhe da tarefa.
     */
    #[Route('/{id}', name: 'tarefa_show', methods: ['GET'])]
    public function show(Tarefa $tarefa, ColaboradoresAtribuiveis $atribuiveis, AtualizarPrazoTarefaUseCase $prazoUseCase, DarCienciaDosDespachosUseCase $ciencia, MontarDespachosDaMetaUseCase $despachos, MontarSituacaoDaMetaUseCase $situacao, MontarLinhaDoTempoDaMetaUseCase $linhaDoTempo, MontarEtapaDaMetaUseCase $etapa, MontarSugestoesDaMetaUseCase $sugestoes): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($usuario, $tarefa, $tenant);

        // Ciência automática (C14) ANTES de montar a tela, para o "Ciente" já aparecer nesta abertura.
        $agora = new \DateTimeImmutable();
        $ciencia->executar($tarefa, $usuario, $tenant, $agora);

        $tenantId = (int) $tenant->getId();
        $timelineItems = $this->timelineAssembler->montar($tarefa, $tenantId);

        $users = $atribuiveis->doEscritorio($tenant, $tarefa->getResponsaveis());

        $despachosDaMeta = $despachos->executar($tarefa, $usuario, $tenant, $agora);

        return $this->render('tarefa/show.html.twig', [
            'tarefa'         => $tarefa,
            'linhaDoTempo'   => $linhaDoTempo->executar($timelineItems, $despachosDaMeta),
            'despachos'      => $despachosDaMeta,
            'sugestoes'      => $sugestoes->doDespacho($tarefa, $usuario, $tenant, $agora->setTime(0, 0)),
            'situacao'       => $situacao->executar($tarefa, $tenant, $agora),
            'etapa'          => $etapa->executar($tarefa, $usuario, $tenant),
            'usuarioAtual'   => $usuario,
            'users'          => $users,
            'podeEditarPrazo' => $prazoUseCase->podeEditarPrazo($tarefa, $usuario, $tenant),
        ]);
    }

    /**
     * Atualiza os responsáveis da tarefa.
     */
    #[Route('/{id}/responsaveis', name: 'tarefa_update_responsaveis', methods: ['POST'])]
    public function updateResponsaveis(Tarefa $tarefa, Request $request, EntityManagerInterface $entityManager, ColaboradoresAtribuiveis $atribuiveis): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($usuario, $tarefa, $tenant);

        if (!$this->isCsrfTokenValid('update_responsaveis_' . $tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $userIds = array_map('intval', $request->request->all('responsaveis', []));
        $users = $atribuiveis->resolverVarios($userIds, $tenant, $tarefa->getResponsaveis());

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
     * Despachar na meta (desenho 04B de 22/10; spec `minhas-metas-esteira-2210.md`, Fase 2a). O controller só monta
     * o Input; a regra (texto ou anexo, código do registro, ciclo de vida e avisos) é do `DespacharNaMetaUseCase`.
     * Responsável envia → Para Revisão. Gestor devolve → Pendente.
     */
    #[Route('/{id}/mensagem', name: 'tarefa_mensagem', methods: ['POST'])]
    public function mensagem(Tarefa $tarefa, Request $request, DespacharNaMetaUseCase $useCase, ValidatorInterface $validator, AprenderComAMetaUseCase $aprender, LoggerInterface $logger): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($usuario, $tarefa, $tenant);

        if (!$this->isCsrfTokenValid('mensagem_tarefa_'.$tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $input = $this->entradaDoDespacho($request);
        $erros = $validator->validate($input);
        if (count($erros) > 0) {
            $this->addFlash('danger', (string) $erros->get(0)->getMessage());

            return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
        }

        $anexo = $request->files->get('arquivo_mensagem');
        try {
            // O anexo vai aos documentos da pasta pela regra de chaves (C4); recusado, nada fica.
            $useCase->executar($tarefa, $usuario, $tenant, $input, $anexo instanceof UploadedFile && $anexo->isValid() ? $anexo : null);
        } catch (DespachoRecusadoException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
        }
        $url = $this->generateUrl('tarefa_show', ['id' => $tarefa->getId()]);
        $this->aprenderDoDespacho($aprender, $logger, $tarefa, $usuario, $tenant, $input);

        return $this->redirect($url);
    }

    /**
     * Aprendizado (Fase 4) é melhor esforço: roda só depois do despacho gravado (e só se a pessoa não desligou no Meu
     * Perfil, D4). Uma falha aqui não desfaz nem esconde o despacho: vai para o log (meta e pessoa, sem o texto) e a
     * resposta segue de sucesso, montada antes, sem depender do EntityManager.
     */
    private function aprenderDoDespacho(AprenderComAMetaUseCase $aprender, LoggerInterface $logger, Tarefa $tarefa, User $usuario, Tenant $tenant, DespacharNaMetaInput $input): void
    {
        try {
            $aprender->doDespacho($tarefa, $usuario, $tenant, $input, new \DateTimeImmutable('today'));
        } catch (\Throwable $e) {
            $logger->error('Aprendizado do despacho falhou; o despacho ficou gravado.', ['meta' => $tarefa->getId(), 'usuario' => $usuario->getId(), 'erro' => $e::class]);
        }
    }

    /** Campo simples (C10): quebras normalizadas antes da validação, para o limite contar o que a pessoa escreveu. */
    private function entradaDoDespacho(Request $request): DespacharNaMetaInput
    {
        $natureza = trim((string) $request->request->get('natureza', ''));
        $prazo    = trim((string) $request->request->get('prazo', ''));

        return new DespacharNaMetaInput(
            texto: TextoDoDespacho::normalizar((string) $request->request->get('mensagem', '')),
            acao: (string) $request->request->get('acao', ''),
            natureza: $natureza === '' ? null : $natureza,
            prazo: $prazo === '' ? null : $prazo,
        );
    }

    /**
     * Conclui a meta (aba Metas da pasta). D3 (spec `minhas-metas-esteira-2210.md`, decisão do dono 22/10): só o
     * responsável ou quem criou a meta conclui, pela mesma regra e com a mesma recusa (403) da passagem de etapa.
     * Vale também para o "Desfazer" da reabertura, que conclui de novo.
     */
    #[Route('/{id}/concluir', name: 'tarefa_concluir', methods: ['POST'])]
    public function concluir(
        Tarefa $tarefa,
        Request $request,
        ListaDeMetasDaPastaEmJson $listaEmJson,
        ReaberturaDesfazivelDaMeta $reaberturaDesfazivel,
        DesfazerReaberturaDaMetaUseCase $desfazerReabertura,
        QuemCuidaDaMeta $quemCuida,
        ConcluirMetaUseCase $concluirMeta,
    ): Response {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($usuario, $tarefa, $tenant);

        if (!$this->isCsrfTokenValid('concluir_tarefa_'.$tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!$quemCuida->ehResponsavelOuCriador($tarefa, $usuario)) {
            return RecusaDeQuemNaoCuidaDaMeta::responder($request);
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

        // Trava e relê a meta: a passagem de etapa pode tê-la concluído depois que esta tela a leu.
        $concluiu = $concluirMeta->executar($tarefa, $agora);
        if ($concluiu) {
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
    public function excluir(Tarefa $tarefa, Request $request, ExcluirTarefaUseCase $useCase, ListaDeMetasDaPastaEmJson $listaEmJson): Response
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
            // A janela do despacho registrado manda `confirmar_despacho=1` (só quem tem o poder sem prazo a vê).
            $useCase->executar($tarefa, $usuario, $tenant, $request->request->getBoolean('confirmar_despacho'));
        } catch (MetaComDespachoSemConfirmacaoException $e) {
            return new Response($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (MetaNaoExcluivelException $e) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['sucesso' => false, 'mensagem' => $e->getMessage()], Response::HTTP_CONFLICT);
            }
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
        }

        // "Desfazer" do aviso "Meta duplicada" (pasta-metas.js): a aba volta renderizada, sem a cópia.
        if ($request->isXmlHttpRequest()) {
            return $listaEmJson->responder($tarefa, true, 'Meta excluída');
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
     * Desde a E2.7 a resposta é a própria `EntregaDeArquivo`: a chave sai de `ChavesDeTarefa`, que
     * traduz o caminho público da coluna sem relaxar `ChaveDeArquivo` (D5).
     */
    #[Route('/mensagem/{id}/arquivo/visualizar', name: 'tarefa_mensagem_arquivo_view', methods: ['GET'])]
    public function viewArquivoMensagem(TarefaMensagem $mensagem): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($usuario, $mensagem->getTarefa(), $tenant);

        return $this->entregarAnexo($mensagem, inline: true);
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

        return $this->entregarAnexo($mensagem, inline: false);
    }

    private function assertAccess(User $user): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('Sem tenant selecionado.');
        }
        if (!$this->moduloTarefas->pode($user, $tenant)) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Tarefas.');
        }

        return $tenant;
    }

    /** A guarda da tela da meta mora no `AcessoAMeta` (SEC-013 e o cadeado da pasta), a mesma do Extrato e do Lembrar. */
    private function verificarAcessoTarefa(User $usuario, Tarefa $tarefa, Tenant $tenant): void
    {
        $this->acessoAMeta->exigir($tarefa, $tenant, $usuario);
    }

    /**
     * Visualizar e baixar entregam pela chave, depois da autorização (INV-5). D10 em Tarefa (spec P2
     * §10.1): valor fora do formato (`admin/` legado, prefixo desconhecido, travessia) é, para quem
     * pede, o mesmo que não existir — 404, como a rota sempre respondeu; valor no formato sem
     * escritório é dado corrompido e a `ChaveDeArquivoInvalida` propaga. Ausência também é 404
     * (`EntregaDeArquivo`); pane do storage sobe.
     *
     * O nome para download é o de sempre: o último segmento do valor guardado na coluna.
     */
    private function entregarAnexo(TarefaMensagem $mensagem, bool $inline): Response
    {
        if (!ChavesDeTarefa::temAnexoEnderecavel($mensagem)) {
            throw $this->createNotFoundException('Arquivo não encontrado.');
        }

        $chave = ChavesDeTarefa::anexoDeMensagem($mensagem);

        return $this->entrega->resposta($chave, $chave->nome, $inline);
    }
}
