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
use App\Ponto\Service\BancoDeHorasPorLotes;
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
 * Nenhuma delas grava a entidade direto: quem valida operação/quantidade/competência é o UseCase
 * (GuardaHorasPagas), não o formulário. Auto-lançamento é PERMITIDO desde 2026-07-31 (decisão do dono);
 * o que resta como rastro de quem acertou o próprio banco é o `audit_log`.
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
        private readonly BancoDeHorasPorLotes $bancoDeHoras,
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

            return $this->voltarParaFicha($tenantId, $id);
        }

        try {
            $lancarHorasPagas($input, $colaborador, $autor, $tenant);
            $this->addFlash('success', 'Lançamento de horas pagas registrado.');
            $this->avisarSeODescontoDeixouOBancoNegativo($input, $colaborador, $tenant);
        } catch (HorasPagasInvalidaException $excecao) {
            $this->addFlash('error', $excecao->getMessage());
        }

        return $this->voltarParaFicha($tenantId, $id);
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

            return $this->voltarParaFicha($tenantId, $id);
        }

        try {
            $editarHorasPagas($lancamento, $input, $autor, $tenant);
            $this->addFlash('success', 'Lançamento de horas pagas atualizado.');
            $this->avisarSeODescontoDeixouOBancoNegativo($input, $colaborador, $tenant);
        } catch (HorasPagasInvalidaException $excecao) {
            $this->addFlash('error', $excecao->getMessage());
        }

        return $this->voltarParaFicha($tenantId, $id);
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

        return $this->voltarParaFicha($tenantId, $id);
    }

    /**
     * AVISA, sem bloquear, quando o desconto deixa negativo o banco de horas que não estava negativo.
     *
     * Origem (decisão do dono, 2026-07-31): o banco zerava em 1º de janeiro, e o aviso pegava o pagamento lançado
     * na competência do ano errado. Desde a E17 (spec ponto-desenho-0811, P51 a) o banco atravessa o ano, e o
     * número conferido é o banco inteiro até hoje: o mesmo "Saldo atual" da aba Banco de Horas e do topo da /ponto
     * (`BancoDeHorasPorLotes`), já com o lançamento recém-gravado (os UseCases dão flush antes de retornar). O dono
     * recusou trava (§2): o lançamento JÁ FOI gravado quando o aviso aparece, e editar/excluir são livres.
     *
     * Só dispara em desconto. Bonificação não tem como deixar o banco negativo.
     */
    private function avisarSeODescontoDeixouOBancoNegativo(
        LancamentoHorasPagasInput $input,
        User $colaborador,
        Tenant $tenant,
    ): void {
        if ($input->minutosComSinal() >= 0) {
            return;
        }

        // O aviso é ACESSÓRIO: o lançamento JÁ está gravado quando ele roda. Qualquer falha aqui
        // (timeout, deadlock, uma mudança de assinatura num refactor) não pode virar 500: o POST não é
        // idempotente, e o admin que reenviasse o formulário gravaria o desconto DUAS VEZES.
        try {
            $saldoDepois = $this->bancoDeHoras->calcular($colaborador, $tenant, new \DateTimeImmutable('today'))->saldoAtualMinutos;
        } catch (\Throwable) {
            return;
        }

        // O gatilho é "o desconto DERRUBOU o banco para o negativo", não "o banco está negativo": §2 da spec
        // recusa avisar sobre saldo negativo, e quem já devia horas por faltas receberia o aviso em TODO desconto.
        // Aviso que dispara no caso errado ensina o admin a ignorá-lo.
        $saldoAntes = $saldoDepois - $input->minutosComSinal();
        if ($saldoAntes < 0 || $saldoDepois >= 0) {
            return;
        }

        $abs = abs($saldoDepois);
        $this->addFlash('warning', sprintf('Atenção: este desconto deixou o banco de horas negativo em -%dh%02d.', intdiv($abs, 60), $abs % 60));
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

    private function voltarParaFicha(int $tenantId, int $colaboradorId): Response
    {
        // Sem `competencia` de propósito: nenhum formulário desta tela envia o campo (a aba
        // "Horas pagas" não tem seletor de mês), então o `?competencia=` montado a partir do POST
        // era código morto — e código morto que monta querystring convida a acreditar que a ficha
        // volta no mês do lançamento, o que nunca aconteceu. A ficha reabre no seu padrão: o mês
        // mais recente COM DADO, que passa a incluir o lançamento recém-criado.
        return $this->redirectToRoute('app_tenant_user_edit_role', [
            'tenantId' => $tenantId,
            'id'       => $colaboradorId,
            'tab'      => 'horas-pagas',
        ]);
    }
}
