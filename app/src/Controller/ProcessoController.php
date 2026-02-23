<?php

namespace App\Controller;

use App\Entity\Contrato\Contrato;
use App\Entity\Processo\Processo;
use App\Entity\Processo\ParteProcesso;
use App\Entity\Processo\MovimentacaoProcesso;
use App\Repository\ProcessoRepository;
use App\Repository\ContratoRepository;
use App\Repository\ClienteRepository;
use App\Service\DatajudClient;
use App\Service\DatajudProcessoMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/processo')]
class ProcessoController extends AbstractController
{
    #[Route('/', name: 'processo_index', methods: ['GET'])]
    public function index(ProcessoRepository $repo): Response
    {
        return $this->render('processo/index.html.twig', [
            'processos' => $repo->findAll(),
        ]);
    }

    #[Route('/novo', name: 'processo_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ContratoRepository $contratoRepo, ClienteRepository $clienteRepo, EntityManagerInterface $em): Response
    {
        $contratos = $contratoRepo->findAll();
        $clientes = $clienteRepo->findAll();
        $processo = new Processo();

        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            $this->fillProcessoFromRequest($processo, $data, $contratoRepo);

            $em->persist($processo);
            $em->flush();

            return $this->redirectToRoute('processo_show', ['id' => $processo->getId()]);
        }

        return $this->render('processo/new.html.twig', [
            'contratos' => $contratos,
            'clientes' => $clientes,
            'processo' => $processo,
            'isEdit' => false,
        ]);
    }

    #[Route('/{id}/editar', name: 'processo_edit', methods: ['GET', 'POST'])]
    public function edit(Processo $processo, Request $request, ContratoRepository $contratoRepo, ClienteRepository $clienteRepo, EntityManagerInterface $em): Response
    {
        $contratos = $contratoRepo->findAll();
        $clientes = $clienteRepo->findAll();

        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            $this->fillProcessoFromRequest($processo, $data, $contratoRepo);
            $em->flush();

            return $this->redirectToRoute('processo_show', ['id' => $processo->getId()]);
        }

        return $this->render('processo/new.html.twig', [
            'contratos' => $contratos,
            'clientes' => $clientes,
            'processo' => $processo,
            'isEdit' => true,
        ]);
    }

    #[Route('/{id}', name: 'processo_show', methods: ['GET'])]
    public function show(Processo $processo): Response
    {
        $historicoTarefas = [];

        foreach ($processo->getTarefas() as $tarefa) {
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

    private function fillProcessoFromRequest(Processo $processo, array $data, ContratoRepository $contratoRepo): void
    {
        $numeroProcessoNormalizado = preg_replace('/\D+/', '', (string) ($data['numeroProcesso'] ?? ''));

        $processo->setNumeroProcesso($numeroProcessoNormalizado ?? '');
        $processo->setOrgaoJulgador((string) ($data['orgaoJulgador'] ?? ''));
        $processo->setSiglaTribunal((string) ($data['siglaTribunal'] ?? ''));
        $processo->setClasseProcessual((string) ($data['classeProcessual'] ?? ''));
        $processo->setAssuntoProcessual((string) ($data['assuntoProcessual'] ?? ''));
        $processo->setSituacaoProcesso((string) ($data['situacaoProcesso'] ?? 'EM_ANDAMENTO'));
        $processo->setInstancia((string) ($data['instancia'] ?? 'G1'));

        if (!empty($data['dataDistribuicao'])) {
            $processo->setDataDistribuicao(\DateTime::createFromFormat('Y-m-d', $data['dataDistribuicao']) ?: null);
        } else {
            $processo->setDataDistribuicao(null);
        }

        if (!empty($data['dataBaixa'])) {
            $processo->setDataBaixa(\DateTime::createFromFormat('Y-m-d', $data['dataBaixa']) ?: null);
        } else {
            $processo->setDataBaixa(null);
        }

        if (!empty($data['contrato_id'])) {
            $processo->setContrato($contratoRepo->find($data['contrato_id']));
        } else {
            $processo->setContrato(null);
        }

        foreach ($processo->getPartes()->toArray() as $parteExistente) {
            $processo->removeParte($parteExistente);
        }

        if (!empty($data['partes']) && is_array($data['partes'])) {
            foreach ($data['partes'] as $parteData) {
                if (!empty($parteData['nome'])) {
                    $parte = new ParteProcesso();
                    $parte->setTipo($parteData['tipo'] ?? 'PARTE');
                    $parte->setNome($parteData['nome']);
                    $parte->setDocumento($parteData['documento'] ?? null);
                    $parte->setPapel($parteData['papel'] ?? null);
                    $processo->addParte($parte);
                }
            }
        }

        foreach ($processo->getMovimentacoes()->toArray() as $movExistente) {
            $processo->removeMovimentacao($movExistente);
        }

        if (!empty($data['movimentacoes']) && is_array($data['movimentacoes'])) {
            foreach ($data['movimentacoes'] as $movData) {
                if (!empty($movData['descricao'])) {
                    $mov = new MovimentacaoProcesso();
                    $mov->setDescricao($movData['descricao']);
                    $mov->setTipo($movData['tipo'] ?? null);
                    $mov->setOrgao($movData['orgao'] ?? null);
                    if (!empty($movData['dataMovimentacao'])) {
                        $mov->setDataMovimentacao(\DateTime::createFromFormat('Y-m-d', $movData['dataMovimentacao']) ?: null);
                    }
                    $processo->addMovimentacao($mov);
                }
            }
        }
    }
}
