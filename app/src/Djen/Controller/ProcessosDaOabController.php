<?php

declare(strict_types=1);

namespace App\Djen\Controller;

use App\Djen\Exception\AcompanhamentoInvalidoException;
use App\Djen\Repository\OabMonitoradaRepository;
use App\Djen\Repository\ProcessosDaOabRepository;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\Service\ClassificacaoDoProcesso;
use App\Djen\Service\FiltroDeProcessosDaOab;
use App\Djen\UseCase\AtualizarAnotacoesDoProcessoUseCase;
use App\Djen\UseCase\BuscarProcessosDaOabUseCase;
use App\Djen\UseCase\CadastrarProcessoNoPushUseCase;
use App\Djen\UseCase\ExcluirProcessoDoPushUseCase;
use App\Djen\UseCase\IncluirProcessoCadastradoNoPushUseCase;
use App\Djen\UseCase\IncluirProcessoNoAcompanhamentoUseCase;
use App\Djen\UseCase\PararDeAcompanharProcessoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\AcessoAPasta;
use App\Processo\DTO\CadastroDoProcessoInput;
use App\Processo\Exception\DadosDoProcessoInvalidosException;
use App\Processo\Service\ConsultasDoCnjGuardadas;
use App\Processo\Repository\ProcessoRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Aba "Processos da OAB" do Push Processual (desenho 11): processos acompanhados, Relatório (números que a
 * OAB trouxe e ainda não são acompanhados), inclusão por número, anotações, parar de acompanhar e exportação.
 *
 * Acesso: módulos `djen` (Push) e `processos`, os dois. Toda leitura leva o escritório explícito.
 */
#[Route('/push-processual/processos')]
final class ProcessosDaOabController extends AbstractController
{
    private const POR_PAGINA = 25;
    private const SUBABAS = ['acompanhados', 'relatorio'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly ProcessosDaOabRepository $processosDaOab,
        private readonly FiltroDeProcessosDaOab $filtro,
        private readonly OabMonitoradaRepository $oabRepository,
        private readonly PublicacaoDjenRepository $publicacaoRepository,
        private readonly ProcessoRepository $processoRepository,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly IncluirProcessoNoAcompanhamentoUseCase $incluir,
        private readonly PararDeAcompanharProcessoUseCase $parar,
        private readonly AtualizarAnotacoesDoProcessoUseCase $anotar,
        private readonly ExcluirProcessoDoPushUseCase $excluir,
        private readonly CadastrarProcessoNoPushUseCase $cadastrar,
        private readonly IncluirProcessoCadastradoNoPushUseCase $incluirCadastrado,
        private readonly ConsultasDoCnjGuardadas $consultasDoCnj,
        private readonly BuscarProcessosDaOabUseCase $buscar,
    ) {
    }

    #[Route('', name: 'push_processual_processos', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantComAcesso();
        if ($tenant === null) {
            return $this->negarAcesso();
        }

        $hoje = new \DateTimeImmutable('today');
        $sub = (string) $request->query->get('sub', 'acompanhados');
        $sub = \in_array($sub, self::SUBABAS, true) ? $sub : 'acompanhados';
        $criterios = $this->criterios($request);

        $acompanhados = $this->acompanhados($tenant);
        $relatorio = $this->processosDaOab->relatorio($tenant);
        $conjunto = $sub === 'relatorio' ? $relatorio : $acompanhados;
        $filtradas = $this->filtro->filtrar($conjunto, $criterios, $hoje);

        $pagina = max(1, (int) $request->query->get('pagina', 1));
        $totalPaginas = max(1, (int) ceil(\count($filtradas) / self::POR_PAGINA));
        $pagina = min($pagina, $totalPaginas);

        return $this->render('djen/processos.html.twig', [
            'abas' => $this->contagensDasAbas($tenant, \count($acompanhados)),
            'sub' => $sub,
            'sub_contagens' => ['acompanhados' => \count($acompanhados), 'relatorio' => \count($relatorio)],
            'indicadores' => $this->filtro->indicadores($acompanhados, $hoje),
            'linhas' => \array_slice($filtradas, ($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA),
            'total' => \count($filtradas),
            'pagina' => $pagina,
            'total_paginas' => $totalPaginas,
            'criterios' => $criterios,
            'tem_filtro' => array_filter($criterios) !== [],
            'oabs_opcoes' => $this->oabsDoFiltro($tenant),
            'areas' => ClassificacaoDoProcesso::AREAS,
            'fases' => ClassificacaoDoProcesso::FASES,
            'grupo_da_fase' => ClassificacaoDoProcesso::grupoDeCadaFase(),
            'hoje' => $hoje,
            'email_padrao' => $this->emailPadrao(),
        ]);
    }

