<?php

declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\FeriadoDoPontoInput;
use App\Ponto\DTO\SedeDoPontoInput;
use App\Ponto\Exception\ConfiguracaoDoPontoRecusadaException;
use App\Ponto\Service\GestaoDoPonto;
use App\Ponto\Service\SedesEFeriadosDoPonto;
use App\Ponto\UseCase\CadastrarFeriadoDoPontoUseCase;
use App\Ponto\UseCase\RemoverFeriadoDoPontoUseCase;
use App\Ponto\UseCase\RemoverSedeDoPontoUseCase;
use App\Ponto\UseCase\SalvarSedeDoPontoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Aba "Configurações" da tela /ponto (spec `docs/specs/ponto-desenho-0811.md`, E6 em diante; desenho 16 L227 a
 * L260). O painel é buscado pelo js/ponto-paineis.js na primeira vez que a aba abre (`data-carregar` no
 * `_abas.html.twig`).
 *
 * Quem vê: `GestaoDoPonto::veConfiguracoes` no escritório da sessão (P58); cada seção confere a permissão dela.
 * Sem ele, 403.
 *
 * Sedes e feriados (E6): cada rota de gravação confere o escritório da sessão, o módulo do ponto e a permissão da
 * seção (`GestaoDoPonto::SEDES` ou `::FERIADOS`; sem ela, 403), depois o token da página (X-CSRF-Token, 403). A sede
 * ou o feriado de outro escritório é "não encontrado" (404). Respostas em JSON: `{ok, mensagem, erros}` e, conforme a
 * rota, a sede gravada ou o HTML da lista de feriados.
 */
