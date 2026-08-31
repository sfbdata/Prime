<?php

namespace App\Processo\Controller;

use App\Controller\Trait\ResourceAccessTrait;
use App\Processo\Entity\Processo;
use App\Processo\Entity\ParteProcesso;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Processo\Entity\AssuntoProcesso;
use App\Processo\Repository\ProcessoRepository;
use App\Entity\Permission\AccessRequest;
use App\Cliente\Repository\ClienteRepository;
use App\Pasta\Repository\PastaRepository;
use App\Tarefa\Repository\TarefaRepository;
use App\Processo\Enum\MotivoFalhaDatajud;
use App\Processo\Exception\ConsultaDatajudException;
use App\Processo\Exception\TribunalNaoIdentificadoException;
use App\Processo\Service\DatajudClient;
use App\Processo\Service\DatajudProcessoMapper;
use App\Processo\Service\TribunalCnjResolver;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ProcessoController - Gerencia processos judiciais.
 *
 * Estrutura de rotas REST:
 * - GET  /processos              → Lista todos os processos
 * - GET  /processos/novo         → Formulário de criação
 * - POST /processos/novo         → Cria novo processo
 * - GET  /processos/{id}         → Exibe detalhes do processo
 * - GET  /processos/{id}/editar  → Formulário de edição
 * - POST /processos/{id}/editar  → Atualiza processo
 * - POST /processos/{id}/deletar → Remove processo
 *
 * Rotas de documentos (aninhadas por contexto):
 * - POST /processos/{id}/documentos/upload              → Upload de documento
 * - POST /processos/{id}/documentos/{documentoId}/excluir → Remove documento
 *
 * API DataJud:
 * - POST /processos/api/search → Busca processo no CNJ/DataJud
 */
#[Route('/processos')]
class ProcessoController extends AbstractController
{
    use ResourceAccessTrait;