    #[Route('/exportar', name: 'push_processual_processos_exportar', methods: ['GET'])]
    public function exportar(Request $request): Response
    {
        $tenant = $this->tenantComAcesso();
        if ($tenant === null) {
            return $this->negarAcesso();
        }

        $hoje = new \DateTimeImmutable('today');
        $linhas = $this->filtro->filtrar($this->acompanhados($tenant), $this->criterios($request), $hoje);

        $resposta = new StreamedResponse(static function () use ($linhas, $hoje): void {
            $saida = fopen('php://output', 'wb');
            if ($saida === false) {
                return;
            }
            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, ['Processo', 'Tribunal', 'Partes', 'Órgão', 'Área', 'Fase', 'Último movimento', 'Dias parado', 'OAB', 'Pasta', 'Observação'], ';');
            foreach ($linhas as $l) {
                fputcsv($saida, [
                    $l->numeroComMascara(), $l->tribunal, $l->partes, $l->orgao, $l->area ?? '', $l->fase ?? '',
                    $l->ultimoMovimento?->format('d/m/Y') ?? '', $l->diasParado($hoje) ?? '', $l->oab ?? '', $l->pastaNup ?? '', $l->observacao ?? '',
                ], ';');
            }
            fclose($saida);
        });
        $resposta->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $resposta->headers->set('Content-Disposition', 'attachment; filename="processos-da-oab.csv"');

