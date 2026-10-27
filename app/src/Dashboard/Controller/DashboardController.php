<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\Inteligencia\MontarLeituraDoDashboard;
use App\Dashboard\Periodo\PeriodoDoDashboard;
use App\Dashboard\UseCase\ObterDadosDashboardUseCase;
use App\Dashboard\UseCase\ObterMarcasDosAlertasUseCase;
use App\Dashboard\UseCase\ObterPreferenciasDoDashboardUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\AcessoAPasta;
use App\Repository\UserRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tenant\Service\RegraDosPrimarios;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly ObterDadosDashboardUseCase $obterDadosUseCase,
        private readonly UserRepository $userRepository,
        private readonly MontarLeituraDoDashboard $montarLeitura,
        private readonly ObterPreferenciasDoDashboardUseCase $obterPreferencias,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly ObterMarcasDosAlertasUseCase $obterMarcasDosAlertas,
    ) {}

    #[Route('', name: 'dashboard_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->assertAccess($currentUser);

        $filtros = $this->lerFiltros($request);

        // Quem gere o escritório (Master ou primário ativo): zera colunas (DASH-014) e define a meta de pastas
        // do mês (DASH-016). Os demais veem a meta, sem o botão de editar.
        $podeGerir = $this->regraDosPrimarios->podeGerirPrimarios($currentUser, $tenant);

        // Estilo pessoal do menu ⋮ do usuário LOGADO neste escritório. Lido também no XHR: as
        // colunas extras ("Adicionar coluna") mudam o HTML da tabela, então o fragmento tem de
        // sair com as colunas que ele ligou — e o servidor só calcula essas.
        $preferencias = $this->obterPreferencias->executar($tenant, $currentUser);
        // Marcas pessoais dos alertas da Central (Resolvido, Ignorar, Aplicar): do usuário logado.
        $marcasAlertas = $this->obterMarcasDosAlertas->executar($tenant, $currentUser);

        // Cadeado (spec pasta-cadeado D1 e D3): as metas das pastas ocultas para quem olha saem dos cards e
        // das colunas de metas, como da lista /tarefas/equipe a que os números levam. Uma consulta só.
        $pastasOcultas = $this->acessoAPasta->idsOcultosPara($currentUser, (int) $tenant->getId());

        $agora  = new \DateTimeImmutable();
        $output = $this->obterDadosUseCase->executar($tenant, $agora, $filtros, $preferencias->colunasExtras, $pastasOcultas);

        // BlueJus Intelligence: leitura por regras sobre o DashboardOutput já calculado (os
        // mesmos filtros). O UseCase não sabe que o painel existe.
        $leitura = $this->montarLeitura->montar($output, $filtros, $agora, $pastasOcultas !== [], (string) $request->query->get('dia_simulado', ''));

        if ($request->isXmlHttpRequest()) {
            // `filtros` vai junto para o fragmento marcar a coluna ordenada — sem ele a seta
            // sumiria do cabeçalho a cada recarga por filtro.
            // `preferencias` vai junto para o interruptor do Modo avançado (no painel, dentro do
            // fragmento) sair no estado gravado do usuário também depois do filtro.
            $vars = [
                'dashboard'    => $output,
                'filtros'      => $filtros,
                'inteligencia' => $leitura,
                'preferencias' => $preferencias,
                'marcasAlertas' => $marcasAlertas,
                'podeMeta'     => $podeGerir,
                'podeAnalisarPessoa' => $podeGerir, // análise individual: Master ou primário ativo
            ];

            // O XHR devolve o conteúdo de [data-filtro-resultado]: cards + tabela e, na mesma
            // ordem da casca, o painel Intelligence — que segue o filtro como no desenho.
            return new Response(
                $this->renderView('dashboard/_resultado.html.twig', $vars)
                . $this->renderView('dashboard/_inteligencia.html.twig', $vars),
            );
        }

        // Opções das facetas — só no render completo (a barra vive na casca). Passa arrays
        // simples ao template (convenção: nunca entidade Doctrine crua na view).
        // Os mapas de cargo e foto são do escritório atual (filtro por tenant no repositório):
        // nada de outro escritório entra nas opções.
        $mapaCargo = $this->userRepository->findCargoPorColaboradores($tenant);
        $mapaFoto  = $this->userRepository->findFotoPorColaboradores($tenant);
        // `foto` e `cargo` alimentam só o select próprio do desenho (avatar + cargo abaixo do
        // nome); o <select> nativo do parcial continua lendo `id`/`nome`.
        $responsaveis = array_map(
            static fn (User $u): array => [
                'id'    => $u->getId(),
                'nome'  => $u->getFullName(),
                'foto'  => $mapaFoto[(int) $u->getId()] ?? null,
                'cargo' => $mapaCargo[(int) $u->getId()] ?? null,
            ],
            $this->userRepository->findColaboradoresAtivosPorTenant($tenant),
        );
        $cargos    = array_values(array_unique(array_filter($mapaCargo)));
        sort($cargos);
        // Há colaborador ativo sem cargo? A opção "Sem cargo" (valor CARGO_SEM) só faz sentido aí.
        $existeSemCargo = array_filter($mapaCargo, static fn (?string $c): bool => trim((string) $c) === '') !== [];
        // "N pessoas" de cada cargo no select próprio: contagem dos colaboradores ATIVOS, a
        // mesma base das opções (o mapa já vem só com vínculos ativos do escritório).
        $contagemCargo    = [];
        $contagemSemCargo = 0;
        foreach ($mapaCargo as $cargoNome) {
            if (trim((string) $cargoNome) === '') {
                ++$contagemSemCargo;
            }
            if ($cargoNome !== null && $cargoNome !== '') {
                $contagemCargo[$cargoNome] = ($contagemCargo[$cargoNome] ?? 0) + 1;
            }
        }

        // As preferências (lidas acima) viram classe no `.db-page` já no HTML, sem esperar o JS.
        // Só no render completo — o XHR troca o fragmento DENTRO do `.db-page`, que mantém as
        // classes.
        return $this->render('dashboard/index.html.twig', [
            'dashboard'      => $output,
            'preferencias'   => $preferencias,
            'filtros'        => $filtros,
            'inteligencia'   => $leitura,
            'marcasAlertas'  => $marcasAlertas,
            'responsaveis'   => $responsaveis,
            'cargos'         => $cargos,
            'existeSemCargo' => $existeSemCargo,
            'contagemCargo'    => $contagemCargo,
            'contagemSemCargo' => $contagemSemCargo,
            'cargoSemValor'  => ObterDadosDashboardUseCase::CARGO_SEM,
            'podeZerar'      => $podeGerir, // "Zerar" (DASH-014): Master ou primário ativo
            'podeMeta'       => $podeGerir, // meta de pastas do mês (DASH-016)
            'podeAnalisarPessoa' => $podeGerir, // análise individual da pessoa (botão da linha): Master ou primário ativo
        ]);
    }

    /**
     * Filtros da URL. Sem `data_de` e sem `data_ate`, o painel abre em "Este mês" (do dia 1º ao
     * último dia do mês; spec dashboard-parte-1, item 1). Data vazia enviada (o usuário apagou) deixa o período aberto.
     *
     * @return array<string, mixed>
     */
    private function lerFiltros(Request $request): array
    {
        $filtros = [
            'data_de'     => (string) $request->query->get('data_de', ''),
            'data_ate'    => (string) $request->query->get('data_ate', ''),
            'responsavel' => (string) $request->query->get('responsavel', ''),
            // Nome do cargo, ou ObterDadosDashboardUseCase::CARGO_SEM ('__sem__') para quem não tem cargo.
            'cargo'       => (string) $request->query->get('cargo', ''),
            // Trecho do nome do colaborador: esconde linhas da tabela, não mexe nos cards.
            'busca'       => trim((string) $request->query->get('busca', '')),
            // Coluna clicada no cabeçalho da tabela. A validação é do UseCase: chave que não
            // existe cai no padrão do painel em vez de quebrar a tela.
            'ordenar'     => (string) $request->query->get('ordenar', ''),
            'direcao'     => (string) $request->query->get('direcao', ''),
        ];

        $veioData = $request->query->has('data_de') || $request->query->has('data_ate');

        return PeriodoDoDashboard::comPadrao($filtros, $veioData, new \DateTimeImmutable());
    }

    private function assertAccess(User $user): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || !$this->permissionChecker->canAccessModule($user, $tenant, 'bi')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Dashboard.');
        }

        return $tenant;
    }
}
