<?php

namespace App\Controller;

use App\Controller\Trait\ResourceAccessTrait;
use App\Entity\Auth\User;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\ParteContraria;
use App\Entity\Pasta\PastaDocumento;
use App\Processo\Entity\Processo;
use App\Processo\Entity\ParteProcesso;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Cliente\Repository\ClienteRepository;
use App\Cliente\Repository\ClientePFRepository;
use App\Cliente\Repository\ClientePJRepository;
use App\Cliente\Entity\ClienteDocumento;
use App\Repository\ClienteDocumentoRepository;
use App\Repository\PastaDocumentoRepository;
use App\Repository\PastaRepository;
use App\Processo\Repository\ProcessoRepository;
use App\Entity\Permission\AccessRequest;
use App\Repository\UserRepository;
use App\Service\PermissionChecker;
use App\Pasta\Service\PastaTimelineAssembler;
use App\Pasta\UseCase\EnviarMensagemPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Shared\Service\ArquivoStorageService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * PastaController - Gerencia pastas de clientes.
 *
 * Estrutura de rotas:
 * - GET  /pasta              → Lista todas as pastas
 * - GET  /pasta/nova         → Formulário de criação
 * - POST /pasta/nova         → Cria nova pasta
 * - GET  /pasta/{id}                         → Exibe detalhes da pasta
 * - GET  /pasta/{id}/editar                  → Formulário de edição
 * - POST /pasta/{id}/editar                  → Atualiza pasta
 * - POST /pasta/{id}/deletar                 → Remove pasta
 * - POST /pasta/{id}/documento/upload        → Faz upload de documento
 * - GET  /pasta/documento/{id}/download      → Faz download de documento
 * - POST /pasta/documento/{id}/deletar       → Remove documento
 */
#[Route('/pasta')]
class PastaController extends AbstractController
{
    use ResourceAccessTrait;
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaRepository $pastaRepository,
        private readonly PastaDocumentoRepository $pastaDocumentoRepository,
        private readonly ClienteDocumentoRepository $clienteDocumentoRepository,
        private readonly ProcessoRepository $processoRepository,
        private readonly ClienteRepository $clienteRepository,
        private readonly ClientePFRepository $clientePFRepository,
        private readonly ClientePJRepository $clientePJRepository,
        private readonly UserRepository $userRepository,
        private readonly ValidatorInterface $validator,
        private readonly string $uploadsDir,
        private readonly ArquivoStorageService $storage,
        private readonly PermissionChecker $permissionChecker,
        private readonly PastaTimelineAssembler $timelineAssembler,
        private readonly EnviarMensagemPastaUseCase $enviarMensagemUseCase,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {}