        return $resposta;
    }

    #[Route('/incluir', name: 'push_processual_processos_incluir', methods: ['POST'])]
    public function incluir(Request $request): Response
    {
        $tenant = $this->tenantComAcesso();
        if ($tenant === null) {
            return $this->negarAcesso();
        }
        if (!$this->csrfValido($request)) {
            return $this->voltar('danger', 'Token de segurança inválido.');
        }

        /** @var User $usuario */
        $usuario = $this->getUser();
        $numero = (string) $request->request->get('numero', '');
        try {
            $this->incluir->executar($tenant, $usuario, $numero, (string) $request->request->get('email', ''), (string) $request->request->get('observacao', ''));
        } catch (AcompanhamentoInvalidoException $e) {
            return $this->voltar('danger', $e->getMessage());
        }

        return $this->voltar('success', sprintf('Processo %s incluído para acompanhamento.', trim($numero)));
    }

    #[Route('/incluir-do-relatorio', name: 'push_processual_processos_incluir_relatorio', methods: ['POST'])]
    public function incluirDoRelatorio(Request $request): Response
    {
        $tenant = $this->tenantComAcesso();
        if ($tenant === null) {
            return $this->negarAcesso();
        }
        if (!$this->csrfValido($request)) {
            return $this->voltar('danger', 'Token de segurança inválido.', ['sub' => 'relatorio']);
        }

        /** @var User $usuario */
        $usuario = $this->getUser();
        $incluidos = 0;
        foreach ($this->numeros($request) as $numero) {
            try {
                $this->incluir->executar($tenant, $usuario, $numero);
                ++$incluidos;
            } catch (AcompanhamentoInvalidoException) {
                // já acompanhado ou com baixa: segue com os demais
            }
        }

        return $this->voltar($incluidos > 0 ? 'success' : 'warning', sprintf('%d processo(s) incluído(s) no acompanhamento.', $incluidos), ['sub' => 'relatorio']);
    }

    #[Route('/parar', name: 'push_processual_processos_parar', methods: ['POST'])]
    public function parar(Request $request): Response
    {
        $tenant = $this->tenantComAcesso();
        if ($tenant === null) {
            return $this->negarAcesso();
        }
        if (!$this->csrfValido($request)) {
            return $this->voltar('danger', 'Token de segurança inválido.');
        }

        /** @var User $usuario */
        $usuario = $this->getUser();
        $parados = $this->parar->executar($tenant, $usuario, $this->numeros($request));

        return $this->voltar('success', sprintf('%d processo(s) deixaram de ser acompanhados. As publicações já recebidas continuam.', $parados));
    }

    /** Modal "Vincular processo", modo "Buscar processo existente" (desenho 11). */
    #[Route('/cadastrados', name: 'push_processual_processos_cadastrados', methods: ['GET'])]
    public function cadastrados(Request $request): Response
    {
        $tenant = $this->tenantComAcesso();
        if ($tenant === null) {
            return $this->json([], Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        $ocultos = $user instanceof User ? $this->acessoAPasta->idsDosProcessosOcultos($user, $tenant) : [];
        $achados = $this->processosDaOab->buscarCadastrados($tenant, (string) $request->query->get('q', ''), $ocultos);

        return $this->json($achados);
    }

    #[Route('/incluir-cadastrado', name: 'push_processual_processos_incluir_cadastrado', methods: ['POST'])]
    public function incluirCadastrado(Request $request): Response
    {
        $tenant = $this->tenantComAcesso();
        if ($tenant === null) {
            return $this->negarAcesso();
        }
        if (!$this->csrfValido($request)) {
            return $this->voltar('danger', 'Token de segurança inválido.');
        }

        /** @var User $usuario */
        $usuario = $this->getUser();
        $numero = (string) $request->request->get('numero', '');
        try {
            $this->incluirCadastrado->executarPorNumero($tenant, $usuario, $numero);
        } catch (AcompanhamentoInvalidoException $e) {
            return $this->voltar('danger', $e->getMessage());
        }

        return $this->voltar('success', sprintf('Processo %s vinculado. As publicações passam a aparecer como Vinculadas.', trim($numero)));
    }

    /** Modal "Vincular processo", modo "Cadastrar novo (CNJ)" (desenho 11). */
    #[Route('/buscar', name: 'push_processual_processos_buscar', methods: ['POST'])]
    public function buscarDaOab(Request $request): Response
    {
        $tenant = $this->tenantComAcesso();
        if ($tenant === null) {
            return $this->negarAcesso();
        }
        if (!$this->csrfValido($request)) {
            return $this->voltar('danger', 'Token de segurança inválido.');
        }

        try {
            $busca = $this->buscar->executar($tenant, new \DateTimeImmutable('today'));
        } catch (AcompanhamentoInvalidoException $e) {
            $this->addFlash('warning', $e->getMessage());

            return $this->redirectToRoute('push_processual_oabs');
        } catch (\Throwable) {
            return $this->voltar('danger', 'Não foi possível buscar os processos agora. Tente novamente em instantes.');
        }

        $mensagem = sprintf('Busca concluída: %d processos encontrados para %s.', $busca->processos, implode(', ', $busca->oabs));
        if ($busca->resultado->temFalhas()) {
            return $this->voltar('warning', 'Busca parcial (alguns tribunais não responderam). ' . $mensagem);
        }

        return $this->voltar('success', $mensagem);
    }

    #[Route('/cadastrar', name: 'push_processual_processos_cadastrar', methods: ['POST'])]
    public function cadastrar(Request $request): Response
    {
        $tenant = $this->tenantComAcesso();
        if ($tenant === null) {
            return $this->negarAcesso();
        }
        if (!$this->csrfValido($request)) {
            return $this->voltar('danger', 'Token de segurança inválido.');
        }

        /** @var User $usuario */
        $usuario = $this->getUser();
        $entrada = CadastroDoProcessoInput::fromRequest($request);
        $consulta = $this->consultasDoCnj->buscar($tenant, $usuario, $request->getSession()->getId(), $entrada->numeroSoDigitos());
        $sn = static fn (string $campo): ?bool => match ((string) $request->request->get($campo, '')) {
            '1' => true,
            '0' => false,
            default => null,
        };
        try {
            $this->cadastrar->executar($tenant, $usuario, $entrada, $consulta, [
                'valorCausa' => (string) $request->request->get('valorCausa', ''),
                'jurisdicao' => (string) $request->request->get('jurisdicao', ''),
                'segredo' => $sn('segredo'),
                'digital' => $sn('digital'),
                'gratuita' => $sn('gratuita'),
                'tutela' => $sn('tutela'),
                'prioridade' => $sn('prioridade'),
            ]);
        } catch (DadosDoProcessoInvalidosException $e) {
            return $this->voltar('danger', $e->getMessage());
        }

        return $this->voltar('success', sprintf('Processo %s cadastrado e incluído no monitoramento.', $entrada->numeroProcesso));
    }

    #[Route('/excluir', name: 'push_processual_processos_excluir', methods: ['POST'])]
    public function excluir(Request $request): Response
    {
        $tenant = $this->tenantComAcesso();
        if ($tenant === null) {
            return $this->negarAcesso();
        }
        if (!$this->csrfValido($request)) {
            return $this->voltar('danger', 'Token de segurança inválido.');
        }

        /** @var User $usuario */
        $usuario = $this->getUser();
        try {
            $this->excluir->executar($tenant, $usuario, (string) $request->request->get('numero', ''));
        } catch (AcompanhamentoInvalidoException $e) {
            return $this->voltar('danger', $e->getMessage());
        }

        return $this->voltar('success', 'Processo excluído.');
    }

    #[Route('/anotacoes', name: 'push_processual_processos_anotacoes', methods: ['POST'])]
    public function anotacoes(Request $request): Response
    {
        $tenant = $this->tenantComAcesso();
        if ($tenant === null) {
            return $this->negarAcesso();
        }
        if (!$this->csrfValido($request)) {
            return $this->voltar('danger', 'Token de segurança inválido.');
        }

        /** @var User $usuario */
        $usuario = $this->getUser();
        $numero = (string) $request->request->get('numero', '');
        try {
            $this->anotar->executar(
                $tenant,
                $usuario,
                $numero,
                (string) $request->request->get('observacao', ''),
                (string) $request->request->get('area', ''),
                (string) $request->request->get('fase', ''),
                (string) $request->request->get('email', ''),
                $this->processoRepository->findByNumeroProcessoDoTenant((string) preg_replace('/\D/', '', $numero), $tenant) !== null,
            );
        } catch (AcompanhamentoInvalidoException $e) {
            return $this->voltar('danger', $e->getMessage());
        }

        return $this->voltar('success', 'Anotações salvas.');
    }

    /** @return list<\App\Djen\DTO\LinhaDoProcessoDaOab> */
    private function acompanhados(Tenant $tenant): array
    {
        $user = $this->getUser();
        $ocultos = $user instanceof User ? $this->acessoAPasta->idsDosProcessosOcultos($user, $tenant) : [];

        return $this->processosDaOab->acompanhados($tenant, $ocultos);
    }

    /** @return array{busca: string, oab: string, mov: string, area: string, fase: string, pasta: string} */
    private function criterios(Request $request): array
    {
        $mov = (string) $request->query->get('mov', '');
        $pasta = (string) $request->query->get('pasta', '');

        return [
            'busca' => trim((string) $request->query->get('busca', '')),
            'oab' => (string) $request->query->get('oab', ''),
            'mov' => \in_array($mov, FiltroDeProcessosDaOab::MOVIMENTACOES, true) ? $mov : '',
            'area' => (string) $request->query->get('area', ''),
            'fase' => (string) $request->query->get('fase', ''),
            'pasta' => \in_array($pasta, ['c', 's'], true) ? $pasta : '',
        ];
    }

    /** @return list<string> */
    private function oabsDoFiltro(Tenant $tenant): array
    {
        return array_map(static fn ($oab): string => $oab->getNumero() . '/' . $oab->getUf(), $this->oabRepository->listarDoTenant($tenant));
    }

    private function emailPadrao(): string
    {
        $user = $this->getUser();

        return $user instanceof User ? (string) $user->getEmail() : '';
    }

    /** @return list<string> */
    private function numeros(Request $request): array
    {
        $numeros = $request->request->all('numeros');

        return array_values(array_filter(array_map('strval', $numeros), static fn (string $n): bool => $n !== ''));
    }

    private function csrfValido(Request $request): bool
    {
        return $this->isCsrfTokenValid('push_processos', (string) $request->request->get('_token'));
    }

    /** @param array<string, string> $parametros */
    private function voltar(string $tipo, string $mensagem, array $parametros = []): Response
    {
        $this->addFlash($tipo, $mensagem);

        return $this->redirectToRoute('push_processual_processos', $parametros);
    }

    /**
     * @return array{publicacoesNovas: int, processos: int, diarioNovas: int, oabs: int}
     */
    private function contagensDasAbas(Tenant $tenant, int $processos): array
    {
        $user = $this->getUser();
        $ocultos = $user instanceof User ? $this->acessoAPasta->numerosDeProcessoOcultos($user, $tenant) : [];

        return [
            'publicacoesNovas' => $this->publicacaoRepository->contarIndicadoresDoTenant($tenant, new \DateTimeImmutable('today'), $ocultos)['naoLidas'],
            'processos' => $processos,
            'diarioNovas' => 0,
            'oabs' => \count($this->oabRepository->listarDoTenant($tenant)),
        ];
    }

    private function tenantComAcesso(): ?Tenant
    {
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($user instanceof User && $tenant !== null
            && $this->permissionChecker->canAccessModule($user, $tenant, 'djen')
            && $this->permissionChecker->canAccessModule($user, $tenant, 'processos')) {
            return $tenant;
        }

        return null;
    }

    private function negarAcesso(): Response
    {
        $this->addFlash('warning', 'Você não tem permissão para acessar os processos do Push Processual.');

        return $this->redirectToRoute('homepage');
    }
}