#[Route('/ponto/configuracoes')]
final class ConfiguracoesDoPontoController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissoes,
        private readonly GestaoDoPonto $gestao,
        private readonly SedesEFeriadosDoPonto $sedesEFeriados,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('', name: 'ponto_configuracoes', methods: ['GET'])]
    public function painel(): Response
    {
        $escritorio = $this->escritorioDoGestor();
        $veSedes = $this->podeNaSecao($escritorio, GestaoDoPonto::SEDES);
        $veFeriados = $this->podeNaSecao($escritorio, GestaoDoPonto::FERIADOS);
        $feriados = $veFeriados ? $this->dadosDosFeriados($escritorio) : ['lotacoesDoPonto' => [], 'feriadosDoPonto' => []];

        return $this->render('ponto/_abas_configuracoes.html.twig', [
            'veSedes' => $veSedes,
            'veFeriados' => $veFeriados,
            'sedesDoPonto' => $veSedes ? $this->sedesEFeriados->sedes($escritorio) : [],
            'raioPadrao' => Sede::RAIO_PADRAO,
            'tenantIdDoPonto' => $escritorio->getId(),
            'lotacoesDoPonto' => $feriados['lotacoesDoPonto'],
            'feriadosDoPonto' => $feriados['feriadosDoPonto'],
        ]);
    }

    #[Route('/sedes', name: 'ponto_configuracoes_sede_criar', methods: ['POST'])]
    public function criarSede(Request $request, SalvarSedeDoPontoUseCase $salvar): JsonResponse
    {
        $escritorio = $this->escritorioDaSecao($request, GestaoDoPonto::SEDES);

        return $this->responder(fn (): array => $this->sedeGravada($salvar->executar(SedeDoPontoInput::daRequisicao($request), $escritorio)->paraJson()));
    }

    #[Route('/sedes/{id}', name: 'ponto_configuracoes_sede_salvar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function salvarSede(int $id, Request $request, SalvarSedeDoPontoUseCase $salvar): JsonResponse
    {
        $escritorio = $this->escritorioDaSecao($request, GestaoDoPonto::SEDES);

        return $this->responder(fn (): array => $this->sedeGravada($salvar->executar(SedeDoPontoInput::daRequisicao($request), $escritorio, $id)->paraJson()));
    }

    #[Route('/sedes/{id}/remover', name: 'ponto_configuracoes_sede_remover', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function removerSede(int $id, Request $request, RemoverSedeDoPontoUseCase $remover): JsonResponse
    {
        $escritorio = $this->escritorioDaSecao($request, GestaoDoPonto::SEDES);

        return $this->responder(function () use ($remover, $id, $escritorio): array {
            $desvinculadas = $remover->executar($id, $escritorio);
            $mensagem = $this->tradutor->trans('escritorio.flash.sede_removida');
            if ($desvinculadas > 0) {
                $mensagem .= ' ' . $this->tradutor->trans('escritorio.flash.sede_registros_desvinculados', ['qtd' => (string) $desvinculadas]);
            }

            return ['mensagem' => $mensagem];
        });
    }

    #[Route('/feriados', name: 'ponto_configuracoes_feriado_criar', methods: ['POST'])]
    public function criarFeriado(Request $request, CadastrarFeriadoDoPontoUseCase $cadastrar): JsonResponse
    {
        $escritorio = $this->escritorioDaSecao($request, GestaoDoPonto::FERIADOS);

        return $this->responder(function () use ($cadastrar, $request, $escritorio): array {
            $cadastrar->executar(FeriadoDoPontoInput::daRequisicao($request), $escritorio);

            return ['mensagem' => 'Feriado cadastrado.', 'lista' => $this->listaDeFeriados($escritorio)];
        });
    }

    #[Route('/feriados/{id}/remover', name: 'ponto_configuracoes_feriado_remover', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function removerFeriado(int $id, Request $request, RemoverFeriadoDoPontoUseCase $remover): JsonResponse
    {
        $escritorio = $this->escritorioDaSecao($request, GestaoDoPonto::FERIADOS);

        return $this->responder(function () use ($remover, $id, $escritorio): array {
            $remover->executar($id, $escritorio);

            return ['mensagem' => 'Feriado removido.', 'lista' => $this->listaDeFeriados($escritorio)];
        });
    }

    /** O escritório da sessão, se quem pede vê as configurações do ponto dele. */
    private function escritorioDoGestor(): Tenant
    {
        $eu = $this->getUser();
        if (!$eu instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $escritorio = $this->tenantContext->getCurrentTenant();
        if ($escritorio === null || !$this->permissoes->canAccessModule($eu, $escritorio, 'ponto') || !$this->gestao->veConfiguracoes($eu, $escritorio)) {
            throw $this->createAccessDeniedException('Sem acesso às configurações do ponto.');
        }

        return $escritorio;
    }

    /** O escritório da sessão, se quem grava tem a permissão da seção nele; depois, o token da página. */
    private function escritorioDaSecao(Request $request, string $permissao): Tenant
    {
        $eu = $this->getUser();
        $escritorio = $this->tenantContext->getCurrentTenant();
        if (!$eu instanceof User || $escritorio === null || !$this->permissoes->canAccessModule($eu, $escritorio, 'ponto') || !$this->permissoes->canAdminister($eu, $escritorio, $permissao)) {
            throw $this->createAccessDeniedException('Sem permissão para alterar esta configuração do ponto.');
        }
        $this->validarCsrfAjax($request);

        return $escritorio;
    }

    private function podeNaSecao(Tenant $escritorio, string $permissao): bool
    {
        $eu = $this->getUser();

        return $eu instanceof User && $this->permissoes->canAdminister($eu, $escritorio, $permissao);
    }

    /** @return array{lotacoesDoPonto: list<\App\Entity\Tenant\Lotacao>, feriadosDoPonto: list<\App\Ponto\DTO\FeriadoDoPontoOutput>} */
    private function dadosDosFeriados(Tenant $escritorio): array
    {
        // As lotações antes dos feriados: o nome da abrangência já está carregado.
        $lotacoes = $this->sedesEFeriados->lotacoes($escritorio);

        return ['lotacoesDoPonto' => $lotacoes, 'feriadosDoPonto' => $this->sedesEFeriados->feriados($escritorio)];
    }

    private function listaDeFeriados(Tenant $escritorio): string
    {
        return $this->renderView('ponto/_config_feriados_lista.html.twig', $this->dadosDosFeriados($escritorio));
    }

    /**
     * @param array{id: int, nome: string, latitude: string, longitude: string, raio: int} $sede
     *
     * @return array<string, mixed>
     */
    private function sedeGravada(array $sede): array
    {
        return ['sede' => $sede + [
            'urlSalvar'  => $this->generateUrl('ponto_configuracoes_sede_salvar', ['id' => $sede['id']]),
            'urlRemover' => $this->generateUrl('ponto_configuracoes_sede_remover', ['id' => $sede['id']]),
        ]];
    }

    /** @param callable(): array<string, mixed> $acao */
    private function responder(callable $acao): JsonResponse
    {
        try {
            return $this->json(['ok' => true, 'erros' => []] + $acao());
        } catch (ConfiguracaoDoPontoRecusadaException $recusa) {
            return $this->json(['ok' => false, 'mensagem' => $recusa->getMessage(), 'erros' => $recusa->erros()], $recusa->status());
        }
    }
}
