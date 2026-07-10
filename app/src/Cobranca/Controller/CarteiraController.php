<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cliente\Repository\ClienteRepository;
use App\Cobranca\DTO\AbrirCasoInput;
use App\Cobranca\DTO\CriarCarteiraInput;
use App\Cobranca\DTO\CriarObjetoInput;
use App\Cobranca\DTO\EditarConfiguracaoCarteiraInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Exception\CasoAtivoJaExisteException;
use App\Cobranca\Exception\ClienteCredorNaoEncontradoException;
use App\Cobranca\Exception\ObjetoNaoEncontradoException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Form\AbrirCasoType;
use App\Cobranca\Form\CriarCarteiraType;
use App\Cobranca\Form\CriarObjetoType;
use App\Cobranca\Form\CriarPessoaType;
use App\Cobranca\Form\EditarConfiguracaoCarteiraType;
use App\Cobranca\Form\EncerrarVinculoType;
use App\Cobranca\Form\VincularPessoaAObjetoType;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Repository\VinculoPessoaObjetoRepository;
use App\Cobranca\UseCase\AbrirCasoUseCase;
use App\Cobranca\UseCase\CriarCarteiraUseCase;
use App\Cobranca\UseCase\CriarObjetoUseCase;
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
        private readonly ObjetoCobrancaRepository $objetoRepository,
        private readonly VinculoPessoaObjetoRepository $vinculoRepository,
        private readonly PessoaRepository $pessoaRepository,
        private readonly ClienteRepository $clienteRepository,
        private readonly ListarCarteirasUseCase $listarCarteiras,
        private readonly MontarVisaoCarteiraUseCase $montarVisaoCarteira,
        private readonly CriarCarteiraUseCase $criarCarteira,
        private readonly EditarConfiguracaoCarteiraUseCase $editarConfiguracao,
        private readonly CriarObjetoUseCase $criarObjeto,
        private readonly AbrirCasoUseCase $abrirCaso,
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
            'objetos' => $this->objetosDaCarteira($carteira, $tenant),
            'forms' => $this->formulariosDaCarteira($carteira, $tenant),
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
                $this->criarObjeto->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Objeto criado.');
            } catch (CarteiraNaoEncontradaException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_carteira_show', ['id' => $id]);
    }

    #[Route('/objetos/{id}/casos', name: 'cobranca_caso_abrir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function abrir(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $objeto = $this->objetoRepository->findOneByIdDoTenant($id, $tenant);
        if ($objeto === null) {
            throw $this->createNotFoundException('Objeto de cobrança não encontrado.');
        }
        $carteiraId = $objeto->getCarteira()?->getId();

        $input = new AbrirCasoInput();
        $input->objetoId = $id;
        $form = $this->createForm(AbrirCasoType::class, $input, ['pessoas' => $this->pessoaRepository->opcoesDoTenant($tenant)]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $caso = $this->abrirCaso->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Caso aberto.');

                return $this->redirectToRoute('cobranca_caso_show', ['id' => $caso->getId()]);
            } catch (ObjetoNaoEncontradoException | PessoaNaoEncontradaException | CasoAtivoJaExisteException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_carteira_show', ['id' => $carteiraId]);
    }

    /**
     * Objetos da carteira (+ vínculos abertos) já mapeados em arrays simples para o Twig — sem expor
     * entidades Doctrine. Leitura escopada ao tenant (defesa em profundidade). Vínculos abertos vêm em
     * uma única query (IN nos objetos) para não gerar N+1.
     *
     * @return list<array{id: int, identificacao: string, descricao: ?string, vinculos: list<array{id: int, pessoaNome: string, tipoLabel: string}>}>
     */
    private function objetosDaCarteira(Carteira $carteira, Tenant $tenant): array
    {
        $objetos = $this->objetoRepository->findBy(
            ['carteira' => $carteira, 'tenant' => $tenant],
            ['identificacao' => 'ASC'],
        );

        $vinculosPorObjeto = [];
        if ($objetos !== []) {
            $vinculosAbertos = $this->vinculoRepository->findBy(
                ['objeto' => $objetos, 'tenant' => $tenant, 'dataFim' => null],
                ['dataInicio' => 'ASC'],
            );
            foreach ($vinculosAbertos as $vinculo) {
                $objetoId = $vinculo->getObjeto()?->getId();
                if ($objetoId === null) {
                    continue;
                }
                $vinculosPorObjeto[$objetoId][] = [
                    'id' => (int) $vinculo->getId(),
                    'pessoaNome' => $vinculo->getPessoa()?->getNome() ?? '—',
                    'tipoLabel' => $vinculo->getTipoVinculo()->label(),
                ];
            }
        }

        $lista = [];
        foreach ($objetos as $objeto) {
            $objetoId = (int) $objeto->getId();
            $lista[] = [
                'id' => $objetoId,
                'identificacao' => $objeto->getIdentificacao(),
                'descricao' => $objeto->getDescricao(),
                'vinculos' => $vinculosPorObjeto[$objetoId] ?? [],
            ];
        }

        return $lista;
    }

    /**
     * Views dos formulários de mutação da carteira (modais no detalhe). Cada form só é montado se o
     * usuário tiver a capacidade correspondente — o mesmo gate que esconde os modais no Twig. Config
     * exige `resources.carteira.gerenciar`; objeto/vínculo/abrir-caso exigem `resources.cobranca.gerenciar`.
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
            $pessoas = $this->pessoaRepository->opcoesDoTenant($tenant);
            $forms['criarObjeto'] = $this->createForm(CriarObjetoType::class)->createView();
            $forms['criarPessoa'] = $this->createForm(CriarPessoaType::class)->createView();
            $forms['vincularPessoa'] = $this->createForm(VincularPessoaAObjetoType::class, null, ['pessoas' => $pessoas])->createView();
            $forms['encerrarVinculo'] = $this->createForm(EncerrarVinculoType::class)->createView();
            $forms['abrirCaso'] = $this->createForm(AbrirCasoType::class, null, ['pessoas' => $pessoas])->createView();
        }

        return $forms;
    }
}
