<?php

namespace App\Controller;

use App\Entity\Contrato;
use App\Entity\Processo;
use App\Entity\ParteProcesso;
use App\Entity\MovimentacaoProcesso;
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

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            $processo = new Processo();
            $processo->setNumeroProcesso($data['numeroProcesso'] ?? '');
            $processo->setOrgaoJulgador($data['orgaoJulgador'] ?? '');
            $processo->setSiglaTribunal($data['siglaTribunal'] ?? '');
            $processo->setClasseProcessual($data['classeProcessual'] ?? '');
            $processo->setAssuntoProcessual($data['assuntoProcessual'] ?? '');
            $processo->setSituacaoProcesso($data['situacaoProcesso'] ?? 'EM_ANDAMENTO');
            $processo->setInstancia($data['instancia'] ?? 'G1');

            if (!empty($data['dataDistribuicao'])) {
                $processo->setDataDistribuicao(\DateTime::createFromFormat('Y-m-d', $data['dataDistribuicao']));
            }

            if (!empty($data['dataBaixa'])) {
                $processo->setDataBaixa(\DateTime::createFromFormat('Y-m-d', $data['dataBaixa']));
            }

            // Associar contrato
            if (!empty($data['contrato_id'])) {
                $contrato = $contratoRepo->find($data['contrato_id']);
                if ($contrato) {
                    $processo->setContrato($contrato);
                }
            }

            // Adicionar partes
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

            // Adicionar movimentações
            if (!empty($data['movimentacoes']) && is_array($data['movimentacoes'])) {
                foreach ($data['movimentacoes'] as $movData) {
                    if (!empty($movData['descricao'])) {
                        $mov = new MovimentacaoProcesso();
                        $mov->setDescricao($movData['descricao']);
                        $mov->setTipo($movData['tipo'] ?? null);
                        $mov->setOrgao($movData['orgao'] ?? null);
                        if (!empty($movData['dataMovimentacao'])) {
                            $mov->setDataMovimentacao(\DateTime::createFromFormat('Y-m-d', $movData['dataMovimentacao']));
                        }
                        $processo->addMovimentacao($mov);
                    }
                }
            }

            $em->persist($processo);
            $em->flush();

            return $this->redirectToRoute('processo_show', ['id' => $processo->getId()]);
        }

        return $this->render('processo/new.html.twig', [
            'contratos' => $contratos,
            'clientes' => $clientes,
        ]);
    }

    #[Route('/{id}', name: 'processo_show', methods: ['GET'])]
    public function show(Processo $processo): Response
    {
        return $this->render('processo/show.html.twig', [
            'processo' => $processo,
        ]);
    }

    #[Route('/api/search', name: 'api_datajud_search', methods: ['POST'])]
    public function datajudSearch(Request $request, DatajudClient $datajudClient, DatajudProcessoMapper $mapper, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $numeroProcesso = $data['numeroProcesso'] ?? '';
        $tribunalAlias = $data['tribunalAlias'] ?? '';

        if (!$numeroProcesso || !$tribunalAlias) {
            $response = new JsonResponse(['error' => 'numeroProcesso e tribunalAlias são obrigatórios'], 400);
            $response->setEncodingOptions(JSON_UNESCAPED_UNICODE);
            return $response;
        }

        try {
            $apiResponse = $datajudClient->searchByNumeroProcesso($tribunalAlias, $numeroProcesso);

            // Log completo da resposta
            error_log('DataJud Response: ' . json_encode($apiResponse));

            // Verificar se a resposta contém erros da API
            if (isset($apiResponse['error'])) {
                $response = new JsonResponse([
                    'error' => 'Erro da API DataJud: ' . ($apiResponse['error']['reason'] ?? $apiResponse['error']),
                    'debug' => [
                        'numeroProcesso' => $numeroProcesso,
                        'tribunalAlias' => $tribunalAlias,
                        'apiError' => $apiResponse['error'],
                        'fullResponse' => $apiResponse
                    ]
                ], 400);
                $response->setEncodingOptions(JSON_UNESCAPED_UNICODE);
                return $response;
            }

            // Debug: retornar resposta completa se houver erro
            if (!isset($apiResponse['hits'])) {
                $response = new JsonResponse([
                    'error' => 'Resposta inesperada da API DataJud - estrutura inválida',
                    'debug' => [
                        'numeroProcesso' => $numeroProcesso,
                        'tribunalAlias' => $tribunalAlias,
                        'fullResponse' => $apiResponse,
                        'keys' => array_keys($apiResponse)
                    ]
                ], 400);
                $response->setEncodingOptions(JSON_UNESCAPED_UNICODE);
                return $response;
            }

            // Se há results na resposta
            if (!isset($apiResponse['hits']['hits']) || empty($apiResponse['hits']['hits'])) {
                $response = new JsonResponse([
                    'error' => 'Nenhum processo encontrado',
                    'debug' => [
                        'numeroProcesso' => $numeroProcesso,
                        'tribunalAlias' => $tribunalAlias,
                        'hitsCount' => isset($apiResponse['hits']['total']) ? $apiResponse['hits']['total'] : 0,
                        'fullResponse' => $apiResponse
                    ]
                ], 404);
                $response->setEncodingOptions(JSON_UNESCAPED_UNICODE);
                return $response;
            }

            $processData = $apiResponse['hits']['hits'][0]['_source'];

            // DEBUG: Log completo dos dados da API
            error_log('DataJud Response received');

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
            $response = new JsonResponse(['error' => $e->getMessage()], 500);
            $response->setEncodingOptions(JSON_UNESCAPED_UNICODE);
            return $response;
        }
    }
}
