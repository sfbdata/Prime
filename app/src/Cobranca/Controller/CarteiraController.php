<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cliente\Repository\ClienteRepository;
use App\Cobranca\DTO\CriarCarteiraInput;
use App\Cobranca\DTO\CriarObjetoInput;
use App\Cobranca\DTO\EditarConfiguracaoCarteiraInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\CategoriaDocumentoCarteira;
use App\Cobranca\Exception\ArquivoMuitoGrandeException;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Exception\ClienteCredorNaoEncontradoException;
use App\Cobranca\Exception\TipoArquivoNaoPermitidoException;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Form\CriarCarteiraType;
use App\Cobranca\Form\CriarObjetoType;
use App\Cobranca\Form\EditarConfiguracaoCarteiraType;
use App\Cobranca\Repository\CarteiraDocumentoRepository;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\UseCase\CriarCarteiraUseCase;
use App\Cobranca\UseCase\CriarObjetoComCobrancaUseCase;
use App\Cobranca\UseCase\EditarConfiguracaoCarteiraUseCase;
use App\Cobranca\UseCase\EnviarDocumentoCarteiraUseCase;
use App\Cobranca\UseCase\ExcluirDocumentoCarteiraUseCase;
use App\Cobranca\UseCase\ListarCarteirasUseCase;
use App\Cobranca\UseCase\MontarVisaoCarteiraUseCase;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Service\ArquivoStorageInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Camada HTTP das Carteiras de Cobrança (Etapa 8). Leitura (Onda 8A): lista + visão da carteira.
 * Escrita (Onda 8B-E, cadastro/seleção): criar carteira, editar configuração, criar objeto e abrir
 * caso. Controller FINO: gate de módulo + capacidade, resolução tenant-safe por id (anti-IDOR → 404),
 * Form → UseCase, PRG sempre. Nenhuma regra de negócio aqui — os UseCases fazem flush internamente.
 */
#[Route('/cobrancas')]
#[IsGranted('ROLE_USER')]
final class CarteiraController extends AbstractController
{
    use AutorizacaoCobranca;