    private const PER_PAGE = 25;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TribunalCnjResolver $tribunalResolver,
    ) {}

    #[Route('/', name: 'processo_index', methods: ['GET'])]
    public function index(Request $request, ProcessoRepository $repo, PermissionChecker $permissionChecker): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$permissionChecker->canAccessModule($currentUser, $tenant, 'processos')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de processos.');
            return $this->redirectToRoute('homepage');
        }

        $filtros = [
            'busca'    => trim((string) $request->query->get('busca', '')),
            'tribunal' => (string) $request->query->get('tribunal', ''),
            'situacao' => (string) $request->query->get('situacao', ''),
            'data_de'  => (string) $request->query->get('data_de', ''),
            'data_ate' => (string) $request->query->get('data_ate', ''),
        ];
        $ordenar = (string) $request->query->get('ordenar', '');
        $direcao = strtolower((string) $request->query->get('direcao', 'desc')) === 'asc' ? 'asc' : 'desc';
        $pagina  = max(1, (int) $request->query->get('page', 1));

        $total        = $repo->countByFiltros($tenant, $filtros);
        $totalPaginas = max(1, (int) ceil($total / self::PER_PAGE));

        $dados = [
            'processos'     => $repo->findByFiltrosPaginado($tenant, $filtros, $pagina, self::PER_PAGE, $ordenar, $direcao),
            'total'         => $total,
            'pagina'        => $pagina,
            'total_paginas' => $totalPaginas,
            'filtros'       => $filtros + ['ordenar' => $ordenar, 'direcao' => $direcao],
            'tribunais'     => $repo->findAllTribunais($tenant),
        ];

        if ($request->isXmlHttpRequest()) {
            return $this->render('processo/_resultado.html.twig', $dados);
        }

        return $this->render('processo/index.html.twig', $dados);
    }

    #[Route('/novo', name: 'processo_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ClienteRepository $clienteRepo, ProcessoRepository $processoRepo, EntityManagerInterface $em, PermissionChecker $permissionChecker): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$permissionChecker->canAccessModule($currentUser, $tenant, 'processos')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de processos.');
            return $this->redirectToRoute('homepage');
        }

        $clientes = $clienteRepo->findAll();
        $processo = new Processo();

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            // Verificar se o número do processo já está cadastrado
            $numeroProcessoNormalizado = preg_replace('/\D+/', '', (string) ($data['numeroProcesso'] ?? ''));
            if (!empty($numeroProcessoNormalizado)) {
                $processoExistente = $processoRepo->findByNumeroProcesso($numeroProcessoNormalizado);
                if ($processoExistente !== null) {
                    $this->addFlash('warning', 'Este número de processo já está cadastrado no sistema. Por favor, verifique o número informado ou acesse o processo existente.');
                    return $this->render('processo/new.html.twig', [
                        'clientes' => $clientes,
                        'processo' => $processo,
                        'isEdit' => false,
                    ]);
                }
            }

            $processo->setTenant($tenant);
            $this->fillProcessoFromRequest($processo, $data);
            $processo->setCriadoPor($this->getUser());

            $em->persist($processo);
            $em->flush();

            return $this->redirectToRoute('processo_show', ['id' => $processo->getId()]);
        }

        return $this->render('processo/new.html.twig', [
            'clientes' => $clientes,
            'processo' => $processo,
            'isEdit' => false,
        ]);
    }

    #[Route('/{id}/editar', name: 'processo_edit', methods: ['GET', 'POST'])]
    public function edit(Processo $processo, Request $request, ClienteRepository $clienteRepo, EntityManagerInterface $em, PermissionChecker $permissionChecker): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $tenant = $this->tenantContext->getCurrentTenant();
        $processoId = (int) $processo->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($permissionChecker, $tenant, AccessRequest::RESOURCE_PROCESSO, $processoId, AccessRequest::ACTION_EDIT, 'processo_index', $processo->getNumeroProcesso())) {
            return $redirect;
        }

        $clientes = $clienteRepo->findAll();

        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            $this->fillProcessoFromRequest($processo, $data);
            $em->flush();

            return $this->redirectToRoute('processo_show', ['id' => $processo->getId()]);
        }

        return $this->render('processo/new.html.twig', [
            'clientes' => $clientes,
            'processo' => $processo,
            'isEdit' => true,
        ]);
    }

    #[Route('/{id}', name: 'processo_show', methods: ['GET'])]
    public function show(
        Processo $processo,
        TarefaRepository $tarefaRepository,
        PastaRepository $pastaRepository,
        PermissionChecker $permissionChecker,
    ): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $tenant = $this->tenantContext->getCurrentTenant();
        $processoId = (int) $processo->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($permissionChecker, $tenant, AccessRequest::RESOURCE_PROCESSO, $processoId, AccessRequest::ACTION_VIEW, 'processo_index', $processo->getNumeroProcesso())) {
            return $redirect;
        }

        $historicoTarefas = [];

        foreach ($tarefaRepository->findByProcesso($processo) as $tarefa) {
            $usuariosAtribuidos = [];

            foreach ($tarefa->getResponsaveis() as $usuario) {
                $nomeUsuario = $usuario->getFullName();
                if ($nomeUsuario !== null && $nomeUsuario !== '') {
                    $usuariosAtribuidos[] = $nomeUsuario;
                }
            }

            $usuariosAtribuidos = array_values(array_unique($usuariosAtribuidos));

            $historicoTarefas[] = [
                'tarefaId' => $tarefa->getId(),
                'titulo' => $tarefa->getTitulo(),
                'descricao' => $tarefa->getDescricao(),
                'prazo' => $tarefa->getPrazo(),
                'usuarios' => $usuariosAtribuidos !== [] ? implode(', ', $usuariosAtribuidos) : '-',
                'statusAtual' => $tarefa->getStatus(),
                'dataCriacao' => $tarefa->getDataCriacao(),
                'dataUltimaRevisao' => null,
                'dataConclusaoFinal' => $tarefa->getDataConclusao(),
                'tempoTotalSegundos' => null,
            ];
        }

        usort(
            $historicoTarefas,
            fn (array $a, array $b): int => $b['dataCriacao'] <=> $a['dataCriacao']
        );

        // Sem escritório no contexto não há a quem perguntar quais pastas são visíveis —
        // lista vazia é o único resultado seguro (o super admin global cai aqui).
        $pastasVinculadas = $tenant !== null
            ? $pastaRepository->listarVinculadasAoProcesso($processo, $tenant)
            : [];

        return $this->render('processo/show.html.twig', [
            'processo' => $processo,
            'historicoTarefas' => $historicoTarefas,
            'pastasVinculadas' => $pastasVinculadas,
        ]);
    }

    #[Route('/{id}/deletar', name: 'processo_delete', methods: ['POST'])]
    public function delete(Request $request, Processo $processo, EntityManagerInterface $em, PermissionChecker $permissionChecker): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $tenant = $this->tenantContext->getCurrentTenant();
        $processoId = (int) $processo->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($permissionChecker, $tenant, AccessRequest::RESOURCE_PROCESSO, $processoId, AccessRequest::ACTION_DELETE, 'processo_index', $processo->getNumeroProcesso())) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('delete_processo_'.$processo->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $em->remove($processo);
        $em->flush();

        return $this->redirectToRoute('processo_index');
    }

    #[Route('/api/search', name: 'api_datajud_search', methods: ['POST'])]
    public function datajudSearch(Request $request, DatajudClient $datajudClient, DatajudProcessoMapper $mapper, EntityManagerInterface $em, PermissionChecker $permissionChecker, LoggerInterface $logger): JsonResponse
    {
        // Gateia pela permissão do módulo: a action dispara consulta à API externa do CNJ — sem
        // guard, qualquer logado abusaria do custo/rate-limit. Não persiste nada (B8).
        /** @var \App\Entity\Auth\User $user */
        $user = $this->getUser();
        if (!$permissionChecker->canAccessModule($user, $this->tenantContext->getCurrentTenant(), 'processos')) {
            return $this->erroDatajud('Você não tem permissão para consultar processos no CNJ.', Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $numeroProcesso = preg_replace('/\D+/', '', (string) ($data['numeroProcesso'] ?? ''));

        if (!$numeroProcesso) {
            return $this->erroDatajud('Informe o número do processo para consultar no CNJ.', 400);
        }

        // O tribunal é derivado do número (padrão CNJ); o usuário não informa a sigla.
        try {
            $apiResponse = $datajudClient->searchByNumeroProcesso($numeroProcesso);

            $hits = $apiResponse['hits']['hits'] ?? null;
            if (!is_array($hits)) {
                return $this->erroDatajud(
                    MotivoFalhaDatajud::RespostaInvalida->mensagemUsuario(),
                    MotivoFalhaDatajud::RespostaInvalida->statusHttp()
                );
            }

            if ($hits === []) {
                $sigla = $this->derivarSiglaSegura($numeroProcesso);
                $mensagem = $sigla !== null
                    ? sprintf('Consultamos a base do %s e não encontramos nenhum processo com esse número. Confira o número digitado.', $sigla)
                    : 'Não encontramos nenhum processo com esse número. Confira o número digitado.';

                return $this->erroDatajud($mensagem, 404);
            }

            $processData = $hits[0]['_source'] ?? [];

            // Criar um processo temporário para mapping
            $processo = new Processo();
            $processo = $mapper->mapFromSource($processo, $processData);

            // Formatar resposta
            $response = new JsonResponse([
                'success' => true,
                'data' => [
                    'numeroProcesso' => $processo->getNumeroProcesso(),
                    'orgaoJulgador' => $processo->getOrgaoJulgador(),
                    // Sigla derivada do número (mesma fonte usada na persistência), garantindo
                    // consistência com o que será salvo mesmo se a resposta do CNJ omitir o tribunal.
                    'siglaTribunal' => $this->tribunalResolver->resolverSigla($numeroProcesso),
                    'classeProcessual' => $processo->getClasseProcessual(),
                    'assuntoProcessual' => $processo->getAssuntoProcessual(),
                    'situacaoProcesso' => $processo->getSituacaoProcesso(),
                    'instancia' => $processo->getInstancia(),
                    'dataDistribuicao' => $processo->getDataDistribuicao()?->format('Y-m-d'),
                    'dataBaixa' => $processo->getDataBaixa()?->format('Y-m-d'),
                    'nivelSigilo' => $processo->getNivelSigilo(),
                    'nivelSigiloLabel' => $processo->getNivelSigiloLabel(),
                    'formato' => $processo->getFormato(),
                    'formatoCodigo' => $processo->getFormatoCodigo(),
                    'sistema' => $processo->getSistema(),
                    'sistemaCodigo' => $processo->getSistemaCodigo(),
                    'classeCodigo' => $processo->getClasseCodigo(),
                    'orgaoJulgadorCodigo' => $processo->getOrgaoJulgadorCodigo(),
                    'orgaoJulgadorMunicipioIbge' => $processo->getOrgaoJulgadorMunicipioIbge(),
                    'datajudId' => $processo->getDatajudId(),
                    'assuntos' => array_map(fn(AssuntoProcesso $a) => [
                        'codigo' => $a->getCodigo(),
                        'nome' => $a->getNome(),
                    ], $processo->getAssuntos()->toArray()),
                    'partes' => array_map(fn(ParteProcesso $p) => [
                        'tipo' => $p->getTipo(),
                        'nome' => $p->getNome(),
                        'documento' => $p->getDocumento(),
                        'papel' => $p->getPapel(),
                    ], $processo->getPartes()->toArray()),
                    'movimentacoes' => array_map(fn(MovimentacaoProcesso $m) => [
                        'descricao' => $m->getDescricao(),
                        'tipo' => $m->getTipo(),
                        'orgao' => $m->getOrgao(),
                        'dataMovimentacao' => $m->getDataMovimentacao()?->format('Y-m-d'),
                    ], $processo->getMovimentacoes()->toArray()),
                ]
            ]);
            $response->setEncodingOptions(JSON_UNESCAPED_UNICODE);
            return $response;
        } catch (TribunalNaoIdentificadoException $e) {
            // Número fora do padrão CNJ ou tribunal desconhecido: erro do dado de entrada.
            return $this->erroDatajud($this->mensagemNumeroInvalido($numeroProcesso), 422);
        } catch (ConsultaDatajudException $e) {
            // Falha na interação com o CNJ (rede, timeout, indisponibilidade, limite, etc.).
            // Registra a causa técnica: a mensagem ao usuário é intencionalmente amigável/vaga.
            $motivo = $e->getMotivo();
            $logger->warning('Consulta ao Datajud falhou: {motivo}', [
                'motivo' => $motivo->value,
                'exception' => $e,
            ]);

            return $this->erroDatajud(
                $motivo->mensagemUsuario($this->derivarSiglaSegura($numeroProcesso)),
                $motivo->statusHttp()
            );
        } catch (\Exception $e) {
            // Erro interno inesperado (ex.: mapeamento) — não culpa o CNJ; loga a causa real.
            $logger->error('Erro inesperado na consulta ao Datajud', ['exception' => $e]);

            return $this->erroDatajud(
                'Não conseguimos concluir a consulta agora. Tente novamente; se persistir, avise o suporte.',
                500
            );
        }
    }

    private function erroDatajud(string $mensagem, int $status): JsonResponse
    {
        $response = new JsonResponse(['error' => $mensagem], $status);
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE);

        return $response;
    }

    /**
     * Deriva a sigla do tribunal do número sem quebrar quando o número é inválido — usada só
     * para enriquecer mensagens de erro (número que já falhou não deve derrubar a resposta).
     */
    private function derivarSiglaSegura(string $numeroProcesso): ?string
    {
        try {
            return $this->tribunalResolver->resolverSigla($numeroProcesso);
        } catch (TribunalNaoIdentificadoException) {
            return null;
        }
    }

    private function mensagemNumeroInvalido(string $numeroDigitos): string
    {
        $qtd = strlen($numeroDigitos);
        if ($qtd !== 20) {
            return sprintf('O número informado tem %d dígito(s), mas o padrão do CNJ tem 20. Confira e tente de novo.', $qtd);
        }

        return 'Não reconhecemos o tribunal a partir desse número. Confira se o número está correto.';
    }

    private function fillProcessoFromRequest(Processo $processo, array $data): void
    {
        $numeroProcessoNormalizado = preg_replace('/\D+/', '', (string) ($data['numeroProcesso'] ?? ''));

        $numeroProcesso = $numeroProcessoNormalizado ?? '';
        if ($processo->getNumeroProcesso() !== $numeroProcesso) {
            $processo->setNumeroProcesso($numeroProcesso);
        }

        $orgaoJulgador = (string) ($data['orgaoJulgador'] ?? '');
        if ($processo->getOrgaoJulgador() !== $orgaoJulgador) {
            $processo->setOrgaoJulgador($orgaoJulgador);
        }

        // A sigla do tribunal é derivada do número (padrão CNJ), não mais informada no form.
        // Se o número for legado/não-CNJ, preserva a sigla atual — nunca sobrescreve com vazio.
        try {
            $siglaTribunal = $this->tribunalResolver->resolverSigla($numeroProcesso);
            if ($processo->getSiglaTribunal() !== $siglaTribunal) {
                $processo->setSiglaTribunal($siglaTribunal);
            }
        } catch (TribunalNaoIdentificadoException $e) {
            // Mantém $processo->getSiglaTribunal() como está.
        }

        $classeProcessual = (string) ($data['classeProcessual'] ?? '');
        if ($processo->getClasseProcessual() !== $classeProcessual) {
            $processo->setClasseProcessual($classeProcessual);
        }

        $assuntoProcessual = (string) ($data['assuntoProcessual'] ?? '');
        if ($processo->getAssuntoProcessual() !== $assuntoProcessual) {
            $processo->setAssuntoProcessual($assuntoProcessual);
        }

        $situacaoProcesso = (string) ($data['situacaoProcesso'] ?? 'EM_ANDAMENTO');
        if ($processo->getSituacaoProcesso() !== $situacaoProcesso) {
            $processo->setSituacaoProcesso($situacaoProcesso);
        }

        $instancia = (string) ($data['instancia'] ?? 'G1');
        if ($processo->getInstancia() !== $instancia) {
            $processo->setInstancia($instancia);
        }

        $dataDistribuicao = $this->parseDateOrNull($data['dataDistribuicao'] ?? null);
        if (!$this->isSameDate($processo->getDataDistribuicao(), $dataDistribuicao)) {
            $processo->setDataDistribuicao($dataDistribuicao);
        }

        $dataBaixa = $this->parseDateOrNull($data['dataBaixa'] ?? null);
        if (!$this->isSameDate($processo->getDataBaixa(), $dataBaixa)) {
            $processo->setDataBaixa($dataBaixa);
        }

        // Metadados do Datajud: não são editáveis pelo usuário — chegam via hidden preenchidos pela
        // busca no CNJ. Se ausentes no request, viram null (não sobrescrevem à toa em edição manual
        // porque o hidden re-renderiza o valor atual).
        $processo->setNivelSigilo($this->intOrNull($data['nivelSigilo'] ?? null));
        $processo->setFormato($this->trimOrNull($data['formato'] ?? null));
        $processo->setFormatoCodigo($this->intOrNull($data['formatoCodigo'] ?? null));
        $processo->setSistema($this->trimOrNull($data['sistema'] ?? null));
        $processo->setSistemaCodigo($this->intOrNull($data['sistemaCodigo'] ?? null));
        $processo->setClasseCodigo($this->intOrNull($data['classeCodigo'] ?? null));
        $processo->setOrgaoJulgadorCodigo($this->trimOrNull($data['orgaoJulgadorCodigo'] ?? null));
        $processo->setOrgaoJulgadorMunicipioIbge($this->intOrNull($data['orgaoJulgadorMunicipioIbge'] ?? null));
        $processo->setDatajudId($this->trimOrNull($data['datajudId'] ?? null));

        $this->syncPartesFromRequest($processo, is_array($data['partes'] ?? null) ? $data['partes'] : []);
        $this->syncMovimentacoesFromRequest($processo, is_array($data['movimentacoes'] ?? null) ? $data['movimentacoes'] : []);
        $this->syncAssuntosFromRequest($processo, is_array($data['assuntos'] ?? null) ? $data['assuntos'] : []);
    }

    private function syncAssuntosFromRequest(Processo $processo, array $assuntosData): void
    {
        $existingById = [];
        foreach ($processo->getAssuntos() as $assunto) {
            $id = $assunto->getId();
            if ($id !== null) {
                $existingById[(string) $id] = $assunto;
            }
        }

        $kept = [];

        foreach ($assuntosData as $assuntoData) {
            $nome = trim((string) ($assuntoData['nome'] ?? ''));
            if ($nome === '') {
                continue;
            }

            $id = trim((string) ($assuntoData['id'] ?? ''));
            $assunto = ($id !== '' && isset($existingById[$id])) ? $existingById[$id] : new AssuntoProcesso();

            if (!$processo->getAssuntos()->contains($assunto)) {
                $processo->addAssunto($assunto);
                $assunto->setTenant($processo->getTenant());
            }

            if ($assunto->getNome() !== $nome) {
                $assunto->setNome($nome);
            }

            $codigo = $this->intOrNull($assuntoData['codigo'] ?? null);
            if ($assunto->getCodigo() !== $codigo) {
                $assunto->setCodigo($codigo);
            }

            $kept[spl_object_id($assunto)] = true;
        }

        foreach ($processo->getAssuntos()->toArray() as $assuntoExistente) {
            if (!isset($kept[spl_object_id($assuntoExistente)])) {
                $processo->removeAssunto($assuntoExistente);
            }
        }
    }

    private function syncPartesFromRequest(Processo $processo, array $partesData): void
    {
        $existingById = [];
        foreach ($processo->getPartes() as $parte) {
            $id = $parte->getId();
            if ($id !== null) {
                $existingById[(string) $id] = $parte;
            }
        }

        $kept = [];

        foreach ($partesData as $parteData) {
            $nome = trim((string) ($parteData['nome'] ?? ''));
            if ($nome === '') {
                continue;
            }

            $id = trim((string) ($parteData['id'] ?? ''));
            $parte = ($id !== '' && isset($existingById[$id])) ? $existingById[$id] : new ParteProcesso();

            if (!$processo->getPartes()->contains($parte)) {
                $processo->addParte($parte);
                $parte->setTenant($processo->getTenant());
            }

            $tipo = (string) ($parteData['tipo'] ?? 'PARTE');
            if ($parte->getTipo() !== $tipo) {
                $parte->setTipo($tipo);
            }

            if ($parte->getNome() !== $nome) {
                $parte->setNome($nome);
            }

            $documento = ($parteData['documento'] ?? '') !== '' ? (string) $parteData['documento'] : null;
            if ($parte->getDocumento() !== $documento) {
                $parte->setDocumento($documento);
            }

            $papel = ($parteData['papel'] ?? '') !== '' ? (string) $parteData['papel'] : null;
            if ($parte->getPapel() !== $papel) {
                $parte->setPapel($papel);
            }

            $kept[spl_object_id($parte)] = true;
        }

        foreach ($processo->getPartes()->toArray() as $parteExistente) {
            if (!isset($kept[spl_object_id($parteExistente)])) {
                $processo->removeParte($parteExistente);
            }
        }
    }

    private function syncMovimentacoesFromRequest(Processo $processo, array $movimentacoesData): void
    {
        $existingById = [];
        foreach ($processo->getMovimentacoes() as $movimentacao) {
            $id = $movimentacao->getId();
            if ($id !== null) {
                $existingById[(string) $id] = $movimentacao;
            }
        }

        $kept = [];

        foreach ($movimentacoesData as $movData) {
            $descricao = trim((string) ($movData['descricao'] ?? ''));
            if ($descricao === '') {
                continue;
            }

            $id = trim((string) ($movData['id'] ?? ''));
            $movimentacao = ($id !== '' && isset($existingById[$id])) ? $existingById[$id] : new MovimentacaoProcesso();

            if (!$processo->getMovimentacoes()->contains($movimentacao)) {
                $processo->addMovimentacao($movimentacao);
                $movimentacao->setTenant($processo->getTenant());
            }

            if ($movimentacao->getDescricao() !== $descricao) {
                $movimentacao->setDescricao($descricao);
            }

            $tipo = ($movData['tipo'] ?? '') !== '' ? (string) $movData['tipo'] : null;
            if ($movimentacao->getTipo() !== $tipo) {
                $movimentacao->setTipo($tipo);
            }

            $orgao = ($movData['orgao'] ?? '') !== '' ? (string) $movData['orgao'] : null;
            if ($movimentacao->getOrgao() !== $orgao) {
                $movimentacao->setOrgao($orgao);
            }

            $dataMovimentacao = $this->parseDateOrNull($movData['dataMovimentacao'] ?? null);
            if (!$this->isSameDate($movimentacao->getDataMovimentacao(), $dataMovimentacao)) {
                $movimentacao->setDataMovimentacao($dataMovimentacao);
            }

            $kept[spl_object_id($movimentacao)] = true;
        }

        foreach ($processo->getMovimentacoes()->toArray() as $movimentacaoExistente) {
            if (!isset($kept[spl_object_id($movimentacaoExistente)])) {
                $processo->removeMovimentacao($movimentacaoExistente);
            }
        }
    }

    private function parseDateOrNull(mixed $value): ?\DateTimeInterface
    {
        $dateValue = is_string($value) ? trim($value) : '';
        if ($dateValue === '') {
            return null;
        }

        return \DateTime::createFromFormat('!Y-m-d', $dateValue) ?: null;
    }

    private function trimOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $texto = trim((string) $value);

        return $texto === '' ? null : $texto;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }

        $texto = trim((string) $value);

        return is_numeric($texto) ? (int) $texto : null;
    }

    private function isSameDate(?\DateTimeInterface $left, ?\DateTimeInterface $right): bool
    {
        if ($left === null && $right === null) {
            return true;
        }

        if ($left === null || $right === null) {
            return false;
        }

        return $left->format('Y-m-d') === $right->format('Y-m-d');
    }
}
