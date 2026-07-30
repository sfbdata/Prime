<?php

declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\LancamentoHorasPagasInput;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Exception\HorasPagasInvalidaException;
use App\Ponto\Form\LancamentoHorasPagasType;
use App\Ponto\Repository\LancamentoHorasPagasRepository;
use App\Ponto\UseCase\EditarHorasPagasUseCase;
use App\Ponto\UseCase\ExcluirHorasPagasUseCase;
use App\Ponto\UseCase\LancarHorasPagasUseCase;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ajuste manual do banco de horas (horas pagas em dinheiro ou bonificação). Só escreve — a leitura
 * mora na ficha do funcionário (`app_tenant_user_edit_role`), para onde as três ações redirecionam.
 *
 * Risco ALTO: mexe em verba trabalhista. Toda ação passa, nesta ordem, por
 * permissão → escopo de tenant → vínculo do colaborador (IDOR) → CSRF por intenção → UseCase.
 * Nenhuma delas grava a entidade direto: quem valida operação/quantidade/competência e recusa
 * auto-lançamento é o UseCase (GuardaHorasPagas), não o formulário.
 */
#[Route('/tenant')]
final class HorasPagasController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantRepository $tenantRepository,
        private readonly UserRepository $userRepository,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly PermissionChecker $permissionChecker,
        private readonly LancamentoHorasPagasRepository $lancamentoRepository,
    ) {}

    #[Route(
        '/{tenantId}/users/{id}/horas-pagas',
        name: 'ponto_horas_pagas_lancar',
        requirements: ['tenantId' => '\d+', 'id' => '\d+'],
        methods: ['POST'],
    )]
    public function lancar(
        int $tenantId,
        int $id,
        Request $request,
        LancarHorasPagasUseCase $lancarHorasPagas,
    ): Response {
        [$tenant, $colaborador, $autor] = $this->resolverContexto($tenantId, $id);

        $this->exigirCsrf($request, 'horas_pagas_lancar_' . $id);

        $input = new LancamentoHorasPagasInput();
        $form  = $this->createForm(LancamentoHorasPagasType::class, $input);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', $this->primeiroErro($form));

            return $this->voltarParaFicha($tenantId, $id, $request);
        }

        try {
            $lancarHorasPagas($input, $colaborador, $autor, $tenant);
            $this->addFlash('success', 'Lançamento de horas pagas registrado.');
        } catch (HorasPagasInvalidaException $excecao) {
            $this->addFlash('error', $excecao->getMessage());
        }

        return $this->voltarParaFicha($tenantId, $id, $request);
    }

    #[Route(
        '/{tenantId}/users/{id}/horas-pagas/{lancamentoId}/editar',
        name: 'ponto_horas_pagas_editar',
        requirements: ['tenantId' => '\d+', 'id' => '\d+', 'lancamentoId' => '\d+'],
        methods: ['POST'],
    )]
    public function editar(
        int $tenantId,
        int $id,
        int $lancamentoId,
        Request $request,
        EditarHorasPagasUseCase $editarHorasPagas,
    ): Response {
        [$tenant, $colaborador, $autor] = $this->resolverContexto($tenantId, $id);
        $lancamento = $this->resolverLancamento($lancamentoId, $tenant, $colaborador);

        $this->exigirCsrf($request, 'horas_pagas_editar_' . $lancamentoId);

        $input = new LancamentoHorasPagasInput();
        $form  = $this->createForm(LancamentoHorasPagasType::class, $input);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', $this->primeiroErro($form));

            return $this->voltarParaFicha($tenantId, $id, $request);
        }

        try {
            $editarHorasPagas($lancamento, $input, $autor, $tenant);
            $this->addFlash('success', 'Lançamento de horas pagas atualizado.');
        } catch (HorasPagasInvalidaException $excecao) {
            $this->addFlash('error', $excecao->getMessage());
        }

        return $this->voltarParaFicha($tenantId, $id, $request);
    }

    #[Route(
        '/{tenantId}/users/{id}/horas-pagas/{lancamentoId}/excluir',
        name: 'ponto_horas_pagas_excluir',
        requirements: ['tenantId' => '\d+', 'id' => '\d+', 'lancamentoId' => '\d+'],
        methods: ['POST'],
    )]
    public function excluir(
        int $tenantId,
        int $id,
        int $lancamentoId,
        Request $request,
        ExcluirHorasPagasUseCase $excluirHorasPagas,
    ): Response {
        [$tenant, $colaborador, $autor] = $this->resolverContexto($tenantId, $id);
        $lancamento = $this->resolverLancamento($lancamentoId, $tenant, $colaborador);

        $this->exigirCsrf($request, 'horas_pagas_excluir_' . $lancamentoId);

        try {
            $excluirHorasPagas($lancamento, $autor, $tenant);
            $this->addFlash('success', 'Lançamento de horas pagas excluído.');
        } catch (HorasPagasInvalidaException $excecao) {
            $this->addFlash('error', $excecao->getMessage());
        }

        return $this->voltarParaFicha($tenantId, $id, $request);
    }

    /**
     * Resolve escritório, colaborador e autor aplicando, na ordem, as guardas de acesso.
     *
     * @return array{Tenant, User, User}
     */
    private function resolverContexto(int $tenantId, int $colaboradorId): array
    {
        $autor = $this->getUser();

        if (!$autor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $tenant = $this->tenantRepository->find($tenantId);
        if ($tenant === null) {
            throw $this->createNotFoundException('Escritório não encontrado.');
        }

        // Mesma guarda da ficha do funcionário (TenantController.php:332-336).
        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $autor->getRoles(), true);
        $isOwnTenant  = $this->userTenantRepository->existeVinculoAtivo($autor, $tenant);

        if (!$isSuperAdmin && !($isOwnTenant && $this->permissionChecker->canAdminister($autor, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Você não tem permissão para lançar horas pagas neste escritório.');
        }

        $this->escoparFiltroNoTenant($tenant);

        $colaborador = $this->userRepository->find($colaboradorId);
        if ($colaborador === null) {
            throw $this->createNotFoundException('Colaborador não encontrado.');
        }

        // Sem esta guarda, /tenant/1/users/999/horas-pagas alcança o funcionário do escritório
        // vizinho: User não é TenantAware, o filtro do Doctrine não o protege.
        if (!$this->userTenantRepository->existeVinculoAtivo($colaborador, $tenant)) {
            throw $this->createAccessDeniedException('Colaborador não pertence a este escritório.');
        }

        return [$tenant, $colaborador, $autor];
    }

    /**
     * Lançamento tem de ser do escritório da URL E do colaborador da URL — nunca buscar só por id.
     */
    private function resolverLancamento(int $lancamentoId, Tenant $tenant, User $colaborador): LancamentoHorasPagas
    {
        $lancamento = $this->lancamentoRepository->buscarDoTenant($lancamentoId, $tenant);

        if ($lancamento === null || $lancamento->getUser()?->getId() !== $colaborador->getId()) {
            throw $this->createNotFoundException('Lançamento não encontrado.');
        }

        return $lancamento;
    }

    /**
     * Re-aponta o TenantFilter para o tenant da URL, como em TenantController::escoparFiltroNoTenant
     * (`TenantController.php:448`): é o cinto da trava automática (TenantUrlScopeListener).
     *
     * Chamar SEMPRE depois do guard de acesso ao tenant e antes de tocar em entidade TenantAware. As
     * duas queries que rodam antes dele (`Tenant` por id da URL e o vínculo do próprio autor) não
     * dependem do filtro: `Tenant` não é TenantAware, e o vínculo é consultado com o tenant
     * explícito — é justamente o resultado delas que diz qual tenant fixar.
     */
    private function escoparFiltroNoTenant(Tenant $tenant): void
    {
        $this->em->getFilters()->enable('tenant')->setParameter('tenant', (int) $tenant->getId(), Types::INTEGER);
    }

    private function exigirCsrf(Request $request, string $intencao): void
    {
        if (!$this->isCsrfTokenValid($intencao, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
    }

    /**
     * Primeira mensagem de erro do formulário — o modal não sobrevive ao redirect, então o motivo
     * precisa viajar no flash, senão a recusa vira "não aconteceu nada".
     */
    private function primeiroErro(FormInterface $form): string
    {
        foreach ($form->getErrors(true) as $erro) {
            $mensagem = trim($erro->getMessage());
            if ($mensagem !== '') {
                return $mensagem;
            }
        }

        return 'Erro ao registrar horas pagas. Verifique os campos.';
    }

    private function voltarParaFicha(int $tenantId, int $colaboradorId, Request $request): Response
    {
        $parametros = [
            'tenantId' => $tenantId,
            'id'       => $colaboradorId,
            'tab'      => 'horas-pagas',
        ];

        $competencia = (string) $request->request->get('competencia', '');
        if ($competencia !== '') {
            $parametros['competencia'] = $competencia;
        }

        return $this->redirectToRoute('app_tenant_user_edit_role', $parametros);
    }
}
