<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\DTO\MarcarPessoaDaInteligenciaInput;
use App\Dashboard\DTO\ObterPerfilDaPessoaInput;
use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Dashboard\Exception\PreferenciaInvalidaException;
use App\Dashboard\Periodo\PeriodoDoDashboard;
use App\Dashboard\UseCase\MarcarPessoaDaInteligenciaUseCase;
use App\Dashboard\UseCase\ObterPerfilDaPessoaUseCase;
use App\Dashboard\UseCase\RegistrarAuditoriaDoDesempenhoIndividualUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\AcessoAPasta;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use App\Tenant\Service\RegraDosPrimarios;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Análise individual da pessoa no painel BlueJus Intelligence (dc 01, botão da linha da tabela).
 *
 * - GET  /dashboard/inteligencia/pessoa/{id}: fragmento HTML do perfil (XHR). Sem efeito colateral
 *   além da auditoria da visualização (`audit_log`, ação `ia_pessoa`).
 * - POST /dashboard/inteligencia/pessoa/{id}/marca: "Marcar como analisado" e "Essa análise ajudou?"
 *   (JSON `{"acao": "analisado|sim|nao|parcial"}`), marca pessoal em `preferencia_usuario`.
 *
 * Guardas: escritório na sessão + módulo `bi` → 403; só o Master ou um primário ativo abre a análise
 * individual (RegraDosPrimarios) → 403; o id tem de ser colaborador ativo do escritório → 404 (IDOR);
 * CSRF do header no POST → 403; ação desconhecida ou corpo inválido → 400.
 */
#[Route('/dashboard/inteligencia/pessoa/{id}', requirements: ['id' => '\d+'])]
final class PerfilDaPessoaDoDashboardController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly ObterPerfilDaPessoaUseCase $obterPerfil,
        private readonly MarcarPessoaDaInteligenciaUseCase $marcarPessoa,
        private readonly RegistrarAuditoriaDoDesempenhoIndividualUseCase $auditoria,
    ) {
    }

    #[Route('', name: 'dashboard_inteligencia_pessoa', methods: ['GET'])]
    public function perfil(int $id, Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->autorizar($usuario);

        $veioData = $request->query->has('data_de') || $request->query->has('data_ate');
        $filtros  = PeriodoDoDashboard::comPadrao([
            'data_de'  => (string) $request->query->get('data_de', ''),
            'data_ate' => (string) $request->query->get('data_ate', ''),
        ], $veioData, new \DateTimeImmutable());

        try {
            $perfil = $this->obterPerfil->executar(new ObterPerfilDaPessoaInput(
                $tenant,
                $usuario,
                $id,
                $filtros,
                new \DateTimeImmutable(),
                $this->acessoAPasta->idsOcultosPara($usuario, (int) $tenant->getId()),
            ));
        } catch (ColaboradorForaDoEscritorioException) {
            throw $this->createNotFoundException('Colaborador não encontrado.');
        }

        $this->auditoria->visualizou($tenant, $usuario, $id);

        return $this->render('dashboard/_perfil_pessoa.html.twig', ['perfil' => $perfil, 'filtros' => $filtros]);
    }

    #[Route('/marca', name: 'dashboard_inteligencia_pessoa_marcar', methods: ['POST'])]
    public function marcar(int $id, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->autorizar($usuario);
        $this->validarCsrfAjax($request);

        $acao = $this->lerAcao($request);
        if ($acao === null) {
            return $this->json(['erro' => 'Informe a ação.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->json($this->marcarPessoa->executar(new MarcarPessoaDaInteligenciaInput($tenant, $usuario, $id, $acao))->paraArray());
        } catch (ColaboradorForaDoEscritorioException) {
            throw $this->createNotFoundException('Colaborador não encontrado.');
        } catch (PreferenciaInvalidaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    private function autorizar(User $usuario): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || !$this->permissionChecker->canAccessModule($usuario, $tenant, 'bi')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Dashboard.');
        }
        if (!$this->regraDosPrimarios->podeGerirPrimarios($usuario, $tenant)) {
            throw $this->createAccessDeniedException('Seu perfil não pode abrir análises individuais.');
        }

        return $tenant;
    }

    private function lerAcao(Request $request): ?string
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($dados) && is_string($dados['acao'] ?? null) ? $dados['acao'] : null;
    }
}
