<?php

declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\AjustarBatidaInput;
use App\Ponto\DTO\DesconsiderarBatidaInput;
use App\Ponto\DTO\IncluirBatidaInput;
use App\Ponto\DTO\RestaurarBatidaInput;
use App\Ponto\Exception\AjusteDeBatidaRecusadoException;
use App\Ponto\Form\RegistroPontoManualType;
use App\Ponto\Model\AlvoDoAjuste;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Ponto\UseCase\AjustarBatidaUseCase;
use App\Ponto\UseCase\DesconsiderarBatidaUseCase;
use App\Ponto\UseCase\IncluirBatidaUseCase;
use App\Ponto\UseCase\RestaurarBatidaUseCase;
use App\Repository\TenantRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * As correções do gestor nas batidas, pela ficha do colaborador (E10, plano-e10-ponto §6): incluir a batida que falta,
 * ajustar o tipo, a data ou a hora, desconsiderar (tirar da conta) e restaurar. A batida gravada nunca muda: cada
 * correção é um ajuste novo, com motivo, e o registro original fica guardado. As rotas vieram do TenantController
 * (`pontoAdd`, `pontoEdit`, `pontoDelete`), com as mesmas guardas na mesma ordem: quem executa (escritório e
 * permissão `admin.users.manage`, ou Master), depois o alvo (vínculo ativo, auditoria 1.08), depois o CSRF por
 * intenção, depois o escopo do filtro no escritório da URL.
 *
 * Recusa do ajuste ({@see AjusteDeBatidaRecusadoException}): batida fora desta folha responde 404; a própria batida de
 * quem não é primário nem Master responde 403 (P43); as outras voltam à ficha com o aviso. A página "Ajustar batida"
 * nem abre para a batida desconsiderada ou com a justificativa em análise (C5). Nela, o erro de campo (horário
 * futuro, nada a mudar, motivo vazio) volta na própria página, com o que foi digitado.
 */
#[Route('/tenant/{tenantId}/user/{id}/ponto', requirements: ['tenantId' => '\d+', 'id' => '\d+'])]
final class AjusteDeBatidaController extends AbstractController
{
    private const ALVO = '[ba]\d+';

    private const ERRO_DOS_CAMPOS = 'Erro ao registrar batida. Verifique os campos.';

    /** As recusas que são do que foi digitado: a página de ajuste volta com o erro, sem perder o formulário. */
    private const RECUSAS_DO_FORMULARIO = [
        AjusteDeBatidaRecusadoException::FUTURO => 'hora',
        AjusteDeBatidaRecusadoException::SEM_MOTIVO => 'motivo',
        AjusteDeBatidaRecusadoException::NADA_MUDOU => null,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenantRepository,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly PermissionChecker $permissionChecker,
        private readonly RegistroPontoRepository $registros,
        private readonly AnaliseDaPropriaJustificativa $analiseDaPropria,
        private readonly JustificativaPontoRepository $justificativas,
    ) {
    }

    /** O "+" e o "Adicionar Batida" da ficha: a batida que falta entra como inclusão, com o motivo. */
    #[Route('/add', name: 'app_tenant_user_ponto_add', methods: ['POST'])]
    public function incluir(int $tenantId, User $user, Request $request, IncluirBatidaUseCase $incluirBatida): Response
    {
        [$autor, $tenant] = $this->autorNaFicha($tenantId, $user);
        $this->exigirCsrf($request, 'ponto_manual_add');
        $this->escoparFiltroNoTenant($tenant);

        $form = $this->createForm(RegistroPontoManualType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $input = new IncluirBatidaInput($tenant, $user, self::tipoDo($form), self::dataHoraDo($form), self::motivoDo($form), $autor);
            $this->tentar(static fn () => $incluirBatida->executar($input), 'Batida incluída na folha.');
        } else {
            $this->addFlash('danger', self::erroDoFormulario($form));
        }

        return $this->voltarParaFicha($tenantId, $user, self::competenciaDaTela($request));
    }

