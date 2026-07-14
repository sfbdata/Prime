<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cliente\Repository\ClienteRepository;
use App\Cobranca\DTO\CriarCarteiraInput;
use App\Cobranca\DTO\CriarObjetoInput;
use App\Cobranca\DTO\EditarConfiguracaoCarteiraInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Exception\ClienteCredorNaoEncontradoException;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Form\CriarCarteiraType;
use App\Cobranca\Form\CriarObjetoType;
use App\Cobranca\Form\EditarConfiguracaoCarteiraType;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\UseCase\CriarCarteiraUseCase;
use App\Cobranca\UseCase\CriarObjetoComCobrancaUseCase;
use App\Cobranca\UseCase\EditarConfiguracaoCarteiraUseCase;
use App\Cobranca\UseCase\ListarCarteirasUseCase;
use App\Cobranca\UseCase\MontarVisaoCarteiraUseCase;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        private readonly ListarCarteirasUseCase $listarCarteiras,
        private readonly MontarVisaoCarteiraUseCase $montarVisaoCarteira,
        private readonly CriarCarteiraUseCase $criarCarteira,
        private readonly EditarConfiguracaoCarteiraUseCase $editarConfiguracao,
        private readonly CriarObjetoComCobrancaUseCase $criarObjeto,
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
    public function show(int $id): Response
    {
        $tenant = $this->tenantComModulo();
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $carteira = $this->carteiraRepository->findOneByIdDoTenant($id, $tenant);
        if ($carteira === null) {
            throw $this->createNotFoundException('Carteira de cobrança não encontrada.');
        }

        $visao = $this->montarVisaoCarteira->executar($carteira);

        return $this->render('cobranca/carteira/show.html.twig', [
            'carteira' => $visao['carteira'],
            'casos' => $visao['casos'],
            'forms' => $this->formulariosDaCarteira($carteira, $tenant),
            'ajudaCarteira' => self::ajudaDosCampos(),
        ]);
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

            $forms['editarConfiguracao'] = $this->createForm(EditarConfiguracaoCarteiraType::class, $config)->createView();
        }

        if ($podeGerenciarCobranca) {
            $forms['criarObjeto'] = $this->createForm(CriarObjetoType::class)->createView();
        }

        return $forms;
    }
}
