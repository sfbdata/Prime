<?php

namespace App\Controller;

use App\Entity\Contrato\Contrato;
use App\Entity\Processo\DocumentoProcesso;
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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/processo')]
class ProcessoController extends AbstractController
{
    /** @var array<string, string> */
    private const DOCUMENT_TYPES = [
        DocumentoProcesso::TIPO_PECA => 'Peça',
        DocumentoProcesso::TIPO_PROCURACAO => 'Procuração',
        DocumentoProcesso::TIPO_IDENTIFICACAO => 'Identificação',
        DocumentoProcesso::TIPO_COMPROVANTE_RESIDENCIA => 'Comprovante de residência',
        DocumentoProcesso::TIPO_GRATUIDADE_JUSTICA => 'Gratuidade de justiça',
        DocumentoProcesso::TIPO_DEMAIS => 'Demais documentos',
    ];

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
            'documentTypeOptions' => self::DOCUMENT_TYPES,
            'documentosPorTipo' => $this->groupDocumentsByType($processo),
        ]);
    }

    #[Route('/{id}/documentos/upload', name: 'processo_documento_upload', methods: ['POST'])]
    public function uploadDocumento(Processo $processo, Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('upload_documento_processo_'.$processo->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $tipo = strtoupper(trim((string) $request->request->get('tipo', '')));
        if (!array_key_exists($tipo, self::DOCUMENT_TYPES)) {
            $this->addFlash('error', 'Tipo de documento inválido.');

            return $this->redirectToRoute('processo_show', ['id' => $processo->getId()]);
        }

        $uploadedFiles = $request->files->all('documento');

        if ($uploadedFiles === [] || !is_array($uploadedFiles)) {
            $this->addFlash('error', 'Arquivo inválido para upload.');

            return $this->redirectToRoute('processo_show', ['id' => $processo->getId()]);
        }

        $uploadPath = $this->getParameter('kernel.project_dir').'/public/uploads/processo_documentos/'.$processo->getId();
        if (!is_dir($uploadPath) && !mkdir($uploadPath, 0775, true) && !is_dir($uploadPath)) {
            $this->addFlash('error', 'Não foi possível preparar o diretório de upload.');

            return $this->redirectToRoute('processo_show', ['id' => $processo->getId()]);
        }

        $uploadedCount = 0;

        foreach ($uploadedFiles as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }

            $safeName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', (string) $file->getClientOriginalName()) ?: 'documento';
            $storedFileName = sprintf('%s_%s', uniqid('doc_', true), $safeName);
            $fileSize = $file->getSize();
            $mimeType = $file->getClientMimeType();
            $originalName = (string) $file->getClientOriginalName();

            $file->move($uploadPath, $storedFileName);

            $documento = (new DocumentoProcesso())
                ->setTipo($tipo)
                ->setNomeOriginal($originalName)
                ->setCaminhoArquivo('uploads/processo_documentos/'.$processo->getId().'/'.$storedFileName)
                ->setMimeType($mimeType)
                ->setTamanho($fileSize);

            $processo->addDocumento($documento);
            $uploadedCount++;
        }

        if ($uploadedCount === 0) {
            $this->addFlash('error', 'Nenhum arquivo válido foi enviado.');

            return $this->redirectToRoute('processo_show', ['id' => $processo->getId()]);
        }

        $this->markChecklistByDocumentType($processo, $tipo);
        $entityManager->flush();

        $this->addFlash('success', sprintf('%d arquivo(s) enviado(s) para %s.', $uploadedCount, self::DOCUMENT_TYPES[$tipo]));

        return $this->redirectToRoute('processo_show', ['id' => $processo->getId()]);
    }

    #[Route('/{id}/documentos/{documentoId}/excluir', name: 'processo_documento_delete', methods: ['POST'])]
    public function deleteDocumento(Processo $processo, int $documentoId, Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('delete_documento_processo_'.$documentoId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $documento = null;
        foreach ($processo->getDocumentos() as $item) {
            if ($item->getId() === $documentoId) {
                $documento = $item;
                break;
            }
        }

        if (!$documento instanceof DocumentoProcesso) {
            $this->addFlash('error', 'Documento não encontrado para este processo.');

            return $this->redirectToRoute('processo_show', ['id' => $processo->getId()]);
        }

        $fullPath = $this->getParameter('kernel.project_dir').'/public/'.$documento->getCaminhoArquivo();
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }

        $processo->removeDocumento($documento);
        $entityManager->flush();

        $this->addFlash('success', 'Documento removido com sucesso.');

        return $this->redirectToRoute('processo_show', ['id' => $processo->getId()]);
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

        $novoContrato = !empty($data['contrato_id']) ? $contratoRepo->find($data['contrato_id']) : null;
        $contratoAtualId = $processo->getContrato()?->getId();
        $novoContratoId = $novoContrato?->getId();
        if ($contratoAtualId !== $novoContratoId) {
            $processo->setContrato($novoContrato);
        }

        $processo->setDocPecaOk($this->requestHasChecked($data, 'doc_peca_ok'));
        $processo->setDocProcuracaoOk($this->requestHasChecked($data, 'doc_procuracao_ok'));
        $processo->setDocIdentificacaoOk($this->requestHasChecked($data, 'doc_identificacao_ok'));
        $processo->setDocComprovanteResidenciaOk($this->requestHasChecked($data, 'doc_comprovante_residencia_ok'));
        $processo->setDocGratuidadeJusticaOk($this->requestHasChecked($data, 'doc_gratuidade_justica_ok'));
        $processo->setDocDemaisOk($this->requestHasChecked($data, 'doc_demais_ok'));

        $this->syncPartesFromRequest($processo, is_array($data['partes'] ?? null) ? $data['partes'] : []);
        $this->syncMovimentacoesFromRequest($processo, is_array($data['movimentacoes'] ?? null) ? $data['movimentacoes'] : []);
    }

    private function groupDocumentsByType(Processo $processo): array
    {
        $grouped = [];

        foreach (array_keys(self::DOCUMENT_TYPES) as $type) {
            $grouped[$type] = [];
        }

        foreach ($processo->getDocumentos() as $documento) {
            $grouped[$documento->getTipo()][] = $documento;
        }

        return $grouped;
    }

    private function markChecklistByDocumentType(Processo $processo, string $tipo): void
    {
        match ($tipo) {
            DocumentoProcesso::TIPO_PECA => $processo->setDocPecaOk(true),
            DocumentoProcesso::TIPO_PROCURACAO => $processo->setDocProcuracaoOk(true),
            DocumentoProcesso::TIPO_IDENTIFICACAO => $processo->setDocIdentificacaoOk(true),
            DocumentoProcesso::TIPO_COMPROVANTE_RESIDENCIA => $processo->setDocComprovanteResidenciaOk(true),
            DocumentoProcesso::TIPO_GRATUIDADE_JUSTICA => $processo->setDocGratuidadeJusticaOk(true),
            DocumentoProcesso::TIPO_DEMAIS => $processo->setDocDemaisOk(true),
            default => null,
        };
    }

    private function requestHasChecked(array $data, string $key): bool
    {
        return array_key_exists($key, $data);
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