    /** A página "Ajustar batida": o link da hora na ficha. */
    #[Route('/{alvo}/ajustar', name: 'app_tenant_user_ponto_ajustar', requirements: ['alvo' => self::ALVO], methods: ['GET', 'POST'])]
    public function ajustar(int $tenantId, User $user, string $alvo, Request $request, AjustarBatidaUseCase $ajustarBatida): Response
    {
        [$autor, $tenant] = $this->autorNaFicha($tenantId, $user);
        if ($request->isMethod('POST')) {
            $this->exigirCsrf($request, 'ponto_ajustar');
        }
        $this->escoparFiltroNoTenant($tenant);
        $this->exigirQuePodeCorrigir($autor, $user, $tenant);

        $batida = $this->registros->alvoDoAjuste($user, $tenant, $alvo)
            ?? throw $this->createNotFoundException(AjusteDeBatidaRecusadoException::MENSAGEM_NAO_ENCONTRADA);
        $competencia = self::competenciaDaTela($request) ?? $batida->dataHoraAtual?->format('Y-m');
        $recusa = $this->recusaDaPagina($batida);
        if ($recusa !== null) {
            $this->addFlash('danger', $recusa);

            return $this->voltarParaFicha($tenantId, $user, $competencia);
        }

        $form = $this->formDoAjuste($batida, $request);
        if ($form->isSubmitted() && $form->isValid()) {
            $input = new AjustarBatidaInput($tenant, $user, $alvo, self::cabecaDaTela($request), self::tipoDo($form), self::dataHoraDo($form), self::motivoDo($form), $autor);
            $resposta = $this->gravarAjuste($form, static fn () => $ajustarBatida->executar($input), $tenantId, $user, $competencia);
            if ($resposta !== null) {
                return $resposta;
            }
        }

        // A página que volta com erro mantém a cabeça da tela: se outro ajuste entrou no meio, o próximo envio é "mudou".
        $cabeca = $request->isMethod('POST') ? self::cabecaDaTela($request) : $batida->cabecaId();

        return $this->render('tenant/ponto_edit.html.twig', [
            'form' => $form->createView(), 'user' => $user, 'tenantId' => $tenantId, 'competencia' => $competencia,
            'alvo' => $alvo, 'cabeca' => $cabeca, 'tipoAtual' => $batida->tipoAtual, 'dataHoraAtual' => $batida->dataHoraAtual,
        ]);
    }

    /** O "x" da batida na ficha, com o motivo da janela "Desconsiderar batida": a batida sai da conta. */
    #[Route('/{alvo}/desconsiderar', name: 'app_tenant_user_ponto_desconsiderar', requirements: ['alvo' => self::ALVO], methods: ['POST'])]
    public function desconsiderar(int $tenantId, User $user, string $alvo, Request $request, DesconsiderarBatidaUseCase $desconsiderarBatida): Response
    {
        [$autor, $tenant] = $this->autorNaFicha($tenantId, $user);
        $this->exigirCsrf($request, 'ponto_desconsiderar_' . $alvo);
        $this->escoparFiltroNoTenant($tenant);

        $input = DesconsiderarBatidaInput::daTela($tenant, $user, $alvo, self::cabecaDaTela($request), self::motivoDaTela($request), $autor);
        $this->tentar(static fn () => $desconsiderarBatida->executar($input), 'Batida desconsiderada. O registro original fica guardado.');

        return $this->voltarParaFicha($tenantId, $user, self::competenciaDaTela($request));
    }

    /** O "Restaurar" da linha "Desconsideradas" da ficha: a batida volta para a conta como estava. */
    #[Route('/ajuste/{ajusteId}/restaurar', name: 'app_tenant_user_ponto_restaurar', requirements: ['ajusteId' => '\d+'], methods: ['POST'])]
    public function restaurar(int $tenantId, User $user, int $ajusteId, Request $request, RestaurarBatidaUseCase $restaurarBatida): Response
    {
        [$autor, $tenant] = $this->autorNaFicha($tenantId, $user);
        $this->exigirCsrf($request, 'ponto_restaurar_' . $ajusteId);
        $this->escoparFiltroNoTenant($tenant);

        $input = new RestaurarBatidaInput($tenant, $user, $ajusteId, self::motivoDaTela($request), $autor);
        $this->tentar(static fn () => $restaurarBatida->executar($input), 'Batida restaurada.');

        return $this->voltarParaFicha($tenantId, $user, self::competenciaDaTela($request));
    }

