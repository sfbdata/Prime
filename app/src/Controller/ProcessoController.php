<?php

namespace App\Controller;

use App\Entity\Processo\Processo;
use App\Entity\Processo\ParteProcesso;
use App\Entity\Processo\MovimentacaoProcesso;
use App\Repository\ProcessoRepository;
use App\Repository\ClienteRepository;
use App\Repository\TarefaRepository;
use App\Service\DatajudClient;
use App\Service\DatajudProcessoMapper;
use Doctrine\ORM\EntityManagerInterface;
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
    #[Route('/', name: 'processo_index', methods: ['GET'])]
    public function index(Request $request, ProcessoRepository $repo): Response
    {
        $filters = [
            'numero_processo' => $request->query->get('numero_processo', ''),
            'tribunal' => $request->query->get('tribunal', ''),
            'classe' => $request->query->get('classe', ''),
            'assunto' => $request->query->get('assunto', ''),
            'situacao' => $request->query->get('situacao', ''),
        ];

        $hasFilters = array_filter($filters, fn($v) => $v !== '');

        return $this->render('processo/index.html.twig', [
            'processos' => $hasFilters ? $repo->findByFilters($filters) : $repo->findAll(),
            'filters' => $filters,
            'numerosProcesso' => $repo->findAllNumerosProcesso(),
            'tribunais' => $repo->findAllTribunais(),
            'classes' => $repo->findAllClasses(),
            'assuntos' => $repo->findAllAssuntos(),
        ]);
    }

    #[Route('/novo', name: 'processo_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ClienteRepository $clienteRepo, ProcessoRepository $processoRepo, EntityManagerInterface $em): Response
    {
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
    public function edit(Processo $processo, Request $request, ClienteRepository $clienteRepo, EntityManagerInterface $em): Response
    {
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
    public function show(Processo $processo, TarefaRepository $tarefaRepository): Response
    {
        $historicoTarefas = [];

        foreach ($tarefaRepository->findByProcesso($processo) as $tarefa) {
            $usuariosAtribuidos = [];
            $ultimaRevisao = null;

            foreach ($tarefa->getAtribuicoes() as $atribuicao) {
                $nomeUsuario = $atribuicao->getUsuario()?->getFullName();
                if ($nomeUsuario !== null && $nomeUsuario !== '') {
                    $usuariosAtribuidos[] = $nomeUsuario;
                }

                $dataEnvioRevisao = $atribuicao->getDataEnvioRevisao();
                if ($dataEnvioRevisao !== null && ($ultimaRevisao === null || $dataEnvioRevisao > $ultimaRevisao)) {
                    $ultimaRevisao = $dataEnvioRevisao;
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
                'dataUltimaRevisao' => $ultimaRevisao,
                'dataConclusaoFinal' => $tarefa->getDataConclusao(),
                'tempoTotalSegundos' => $tarefa->getTempoTotalSegundos(),
            ];
        }

        usort(
            $historicoTarefas,
            fn (array $a, array $b): int => $b['dataCriacao'] <=> $a['dataCriacao']
        );

        return $this->render('processo/show.html.twig', [
            'processo' => $processo,
            'historicoTarefas' => $historicoTarefas,
        ]);
    }

    #[Route('/{id}/deletar', name: 'processo_delete', methods: ['POST'])]
    public function delete(Request $request, Processo $processo, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_processo_'.$processo->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $em->remove($processo);
        $em->flush();

        return $this->redirectToRoute('processo_index');
    }

    #[Route('/api/search', name: 'api_datajud_search', methods: ['POST'])]
    public function datajudSearch(Request $request, DatajudClient $datajudClient, DatajudProcessoMapper $mapper, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $numeroProcesso = preg_replace('/\D+/', '', (string) ($data['numeroProcesso'] ?? ''));
        $tribunalAlias = $data['tribunalAlias'] ?? '';

        if (!$numeroProcesso || !$tribunalAlias) {
            $response = new JsonResponse(['error' => 'numeroProcesso e tribunalAlias são obrigatórios'], 400);
            $response->setEncodingOptions(JSON_UNESCAPED_UNICODE);
            return $response;
        }

        try {
            $apiResponse = $datajudClient->searchByNumeroProcesso($tribunalAlias, $numeroProcesso);

            // Verificar se a resposta contém erros da API
            if (isset($apiResponse['error'])) {
                $response = new JsonResponse([
                    'error' => 'Erro ao consultar o CNJ/DataJud.'
                ], 400);
                $response->setEncodingOptions(JSON_UNESCAPED_UNICODE);
                return $response;
            }

            if (!isset($apiResponse['hits'])) {
                $response = new JsonResponse([
                    'error' => 'Resposta inesperada da API CNJ/DataJud.'
                ], 400);
                $response->setEncodingOptions(JSON_UNESCAPED_UNICODE);
                return $response;
            }

            if (!isset($apiResponse['hits']['hits']) || empty($apiResponse['hits']['hits'])) {
                $response = new JsonResponse([
                    'error' => 'Nenhum processo encontrado.'
                ], 404);
                $response->setEncodingOptions(JSON_UNESCAPED_UNICODE);
                return $response;
            }

            $processData = $apiResponse['hits']['hits'][0]['_source'];

            // Criar um processo temporário para mapping
            $processo = new Processo();
            $processo = $mapper->mapFromSource($processo, $processData);

            // Formatar resposta
            $response = new JsonResponse([
                'success' => true,
                'data' => [
                    'numeroProcesso' => $processo->getNumeroProcesso(),
                    'orgaoJulgador' => $processo->getOrgaoJulgador(),
                    'siglaTribunal' => $processo->getSiglaTribunal(),
                    'classeProcessual' => $processo->getClasseProcessual(),
                    'assuntoProcessual' => $processo->getAssuntoProcessual(),
                    'situacaoProcesso' => $processo->getSituacaoProcesso(),
                    'instancia' => $processo->getInstancia(),
                    'dataDistribuicao' => $processo->getDataDistribuicao()?->format('Y-m-d'),
                    'dataBaixa' => $processo->getDataBaixa()?->format('Y-m-d'),
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
        } catch (\Exception $e) {
            $response = new JsonResponse(['error' => 'Falha ao consultar processo no CNJ/DataJud.'], 500);
            $response->setEncodingOptions(JSON_UNESCAPED_UNICODE);
            return $response;
        }
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

        $siglaTribunal = (string) ($data['siglaTribunal'] ?? '');
        if ($processo->getSiglaTribunal() !== $siglaTribunal) {
            $processo->setSiglaTribunal($siglaTribunal);
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

        $this->syncPartesFromRequest($processo, is_array($data['partes'] ?? null) ? $data['partes'] : []);
        $this->syncMovimentacoesFromRequest($processo, is_array($data['movimentacoes'] ?? null) ? $data['movimentacoes'] : []);
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