    private const POR_PAGINA = 20;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CarteiraRepository $carteiraRepository,
        private readonly ClienteRepository $clienteRepository,
        private readonly CarteiraDocumentoRepository $carteiraDocumentoRepository,
        private readonly ListarCarteirasUseCase $listarCarteiras,
        private readonly MontarVisaoCarteiraUseCase $montarVisaoCarteira,
        private readonly CriarCarteiraUseCase $criarCarteira,
        private readonly EditarConfiguracaoCarteiraUseCase $editarConfiguracao,
        private readonly CriarObjetoComCobrancaUseCase $criarObjeto,
        private readonly EnviarDocumentoCarteiraUseCase $enviarDocumentoCarteira,
        private readonly ExcluirDocumentoCarteiraUseCase $excluirDocumentoCarteira,
        private readonly ArquivoStorageInterface $storage,
        private readonly string $cobrancasUploadsDir,
    ) {
    }

    #[Route('', name: 'cobranca_carteira_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantComModulo();
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $filtros = [
            'busca' => trim((string) $request->query->get('busca', '')),
            'modo' => (string) $request->query->get('modo', ''),
        ];
        $ordenar = (string) $request->query->get('ordenar', '') ?: 'nome';
        $direcao = strtolower((string) $request->query->get('direcao', 'asc')) === 'desc' ? 'desc' : 'asc';
        $pagina = max(1, (int) $request->query->get('page', 1));

        $resultado = $this->listarCarteiras->executar($tenant, $filtros, $pagina, self::POR_PAGINA, $ordenar, $direcao);
        $total = $resultado['total'];

        $dados = [
            'carteiras' => $resultado['itens'],
            'total' => $total,
            'pagina' => $pagina,
            'total_paginas' => (int) max(1, ceil($total / self::POR_PAGINA)),
            'filtros' => $filtros + ['ordenar' => $ordenar, 'direcao' => $direcao],
        ];

        if ($request->isXmlHttpRequest()) {
            return $this->render('cobranca/carteira/_resultado.html.twig', $dados);
        }

        // O modal "Nova carteira" (com o select de credores do tenant) só existe na página cheia e só
        // para quem pode gerenciar carteiras — mesmo gate que esconde o botão no Twig.
        $podeGerenciarCarteira = $this->permissionChecker->hasPermission($this->usuarioLogado(), $tenant, 'resources.carteira.gerenciar');
        $dados['formCriarCarteira'] = $podeGerenciarCarteira
            ? $this->createForm(CriarCarteiraType::class, null, ['clientes' => $this->clienteRepository->opcoesDoTenant($tenant)])->createView()
            : null;
        $dados['ajudaCarteira'] = self::ajudaDosCampos();

        return $this->render('cobranca/carteira/index.html.twig', $dados);
    }

    #[Route('/carteiras/{id}', name: 'cobranca_carteira_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request): Response
    {
        $tenant = $this->tenantComModulo();
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $carteira = $this->carteiraRepository->findOneByIdDoTenant($id, $tenant);
        if ($carteira === null) {
            throw $this->createNotFoundException('Carteira de cobrança não encontrada.');
        }

        // Busca livre da lista de cobranças (objeto ou pessoa cobrada). Filtra SÓ a tabela — os
        // agregados do cabeçalho seguem sendo os da carteira inteira (regra no UseCase).
        $busca = trim((string) $request->query->get('busca', ''));
        // Mesmo contrato de URL do `index()` (`page`/`ordenar`/`direcao`), porque é o mesmo motor
        // de front (`public/js/filtro-tabela.js`) que dispara os dois. Quem valida o que chega é o
        // UseCase — aqui só normaliza o tipo.
        $pagina = max(1, (int) $request->query->get('page', 1));
        $ordenar = (string) $request->query->get('ordenar', '') ?: 'saldo';
        $direcao = strtolower((string) $request->query->get('direcao', 'desc')) === 'asc' ? 'asc' : 'desc';
        // Faceta de Estado (desenho 1B): recorta a lista por `StatusCaso` ou pelo derivado "só com
        // atraso". Como a busca, NÃO toca os agregados do cabeçalho — quem garante isso é o UseCase.
        $estado = (string) $request->query->get('estado', '');

        $visao = $this->montarVisaoCarteira->executar($carteira, $busca, $pagina, self::POR_PAGINA, $ordenar, $direcao, $estado);

        $dados = [
            'carteira' => $visao['carteira'],
            'casos' => $visao['casos'],
            'total' => $visao['total'],
            // A página devolvida é a do UseCase, não a que chegou na URL: ele grampeia quem pediu
            // página inexistente, e o rodapé tem de mostrar onde o usuário REALMENTE está.
            'pagina' => $visao['pagina'],
            'total_paginas' => $visao['total_paginas'],
            'por_pagina' => $visao['por_pagina'],
            'filtros' => ['busca' => $busca, 'estado' => $estado, 'ordenar' => $ordenar, 'direcao' => $direcao],
        ];

        // Contrato do `filtro-tabela.js`: no XHR devolve só o innerHTML do [data-filtro-resultado].
        if ($request->isXmlHttpRequest()) {
            return $this->render('cobranca/carteira/_resultado_casos.html.twig', $dados);
        }

        return $this->render('cobranca/carteira/show.html.twig', $dados + [
            'forms' => $this->formulariosDaCarteira($carteira, $tenant),
            'ajudaCarteira' => self::ajudaDosCampos(),
            // Documentos da carteira (Ajuste #5): lista cronológica abaixo da configuração.
            'documentos' => $this->carteiraDocumentoRepository->listarPorCarteira($carteira),
            'categoriasCarteira' => CategoriaDocumentoCarteira::cases(),
            'facetasEstado' => self::facetasDaLista(),
        ]);
    }

    /**
     * Opções da faceta de Estado, no formato do `_partials/_filtro_barra.html.twig`. Só na página
     * cheia: a barra de filtro vive FORA do fragmento trocável, então nunca viaja no XHR.
     *
     * @return list<array{name: string, rotulo: string, tipo: string, opcoes: list<array{valor: string, label: string}>}>
     */
    private static function facetasDaLista(): array
    {
        return [[
            'name' => 'estado',
            'rotulo' => 'Estado',
            'tipo' => 'select',
            'opcoes' => [
                ['valor' => 'ativo', 'label' => 'Ativo'],
                ['valor' => 'judicializado', 'label' => 'Judicializado'],
                ['valor' => 'encerrado', 'label' => 'Encerrado'],
                // Não é um estado, é o recorte derivado "tem atraso" — por isso vem por último e
                // com rótulo que não imita os três de cima, para não se ler como um quarto
                // valor de `StatusCaso`.
                ['valor' => 'vencidos', 'label' => 'Só com atraso'],
            ],
        ]];
    }

    #[Route('/carteiras/nova', name: 'cobranca_carteira_criar', methods: ['POST'])]
    public function criar(Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.carteira.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $input = new CriarCarteiraInput();
        $form = $this->createForm(CriarCarteiraType::class, $input, ['clientes' => $this->clienteRepository->opcoesDoTenant($tenant)]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->criarCarteira->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Carteira criada.');
            } catch (ClienteCredorNaoEncontradoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_carteira_index');
    }

    #[Route('/carteiras/{id}/configuracao', name: 'cobranca_carteira_configurar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function configurar(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.carteira.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $carteira = $this->carteiraRepository->findOneByIdDoTenant($id, $tenant);
        if ($carteira === null) {
            throw $this->createNotFoundException('Carteira de cobrança não encontrada.');
        }

        $input = new EditarConfiguracaoCarteiraInput();
        $input->carteiraId = $id;
        $form = $this->createForm(EditarConfiguracaoCarteiraType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->editarConfiguracao->executar($input, $tenant);
                $this->addFlash('success', 'Configuração da carteira atualizada.');
            } catch (CarteiraNaoEncontradaException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_carteira_show', ['id' => $id]);
    }

    #[Route('/carteiras/{id}/objetos', name: 'cobranca_objeto_criar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function criarObjeto(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $carteira = $this->carteiraRepository->findOneByIdDoTenant($id, $tenant);
        if ($carteira === null) {
            throw $this->createNotFoundException('Carteira de cobrança não encontrada.');
        }

        $input = new CriarObjetoInput();
        $input->carteiraId = $id;
        $form = $this->createForm(CriarObjetoType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $objeto = $this->criarObjeto->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Objeto criado — cobrança iniciada.');

                // Ajuste 2: criar o objeto já cria a cobrança; cai direto na página do objeto.
                return $this->redirectToRoute('cobranca_objeto_show', ['id' => $objeto->getId()]);
            } catch (CarteiraNaoEncontradaException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_carteira_show', ['id' => $id]);
    }

    // ------------------------------------------------------- documentos (#5) ---

    /**
     * Envia um documento da carteira (Ajuste #5): form multipart HTML puro + PRG — não é o
     * file-manager JSON do Caso (`DocumentoCobrancaController`), é uma lista simples. Whitelist de
     * MIME/tamanho e o guard de tenant vivem no `EnviarDocumentoCarteiraUseCase`.
     */
    #[Route('/carteiras/{id}/documentos', name: 'cobranca_carteira_documento_upload', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function uploadDocumento(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $carteira = $this->carteiraRepository->findOneByIdDoTenant($id, $tenant);
        if ($carteira === null) {
            throw $this->createNotFoundException('Carteira de cobrança não encontrada.');
        }

        if (!$this->isCsrfTokenValid('cobranca_carteira_documento_upload_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->redirectToRoute('cobranca_carteira_show', ['id' => $id]);
        }

        $arquivo = $request->files->get('arquivo');
        if (!$arquivo instanceof UploadedFile) {
            $this->addFlash('danger', 'Nenhum arquivo enviado.');

            return $this->redirectToRoute('cobranca_carteira_show', ['id' => $id]);
        }

        $categoria = CategoriaDocumentoCarteira::tryFrom((string) $request->request->get('categoria', ''))
            ?? CategoriaDocumentoCarteira::Outro;
        $observacao = trim((string) $request->request->get('observacao', ''));

        try {
            $this->enviarDocumentoCarteira->executar($carteira, $arquivo, $categoria, $observacao !== '' ? $observacao : null, $tenant);
            $this->addFlash('success', 'Documento adicionado.');
        } catch (TipoArquivoNaoPermitidoException | ArquivoMuitoGrandeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('cobranca_carteira_show', ['id' => $id]);
    }

    #[Route('/carteiras/documentos/{docId}/excluir', name: 'cobranca_carteira_documento_excluir', methods: ['POST'], requirements: ['docId' => '\d+'])]
    public function excluirDocumento(int $docId, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $documento = $this->carteiraDocumentoRepository->findOneByIdDoTenant($docId, $tenant);
        if ($documento === null) {
            throw $this->createNotFoundException('Documento não encontrado.');
        }
        $carteiraId = (int) $documento->getCarteira()?->getId();

        if (!$this->isCsrfTokenValid('cobranca_carteira_documento_excluir_' . $docId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->redirectToRoute('cobranca_carteira_show', ['id' => $carteiraId]);
        }

        $this->excluirDocumentoCarteira->executar($documento, $tenant);
        $this->addFlash('success', 'Documento removido.');

        return $this->redirectToRoute('cobranca_carteira_show', ['id' => $carteiraId]);
    }

    #[Route('/carteiras/documentos/{docId}/download', name: 'cobranca_carteira_documento_download', methods: ['GET'], requirements: ['docId' => '\d+'])]
    public function downloadDocumento(int $docId): BinaryFileResponse
    {
        $tenant = $this->tenantComModulo();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo de Cobranças.');
        }

        $documento = $this->carteiraDocumentoRepository->findOneByIdDoTenant($docId, $tenant);
        if ($documento === null) {
            throw $this->createNotFoundException('Documento não encontrado.');
        }

        $caminho = $this->storage->caminho($this->cobrancasUploadsDir . '/' . $tenant->getId(), $documento->getCaminhoArquivo());
        if (!$this->storage->existe($caminho)) {
            throw $this->createNotFoundException('Arquivo não encontrado no armazenamento.');
        }

        return $this->storage->servir($caminho, $documento->getNomeOriginal(), inline: false);
    }

    /**
     * Texto de ajuda (popover) dos campos de configuração da carteira, a partir dos enums (fonte única).
     * Consumido pelo partial `_campos_config.html.twig` nos modais de criar e editar.
     *
     * @return array{modo: list<array{label: string, descricao: string}>, honorarios: list<array{label: string, descricao: string}>}
     */
    private static function ajudaDosCampos(): array
    {
        $modo = [];
        foreach (ModoCarteira::cases() as $caso) {
            $modo[] = ['label' => $caso->label(), 'descricao' => $caso->descricao()];
        }

        $honorarios = [];
        foreach (FormaHonorarios::cases() as $caso) {
            $honorarios[] = ['label' => $caso->label(), 'descricao' => $caso->descricao()];
        }

        return ['modo' => $modo, 'honorarios' => $honorarios];
    }

    /**
     * Views dos formulários de mutação da carteira (modais). Config exige `resources.carteira.gerenciar`;
     * "Novo objeto" exige `resources.cobranca.gerenciar`. Vínculos e pessoas passaram a ser geridos DENTRO
     * do objeto (ajuste 2 — página unificada), não mais na carteira.
     *
     * @return array<string, \Symfony\Component\Form\FormView>
     */
    private function formulariosDaCarteira(Carteira $carteira, Tenant $tenant): array
    {
        $usuario = $this->usuarioLogado();
        $podeGerenciarCarteira = $this->permissionChecker->hasPermission($usuario, $tenant, 'resources.carteira.gerenciar');
        $podeGerenciarCobranca = $this->permissionChecker->hasPermission($usuario, $tenant, 'resources.cobranca.gerenciar');

        $forms = [];

        if ($podeGerenciarCarteira) {
            $config = new EditarConfiguracaoCarteiraInput();
            $config->carteiraId = $carteira->getId();
            $config->modo = $carteira->getModo();
            $config->formaHonorarios = $carteira->getFormaHonorarios();
            $config->percentualHonorarios = $carteira->getPercentualHonorarios();
            $config->toleranciaAtrasoDias = $carteira->getToleranciaAtrasoDias();
            $config->tipoVinculoPreferido = $carteira->getTipoVinculoPreferido();
            $config->rotuloObjeto = $carteira->getRotuloObjeto();
            // Encargos por atraso: o modal abre com o que já está salvo (senão editar a tolerância
            // zeraria as taxas sem querer, porque o form submete o DTO inteiro).
            $config->taxaJurosMensalBp = $carteira->getTaxaJurosMensalBp();
            $config->regimeJuros = $carteira->getRegimeJuros();
            $config->taxaMultaBp = $carteira->getTaxaMultaBp();
            $config->baseMulta = $carteira->getBaseMulta();
            $config->taxaCorrecaoBp = $carteira->getTaxaCorrecaoBp();
            $config->baseCorrecao = $carteira->getBaseCorrecao();
            $config->baseHonorarios = $carteira->getBaseHonorarios();
            $config->carenciaHonorariosDias = $carteira->getCarenciaHonorariosDias();
            $config->toleranciaJurosMultaDias = $carteira->getToleranciaJurosMultaDias();

            $forms['editarConfiguracao'] = $this->createForm(EditarConfiguracaoCarteiraType::class, $config)->createView();
        }

        if ($podeGerenciarCobranca) {
            $forms['criarObjeto'] = $this->createForm(CriarObjetoType::class)->createView();
        }

        return $forms;
    }
}