    /**
     * Quem executa e o escritório da URL, nas guardas de sempre da ficha: logado; escritório existe; Master, ou vínculo
     * ativo com `admin.users.manage` (403). O alvo só depois de quem executa (auditoria 1.08): a pessoa sem vínculo
     * ativo neste escritório responde 404, e quem é de fora recebe 403 com ou sem vínculo do alvo.
     *
     * @return array{User, Tenant}
     */
    private function autorNaFicha(int $tenantId, User $pessoa): array
    {
        $autor = $this->getUser();
        if (!$autor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $tenant = $this->tenantRepository->find($tenantId) ?? throw $this->createNotFoundException();

        $isSuperAdmin = \in_array('ROLE_SUPER_ADMIN', $autor->getRoles(), true);
        $isOwnTenant  = $this->userTenantRepository->existeVinculoAtivo($autor, $tenant);
        if (!$isSuperAdmin && !($isOwnTenant && $this->permissionChecker->canAdminister($autor, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para corrigir batidas.');
        }

        if (!$this->userTenantRepository->existeVinculoAtivo($pessoa, $tenant)) {
            throw $this->createNotFoundException();
        }

        return [$autor, $tenant];
    }

    /** CSRF por intenção, stateful (o formulário tem `csrf_protection` desligado). */
    private function exigirCsrf(Request $request, string $intencao): void
    {
        if (!$this->isCsrfTokenValid($intencao, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
    }

    /**
     * Re-aponta o filtro de escritório para o da URL: o admin de vários escritórios e o Master sem escritório na sessão
     * não alcançam batida de outro escritório pelo id.
     */
    private function escoparFiltroNoTenant(Tenant $tenant): void
    {
        $this->entityManager->getFilters()->enable('tenant')->setParameter('tenant', (int) $tenant->getId(), Types::INTEGER);
    }

    /** P43 na página de ajuste: a própria batida, só o primário ou o Master (os UseCases conferem de novo ao gravar). */
    private function exigirQuePodeCorrigir(User $autor, User $pessoa, Tenant $tenant): void
    {
        if (!$this->analiseDaPropria->permite($autor, $pessoa, $tenant)) {
            throw $this->createAccessDeniedException(AjusteDeBatidaRecusadoException::MENSAGEM_DA_PROPRIA_BATIDA);
        }
    }

    /** O que não deixa a página abrir: a batida fora da conta, ou com a justificativa em análise (C5, já no GET). */
    private function recusaDaPagina(AlvoDoAjuste $batida): ?string
    {
        return match (true) {
            $batida->desconsiderada() => AjusteDeBatidaRecusadoException::MENSAGEM_MUDOU,
            $this->justificativas->temEmAnaliseLigadaA($batida) => AjusteDeBatidaRecusadoException::MENSAGEM_EM_ANALISE,
            default => null,
        };
    }

    /** O formulário da página de ajuste, preenchido com a forma que a folha conta agora. */
    private function formDoAjuste(AlvoDoAjuste $batida, Request $request): FormInterface
    {
        $form = $this->createForm(RegistroPontoManualType::class);
        $atual = $batida->dataHoraAtual !== null ? \DateTime::createFromImmutable($batida->dataHoraAtual) : null;
        $form->get('data')->setData($atual);
        $form->get('hora')->setData($atual !== null ? clone $atual : null);
        $form->get('tipo')->setData($batida->tipoAtual);
        $form->handleRequest($request);

        return $form;
    }

    /**
     * Grava o ajuste. Nulo quando a recusa é do que foi digitado: a página volta com o erro no campo. As outras recusas
     * e o sucesso voltam à ficha.
     *
     * @param \Closure(): mixed $gravacao
     */
    private function gravarAjuste(FormInterface $form, \Closure $gravacao, int $tenantId, User $pessoa, ?string $competencia): ?Response
    {
        try {
            $gravacao();
            $this->addFlash('success', 'Batida ajustada. O registro original fica guardado.');
        } catch (AjusteDeBatidaRecusadoException $recusa) {
            if (\array_key_exists($recusa->motivo, self::RECUSAS_DO_FORMULARIO)) {
                $campo = self::RECUSAS_DO_FORMULARIO[$recusa->motivo];
                ($campo !== null ? $form->get($campo) : $form)->addError(new FormError($recusa->getMessage()));

                return null;
            }
            $this->recusar($recusa);
        }

        return $this->voltarParaFicha($tenantId, $pessoa, $competencia);
    }

    /** @param \Closure(): mixed $acao */
    private function tentar(\Closure $acao, string $sucesso): void
    {
        try {
            $acao();
            $this->addFlash('success', $sucesso);
        } catch (AjusteDeBatidaRecusadoException $recusa) {
            $this->recusar($recusa);
        }
    }

    /** Fora desta folha: 404. A própria batida (P43): 403. As outras: o aviso na ficha. */
    private function recusar(AjusteDeBatidaRecusadoException $recusa): void
    {
        match ($recusa->motivo) {
            AjusteDeBatidaRecusadoException::NAO_ENCONTRADA => throw $this->createNotFoundException($recusa->getMessage(), $recusa),
            AjusteDeBatidaRecusadoException::DA_PROPRIA_BATIDA => throw $this->createAccessDeniedException($recusa->getMessage(), $recusa),
            default => $this->addFlash('danger', $recusa->getMessage()),
        };
    }

    private function voltarParaFicha(int $tenantId, User $pessoa, ?string $competencia): Response
    {
        return $this->redirectToRoute('app_tenant_user_edit_role', array_filter([
            'tenantId'    => $tenantId,
            'id'          => $pessoa->getId(),
            'competencia' => $competencia,
            'tab'         => 'ponto',
        ], static fn (mixed $valor): bool => $valor !== null));
    }

    /** O mês da folha de onde o gestor veio (AAAA-MM), para a ficha voltar nele; outro formato não vira endereço. */
    private static function competenciaDaTela(Request $request): ?string
    {
        $competencia = (string) ($request->request->get('competencia') ?? $request->query->get('competencia') ?? '');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $competencia) === 1 ? $competencia : null;
    }

    /** A cabeça que a folha mostrou (o campo oculto `cabeca`); vazia na batida nunca ajustada. */
    private static function cabecaDaTela(Request $request): ?int
    {
        $cabeca = (string) $request->request->get('cabeca', '');

        return preg_match('/^[1-9]\d{0,17}$/D', $cabeca) === 1 ? (int) $cabeca : null;
    }

    private static function motivoDaTela(Request $request): string
    {
        return (string) $request->request->get('motivo', '');
    }

    private static function tipoDo(FormInterface $form): string
    {
        return (string) $form->get('tipo')->getData();
    }

    private static function motivoDo(FormInterface $form): string
    {
        return (string) $form->get('motivo')->getData();
    }

    /** Data e hora digitadas, no fuso do servidor (a mesma régua da batida e do horário futuro). */
    private static function dataHoraDo(FormInterface $form): \DateTimeImmutable
    {
        /** @var \DateTimeInterface $data */
        $data = $form->get('data')->getData();
        /** @var \DateTimeInterface $hora */
        $hora = $form->get('hora')->getData();

        return new \DateTimeImmutable($data->format('Y-m-d') . ' ' . $hora->format('H:i:s'));
    }

    /** Motivo em branco tem a frase dele; o resto, o aviso de sempre. */
    private static function erroDoFormulario(FormInterface $form): string
    {
        return $form->isSubmitted() && !$form->get('motivo')->isValid() && self::soOMotivoFalhou($form)
            ? AjusteDeBatidaRecusadoException::MENSAGEM_SEM_MOTIVO
            : self::ERRO_DOS_CAMPOS;
    }

    private static function soOMotivoFalhou(FormInterface $form): bool
    {
        foreach (['data', 'hora', 'tipo'] as $campo) {
            if (!$form->get($campo)->isValid()) {
                return false;
            }
        }

        return true;
    }
}