    #[Route('', name: 'pasta_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('expediente_index');
    }

    #[Route('/nova', name: 'pasta_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        if (!$this->permissionChecker->canAccessModule($currentUser, 'pastas')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de pastas.');
            return $this->redirectToRoute('expediente_index');
        }

        $pasta = new Pasta();

        if ($request->isMethod('POST')) {
            $errors = $this->fillPastaFromRequest($pasta, $request->request->all());

            if ($errors === []) {
                $violations = $this->validator->validate($pasta);

                if (count($violations) > 0) {
                    foreach ($violations as $violation) {
                        $this->addFlash('error', $violation->getMessage());
                    }
                } else {
                    $pasta->setCriadoPor($this->getUser());
                    $this->em->persist($pasta);
                    $this->em->flush();
                    $this->addFlash('success', 'Pasta criada com sucesso.');

                    return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId()]);
                }
            } else {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }
            }
        }

        return $this->render('pasta/new.html.twig', [
            'pasta' => $pasta,
            'processos' => $this->processoRepository->findAll(),
            'clientes' => $this->clienteRepository->findAll(),
            'usuarios' => $this->userRepository->findBy(['isActive' => true], ['fullName' => 'ASC']),
            'isEdit' => false,
        ]);
    }

    #[Route('/{id}', name: 'pasta_show', methods: ['GET'])]
    public function show(Pasta $pasta): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        $tenant    = $currentUser->getTenant();
        $tenantId  = $tenant?->getId() ?? 0;
        $processoId = $pasta->getProcesso()?->getId();

        $timelineItems = $this->timelineAssembler->montar($pasta, $tenant, $tenantId, $processoId);

        return $this->render('pasta/show.html.twig', [
            'pasta'                       => $pasta,
            'documentTypeOptions'         => self::DOCUMENT_TYPES,
            'documentosPorTipo'           => $this->groupDocumentsByType($pasta),
            'documentTypeOptionsCliente'  => self::DOCUMENT_TYPES_CLIENTE,
            'clientesComDocumentos'       => $this->groupClienteDocumentosByCliente($pasta),
            'timelineItems'               => $timelineItems,
        ]);
    }

    #[Route('/{id}/parte-contraria/nova', name: 'pasta_parte_contraria_nova', methods: ['POST'])]
    public function novaParteContraria(Pasta $pasta, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('pasta_parte_contraria_nova_' . $pasta->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de segurança inválido.');
            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
        }

        $nome = trim((string) $request->request->get('nome', ''));

        if ($nome === '') {
            $this->addFlash('error', 'O nome da parte contrária é obrigatório.');
            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
        }

        $parte = new ParteContraria();
        $parte->setNome($nome);
        $pasta->addParteContraria($parte);

        $this->em->persist($parte);
        $this->em->flush();

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'sucesso' => true,
                'parte' => [
                    'id' => $parte->getId(),
                    'nome' => $parte->getNome(),
                    'csrfToken' => $this->csrfTokenManager->getToken('pasta_parte_contraria_desvincular_' . $pasta->getId() . '_' . $parte->getId())->getValue(),
                ],
            ]);
        }

        $this->addFlash('success', 'Parte contrária adicionada com sucesso.');

        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
    }

    #[Route('/{id}/cliente/novo', name: 'pasta_cliente_novo', methods: ['POST'])]
    public function novoCliente(Pasta $pasta, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        $isXhr = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('pasta_cliente_novo_' . $pasta->getId(), (string) $request->request->get('_token'))) {
            if ($isXhr) {
                return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Token de segurança inválido.');
            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
        }

        $tipo = $request->request->get('tipo');
        $dados = $request->request->all();

        if ($tipo === 'pf') {
            $cliente = new ClientePF();
            $nomeCompleto = trim((string) ($dados['nomeCompleto'] ?? ''));
            $cpf          = trim((string) ($dados['cpf'] ?? ''));
            $rg           = trim((string) ($dados['rg'] ?? ''));
            $rgOrgao      = trim((string) ($dados['rgOrgaoExpedidor'] ?? ''));

            if ($nomeCompleto === '' || $cpf === '' || $rg === '' || $rgOrgao === '') {
                if ($isXhr) {
                    return $this->json(['erro' => 'Nome completo, CPF, RG e órgão expedidor são obrigatórios.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $this->addFlash('error', 'Nome completo, CPF, RG e órgão expedidor são obrigatórios.');
                return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
            }

            $cliente->setNomeCompleto($nomeCompleto);
            $cliente->setCpf($cpf);
            $cliente->setRg($rg);
            $cliente->setRgOrgaoExpedidor($rgOrgao);

            $rgData = trim((string) ($dados['rgDataEmissao'] ?? ''));
            if ($rgData !== '') {
                $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $rgData);
                if ($dt !== false) {
                    $cliente->setRgDataEmissao($dt);
                }
            }

            $nascimento = trim((string) ($dados['dataNascimento'] ?? ''));
            if ($nascimento !== '') {
                $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $nascimento);
                if ($dt !== false) {
                    $cliente->setDataNascimento($dt);
                }
            }

            $estadoCivil = trim((string) ($dados['estadoCivil'] ?? ''));
            if ($estadoCivil !== '') {
                $cliente->setEstadoCivil($estadoCivil);
            }

            $profissao = trim((string) ($dados['profissao'] ?? ''));
            if ($profissao !== '') {
                $cliente->setProfissao($profissao);
            }
        } elseif ($tipo === 'pj') {
            $cliente = new ClientePJ();
            $razaoSocial      = trim((string) ($dados['razaoSocial'] ?? ''));
            $cnpj             = trim((string) ($dados['cnpj'] ?? ''));
            $enderecSede      = trim((string) ($dados['enderecSede'] ?? ''));
            $representante    = trim((string) ($dados['representanteLegal'] ?? ''));
            $representanteCpf = trim((string) ($dados['representanteCpf'] ?? ''));
            $representanteRg  = trim((string) ($dados['representanteRg'] ?? ''));
            $representanteCargo = trim((string) ($dados['representanteCargo'] ?? ''));

            if ($razaoSocial === '' || $cnpj === '' || $enderecSede === '' || $representante === '' || $representanteCpf === '' || $representanteRg === '' || $representanteCargo === '') {
                if ($isXhr) {
                    return $this->json(['erro' => 'Razão social, CNPJ, endereço sede e dados do representante legal são obrigatórios.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $this->addFlash('error', 'Razão social, CNPJ, endereço sede e dados do representante legal são obrigatórios.');
                return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
            }

            $cliente->setRazaoSocial($razaoSocial);
            $cliente->setCnpj($cnpj);
            $cliente->setEnderecSede($enderecSede);
            $cliente->setRepresentanteLegal($representante);
            $cliente->setRepresentanteCpf($representanteCpf);
            $cliente->setRepresentanteRg($representanteRg);
            $cliente->setRepresentanteCargo($representanteCargo);

            $nomeFantasia = trim((string) ($dados['nomeFantasia'] ?? ''));
            if ($nomeFantasia !== '') {
                $cliente->setNomeFantasia($nomeFantasia);
            }

            $inscricaoEstadual = trim((string) ($dados['inscricaoEstadual'] ?? ''));
            if ($inscricaoEstadual !== '') {
                $cliente->setInscricaoEstadual($inscricaoEstadual);
            }

            $inscricaoMunicipal = trim((string) ($dados['inscricaoMunicipal'] ?? ''));
            if ($inscricaoMunicipal !== '') {
                $cliente->setInscricaoMunicipal($inscricaoMunicipal);
            }
        } else {
            if ($isXhr) {
                return $this->json(['erro' => 'Tipo de cliente inválido.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Tipo de cliente inválido.');
            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
        }

        // Campos comuns
        $email = trim((string) ($dados['email'] ?? ''));
        if ($email === '') {
            if ($isXhr) {
                return $this->json(['erro' => 'O e-mail é obrigatório.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('error', 'O e-mail é obrigatório.');
            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
        }
        $cliente->setEmail($email);

        $cep = trim((string) ($dados['cep'] ?? ''));
        $endereco = trim((string) ($dados['endereco'] ?? ''));
        $cidade = trim((string) ($dados['cidade'] ?? ''));
        $estado = trim((string) ($dados['estado'] ?? ''));

        if ($cep === '' || $endereco === '' || $cidade === '' || $estado === '') {
            if ($isXhr) {
                return $this->json(['erro' => 'CEP, endereço, cidade e estado são obrigatórios.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('error', 'CEP, endereço, cidade e estado são obrigatórios.');
            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
        }
        $cliente->setCep($cep);
        $cliente->setEndereco($endereco);
        $cliente->setCidade($cidade);
        $cliente->setEstado(substr($estado, 0, 2));

        $complemento = trim((string) ($dados['complemento'] ?? ''));
        if ($complemento !== '') {
            $cliente->setComplemento($complemento);
        }

        $celular = trim((string) ($dados['telefoneCelular'] ?? ''));
        if ($celular !== '') {
            $cliente->setTelefoneCelular($celular);
        }

        $fixo = trim((string) ($dados['telefoneFixo'] ?? ''));
        if ($fixo !== '') {
            $cliente->setTelefoneFixo($fixo);
        }

        if ($cliente instanceof ClientePF) {
            $cpfExistente = $this->clientePFRepository->findOneBy(['cpf' => $cliente->getCpf()]);
            if ($cpfExistente !== null) {
                $msg = sprintf('Já existe um cliente cadastrado com o CPF %s.', $cliente->getCpf());
                if ($isXhr) {
                    return $this->json(['erro' => $msg], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $this->addFlash('error', $msg);
                return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
            }
        } elseif ($cliente instanceof ClientePJ) {
            $cnpjExistente = $this->clientePJRepository->findOneBy(['cnpj' => $cliente->getCnpj()]);
            if ($cnpjExistente !== null) {
                $msg = sprintf('Já existe um cliente cadastrado com o CNPJ %s.', $cliente->getCnpj());
                if ($isXhr) {
                    return $this->json(['erro' => $msg], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $this->addFlash('error', $msg);
                return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
            }
        }

        $cliente->setCriadoPor($currentUser);

        $this->em->persist($cliente);
        $pasta->addCliente($cliente);
        $this->em->flush();

        if ($isXhr) {
            return $this->json([
                'sucesso' => true,
                'cliente' => [
                    'id' => $cliente->getId(),
                    'nome' => $cliente instanceof ClientePF ? $cliente->getNomeCompleto() : $cliente->getRazaoSocial(),
                    'documento' => $cliente instanceof ClientePF ? $cliente->getCpf() : $cliente->getCnpj(),
                    'tipo' => $cliente instanceof ClientePF ? 'PF' : 'PJ',
                    'csrfToken' => $this->csrfTokenManager->getToken('pasta_cliente_desvincular_' . $pasta->getId() . '_' . $cliente->getId())->getValue(),
                ],
            ]);
        }

        $this->addFlash('success', 'Cliente cadastrado e vinculado à pasta com sucesso.');

        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
    }

    #[Route('/{id}/cliente/{cliente}/desvincular', name: 'pasta_cliente_desvincular', methods: ['POST'])]
    public function desvincularCliente(Pasta $pasta, Cliente $cliente, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('pasta_cliente_desvincular_' . $pasta->getId() . '_' . $cliente->getId(), (string) $request->request->get('_token'))) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Token de segurança inválido.');
            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
        }

        if (!$pasta->getClientes()->contains($cliente)) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['erro' => 'Cliente não vinculado a esta pasta.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('warning', 'Cliente não vinculado a esta pasta.');
            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
        }

        $pasta->removeCliente($cliente);
        $this->em->flush();

        if ($request->isXmlHttpRequest()) {
            return $this->json(['sucesso' => true, 'clienteId' => $cliente->getId()]);
        }

        $this->addFlash('success', 'Cliente desvinculado da pasta com sucesso.');
        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
    }

    #[Route('/{id}/parte-contraria/{parteContraria}/desvincular', name: 'pasta_parte_contraria_desvincular', methods: ['POST'])]
    public function desvincularParteContraria(Pasta $pasta, ParteContraria $parteContraria, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('pasta_parte_contraria_desvincular_' . $pasta->getId() . '_' . $parteContraria->getId(), (string) $request->request->get('_token'))) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Token de segurança inválido.');
            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
        }

        if ($parteContraria->getPasta()?->getId() !== $pasta->getId()) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['erro' => 'Parte contrária não pertence a esta pasta.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('warning', 'Parte contrária não pertence a esta pasta.');
            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
        }

        $pasta->removeParteContraria($parteContraria);
        $this->em->flush();

        if ($request->isXmlHttpRequest()) {
            return $this->json(['sucesso' => true, 'parteContrariaId' => $parteContraria->getId()]);
        }

        $this->addFlash('success', 'Parte contrária desvinculada da pasta com sucesso.');
        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'partes']);
    }

    #[Route('/{id}/clientes/buscar', name: 'pasta_clientes_buscar', methods: ['GET'])]
    public function buscarClientes(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($this->denyResourceAccessUnlessGranted($this->permissionChecker, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $termo = trim((string) $request->query->get('q', ''));
        if (mb_strlen($termo) < 2) {
            return $this->json([]);
        }

        $clientesVinculados = $pasta->getClientes()->map(fn($c) => $c->getId())->toArray();

        $todos = $this->clienteRepository->findAll();
        $resultado = [];

        foreach ($todos as $cliente) {
            if (in_array($cliente->getId(), $clientesVinculados, true)) {
                continue;
            }

            $nome = $cliente instanceof ClientePF
                ? $cliente->getNomeCompleto()
                : $cliente->getRazaoSocial();

            $documento = $cliente instanceof ClientePF
                ? ($cliente->getCpf() ?? '')
                : ($cliente->getCnpj() ?? '');

            if (str_contains(mb_strtolower($nome), mb_strtolower($termo))
                || str_contains($documento, $termo)) {
                $resultado[] = [
                    'id'        => $cliente->getId(),
                    'nome'      => $nome,
                    'documento' => $documento,
                    'tipo'      => $cliente instanceof ClientePF ? 'PF' : 'PJ',
                ];
            }

            if (count($resultado) >= 10) {
                break;
            }
        }

        return $this->json($resultado);
    }

    #[Route('/{id}/cliente/vincular', name: 'pasta_cliente_vincular', methods: ['POST'])]
    public function vincularCliente(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($this->denyResourceAccessUnlessGranted($this->permissionChecker, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_cliente_vincular_' . $pastaId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $clienteId = (int) $request->request->get('cliente_id', 0);
        $cliente = $this->clienteRepository->find($clienteId);

        if (!$cliente) {
            return $this->json(['erro' => 'Cliente não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if ($pasta->getClientes()->contains($cliente)) {
            return $this->json(['erro' => 'Este cliente já está vinculado à pasta.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $pasta->addCliente($cliente);
        $this->em->flush();

        return $this->json([
            'sucesso' => true,
            'cliente' => [
                'id' => $cliente->getId(),
                'nome' => $cliente instanceof ClientePF ? $cliente->getNomeCompleto() : $cliente->getRazaoSocial(),
                'documento' => $cliente instanceof ClientePF ? $cliente->getCpf() : $cliente->getCnpj(),
                'tipo' => $cliente instanceof ClientePF ? 'PF' : 'PJ',
                'csrfToken' => $this->csrfTokenManager->getToken('pasta_cliente_desvincular_' . $pastaId . '_' . $cliente->getId())->getValue(),
            ],
        ]);
    }

    #[Route('/{id}/mensagem', name: 'pasta_enviar_mensagem', methods: ['POST'])]
    public function enviarMensagem(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $this->json(['erro' => 'Sem permissão para acessar esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_mensagem_' . $pasta->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $conteudo = trim((string) $request->request->get('conteudo', ''));

        if ($conteudo === '') {
            return $this->json(['erro' => 'A mensagem não pode ser vazia.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $mensagem = $this->enviarMensagemUseCase->executar($pasta, $currentUser, $conteudo);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'id'         => $mensagem->getId(),
            'conteudo'   => $mensagem->getConteudo(),
            'autorNome'  => $currentUser->getFullName(),
            'criadaEm'   => $mensagem->getCriadaEm()->format('d/m/Y H:i'),
            'criadaEmTs' => $mensagem->getCriadaEm()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}/processo/vincular', name: 'pasta_vincular_processo', methods: ['POST'])]
    public function vincularProcesso(Pasta $pasta, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        $isXhr = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('vincular_processo_' . $pastaId, (string) $request->request->get('_token'))) {
            if ($isXhr) {
                return $this->json(['erro' => 'Token CSRF inválido.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('pasta_show', ['id' => $pastaId]);
        }

        $data = $request->request->all();
        $processoId     = (int) ($data['processo_id'] ?? 0);
        $numeroProcesso = trim((string) ($data['numeroProcesso'] ?? ''));

        if ($processoId > 0) {
            $processo = $this->processoRepository->find($processoId);
            if (!$processo instanceof Processo) {
                if ($isXhr) {
                    return $this->json(['erro' => 'Processo não encontrado.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $this->addFlash('danger', 'Processo não encontrado.');
                return $this->redirectToRoute('pasta_show', ['id' => $pastaId]);
            }
            $pasta->setProcesso($processo);
        } elseif ($numeroProcesso !== '') {
            $numeroNormalizado = preg_replace('/\D+/', '', $numeroProcesso);
            $existente = $this->processoRepository->findByNumeroProcesso($numeroNormalizado ?? '');
            if ($existente !== null) {
                $pasta->setProcesso($existente);
            } else {
                $processo = new Processo();
                $this->fillProcessoFromData($processo, $data);
                $processo->setCriadoPor($this->getUser());
                $this->em->persist($processo);
                $pasta->setProcesso($processo);
            }
        } else {
            if ($isXhr) {
                return $this->json(['erro' => 'Informe o número do processo.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('warning', 'Informe o número do processo.');
            return $this->redirectToRoute('pasta_show', ['id' => $pastaId]);
        }

        $this->em->flush();

        if ($isXhr) {
            $processo = $pasta->getProcesso();
            return $this->json([
                'sucesso' => true,
                'processo' => [
                    'id' => $processo->getId(),
                    'numeroProcesso' => $processo->getNumeroProcesso(),
                    'classeProcessual' => $processo->getClasseProcessual(),
                    'assuntoProcessual' => $processo->getAssuntoProcessual(),
                    'orgaoJulgador' => $processo->getOrgaoJulgador(),
                    'siglaTribunal' => $processo->getSiglaTribunal(),
                    'instancia' => $processo->getInstancia(),
                    'situacaoProcesso' => $processo->getSituacaoProcesso(),
                    'dataDistribuicao' => $processo->getDataDistribuicao()?->format('d/m/Y'),
                    'partes' => array_map(fn($parte) => [
                        'nome' => $parte->getNome(),
                        'tipo' => $parte->getTipo(),
                        'papel' => $parte->getPapel(),
                        'documento' => $parte->getDocumento(),
                    ], $processo->getPartes()->toArray()),
                ],
            ]);
        }

        $this->addFlash('success', 'Processo vinculado com sucesso.');
        return $this->redirectToRoute('pasta_show', ['id' => $pastaId]);
    }

    #[Route('/{id}/editar', name: 'pasta_edit', methods: ['GET', 'POST'])]
    public function edit(Pasta $pasta, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        if ($request->isMethod('POST')) {
            $errors = $this->fillPastaFromRequest($pasta, $request->request->all());

            if ($errors === []) {
                $violations = $this->validator->validate($pasta);

                if (count($violations) > 0) {
                    foreach ($violations as $violation) {
                        $this->addFlash('error', $violation->getMessage());
                    }
                } else {
                    $this->em->flush();
                    $this->addFlash('success', 'Pasta atualizada com sucesso.');

                    return $this->redirectToRoute('expediente_index');
                }
            } else {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }
            }
        }

        return $this->render('pasta/new.html.twig', [
            'pasta' => $pasta,
            'processos' => $this->processoRepository->findAll(),
            'clientes' => $this->clienteRepository->findAll(),
            'usuarios' => $this->userRepository->findBy(['isActive' => true], ['fullName' => 'ASC']),
            'isEdit' => true,
        ]);
    }

    #[Route('/{id}/deletar', name: 'pasta_delete', methods: ['POST'])]
    public function delete(Pasta $pasta, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_DELETE, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('delete_pasta_'.$pasta->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        foreach ($pasta->getDocumentos() as $doc) {
            $this->storage->excluir($this->storage->caminho($this->uploadsDir, $doc->getCaminhoArquivo()));
        }

        $this->em->remove($pasta);
        $this->em->flush();

        $this->addFlash('success', 'Pasta removida com sucesso.');

        return $this->redirectToRoute('expediente_index');
    }

    // -------------------------------------------------------------------------
    // Documentos
    // -------------------------------------------------------------------------

    /** @var array<string, string> */
    private const DOCUMENT_TYPES = [
        PastaDocumento::CATEGORIA_PECA                  => 'Peça',
        PastaDocumento::CATEGORIA_PROCURACAO            => 'Procuração',
        PastaDocumento::CATEGORIA_IDENTIFICACAO         => 'Identificação',
        PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA => 'Comprovante de residência',
        PastaDocumento::CATEGORIA_GRATUIDADE_JUSTICA    => 'Gratuidade de justiça',
        PastaDocumento::CATEGORIA_DEMAIS                => 'Demais documentos',
    ];

    private const DOCUMENT_TYPES_CLIENTE = [
        ClienteDocumento::CATEGORIA_IDENTIFICACAO          => 'Identificação',
        ClienteDocumento::CATEGORIA_PROCURACAO             => 'Procuração',
        ClienteDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA => 'Comprovante de residência',
        ClienteDocumento::CATEGORIA_CONTRATO               => 'Contrato',
        ClienteDocumento::CATEGORIA_DEMAIS                 => 'Demais documentos',
    ];

    private const MIME_LIMITS = [
        // Imagens
        'image/png'                          => 3 * 1024 * 1024,
        'image/jpeg'                         => 3 * 1024 * 1024,
        // Documentos
        'application/pdf'                    => 10 * 1024 * 1024,
        // KML — mimetypes alternativos que diferentes SOs podem detectar
        'application/vnd.google-earth.kml+xml' => 5 * 1024 * 1024,
        'application/xml'                    => 5 * 1024 * 1024,
        'text/xml'                           => 5 * 1024 * 1024,
        // Planilhas
        'application/vnd.ms-excel'                                          => 10 * 1024 * 1024, // .xls
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 10 * 1024 * 1024, // .xlsx
        // Áudio
        'audio/x-opus+ogg'                   => 10 * 1024 * 1024,
        'audio/vorbis'                        => 10 * 1024 * 1024,
        'audio/opus'                          => 10 * 1024 * 1024,
        'audio/mpeg'                          => 10 * 1024 * 1024,
        'audio/ogg'                           => 10 * 1024 * 1024,
        'audio/mp3'                           => 10 * 1024 * 1024,
        'audio/wav'                           => 50 * 1024 * 1024,
        'audio/x-wav'                         => 50 * 1024 * 1024,
        'audio/mp4'                           => 10 * 1024 * 1024,
        // Vídeo
        'video/x-ms-wmv'                      => 50 * 1024 * 1024,
        'video/mpeg'                          => 50 * 1024 * 1024,
        'video/ogg'                           => 50 * 1024 * 1024,
        'video/quicktime'                     => 50 * 1024 * 1024,
        'video/mp4'                           => 50 * 1024 * 1024,
    ];

    #[Route('/{id}/documento/upload', name: 'pasta_documento_upload', methods: ['POST'])]
    public function uploadDocumento(Pasta $pasta, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        if (!$this->permissionChecker->canAccessResource($currentUser, 'pasta', (int) $pasta->getId(), 'edit')) {
            throw $this->createAccessDeniedException('Você não tem permissão para enviar documentos nesta pasta.');
        }

        if (!$this->isCsrfTokenValid('upload_documento_pasta_'.$pasta->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        /** @var \Symfony\Component\HttpFoundation\File\UploadedFile[] $arquivos */
        $arquivos = $request->files->get('arquivos', []);

        if (empty($arquivos)) {
            $this->addFlash('error', 'Nenhum arquivo enviado.');

            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId()]);
        }

        $categorias  = $request->request->all('categorias');
        $descricoes  = $request->request->all('descricoes');
        $numeros     = $request->request->all('numeros');

        $erros    = [];
        $salvos   = 0;

        foreach ($arquivos as $i => $file) {
            if ($file === null) {
                continue;
            }

            $mimeType = $file->getMimeType() ?? '';

            if (!array_key_exists($mimeType, self::MIME_LIMITS)) {
                $erros[] = sprintf('"%s": tipo não permitido (%s).', $file->getClientOriginalName(), $mimeType);
                continue;
            }

            $tamanho = $file->getSize();
            $limite  = self::MIME_LIMITS[$mimeType];

            if ($tamanho > $limite) {
                $erros[] = sprintf(
                    '"%s": excede o limite de %d MB.',
                    $file->getClientOriginalName(),
                    intdiv($limite, 1024 * 1024)
                );
                continue;
            }

            $categoriaRaw = isset($categorias[$i]) ? strtoupper(trim((string) $categorias[$i])) : PastaDocumento::CATEGORIA_DEMAIS;
            $categoria    = array_key_exists($categoriaRaw, self::DOCUMENT_TYPES) ? $categoriaRaw : PastaDocumento::CATEGORIA_DEMAIS;
            $descricao    = isset($descricoes[$i]) ? trim((string) $descricoes[$i]) : '';
            $numero       = isset($numeros[$i]) ? trim((string) $numeros[$i]) : '';

            $nomeUnico = $this->storage->salvar($file, $this->uploadsDir);

            $doc = new PastaDocumento();
            $doc->setPasta($pasta);
            $doc->setTitulo($file->getClientOriginalName());
            $doc->setCategoria($categoria);
            $doc->setDescricao($descricao !== '' ? $descricao : null);
            $doc->setNumero($numero !== '' ? $numero : null);
            $doc->setCaminhoArquivo($nomeUnico);
            $doc->setNomeOriginal($file->getClientOriginalName());
            $doc->setMimeType($mimeType);
            $doc->setTamanhoBytes($tamanho);

            $this->em->persist($doc);
            $this->markChecklistByCategoria($pasta, $categoria);
            ++$salvos;
        }

        $this->em->flush();

        if ($erros) {
            foreach ($erros as $erro) {
                $this->addFlash('error', $erro);
            }
        }

        if ($salvos > 0) {
            $this->addFlash('success', sprintf(
                '%d documento%s enviado%s com sucesso.',
                $salvos,
                $salvos > 1 ? 's' : '',
                $salvos > 1 ? 's' : ''
            ));
        }

        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId()]);
    }

    #[Route('/documento/{id}/visualizar', name: 'pasta_documento_view', methods: ['GET'])]
    public function viewDocumento(PastaDocumento $doc): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $doc->getPasta()?->getId();
        if (!$this->permissionChecker->canAccessResource($currentUser, 'pasta', $pastaId, 'view')) {
            throw $this->createAccessDeniedException('Você não tem permissão para acessar documentos desta pasta.');
        }

        $caminho = $this->storage->caminho($this->uploadsDir, $doc->getCaminhoArquivo());

        if (!$this->storage->existe($caminho)) {
            throw $this->createNotFoundException('Arquivo não encontrado no servidor.');
        }

        return $this->storage->servir($caminho, $doc->getNomeOriginal(), inline: true);
    }

    #[Route('/documento/{id}/download', name: 'pasta_documento_download', methods: ['GET'])]
    public function downloadDocumento(PastaDocumento $doc): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $doc->getPasta()?->getId();
        if (!$this->permissionChecker->canAccessResource($currentUser, 'pasta', $pastaId, 'view')) {
            throw $this->createAccessDeniedException('Você não tem permissão para acessar documentos desta pasta.');
        }

        $caminho = $this->storage->caminho($this->uploadsDir, $doc->getCaminhoArquivo());

        if (!$this->storage->existe($caminho)) {
            $this->addFlash('error', 'Arquivo não encontrado no servidor.');

            return $this->redirectToRoute('pasta_show', ['id' => $doc->getPasta()?->getId()]);
        }

        return $this->storage->servir($caminho, $doc->getNomeOriginal(), inline: false);
    }

    #[Route('/documento/{id}/editar', name: 'pasta_documento_edit', methods: ['POST'])]
    public function editDocumento(PastaDocumento $doc, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaForCheck = $doc->getPasta();
        if ($pastaForCheck !== null && !$this->permissionChecker->canAccessResource($currentUser, 'pasta', (int) $pastaForCheck->getId(), 'edit')) {
            throw $this->createAccessDeniedException('Você não tem permissão para editar documentos desta pasta.');
        }

        if (!$this->isCsrfTokenValid('edit_documento_'.$doc->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $categoriaRaw = strtoupper(trim((string) $request->request->get('categoria', '')));
        $categoria    = array_key_exists($categoriaRaw, self::DOCUMENT_TYPES) ? $categoriaRaw : $doc->getCategoria();

        $descricao    = trim((string) $request->request->get('descricao', ''));
        $numero       = trim((string) $request->request->get('numero', ''));
        $nomeOriginal = trim((string) $request->request->get('nomeOriginal', ''));

        $categoriaAnterior = $doc->getCategoria();
        $doc->setCategoria($categoria);
        $doc->setDescricao($descricao !== '' ? $descricao : null);
        $doc->setNumero($numero !== '' ? $numero : null);
        if ($nomeOriginal !== '') {
            $doc->setNomeOriginal($nomeOriginal);
        }

        $pasta = $doc->getPasta();
        if ($pasta !== null && $categoriaAnterior !== $categoria) {
            $this->recalculateChecklistAfterDelete($pasta, $categoriaAnterior);
            $this->markChecklistByCategoria($pasta, $categoria);
        }

        $this->em->flush();

        $this->addFlash('success', 'Documento atualizado com sucesso.');

        return $this->redirectToRoute('pasta_show', ['id' => $doc->getPasta()?->getId()]);
    }

    #[Route('/documento/{id}/deletar', name: 'pasta_documento_delete', methods: ['POST'])]
    public function deleteDocumento(PastaDocumento $doc, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaForCheck = $doc->getPasta();
        if ($pastaForCheck !== null && !$this->permissionChecker->canAccessResource($currentUser, 'pasta', (int) $pastaForCheck->getId(), 'edit')) {
            throw $this->createAccessDeniedException('Você não tem permissão para remover documentos desta pasta.');
        }

        $pastaId = $doc->getPasta()?->getId();

        if (!$this->isCsrfTokenValid('delete_documento_'.$doc->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $this->storage->excluir($this->storage->caminho($this->uploadsDir, $doc->getCaminhoArquivo()));

        $pasta = $doc->getPasta();
        $categoria = $doc->getCategoria();
        $this->em->remove($doc);

        if ($pasta !== null) {
            $this->recalculateChecklistAfterDelete($pasta, $categoria);
        }

        $this->em->flush();

        $this->addFlash('success', 'Documento removido com sucesso.');

        return $this->redirectToRoute('pasta_show', ['id' => $pastaId]);
    }

    private function markChecklistByCategoria(Pasta $pasta, string $categoria): void
    {
        match ($categoria) {
            PastaDocumento::CATEGORIA_PECA                   => $pasta->setDocPecaOk(true),
            PastaDocumento::CATEGORIA_PROCURACAO             => $pasta->setDocProcuracaoOk(true),
            PastaDocumento::CATEGORIA_IDENTIFICACAO          => $pasta->setDocIdentificacaoOk(true),
            PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA => $pasta->setDocComprovanteResidenciaOk(true),
            PastaDocumento::CATEGORIA_GRATUIDADE_JUSTICA     => $pasta->setDocGratuidadeJusticaOk(true),
            PastaDocumento::CATEGORIA_DEMAIS                 => $pasta->setDocDemaisOk(true),
            default => null,
        };
    }

    private function recalculateChecklistAfterDelete(Pasta $pasta, string $categoriaRemovida): void
    {
        $remaining = $pasta->getDocumentos()->filter(
            fn(PastaDocumento $d) => $d->getCategoria() === $categoriaRemovida
        );

        if ($remaining->count() <= 1) {
            match ($categoriaRemovida) {
                PastaDocumento::CATEGORIA_PECA                   => $pasta->setDocPecaOk(false),
                PastaDocumento::CATEGORIA_PROCURACAO             => $pasta->setDocProcuracaoOk(false),
                PastaDocumento::CATEGORIA_IDENTIFICACAO          => $pasta->setDocIdentificacaoOk(false),
                PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA => $pasta->setDocComprovanteResidenciaOk(false),
                PastaDocumento::CATEGORIA_GRATUIDADE_JUSTICA     => $pasta->setDocGratuidadeJusticaOk(false),
                PastaDocumento::CATEGORIA_DEMAIS                 => $pasta->setDocDemaisOk(false),
                default => null,
            };
        }
    }

    /**
     * @return array<string, PastaDocumento[]>
     */
    private function groupDocumentsByType(Pasta $pasta): array
    {
        $grouped = [];
        foreach (array_keys(self::DOCUMENT_TYPES) as $tipo) {
            $grouped[$tipo] = [];
        }
        foreach ($pasta->getDocumentos() as $doc) {
            $cat = $doc->getCategoria();
            if (array_key_exists($cat, $grouped)) {
                $grouped[$cat][] = $doc;
            }
        }
        return $grouped;
    }

    /**
     * @return array<int, array{cliente: \App\Cliente\Entity\Cliente, documentosPorTipo: array<string, ClienteDocumento[]>}>
     */
    private function groupClienteDocumentosByCliente(Pasta $pasta): array
    {
        $result = [];
        foreach ($pasta->getClientes() as $cliente) {
            $grouped = [];
            foreach (array_keys(self::DOCUMENT_TYPES_CLIENTE) as $tipo) {
                $grouped[$tipo] = [];
            }
            foreach ($cliente->getDocumentos() as $doc) {
                $cat = $doc->getCategoria();
                if (array_key_exists($cat, $grouped)) {
                    $grouped[$cat][] = $doc;
                }
            }
            $result[] = ['cliente' => $cliente, 'documentosPorTipo' => $grouped];
        }
        return $result;
    }

    /**
     * Preenche a entidade Pasta com os dados do request e retorna lista de erros de validação manual.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function fillPastaFromRequest(Pasta $pasta, array $data): array
    {
        $errors = [];

        $nup = trim((string) ($data['nup'] ?? ''));
        if ($nup === '') {
            $errors[] = 'O NUP é obrigatório.';
        } else {
            $existente = $this->pastaRepository->findOneBy(['nup' => $nup]);
            if ($existente !== null && $existente->getId() !== $pasta->getId()) {
                $errors[] = sprintf('O NUP "%s" já está em uso por outra pasta.', $nup);
            } else {
                $pasta->setNup($nup);
            }
        }

        $status = (string) ($data['status'] ?? Pasta::STATUS_ATIVO);
        if (!in_array($status, [Pasta::STATUS_ATIVO, Pasta::STATUS_ARQUIVADO], true)) {
            $errors[] = 'Status inválido. Valores aceitos: ativo, arquivado.';
        } else {
            $pasta->setStatus($status);
        }

        // dataAbertura é definida apenas na criação (via construtor da entidade)
        // e nunca alterada após isso

        $nomeCliente = trim((string) ($data['nome_cliente'] ?? ''));
        $pasta->setNomeCliente($nomeCliente !== '' ? $nomeCliente : null);

        $nomeAcao = trim((string) ($data['nome_acao'] ?? ''));
        $pasta->setNomeAcao($nomeAcao !== '' ? $nomeAcao : null);

        $processoId      = (int) ($data['processo_id'] ?? 0);
        $numeroProcesso  = trim((string) ($data['numeroProcesso'] ?? ''));

        if ($processoId > 0) {
            $processo = $this->processoRepository->find($processoId);
            if ($processo instanceof Processo) {
                $pasta->setProcesso($processo);
            } else {
                $errors[] = 'Processo não encontrado.';
            }
        } elseif ($numeroProcesso !== '') {
            $processo = new Processo();
            $this->fillProcessoFromData($processo, $data);
            $processo->setCriadoPor($this->getUser());
            $this->em->persist($processo);
            $pasta->setProcesso($processo);
        } else {
            $pasta->setProcesso(null);
        }


        $responsavelId = (int) ($data['responsavel_id'] ?? 0);
        if ($responsavelId > 0) {
            $responsavel = $this->userRepository->find($responsavelId);
            $pasta->setResponsavel($responsavel instanceof User ? $responsavel : null);
        } else {
            $pasta->setResponsavel(null);
        }

        $this->syncClientes($pasta, is_array($data['clientes'] ?? null) ? $data['clientes'] : []);
        $this->syncPartesContrarias($pasta, is_array($data['partes_contrarias'] ?? null) ? $data['partes_contrarias'] : []);

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fillProcessoFromData(Processo $processo, array $data): void
    {
        $numeroNormalizado = preg_replace('/\D+/', '', (string) ($data['numeroProcesso'] ?? ''));
        $processo->setNumeroProcesso($numeroNormalizado ?? '');
        $processo->setOrgaoJulgador((string) ($data['orgaoJulgador'] ?? ''));
        $processo->setSiglaTribunal((string) ($data['siglaTribunal'] ?? ''));
        $processo->setClasseProcessual((string) ($data['classeProcessual'] ?? ''));
        $processo->setAssuntoProcessual((string) ($data['assuntoProcessual'] ?? ''));
        $processo->setSituacaoProcesso((string) ($data['situacaoProcesso'] ?? 'EM_ANDAMENTO'));
        $processo->setInstancia((string) ($data['instancia'] ?? 'G1'));
        $processo->setDataDistribuicao($this->parseDateOrNull($data['dataDistribuicao'] ?? null));
        $processo->setDataBaixa($this->parseDateOrNull($data['dataBaixa'] ?? null));

        foreach (is_array($data['partes'] ?? null) ? $data['partes'] : [] as $parteData) {
            $nome = trim((string) ($parteData['nome'] ?? ''));
            if ($nome === '') {
                continue;
            }
            $parte = new ParteProcesso();
            $parte->setTipo((string) ($parteData['tipo'] ?? 'PARTE'));
            $parte->setNome($nome);
            $parte->setDocumento(($parteData['documento'] ?? '') !== '' ? (string) $parteData['documento'] : null);
            $parte->setPapel(($parteData['papel'] ?? '') !== '' ? (string) $parteData['papel'] : null);
            $processo->addParte($parte);
        }

        foreach (is_array($data['movimentacoes'] ?? null) ? $data['movimentacoes'] : [] as $movData) {
            $descricao = trim((string) ($movData['descricao'] ?? ''));
            if ($descricao === '') {
                continue;
            }
            $mov = new MovimentacaoProcesso();
            $mov->setDescricao($descricao);
            $mov->setTipo(($movData['tipo'] ?? '') !== '' ? (string) $movData['tipo'] : null);
            $mov->setOrgao(($movData['orgao'] ?? '') !== '' ? (string) $movData['orgao'] : null);
            $mov->setDataMovimentacao($this->parseDateOrNull($movData['dataMovimentacao'] ?? null));
            $processo->addMovimentacao($mov);
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

    /**
     * @param list<int|string> $clienteIds
     */
    private function syncClientes(Pasta $pasta, array $clienteIds): void
    {
        foreach ($pasta->getClientes()->toArray() as $clienteExistente) {
            $pasta->removeCliente($clienteExistente);
        }

        foreach ($clienteIds as $clienteId) {
            $cliente = $this->clienteRepository->find((int) $clienteId);
            if ($cliente instanceof Cliente) {
                $pasta->addCliente($cliente);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $partesData
     */
    private function syncPartesContrarias(Pasta $pasta, array $partesData): void
    {
        $existingById = [];
        foreach ($pasta->getPartesContrarias() as $parte) {
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
            $parte = ($id !== '' && isset($existingById[$id])) ? $existingById[$id] : new ParteContraria();

            if (!$pasta->getPartesContrarias()->contains($parte)) {
                $pasta->addParteContraria($parte);
            }

            if ($parte->getNome() !== $nome) {
                $parte->setNome($nome);
            }

            $kept[spl_object_id($parte)] = true;
        }

        foreach ($pasta->getPartesContrarias()->toArray() as $parteExistente) {
            if (!isset($kept[spl_object_id($parteExistente)])) {
                $pasta->removeParteContraria($parteExistente);
            }
        }
    }
}
