<?php

namespace App\Controller;

use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Armazenamento\ChavesDePonto;
use App\Ponto\Validacao\RestricoesAnexoJustificativa;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Enum\TipoJustificativa;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\SaidaDeEsquecimento;
use App\Ponto\Service\BatidaJaRegistrada;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RegraDosMasters;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Form\EditUserTenantRoleType;
use App\Profile\Entity\UserProfile;
use App\Ponto\Form\RegistroPontoManualType;
use App\Form\SedeType;
use App\Form\TenantCargosLotacoesType;
use App\Form\TenantCargosType;
use App\Form\TenantLotacoesType;
use App\Form\TenantType;
use App\Form\TenantNameType;
use App\Cliente\Repository\ClienteRepository;
use App\Pasta\Repository\PastaRepository;
use App\Ponto\Repository\FeriadoRepository;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\LancamentoHorasPagasRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\HorarioFuturoDaBatida;
use App\Ponto\UseCase\RegistrarBatidaDoEsquecimentoUseCase;
use App\Processo\Repository\ProcessoRepository;
use App\Repository\ResourceAccessRepository;
use App\Repository\SedeRepository;
use App\Repository\CargoRepository;
use App\Repository\LotacaoRepository;
use App\Repository\TenantRepository;
use App\Repository\UserTenantRepository;
use App\Repository\TenantRoleRepository;
use App\Profile\DTO\DadosPessoaisInput;
use App\Profile\Form\DadosPessoaisType;
use App\Profile\UseCase\ObterOuCriarPerfilUseCase;
use App\Shared\Http\EntregaDeArquivo;
use App\Shared\Http\FonteDeUploadHttp;
use App\Tenant\Controller\MeuEscritorioController;
use App\Tenant\DTO\RemoverColaboradorInput;
use App\Tenant\UseCase\ConsultarMeuEscritorioUseCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use App\Tenant\Service\HierarquiaDePerfis;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tenant\UseCase\RemoverColaboradorDoEscritorioUseCase;
use App\Tenant\Exception\PedidoDeExclusaoRecusado;
use App\Tenant\UseCase\ExcluirEscritorioUseCase;
use App\Tenant\UseCase\GerarCodigoFuncionario;
use App\Service\InvitationService;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Ponto\Service\CalculadoraJornada;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\InicioContagemResolver;
use App\Service\TenantBootstrapService;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/tenant')]
final class TenantController extends AbstractController
{
    public function __construct(
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly TransacaoComArquivoNovo $transacao,
        private readonly EntregaDeArquivo $entrega,
        private readonly TenantContext $tenantContext,
        private readonly InicioContagemResolver $inicioContagemResolver,
        private readonly TranslatorInterface $tradutor,
        private readonly HorarioFuturoDaBatida $horarioFuturo,
        private readonly BatidaJaRegistrada $batidaJaRegistrada,
    ) {}

    /** O sucesso do "aprovar todos"; com todos pulados (batida já registrada), só os avisos deles ficam. */
    private function avisarAbonadas(int $abonadas): void
    {
        if ($abonadas > 0) {
            $this->addFlash('success', sprintf('%d justificativa(s) abonada(s) com sucesso.', $abonadas));
        }
    }

    /**
     * Tira do "aprovar todos" o esquecimento cuja batida já existe no dia (spec `ponto-esquecimento-nao-trava.md` R1):
     * ele fica pendente, para o admin decidir, e o motivo vai num aviso.
     *
     * @param list<JustificativaPonto> $pendentes
     *
     * @return list<JustificativaPonto>
     */
    private function semBatidaJaRegistrada(array $pendentes, Tenant $tenant, RegistrarBatidaDoEsquecimentoUseCase $registrar): array
    {
        $seguem = [];
        foreach ($pendentes as $justificativa) {
            if (($recusa = $registrar->recusa($justificativa, $tenant)) !== null) {
                $this->addFlash('warning', $recusa);
                continue;
            }
            $seguem[] = $justificativa;
        }

        return $seguem;
    }

    /**
     * O esquecimento só vale para a batida que falta (spec `ponto-esquecimento-nao-trava.md` R1): a recusa quando a
     * pessoa já tem, num dos dias, uma batida do tipo pedido neste escritório; null quando segue ou não é esquecimento.
     *
     * @param iterable<\DateTimeInterface|null> $dias
     */
    private function esquecimentoJaBatido(User $pessoa, Tenant $tenant, ?string $tipo, ?string $tipoRegistro, iterable $dias): ?string
    {
        if ($tipo !== 'esquecimento_registro' || $tipoRegistro === null || $tipoRegistro === '') {
            return null;
        }
        foreach ($dias as $dia) {
            if ($dia !== null && ($recusa = $this->batidaJaRegistrada->mensagem($pessoa, $tenant, $tipoRegistro, $dia)) !== null) {
                return $recusa;
            }
        }

        return null;
    }

    #[Route(name: 'app_tenant_index', methods: ['GET'])]
    public function index(TenantRepository $tenantRepository, PermissionChecker $permissionChecker): Response
    {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException('Você precisa estar logado.');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            $tenants = $tenantRepository->findAll();
        } elseif ($permissionChecker->canAdminister($user, $tenant, 'admin.tenant.settings.manage')) {
            $tenants = [$tenant];
        } else {
            throw $this->createAccessDeniedException('Você não tem permissão para acessar Tenants.');
        }

        return $this->render('tenant/index.html.twig', [
            'tenants' => $tenants,
        ]);
    }

    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[Route('/new', name: 'app_tenant_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        InvitationService $invitationService,
        TenantBootstrapService $tenantBootstrap
    ): Response {
        $tenant = new Tenant();
        $form = $this->createForm(TenantType::class, $tenant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $adminEmail = mb_strtolower(trim((string) $form->get('adminEmail')->getData()));
            $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $adminEmail]);

            if ($existingUser) {
                $form->get('adminEmail')->addError(new FormError($this->tradutor->trans('escritorio.flash.email_em_uso')));

                return $this->render('tenant/new.html.twig', [
                    'tenant' => $tenant,
                    'form' => $form,
                ]);
            }

            $entityManager->persist($tenant);
            $entityManager->flush();

            $adminUser = new User();
            $adminUser->setEmail($adminEmail);
            $adminUser->setRoles(['ROLE_USER']);
            $adminUser->setFullName('Administrador do Tenant');

            // Bootstrap: cria perfil "Administrador do Escritório" e vincula o criador
            $tenantBootstrap->bootstrap($tenant, $adminUser);

            $result = $invitationService->sendInvitation($adminUser, 'Confirme seu acesso ao Tenant');

            if ($result['duplicateEmail']) {
                $entityManager->remove($tenant);
                $entityManager->flush();

                $form->get('adminEmail')->addError(new FormError($this->tradutor->trans('escritorio.flash.email_cadastrado')));

                return $this->render('tenant/new.html.twig', [
                    'tenant' => $tenant,
                    'form' => $form,
                ]);
            }

            if (!$result['sent']) {
                $this->addFlash('warning', $this->tradutor->trans('escritorio.flash.criado_sem_email'));

                if ($this->getParameter('kernel.environment') === 'dev') {
                    $this->addFlash('info', $this->tradutor->trans('escritorio.flash.link_dev', ['link' => $result['link']]));
                }

                return $this->redirectToRoute('app_tenant_index', [], Response::HTTP_SEE_OTHER);
            }

            $this->addFlash('success', $this->tradutor->trans('escritorio.flash.criado'));

            return $this->redirectToRoute('app_tenant_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('tenant/new.html.twig', [
            'tenant' => $tenant,
            'form' => $form,
        ]);
    }

    #[Route('/{tenantId}', name: 'app_tenant_show', methods: ['GET'])]
    public function show(
        #[MapEntity(id: 'tenantId')] Tenant $tenant,
        PermissionChecker $permissionChecker,
        UserTenantRepository $userTenantRepository,
        ConsultarMeuEscritorioUseCase $consultarMeuEscritorio,
        CsrfTokenManagerInterface $csrf,
    ): Response {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
        $isOwnTenant  = $userTenantRepository->existeVinculoAtivo($user, $tenant);

        if (!($isSuperAdmin || ($isOwnTenant && $permissionChecker->canAdminister($user, $tenant, 'admin.tenant.settings.manage')))) {
            throw $this->createAccessDeniedException('Você não tem permissão para ver este Tenant.');
        }

        // Meu Escritório (spec meu-escritorio.md). O Drive é da conexão do escritório da SESSÃO: os endereços só vão a
        // quem pode usá-los (quem edita e administra o escritório que está aberto).
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $daSessao = $this->tenantContext->getCurrentTenant()?->getId() === $tenant->getId();
        $escritorio = $consultarMeuEscritorio->executar($user, $tenant, $daSessao);
        $podeUsarODrive = $escritorio->podeEditar && $daSessao && $permissionChecker->canAdminister($user, $tenant, 'admin.tenant.settings.manage');

        return $this->render('tenant/meu_escritorio.html.twig', [
            'tenant' => $tenant,
            'escritorio' => $escritorio,
            'csrf' => $csrf->getToken(MeuEscritorioController::CSRF)->getValue(),
            'urlDriveConectar' => $podeUsarODrive ? $this->generateUrl('app_sync_drive_iniciar') : null,
            'urlDriveGerenciar' => $podeUsarODrive ? $this->generateUrl('app_sync_drive_conexao') : null,
        ]);
    }

    #[Route('/{tenantId}/edit', name: 'app_tenant_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        #[MapEntity(id: 'tenantId')] Tenant $tenant,
        EntityManagerInterface $entityManager,
        PermissionChecker $permissionChecker,
        UserTenantRepository $userTenantRepository
    ): Response {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
        $isOwnTenant  = $userTenantRepository->existeVinculoAtivo($user, $tenant);

        if (!($isSuperAdmin || ($isOwnTenant && $permissionChecker->canAdminister($user, $tenant, 'admin.tenant.settings.manage')))) {
            throw $this->createAccessDeniedException('Você não tem permissão para editar este Tenant.');
        }

        // Form para editar nome
        $nameForm = $this->createForm(TenantNameType::class, $tenant);
        $nameForm->handleRequest($request);
        if ($nameForm->isSubmitted() && $nameForm->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', $this->tradutor->trans('escritorio.flash.nome_atualizado'));
            return $this->redirectToRoute('app_tenant_edit', ['tenantId' => $tenant->getId()]);
        }

        // Form para gerenciar cargos
        $cargosForm = $this->createForm(TenantCargosType::class, $tenant);
        $cargosForm->handleRequest($request);
        if ($cargosForm->isSubmitted() && $cargosForm->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', $this->tradutor->trans('escritorio.flash.cargos_atualizados'));
            return $this->redirectToRoute('app_tenant_edit', ['tenantId' => $tenant->getId(), '_fragment' => 'tab-cargos']);
        }

        // Form para gerenciar lotações
        $lotacoesForm = $this->createForm(TenantLotacoesType::class, $tenant);
        $lotacoesForm->handleRequest($request);
        if ($lotacoesForm->isSubmitted() && $lotacoesForm->isValid()) {
            $lotacoesNoForm = [];
            foreach ($lotacoesForm->get('lotacoes')->all() as $childForm) {
                /** @var \App\Entity\Tenant\Lotacao $lotacao */
                $lotacao = $childForm->getData();
                if ($lotacao->getId() === null) {
                    $lotacao->setTenant($tenant);
                    $entityManager->persist($lotacao);
                }
                if ($lotacao->getId() !== null) {
                    $lotacoesNoForm[] = $lotacao->getId();
                }
            }
            foreach ($tenant->getLotacoes() as $lotacaoExistente) {
                if (!in_array($lotacaoExistente->getId(), $lotacoesNoForm, true)) {
                    $entityManager->remove($lotacaoExistente);
                }
            }
            $entityManager->flush();
            $this->addFlash('success', $this->tradutor->trans('escritorio.flash.lotacoes_atualizadas'));
            return $this->redirectToRoute('app_tenant_edit', ['tenantId' => $tenant->getId(), '_fragment' => 'tab-lotacoes']);
        }

        return $this->render('tenant/edit.html.twig', [
            'tenant'        => $tenant,
            'nameForm'      => $nameForm->createView(),
            'cargosForm'    => $cargosForm->createView(),
            'lotacoesForm'  => $lotacoesForm->createView(),
        ]);
    }

    #[Route('/{tenantId}', name: 'app_tenant_delete', methods: ['POST'])]
    public function delete(Request $request, #[MapEntity(id: 'tenantId')] Tenant $tenant, ExcluirEscritorioUseCase $excluirEscritorio, RegraDosMasters $regraDosMasters): Response
    {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        if (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            throw $this->createAccessDeniedException('Somente SUPER_ADMIN pode excluir Tenants.');
        }

        // Conta criada por um Master não é excluída (spec perfis-1.2-contas C5 e Q4).
        $recusa = $regraDosMasters->recusaParaExcluir($tenant);
        if ($recusa !== null) {
            $this->addFlash('danger', $recusa);

            return $this->redirectToRoute('app_tenant_index', [], Response::HTTP_SEE_OTHER);
        }

        // RS08: exclusão é soft delete (quarentena), nunca mais remove() destrutivo.
        if ($this->isCsrfTokenValid('delete'.$tenant->getId(), $request->request->get('_token'))) {
            $ator = $this->getUser();
            if ($ator instanceof User) {
                try {
                    $excluirEscritorio->executar($tenant, $ator);
                } catch (PedidoDeExclusaoRecusado $e) {
                    // Pedido decidido ao mesmo tempo pela desistência do primário (409).
                    $this->addFlash('danger', $e->getMessage());

                    return $this->redirectToRoute('app_tenant_index', [], Response::HTTP_SEE_OTHER);
                }
            }
        }

        return $this->redirectToRoute('app_tenant_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{tenantId}/users', name: 'app_tenant_users', methods: ['GET'])]
    public function listUsers(
        #[MapEntity(id: 'tenantId')] Tenant $tenant,
        PermissionChecker $permissionChecker,
        ResourceAccessRepository $resourceAccessRepository,
        ClienteRepository $clienteRepository,
        PastaRepository $pastaRepository,
        ProcessoRepository $processoRepository,
        UserTenantRepository $userTenantRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
        $isOwnTenant  = $userTenantRepository->existeVinculoAtivo($user, $tenant);

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($user, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Você não tem permissão para ver os usuários deste Tenant.');
        }

        // Remendo (B5 frente 3): re-aponta o filtro p/ o tenant da URL antes de resolver os labels de
        // recursos TenantAware (cliente/pasta/processo) em resolveResourceLabels. É o cinto da trava
        // automática (TenantUrlScopeListener): se o listener for desativado, o super-admin sem tenant
        // na sessão ainda não vê o label de um recurso de outro escritório.
        $this->escoparFiltroNoTenant($entityManager, $tenant);

        // A lista mostra só quem é colaborador de verdade: vínculo existe = colaborador
        // (spec §3.4/§10). Com o hard delete de RemoverColaboradorDoEscritorioUseCase, um
        // vínculo inativo é sempre legado (as 3 linhas de D8) — nunca alguém que ainda está
        // no escritório com um selo "Desligado".
        $vinculos = $userTenantRepository->findBy(['tenant' => $tenant, 'isActive' => true]);
        $users    = array_map(fn($vt) => $vt->getUser(), $vinculos);
        $userTenantByUserId = [];
        foreach ($vinculos as $vt) {
            $userTenantByUserId[$vt->getUser()->getId()] = $vt;
        }

        // Busca todos os ResourceAccess dos usuários do tenant em uma query só
        $accessByUser = $resourceAccessRepository->findByUsers($users);

        // Resolve labels dos recursos referenciados (sem N+1)
        $resourceLabels = $this->resolveResourceLabels($accessByUser, $clienteRepository, $pastaRepository, $processoRepository);

        return $this->render('tenant/users.html.twig', [
            'tenant'             => $tenant,
            'users'              => $users,
            'userTenantByUserId' => $userTenantByUserId,
            'accessByUser'       => $accessByUser,
            'resourceLabels'     => $resourceLabels,
        ]);
    }

    /**
     * Resolve os labels exibíveis de cada recurso referenciado nos acessos.
     * Faz no máximo 3 queries (uma por tipo: cliente, pasta, processo).
     *
     * @param  array<int, \App\Entity\Permission\ResourceAccess[]> $accessByUser
     * @return array<string, array<int, string>>  ['cliente' => [id => label], ...]
     */
    private function resolveResourceLabels(
        array $accessByUser,
        ClienteRepository $clienteRepository,
        PastaRepository $pastaRepository,
        ProcessoRepository $processoRepository,
    ): array {
        $idsByType = ['cliente' => [], 'pasta' => [], 'processo' => []];

        foreach ($accessByUser as $rows) {
            foreach ($rows as $ra) {
                $type = $ra->getResourceType();
                $id   = $ra->getResourceId();
                if (isset($idsByType[$type]) && $id !== null) {
                    $idsByType[$type][$id] = true;
                }
            }
        }

        $labels = ['cliente' => [], 'pasta' => [], 'processo' => []];

        foreach (array_keys($idsByType['cliente']) as $id) {
            $entity = $clienteRepository->find($id);
            $labels['cliente'][$id] = $entity?->getNomeExibicao() ?? "Cliente #$id";
        }

        foreach (array_keys($idsByType['pasta']) as $id) {
            $entity = $pastaRepository->find($id);
            $labels['pasta'][$id] = $entity?->getNup() ?? "Pasta #$id";
        }

        foreach (array_keys($idsByType['processo']) as $id) {
            $entity = $processoRepository->find($id);
            $labels['processo'][$id] = $entity?->getNumeroProcesso() ?? "Processo #$id";
        }

        return $labels;
    }

    /**
     * @return array{cargaHorariaSemanal: int, fonte: string}|null
     */
    private function resolverJornadaInfoAdmin(?JornadaColaborador $jornada, ?\App\Ponto\Entity\JornadaTenant $jornadaTenant): ?array
    {
        if ($jornada !== null && !$jornada->getBlocos()->isEmpty()) {
            $carga = $jornada->getCargaHorariaSemanal()
                ?? $jornadaTenant?->getCargaHorariaSemanal()
                ?? 0;
            return ['cargaHorariaSemanal' => $carga, 'fonte' => 'personalizada'];
        }

        if ($jornadaTenant !== null && !$jornadaTenant->getBlocos()->isEmpty()) {
            return ['cargaHorariaSemanal' => $jornadaTenant->getCargaHorariaSemanal(), 'fonte' => 'escritorio'];
        }

        return null;
    }

    /**
     * Re-aponta o TenantFilter para o tenant da URL ({tenantId}), independentemente do tenant da
     * sessão. As rotas admin de ponto operam sobre o escritório da URL; sem isso, um admin
     * multi-tenant veria a folha do tenant da sessão e o super-admin (sem tenant na sessão → filtro
     * off) teria IDOR cross-tenant nos find() por id. Chamar SEMPRE após o guard de acesso ao tenant.
     */
    private function escoparFiltroNoTenant(EntityManagerInterface $em, Tenant $tenant): void
    {
        $em->getFilters()->enable('tenant')->setParameter('tenant', (int) $tenant->getId(), Types::INTEGER);
    }

    #[Route('/{tenantId}/user/{id}/edit-role', name: 'app_tenant_user_edit_role', methods: ['GET','POST'])]
    public function editUserRole(
        int $tenantId,
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        TenantRoleRepository $tenantRoleRepository,
        ResourceAccessRepository $resourceAccessRepository,
        ClienteRepository $clienteRepository,
        PastaRepository $pastaRepository,
        ProcessoRepository $processoRepository,
        RegistroPontoRepository $registroPontoRepository,
        FeriadoRepository $feriadoRepository,
        JustificativaPontoRepository $justificativaRepository,
        LancamentoHorasPagasRepository $lancamentoHorasPagasRepository,
        PermissionChecker $permissionChecker,
        FolhaPontoBuilder $folhaPontoBuilder,
        CargoRepository $cargoRepository,
        LotacaoRepository $lotacaoRepository,
        GerarCodigoFuncionario $gerarCodigo,
        ObterOuCriarPerfilUseCase $obterOuCriarPerfil,
        TenantRepository $tenantRepository,
        UserTenantRepository $userTenantRepository,
        RegraDosPrimarios $regraDosPrimarios,
        HierarquiaDePerfis $hierarquia,
        JornadaColaboradorRepository $jornadaColaboradorRepository
    ): Response {
        $currentUser = $this->getUser();

        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $tenant = $tenantRepository->find($tenantId);
        if (!$tenant) {
            throw $this->createNotFoundException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        $isOwnTenant  = $userTenantRepository->existeVinculoAtivo($currentUser, $tenant);

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Você não tem permissão para editar perfis de usuário.');
        }

        $userTenant = $userTenantRepository->findAtivoPorUserETenant($user, $tenant);
        if ($userTenant === null) {
            throw $this->createNotFoundException();
        }

        // A folha e os recursos exibidos são do escritório da URL, não do tenant da sessão.
        $this->escoparFiltroNoTenant($entityManager, $tenant);

        $tenantRoles = $tenantRoleRepository->findByTenantId($tenantId);
        $cargos      = $cargoRepository->findBy(['tenant' => $tenant], ['nome' => 'ASC']);
        $lotacoes    = $lotacaoRepository->findBy(['tenant' => $tenant], ['nome' => 'ASC']);

        if ($userTenant->getCodigoFuncionario() === null) {
            $userTenant->setCodigoFuncionario($gerarCodigo->executar($tenant));
        }

        // Perfil ANTES do submit: o form escreve direto no vínculo, e a regra dos primários
        // precisa saber se a troca põe ou tira alguém do perfil de sistema.
        $perfilAnterior = $userTenant->getTenantRole();

        // Spec perfis-master D4 (RegraDosPrimarios): só primário ou Master dá ou tira o perfil de
        // sistema e troca senha de primário. Para os demais o perfil de sistema nem é opção; se o
        // alvo já é primário, o único perfil oferecido é o atual (não dá para rebaixar).
        $podeGerirPrimarios = $regraDosPrimarios->podeGerirPrimarios($currentUser, $tenant);
        $podeTrocarSenha    = $hierarquia->podeTrocarSenha($currentUser, $tenant, $userTenant); // RegraDosPrimarios + C5 + C8
        // Conta do Master: o perfil dele só ele mesmo troca (A1). O único perfil oferecido é o atual.
        $podeAlterarConta   = $regraDosPrimarios->podeAlterarConta($currentUser, $user);
        if (!$podeAlterarConta && $perfilAnterior !== null) {
            $tenantRoles = [$perfilAnterior];
        } elseif (!$podeGerirPrimarios) {
            $tenantRoles = $perfilAnterior?->isSystem() === true
                ? [$perfilAnterior]
                : array_values(array_filter($tenantRoles, static fn ($r): bool => !$r->isSystem()));
        }
        $tenantRoles = $hierarquia->perfisAtribuiveis($currentUser, $tenant, $user, $tenantRoles, $perfilAnterior); // C5: subconjunto e próprio perfil

        $form = $this->createForm(EditUserTenantRoleType::class, $userTenant, [
            'tenant_roles'    => $tenantRoles,
            'tenant_cargos'   => $cargos,
            'tenant_lotacoes' => $lotacoes,
            'data_admissao'   => $userTenant->getDataAdmissao(),
            'pode_marcar_controladoria' => $podeGerirPrimarios,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $eraPrimario  = $perfilAnterior?->isSystem() === true;
            $seraPrimario = $userTenant->getTenantRole()?->isSystem() === true;
            $erroDePerfil = null;

            if (!$podeAlterarConta && $userTenant->getTenantRole() !== $perfilAnterior) {
                $erroDePerfil = RegraDosPrimarios::RECUSA_CONTA_DO_MASTER;
            } elseif ($eraPrimario !== $seraPrimario && !$podeGerirPrimarios) {
                // Cinto do select: um POST forjado com o id do perfil de sistema cai aqui.
                $erroDePerfil = RegraDosPrimarios::RECUSA_GERIR_PRIMARIOS;
            } elseif (($recusaDaHierarquia = $hierarquia->recusaAoAtribuir($currentUser, $tenant, $user, $perfilAnterior, $userTenant->getTenantRole())) !== null) {
                $erroDePerfil = $recusaDaHierarquia; // C5: cinto do select, para o POST forjado
            } elseif ($eraPrimario && !$seraPrimario) {
                // Spec §5 E1: esta porta não rebaixa o titular nem o último primário.
                try {
                    $regraDosPrimarios->garantirQuePodeDeixarDeSerPrimario($userTenant);
                } catch (OperacaoDePrimarioRecusada $e) {
                    $erroDePerfil = $e->getMessage();
                }
            }

            if ($erroDePerfil !== null) {
                $form->get('tenantRole')->addError(new FormError($this->traduzirRecusa($erroDePerfil)));
                // Desfaz a troca em memória: mais abaixo o ObterOuCriarPerfilUseCase pode dar
                // flush (perfil pessoal ausente) e levaria a troca recusada junto.
                $userTenant->setTenantRole($perfilAnterior);
            }

            $senhaPedida = $form->get('newPassword')->getData();
            if (is_string($senhaPedida) && trim($senhaPedida) !== '' && !$podeTrocarSenha) {
                $form->get('newPassword')->addError(new FormError($this->traduzirRecusa(
                    !$podeAlterarConta ? RegraDosPrimarios::RECUSA_CONTA_DO_MASTER : (!$regraDosPrimarios->podeTrocarSenhaDe($currentUser, $userTenant)
                        ? RegraDosPrimarios::RECUSA_SENHA_DE_PRIMARIO : (string) $hierarquia->recusaDaSenha($currentUser, $tenant, $userTenant))
                )));
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = $form->get('newPassword')->getData();

            if (is_string($newPassword) && trim($newPassword) !== '') {
                $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
            }

            $userTenant->setDataAdmissao($form->get('dataAdmissao')->getData());

            $entityManager->flush();
            $this->addFlash('success', $this->tradutor->trans('escritorio.flash.usuario_atualizado'));
            return $this->redirectToRoute('app_tenant_users', ['tenantId' => $tenantId]);
        }

        $jornada                  = $jornadaColaboradorRepository->findByUserETenant($user, $tenant);
        $userHasCustomEscala      = $jornada !== null && !$jornada->getBlocos()->isEmpty();

        $userAccesses   = $resourceAccessRepository->findByUsers([$user])[(int) $user->getId()] ?? [];
        $resourceLabels = $this->resolveResourceLabels(
            [(int) $user->getId() => $userAccesses],
            $clienteRepository,
            $pastaRepository,
            $processoRepository,
        );

        $competenciasComRegistro = $registroPontoRepository->findCompetenciasComRegistroPorUsuario($user, $tenant);
        // Lista de competências que REALMENTE têm batida — decide, mais abaixo, se a tabela é
        // montada. Guardada antes de qualquer injeção/mescla para não virar um proxy falso.
        $competenciasComBatida = array_column($competenciasComRegistro, 'valor');

        // O seletor só listava meses com BATIDA. Dois lançamentos de horas pagas ficavam
        // inalcançáveis pelo dropdown (só editando a URL na mão): o do mês corrente sem nenhuma
        // batida ainda, e qualquer lançamento num mês PASSADO sem batida nenhuma (ex.: ajuste
        // retroativo). `listarCompetenciasComLancamento` cobre o segundo caso; o mês corrente cobre
        // o primeiro. A lista de opções mescla as três fontes, sem duplicar, mais recente primeiro —
        // mesma ordem que o `PontoController::index()` usa (mês corrente sempre no topo).
        // `$competenciasComBatida` (acima) fica intocada: só ela decide se a tabela é montada.
        $competenciasParaSelecao = [];
        foreach ($competenciasComRegistro as $c) {
            $competenciasParaSelecao[$c['valor']] = $c['label'];
        }
        $competenciasComLancamento = $lancamentoHorasPagasRepository->listarCompetenciasComLancamento($user, $tenant);
        foreach ($competenciasComLancamento as $valorLancamento) {
            if (!isset($competenciasParaSelecao[$valorLancamento])) {
                [$anoLancamento, $mesLancamento] = array_map('intval', explode('-', $valorLancamento));
                $competenciasParaSelecao[$valorLancamento] = sprintf('%02d/%04d', $mesLancamento, $anoLancamento);
            }
        }
        $competenciaAtualPonto = (new \DateTimeImmutable())->format('Y-m');
        if (!isset($competenciasParaSelecao[$competenciaAtualPonto])) {
            $competenciasParaSelecao[$competenciaAtualPonto] = (new \DateTimeImmutable())->format('m/Y');
        }
        krsort($competenciasParaSelecao);

        $competenciasPonto = [];
        foreach ($competenciasParaSelecao as $valor => $label) {
            $competenciasPonto[] = ['valor' => $valor, 'label' => $label];
        }

        // Mês em que a ficha ABRE quando não vem `?competencia=` na URL: o mais recente COM DADO —
        // batida OU lançamento de horas pagas. NÃO pode sair de `$competenciasPonto[0]`: aquela é a
        // lista de OPÇÕES, que recebe o mês corrente injetado só para ficar selecionável e, depois do
        // `krsort`, quase sempre o deixa no índice 0. Tirar o padrão de lá fazia a ficha de quem tem a
        // última batida em 03/2026 abrir em julho, vazia ("Não há batidas para a competência
        // selecionada") — atinge afastado, férias, desligado e todo dia 1º antes da primeira batida.
        // O mês corrente só vira padrão quando ele PRÓPRIO é o mais recente com dado, ou quando não há
        // dado nenhum (colaborador recém-cadastrado).
        $competenciasComDado = array_unique(array_merge($competenciasComBatida, $competenciasComLancamento));
        rsort($competenciasComDado); // 'YYYY-MM' zero-padded: ordem lexicográfica == cronológica
        $competenciaPadraoPonto = $competenciasComDado[0] ?? $competenciaAtualPonto;

        $competenciaSelecionada = (string) $request->query->get('competencia', '');
        if ($competenciaSelecionada === '') {
            $competenciaSelecionada = $competenciaPadraoPonto;
        }

        // Só o FORMATO é validado (não mais o pertencimento à lista): o cálculo de horas pagas é
        // seguro para qualquer ano/mês (devolve zero) e a tabela fica vazia sem erro para uma
        // competência sem batida. Exigir pertencimento fazia a ficha SALTAR de mês sozinha ao
        // excluir a última batida de uma competência (ela some da lista de "com batida" e o
        // `in_array` rejeitava o valor que o próprio redirect da exclusão mandava de volta).
        // Formato inválido (ex.: "julho", vindo de querystring adulterada) não pode chegar ao
        // `explode` abaixo: sem hífen, o destructuring deixa o mês `null` e estoura TypeError em
        // `somarHorasPagasDaCompetencia`. Mesma validação de formato que o
        // `PontoController::index()` já faz antes de destructurar.
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $competenciaSelecionada)) {
            $competenciaSelecionada = $competenciaAtualPonto;
        }

        $jornadaTenant = $tenant->getJornadaTenant();

        $folhaRowsPonto = [];
        [$anoSelecionado, $mesSelecionado] = array_map('intval', explode('-', $competenciaSelecionada));

        // Estas duas variáveis alimentam SÓ o título do card ("Batidas de ponto - MM/AAAA", montado
        // em tenant/edit_user_role.html.twig:274-279 — é o único consumidor no projeto). Eram
        // preenchidas apenas dentro do `if` de "competência com batida"; agora que a ficha pode abrir
        // num mês com LANÇAMENTO e sem batida nenhuma (rescisão, afastamento), o card saía como
        // "Batidas de ponto", sem mês, com a linha "Horas pagas -10h00m" logo abaixo — dinheiro
        // exibido sob um card que não diz de que competência é. A competência selecionada é conhecida
        // sempre, então o título passa a nomeá-la sempre.
        $mesCompetenciaPonto = $mesSelecionado;
        $anoCompetenciaPonto = $anoSelecionado;

        $horasPagasMinutosPonto = $folhaPontoBuilder->somarHorasPagasDaCompetencia($user, $tenant, $anoSelecionado, $mesSelecionado);

        if (in_array($competenciaSelecionada, $competenciasComBatida, true)) {
            $batidasPonto = $registroPontoRepository->findByUserAndCompetencia($user, $anoSelecionado, $mesSelecionado);

            $inicioMes = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $anoSelecionado, $mesSelecionado));
            $fimMes = $inicioMes->modify('last day of this month')->setTime(23, 59, 59);
            $feriados = $feriadoRepository->findByTenant($tenant);
            $justificativasDoMes = $justificativaRepository->findByUserAndCompetenciaIndexed($user, $anoSelecionado, $mesSelecionado);
            $inicioContagemPonto = $this->inicioContagemResolver->resolver($user, $tenant);
            $folhaRowsPonto = $folhaPontoBuilder->buildRows($inicioMes, $fimMes, $batidasPonto, true, false, $jornada, $feriados, $justificativasDoMes, $jornadaTenant, $inicioContagemPonto);
        }

        $jornadaInfoUsuario = $this->resolverJornadaInfoAdmin($jornada, $jornadaTenant);
        $justificativas = $justificativaRepository->findByTenantUser($user);

        $perfil            = $obterOuCriarPerfil->executar($user);
        $inputDados        = new DadosPessoaisInput();
        $inputDados->nomeCompleto    = $perfil->getNomeCompleto();
        $inputDados->cpf             = $perfil->getCpf();
        $inputDados->dataNascimento  = $perfil->getDataNascimento();
        $inputDados->ctps            = $perfil->getCtps();
        $inputDados->serie           = $perfil->getSerie();
        $formDadosPessoais = $this->createForm(DadosPessoaisType::class, $inputDados);

        $colegasVinculos = $userTenantRepository->findBy(['tenant' => $tenant, 'isActive' => true]);
        $colegas = array_values(array_filter(
            $colegasVinculos,
            static fn (UserTenant $ut) => $ut->getUser()->getId() !== $user->getId()
        ));

        return $this->render('tenant/edit_user_role.html.twig', [
            'form'                   => $form->createView(),
            'user'                   => $user,
            'tenantId'               => $tenantId,
            'userHasCustomEscala'    => $userHasCustomEscala,
            'registraPonto'          => $userTenant->isRegistraPonto(),
            'userAccesses'           => $userAccesses,
            'resourceLabels'         => $resourceLabels,
            'competenciasPonto'      => $competenciasPonto,
            'competenciaSelecionada' => $competenciaSelecionada,
            'folhaRowsPonto'         => $folhaRowsPonto,
            'mesCompetenciaPonto'    => $mesCompetenciaPonto,
            'anoCompetenciaPonto'    => $anoCompetenciaPonto,
            'horasPagasMinutosPonto' => $horasPagasMinutosPonto,
            // Bloco de totais do rodapé da folha (spec §7). O partial é o MESMO do /ponto do
            // colaborador: toda variável nova tem de sair dos DOIS renders, senão o outro quebra.
            // `$folhaRowsPonto` é `[]` em competência sem batida — o helper devolve null e o rodapé
            // trata como saldo do mês zerado.
            'saldoMesMinutosPonto'   => $folhaPontoBuilder->saldoAcumuladoFinal($folhaRowsPonto),
            'jornadaInfo'            => $jornadaInfoUsuario,
            'justificativas'         => $justificativas,
            'tiposJustificativa'     => TipoJustificativa::asPlanarChoices(),
            'lancamentosHorasPagas'  => $lancamentoHorasPagasRepository->listarPorUser($user, $tenant),
            'colegas'                => $colegas,
            'formDadosPessoais'      => $formDadosPessoais->createView(),
        ]);
    }

    #[Route('/{tenantId}/user/{id}/edit-name', name: 'app_tenant_user_edit_name', methods: ['POST'])]
    public function editUserName(
        int $tenantId,
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
        PermissionChecker $permissionChecker,
        TenantRepository $tenantRepository,
        UserTenantRepository $userTenantRepository,
        RegraDosPrimarios $regraDosPrimarios
    ): Response {
        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $tenant = $tenantRepository->find($tenantId);
        if (!$tenant) {
            throw $this->createNotFoundException();
        }

        if (!$userTenantRepository->existeVinculoAtivo($user, $tenant)) {
            throw $this->createNotFoundException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        $isOwnTenant  = $userTenantRepository->existeVinculoAtivo($currentUser, $tenant);

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Você não tem permissão para editar nomes de usuário.');
        }

        if (!$regraDosPrimarios->podeAlterarConta($currentUser, $user)) {
            throw $this->createAccessDeniedException('A conta do Master só pode ser alterada pelo próprio Master.');
        }

        if (!$this->isCsrfTokenValid('edit_user_name_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->tradutor->trans('escritorio.flash.token_invalido'));
            return $this->redirectToRoute('app_tenant_user_edit_role', ['tenantId' => $tenantId, 'id' => $user->getId()]);
        }

        $novoNome = trim($request->request->get('fullName', ''));

        if ($novoNome === '') {
            $this->addFlash('error', $this->tradutor->trans('escritorio.flash.nome_vazio'));
            return $this->redirectToRoute('app_tenant_user_edit_role', ['tenantId' => $tenantId, 'id' => $user->getId()]);
        }

        $user->setFullName($novoNome);
        $entityManager->flush();
        $this->addFlash('success', $this->tradutor->trans('escritorio.flash.nome_do_usuario_atualizado'));

        return $this->redirectToRoute('app_tenant_user_edit_role', ['tenantId' => $tenantId, 'id' => $user->getId()]);
    }

    /**
     * Idioma (spec idioma-do-sistema §27): a recusa da HierarquiaDePerfis ou da RegraDosPrimarios continua sendo o
     * português da constante (o que as regras devolvem e os testes comparam) e vai à tela pela chave dela. A que não
     * tem chave (as próprias do RemoverColaboradorDoEscritorioUseCase) sai como veio.
     */
    private function traduzirRecusa(string $recusa): string
    {
        $chave = HierarquiaDePerfis::CHAVE_DA_RECUSA[$recusa] ?? RegraDosPrimarios::CHAVE_DA_RECUSA[$recusa] ?? null;

        return $chave === null ? $recusa : $this->tradutor->trans($chave);
    }

    /**
     * C5 (spec perfis-1.2-permissoes-e-hierarquia): quem não é primário nem Master não remove do escritório quem tem
     * o perfil atual fora do subconjunto do dele. Devolve a volta com a recusa, ou nulo quando pode.
     */
    private function recusaDaHierarquiaNaRemocao(
        mixed $executor,
        Tenant $tenant,
        User $colaborador,
        HierarquiaDePerfis $hierarquia,
        UserTenantRepository $userTenantRepository
    ): ?Response {
        $perfilDoAlvo = $userTenantRepository->findAtivoPorUserETenant($colaborador, $tenant)?->getTenantRole();
        if ($executor instanceof User && $hierarquia->podeMexerNoAlvo($executor, $tenant, $perfilDoAlvo)) {
            return null;
        }
        $this->addFlash('danger', $this->traduzirRecusa(HierarquiaDePerfis::RECUSA_ALVO_ACIMA_DO_SEU));

        return $this->redirectToRoute('app_tenant_users', ['tenantId' => $tenant->getId()]);
    }

    #[Route('/{tenantId}/user/{userId}/remover', name: 'app_tenant_user_remover', methods: ['POST'])]
    public function removerColaborador(
        int $tenantId,
        int $userId,
        Request $request,
        RemoverColaboradorDoEscritorioUseCase $useCase,
        PermissionChecker $permissionChecker,
        EntityManagerInterface $em,
        TenantRepository $tenantRepository,
        UserTenantRepository $userTenantRepository,
        HierarquiaDePerfis $hierarquia,
    ): Response {
        $executor = $this->getUser();

        if (!$executor) {
            throw $this->createAccessDeniedException();
        }

        // B-route: o tenant vem da URL e é resolvido pelo repositório, nunca da sessão.
        $tenant = $tenantRepository->find($tenantId);
        if (!$tenant) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('remover_' . $userId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Guard do alvo antes do guard do executor: o colaborador precisa ter vínculo
        // ativo com o tenant da rota para sequer existir aos olhos desta ação.
        $colaborador = $em->find(User::class, $userId);
        if (!$colaborador || !$userTenantRepository->existeVinculoAtivo($colaborador, $tenant)) {
            throw $this->createNotFoundException('Colaborador não encontrado.');
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $executor->getRoles(), true);
        $isOwnTenant  = $userTenantRepository->existeVinculoAtivo($executor, $tenant);

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($executor, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException();
        }
        if (($recusa = $this->recusaDaHierarquiaNaRemocao($executor, $tenant, $colaborador, $hierarquia, $userTenantRepository)) !== null) {
            return $recusa; // C5
        }

        // O <select> de substituto manda string VAZIA quando se escolhe "deixar sem
        // responsável", e getInt() lança BadRequestException nesse caso (400) em vez de
        // devolver zero. Sem este tratamento, o caminho padrão do modal — remover sem
        // substituto — quebra na cara do usuário. Valor não vazio e não numérico continua
        // sendo requisição malformada, e segue estourando 400 pelo getInt().
        $substitutoBruto = (string) $request->request->get('substituto_id', '');
        $substitutoId    = $substitutoBruto === '' ? null : ($request->request->getInt('substituto_id') ?: null);
        $substituto      = $substitutoId ? $em->find(User::class, $substitutoId) : null;

        if ($substituto !== null && !$userTenantRepository->existeVinculoAtivo($substituto, $tenant)) {
            throw $this->createNotFoundException('Substituto não encontrado.');
        }

        try {
            // A trava do último administrador (D7) mora no UseCase e vale também para
            // super-admin — não é atalhada aqui, mesmo que o guard acima já tenha passado.
            $useCase->executar(new RemoverColaboradorInput($executor, $colaborador, $tenant, $substituto));
            $this->addFlash('success', $this->tradutor->trans('escritorio.flash.colaborador_removido', ['nome' => (string) $colaborador->getFullName()]));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $this->traduzirRecusa($e->getMessage()));
        }

        return $this->redirectToRoute('app_tenant_users', ['tenantId' => $tenantId]);
    }

    #[Route('/{tenantId}/user/{id}/ponto/add', name: 'app_tenant_user_ponto_add', methods: ['POST'])]
    public function pontoAdd(
        int $tenantId,
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
        PermissionChecker $permissionChecker,
        TenantRepository $tenantRepository,
        UserTenantRepository $userTenantRepository
    ): Response {
        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $tenant = $tenantRepository->find($tenantId);
        if (!$tenant) {
            throw $this->createNotFoundException();
        }

        if (!$userTenantRepository->existeVinculoAtivo($user, $tenant)) {
            throw $this->createNotFoundException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        $isOwnTenant  = $userTenantRepository->existeVinculoAtivo($currentUser, $tenant);

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para lançar batidas.');
        }

        // CSRF por-intenção stateful (form tem csrf_protection=>false). Token renderizado no
        // modal de "Adicionar Batida" em edit_user_role.html.twig.
        if (!$this->isCsrfTokenValid('ponto_manual_add', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $form = $this->createForm(RegistroPontoManualType::class);
        $form->handleRequest($request);

        $valido   = $form->isSubmitted() && $form->isValid();
        $dataHora = $valido ? $this->dataHoraManualForaDoFuturo($form) : null;

        if ($dataHora !== null) {
            $registro = new RegistroPonto();
            $registro->setUser($user);
            $registro->setTenant($tenant);
            $registro->setDataHora($dataHora);
            $registro->setTipo($form->get('tipo')->getData());
            $registro->setObservacao($form->get('observacao')->getData());
            $registro->setSedeNomeSnapshot(RegistroPonto::SNAPSHOT_LANCAMENTO_MANUAL);

            $entityManager->persist($registro);
            $entityManager->flush();

            $this->addFlash('success', 'Batida registrada com sucesso.');
        } elseif ($valido) {
            $this->addFlash('danger', HorarioFuturoDaBatida::MENSAGEM);
        } else {
            $this->addFlash('danger', 'Erro ao registrar batida. Verifique os campos.');
        }

        $competencia = $request->request->get('competencia', '');

        return $this->redirectToRoute('app_tenant_user_edit_role', [
            'tenantId'    => $tenantId,
            'id'          => $user->getId(),
            'competencia' => $competencia,
            'tab'         => 'ponto',
        ]);
    }

    /**
     * Data e hora do lançamento manual, ou null quando ficam acima de agora (a régua da batida
     * em horário futuro). Agora passa.
     */
    private function dataHoraManualForaDoFuturo(FormInterface $form): ?\DateTime
    {
        /** @var \DateTime $data */
        $data = $form->get('data')->getData();
        /** @var \DateTime $hora */
        $hora = $form->get('hora')->getData();

        if ($this->horarioFuturo->estaNoFuturo($data, $hora)) {
            return null;
        }

        return new \DateTime($data->format('Y-m-d') . ' ' . $hora->format('H:i:s'));
    }

    #[Route('/{tenantId}/user/{id}/ponto/{registroId}/edit', name: 'app_tenant_user_ponto_edit', methods: ['GET', 'POST'])]
    public function pontoEdit(
        int $tenantId,
        User $user,
        int $registroId,
        Request $request,
        EntityManagerInterface $entityManager,
        RegistroPontoRepository $registroPontoRepository,
        PermissionChecker $permissionChecker,
        TenantRepository $tenantRepository,
        UserTenantRepository $userTenantRepository
    ): Response {
        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $tenant = $tenantRepository->find($tenantId);
        if (!$tenant) {
            throw $this->createNotFoundException();
        }

        if (!$userTenantRepository->existeVinculoAtivo($user, $tenant)) {
            throw $this->createNotFoundException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        $isOwnTenant  = $userTenantRepository->existeVinculoAtivo($currentUser, $tenant);

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para editar batidas.');
        }

        // CSRF por-intenção stateful só no POST (form tem csrf_protection=>false; no GET não há
        // token). Token renderizado em tenant/ponto_edit.html.twig.
        if ($request->isMethod('POST') && !$this->isCsrfTokenValid('ponto_manual_edit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Escopa o find() ao escritório da URL: fecha o IDOR do super-admin (filtro off na sessão).
        $this->escoparFiltroNoTenant($entityManager, $tenant);

        $registro = $registroPontoRepository->find($registroId);

        if ($registro === null || $registro->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Registro não encontrado.');
        }

        $dataHoraAtual = $registro->getDataHora();

        $form = $this->createForm(RegistroPontoManualType::class);

        // Pré-popular campos virtuais
        $form->get('data')->setData($dataHoraAtual instanceof \DateTimeInterface ? \DateTime::createFromInterface($dataHoraAtual) : null);
        $form->get('hora')->setData($dataHoraAtual instanceof \DateTimeInterface ? \DateTime::createFromInterface($dataHoraAtual) : null);
        $form->get('tipo')->setData($registro->getTipo());
        $form->get('observacao')->setData($registro->getObservacao());

        $form->handleRequest($request);

        $competencia = $request->query->get('competencia', $registro->getDataHora()?->format('Y-m') ?? '');

        $valido   = $form->isSubmitted() && $form->isValid();
        $dataHora = $valido ? $this->dataHoraManualForaDoFuturo($form) : null;

        if ($valido && $dataHora === null) {
            $form->get('hora')->addError(new FormError(HorarioFuturoDaBatida::MENSAGEM));
        }

        if ($dataHora !== null) {
            $registro->setDataHora($dataHora);
            $registro->setTipo($form->get('tipo')->getData());
            $registro->setObservacao($form->get('observacao')->getData());
            $registro->setSedeNomeSnapshot(RegistroPonto::SNAPSHOT_LANCAMENTO_MANUAL);

            $entityManager->flush();

            $this->addFlash('success', 'Batida atualizada com sucesso.');

            return $this->redirectToRoute('app_tenant_user_edit_role', [
                'tenantId'    => $tenantId,
                'id'          => $user->getId(),
                'competencia' => $competencia,
                'tab'         => 'ponto',
            ]);
        }

        return $this->render('tenant/ponto_edit.html.twig', [
            'form'        => $form->createView(),
            'registro'    => $registro,
            'user'        => $user,
            'tenantId'    => $tenantId,
            'competencia' => $competencia,
        ]);
    }

    #[Route('/{tenantId}/user/{id}/ponto/{registroId}/delete', name: 'app_tenant_user_ponto_delete', methods: ['POST'])]
    public function pontoDelete(
        int $tenantId,
        User $user,
        int $registroId,
        Request $request,
        EntityManagerInterface $entityManager,
        RegistroPontoRepository $registroPontoRepository,
        PermissionChecker $permissionChecker,
        TenantRepository $tenantRepository,
        UserTenantRepository $userTenantRepository
    ): Response {
        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $tenant = $tenantRepository->find($tenantId);
        if (!$tenant) {
            throw $this->createNotFoundException();
        }

        if (!$userTenantRepository->existeVinculoAtivo($user, $tenant)) {
            throw $this->createNotFoundException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        $isOwnTenant  = $userTenantRepository->existeVinculoAtivo($currentUser, $tenant);

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para excluir batidas.');
        }

        if (!$this->isCsrfTokenValid('delete_ponto_' . $registroId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Escopa o find() ao escritório da URL: fecha o IDOR do super-admin (filtro off na sessão).
        $this->escoparFiltroNoTenant($entityManager, $tenant);

        $registro = $registroPontoRepository->find($registroId);

        if ($registro === null || $registro->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Registro não encontrado.');
        }

        $competencia = $registro->getDataHora()?->format('Y-m') ?? '';

        $entityManager->remove($registro);
        $entityManager->flush();

        $this->addFlash('success', 'Batida excluída com sucesso.');

        return $this->redirectToRoute('app_tenant_user_edit_role', [
            'tenantId'    => $tenantId,
            'id'          => $user->getId(),
            'competencia' => $competencia,
            'tab'         => 'ponto',
        ]);
    }

    #[Route('/{tenantId}/user/{id}/justificativa/{justificativaId}/aprovar', name: 'app_tenant_user_justificativa_aprovar', methods: ['POST'])]
    public function aprovarJustificativa(
        int $tenantId,
        User $user,
        int $justificativaId,
        Request $request,
        EntityManagerInterface $entityManager,
        JustificativaPontoRepository $justificativaRepository,
        PermissionChecker $permissionChecker,
        NotificacaoService $notificacaoService,
        TenantRepository $tenantRepository,
        UserTenantRepository $userTenantRepository,
        RegistrarBatidaDoEsquecimentoUseCase $registrarBatidaDoEsquecimento
    ): Response {
        $tenant = $tenantRepository->find($tenantId);
        if (!$tenant) {
            throw $this->createNotFoundException();
        }

        if (!$userTenantRepository->existeVinculoAtivo($user, $tenant)) {
            throw $this->createNotFoundException();
        }

        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);

        if (!$isSuperAdmin && !($userTenantRepository->existeVinculoAtivo($currentUser, $tenant) && $permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para abonar justificativas.');
        }

        if (!$this->isCsrfTokenValid('justificativa_aprovar_' . $justificativaId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Escopa o find() ao escritório da URL: fecha o IDOR do super-admin (filtro off na sessão).
        $this->escoparFiltroNoTenant($entityManager, $tenant);

        $justificativa = $justificativaRepository->find($justificativaId);

        if ($justificativa === null || $justificativa->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Justificativa não encontrada.');
        }

        if ($justificativa->getStatus() !== 'pendente') {
            $this->addFlash('warning', 'Apenas justificativas pendentes podem ser abonadas.');
            return $this->redirectToRoute('app_tenant_user_edit_role', ['tenantId' => $tenantId, 'id' => $user->getId(), 'tab' => 'justificativas']);
        }
        if (($jaBatida = $registrarBatidaDoEsquecimento->recusa($justificativa, $tenant)) !== null) {
            $this->addFlash('warning', $jaBatida);   // o pedido fica pendente: o admin decide (R1)
            return $this->redirectToRoute('app_tenant_user_edit_role', ['tenantId' => $tenantId, 'id' => $user->getId(), 'tab' => 'justificativas']);
        }

        // Pedido antigo com hora que ainda não chegou: não abona, para não gravar batida no futuro.
        if ($registrarBatidaDoEsquecimento->pedeHorarioFuturo($justificativa)) {
            $this->addFlash('warning', HorarioFuturoDaBatida::MENSAGEM);
            return $this->redirectToRoute('app_tenant_user_edit_role', ['tenantId' => $tenantId, 'id' => $user->getId(), 'tab' => 'justificativas']);
        }

        $observacao = trim((string) $request->request->get('observacaoAnalise', ''));

        $justificativa->setStatus('abonado');
        $justificativa->setDataAnalise(new \DateTime());
        $justificativa->setAnalisadoPor($currentUser);
        if ($observacao !== '') {
            $justificativa->setObservacaoAnalise($observacao);
        }

        $entityManager->flush();

        // Esquecimento de registro: grava a batida pedida (mesmo caminho do "aprovar todos").
        if ($registrarBatidaDoEsquecimento($justificativa, $tenant) !== null) {
            $entityManager->flush();
        }

        $urlPonto = $this->generateUrl('ponto_index');
        $notificacaoService->notificarJustificativaAprovada($justificativa, $urlPonto);

        $this->addFlash('success', sprintf('Justificativa de %s abonada.', $justificativa->getData()?->format('d/m/Y')));

        return $this->redirectToRoute('app_tenant_user_edit_role', [
            'tenantId' => $tenantId,
            'id'       => $user->getId(),
            'tab'      => 'justificativas',
        ]);
    }

    #[Route('/{tenantId}/user/{id}/justificativa/{justificativaId}/rejeitar', name: 'app_tenant_user_justificativa_rejeitar', methods: ['POST'])]
    public function rejeitarJustificativa(
        int $tenantId,
        User $user,
        int $justificativaId,
        Request $request,
        EntityManagerInterface $entityManager,
        JustificativaPontoRepository $justificativaRepository,
        PermissionChecker $permissionChecker,
        NotificacaoService $notificacaoService,
        TenantRepository $tenantRepository,
        UserTenantRepository $userTenantRepository
    ): Response {
        $tenant = $tenantRepository->find($tenantId);
        if (!$tenant) {
            throw $this->createNotFoundException();
        }

        if (!$userTenantRepository->existeVinculoAtivo($user, $tenant)) {
            throw $this->createNotFoundException();
        }

        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);

        if (!$isSuperAdmin && !($userTenantRepository->existeVinculoAtivo($currentUser, $tenant) && $permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para rejeitar justificativas.');
        }

        if (!$this->isCsrfTokenValid('justificativa_rejeitar_' . $justificativaId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Escopa o find() ao escritório da URL: fecha o IDOR do super-admin (filtro off na sessão).
        $this->escoparFiltroNoTenant($entityManager, $tenant);

        $justificativa = $justificativaRepository->find($justificativaId);

        if ($justificativa === null || $justificativa->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Justificativa não encontrada.');
        }

        if ($justificativa->getStatus() !== 'pendente') {
            $this->addFlash('warning', 'Apenas justificativas pendentes podem ser rejeitadas.');
            return $this->redirectToRoute('app_tenant_user_edit_role', ['tenantId' => $tenantId, 'id' => $user->getId(), 'tab' => 'justificativas']);
        }

        $observacao = trim((string) $request->request->get('observacaoAnalise', ''));
        if ($observacao === '') {
            $this->addFlash('danger', 'Informe o motivo da rejeição.');
            return $this->redirectToRoute('app_tenant_user_edit_role', ['tenantId' => $tenantId, 'id' => $user->getId(), 'tab' => 'justificativas']);
        }

        $justificativa->setStatus('rejeitado');
        $justificativa->setDataAnalise(new \DateTime());
        $justificativa->setAnalisadoPor($currentUser);
        $justificativa->setObservacaoAnalise($observacao);

        $entityManager->flush();

        $urlPonto = $this->generateUrl('ponto_index');
        $notificacaoService->notificarJustificativaRejeitada($justificativa, $urlPonto);

        $this->addFlash('success', sprintf('Justificativa de %s rejeitada.', $justificativa->getData()?->format('d/m/Y')));

        return $this->redirectToRoute('app_tenant_user_edit_role', [
            'tenantId' => $tenantId,
            'id'       => $user->getId(),
            'tab'      => 'justificativas',
        ]);
    }

    #[Route('/{tenantId}/user/{id}/justificativa/batch/{batchId}/aprovar-todos', name: 'app_tenant_user_justificativa_aprovar_todos', methods: ['POST'])]
    public function aprovarTodosJustificativas(
        int $tenantId,
        User $user,
        string $batchId,
        Request $request,
        EntityManagerInterface $entityManager,
        JustificativaPontoRepository $justificativaRepository,
        PermissionChecker $permissionChecker,
        NotificacaoService $notificacaoService,
        TenantRepository $tenantRepository,
        UserTenantRepository $userTenantRepository,
        RegistrarBatidaDoEsquecimentoUseCase $registrarBatidaDoEsquecimento
    ): Response {
        $tenant = $tenantRepository->find($tenantId);
        if (!$tenant) {
            throw $this->createNotFoundException();
        }

        if (!$userTenantRepository->existeVinculoAtivo($user, $tenant)) {
            throw $this->createNotFoundException();
        }

        $currentUser = $this->getUser();
        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        if (!$isSuperAdmin && !($userTenantRepository->existeVinculoAtivo($currentUser, $tenant) && $permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para abonar justificativas.');
        }

        if (!$this->isCsrfTokenValid('justificativa_aprovar_todos_' . $batchId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Escopa o findBy ao escritório da URL: fecha o IDOR do super-admin (filtro off na sessão).
        $this->escoparFiltroNoTenant($entityManager, $tenant);

        $encontradas = $justificativaRepository->findBy(['user' => $user, 'batchId' => $batchId, 'status' => 'pendente']);
        $pendentes   = $this->semBatidaNoFuturo($encontradas, $registrarBatidaDoEsquecimento);
        if (empty($pendentes)) {
            if ($encontradas === []) {   // lote todo no futuro não cai aqui: o aviso de horário futuro já saiu
                $this->addFlash('warning', 'Nenhuma justificativa pendente encontrada neste lote.');
            }
            return $this->redirectToRoute('app_tenant_user_edit_role', ['tenantId' => $tenantId, 'id' => $user->getId(), 'tab' => 'justificativas']);
        }

        $pendentes = $this->semBatidaJaRegistrada($pendentes, $tenant, $registrarBatidaDoEsquecimento);   // R1
        foreach ($pendentes as $justificativa) {
            $justificativa->setStatus('abonado');
            $justificativa->setDataAnalise(new \DateTime());
            $justificativa->setAnalisadoPor($currentUser);
            // Esquecimento de registro: grava a batida pedida, como na aprovação individual.
            $registrarBatidaDoEsquecimento($justificativa, $tenant);
            $notificacaoService->notificarJustificativaAprovada($justificativa, $this->generateUrl('ponto_index'));
        }

        $entityManager->flush();

        $this->avisarAbonadas(count($pendentes));

        return $this->redirectToRoute('app_tenant_user_edit_role', [
            'tenantId' => $tenantId,
            'id'       => $user->getId(),
            'tab'      => 'justificativas',
        ]);
    }

    #[Route('/{tenantId}/user/{id}/justificativa/{justificativaId}/reverter', name: 'app_tenant_user_justificativa_reverter', methods: ['POST'])]
    public function reverterJustificativa(
        int $tenantId,
        User $user,
        int $justificativaId,
        Request $request,
        EntityManagerInterface $entityManager,
        JustificativaPontoRepository $justificativaRepository,
        PermissionChecker $permissionChecker,
        TenantRepository $tenantRepository,
        UserTenantRepository $userTenantRepository
    ): Response {
        $tenant = $tenantRepository->find($tenantId);
        if (!$tenant) {
            throw $this->createNotFoundException();
        }

        if (!$userTenantRepository->existeVinculoAtivo($user, $tenant)) {
            throw $this->createNotFoundException();
        }

        $currentUser = $this->getUser();
        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        if (!$isSuperAdmin && !($userTenantRepository->existeVinculoAtivo($currentUser, $tenant) && $permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para reverter justificativas.');
        }

        if (!$this->isCsrfTokenValid('justificativa_reverter_' . $justificativaId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Escopa o find() ao escritório da URL: fecha o IDOR do super-admin (filtro off na sessão).
        $this->escoparFiltroNoTenant($entityManager, $tenant);

        $justificativa = $justificativaRepository->find($justificativaId);

        if ($justificativa === null || $justificativa->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Justificativa não encontrada.');
        }

        if ($justificativa->getStatus() === 'pendente') {
            $this->addFlash('warning', 'Esta justificativa já está pendente.');
            return $this->redirectToRoute('app_tenant_user_edit_role', ['tenantId' => $tenantId, 'id' => $user->getId(), 'tab' => 'justificativas']);
        }

        $statusAnterior = $justificativa->getStatus();

        $justificativa->setStatus('pendente');
        $justificativa->setAnalisadoPor(null);
        $justificativa->setDataAnalise(null);
        $justificativa->setObservacaoAnalise(null);

        $entityManager->flush();

        if ($justificativa->getTipo() === 'esquecimento_registro' && $statusAnterior === 'abonado') {
            $this->addFlash('warning', sprintf(
                'Justificativa de %s revertida para pendente. Atenção: o registro de ponto criado automaticamente durante o abono NÃO foi removido e deve ser ajustado manualmente.',
                $justificativa->getData()?->format('d/m/Y')
            ));
        } else {
            $this->addFlash('success', sprintf('Justificativa de %s revertida para pendente.', $justificativa->getData()?->format('d/m/Y')));
        }

        return $this->redirectToRoute('app_tenant_user_edit_role', [
            'tenantId' => $tenantId,
            'id'       => $user->getId(),
            'tab'      => 'justificativas',
        ]);
    }


    /**
     * Data do lote do admin que não entra: data futura (por data, não por instante, porque o
     * createFromFormat carrega a hora corrente e hoje passava por futura) ou batida esquecida
     * cuja data e hora ficam acima de agora (relógio do servidor, o mesmo da gravação). Avisa o motivo no flash.
     */
    private function dataDoLoteRecusada(\DateTimeInterface $data, \DateTimeInterface $hoje, ?\DateTimeInterface $horaEsquecida): bool
    {
        if ($data->format('Y-m-d') > $hoje->format('Y-m-d')) {
            $this->addFlash('warning', sprintf('A data %s é futura e foi ignorada.', $data->format('d/m/Y')));

            return true;
        }
        if ($horaEsquecida !== null && $this->horarioFuturo->estaNoFuturo($data, $horaEsquecida)) {
            $this->addFlash('warning', HorarioFuturoDaBatida::MENSAGEM);

            return true;
        }

        return false;
    }

    /**
     * "Aprovar todos" deixa pendente o pedido de esquecimento com hora que ainda não chegou.
     *
     * @param list<JustificativaPonto> $pendentes
     *
     * @return list<JustificativaPonto>
     */
    private function semBatidaNoFuturo(array $pendentes, RegistrarBatidaDoEsquecimentoUseCase $registrar): array
    {
        $aprovaveis = array_values(array_filter(
            $pendentes,
            static fn (JustificativaPonto $j): bool => !$registrar->pedeHorarioFuturo($j),
        ));
        if (count($aprovaveis) < count($pendentes)) {
            $this->addFlash('warning', HorarioFuturoDaBatida::MENSAGEM);
        }

        return $aprovaveis;
    }

    #[Route('/{tenantId}/user/{id}/justificativa/nova', name: 'app_tenant_user_justificativa_nova', methods: ['POST'])]
    public function novaJustificativaAdmin(
        int $tenantId,
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
        PermissionChecker $permissionChecker,
        TenantRepository $tenantRepository,
        UserTenantRepository $userTenantRepository,
        ValidatorInterface $validator
    ): Response {
        $tenant = $tenantRepository->find($tenantId);
        if (!$tenant) {
            throw $this->createNotFoundException();
        }

        if (!$userTenantRepository->existeVinculoAtivo($user, $tenant)) {
            throw $this->createNotFoundException();
        }

        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);

        if (!$isSuperAdmin && !($userTenantRepository->existeVinculoAtivo($currentUser, $tenant) && $permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para lançar justificativas.');
        }

        if (!$this->isCsrfTokenValid('admin_nova_justificativa_' . $user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $redirect = fn () => $this->redirectToRoute('app_tenant_user_edit_role', ['tenantId' => $tenantId, 'id' => $user->getId(), 'tab' => 'justificativas']);

        $datasRaw          = trim((string) $request->request->get('datas', ''));
        $tipo              = trim((string) $request->request->get('tipo', ''));
        $abonoParcial      = (bool) $request->request->get('abonoParcial', false);
        $horaInicioStr     = trim((string) $request->request->get('horaInicioAbono', ''));
        $horaFimStr        = trim((string) $request->request->get('horaFimAbono', ''));
        $tipoRegEsquecido  = trim((string) $request->request->get('tipoRegistroEsquecido', ''));
        $horaRegEsquecidaStr = trim((string) $request->request->get('horaRegistroEsquecido', ''));

        $datasArray = array_filter(array_map('trim', explode(',', $datasRaw)));

        if (empty($datasArray)) {
            $this->addFlash('danger', 'Selecione ao menos uma data.');
            return $redirect();
        }

        if ($abonoParcial && count($datasArray) > 1) {
            $this->addFlash('danger', 'Abono parcial só pode ser aplicado a uma única data.');
            return $redirect();
        }

        $horaInicio = null;
        $horaFim    = null;
        if ($abonoParcial) {
            $horaInicio = \DateTime::createFromFormat('H:i', $horaInicioStr) ?: null;
            $horaFim    = \DateTime::createFromFormat('H:i', $horaFimStr) ?: null;
            if ($horaInicio === null || $horaFim === null || $horaInicio >= $horaFim) {
                $this->addFlash('danger', 'Informe os horários de saída e retorno corretamente para o abono parcial.');
                return $redirect();
            }
        }

        if (!in_array($tipo, TipoJustificativa::valores(), true)) {
            $this->addFlash('danger', 'Tipo de justificativa inválido.');
            return $redirect();
        }

        $horaRegEsquecida = null;
        if ($tipo === 'esquecimento_registro') {
            $horaRegEsquecida = $horaRegEsquecidaStr !== '' ? \DateTime::createFromFormat('H:i', $horaRegEsquecidaStr) ?: null : null;
            if (!in_array($tipoRegEsquecido, RegistroPonto::TIPOS_VALIDOS, true) || $horaRegEsquecida === null) {
                $this->addFlash('danger', 'Informe o tipo e o horário do registro esquecido.');
                return $redirect();
            }
        }

        $hoje = new \DateTimeImmutable('today');
        $datasValidas = [];

        foreach ($datasArray as $dataStr) {
            $dataObj = \DateTime::createFromFormat('Y-m-d', $dataStr);
            if ($dataObj === false) {
                continue;
            }
            if ($this->dataDoLoteRecusada($dataObj, $hoje, $horaRegEsquecida)) {
                continue;
            }
            $datasValidas[] = $dataObj;
        }

        if (empty($datasValidas)) {
            return $redirect();
        }
        if (($jaBatida = $this->esquecimentoJaBatido($user, $tenant, $tipo, $tipoRegEsquecido, $datasValidas)) !== null) {
            $this->addFlash('danger', $jaBatida);   // o esquecimento é só da batida que falta (R1)
            return $redirect();
        }

        $anexoPath  = null;
        $chaveAnexo = null;
        $anexoFile  = $request->files->get('anexo');
        if ($anexoFile !== null) {
            // Mesma regra da porta do colaborador (JustificativaPontoType) e da edição
            // (SubstituirAnexoDoLoteUseCase). Antes da E1 esta porta não validava nada: um admin
            // podia gravar qualquer tipo e qualquer tamanho como atestado, no mesmo diretório
            // plano e servido pela mesma rota de download.
            $violacoes = $validator->validate($anexoFile, RestricoesAnexoJustificativa::constraint());

            if (count($violacoes) > 0) {
                $this->addFlash('warning', (string) $violacoes->get(0)->getMessage());

                return $redirect();
            }

            // O arquivo nasce ANTES das justificativas do lote (ordem da E1); o escopo é o mesmo
            // `$tenant` da URL que cada uma recebe abaixo em setTenant().
            $upload     = FonteDeUploadHttp::de($anexoFile);
            $chaveAnexo = $upload->gravarEm(
                $this->armazenamento,
                ChavesDePonto::novoAnexoDeLote($tenant, $upload->extensao),
            )->chave;
            $anexoPath  = $chaveAnexo->nome;
        }

        $batchId    = bin2hex(random_bytes(16));
        $isFaltaNaoJustificada = $tipo === 'falta_nao_justificada';

        foreach ($datasValidas as $dataObj) {
            $justificativa = new JustificativaPonto();
            $justificativa->setUser($user);
            $justificativa->setTenant($tenant);
            $justificativa->setData($dataObj);
            $justificativa->setTipo($tipo);
            $justificativa->setAnexoPath($anexoPath);
            $justificativa->setStatus('abonado');
            $justificativa->setDataAnalise(new \DateTime());
            $justificativa->setAnalisadoPor($currentUser);
            $justificativa->setAbonoParcial($abonoParcial);
            $justificativa->setBatchId($batchId);
            if ($abonoParcial) {
                $justificativa->setHoraInicioAbono($horaInicio);
                $justificativa->setHoraFimAbono($horaFim);
            }
            if ($tipo === 'esquecimento_registro') {
                $justificativa->setTipoRegistroEsquecido($tipoRegEsquecido ?: null);
                $justificativa->setHoraRegistroEsquecido($horaRegEsquecida);
            }
            $entityManager->persist($justificativa);

            if ($tipo === 'esquecimento_registro' && $horaRegEsquecida !== null && $tipoRegEsquecido !== '') {
                $dataHora = new \DateTime(
                    $dataObj->format('Y-m-d') . ' ' . $horaRegEsquecida->format('H:i:s')
                );
                $registro = new RegistroPonto();
                $registro->setUser($user);
                $registro->setTenant($tenant);
                $registro->setDataHora($dataHora);
                $registro->setTipo($tipoRegEsquecido);
                $registro->setObservacao(SaidaDeEsquecimento::OBSERVACAO_LANCAMENTO_DO_ADMIN);
                $entityManager->persist($registro);
            }
        }

        // O arquivo foi gravado antes da transação. Se ela falhar, ele só sai quando estiver
        // PROVADO que nada foi confirmado; num COMMIT de resultado incerto as justificativas podem
        // existir e apontar para ele, e o arquivo fica (E2.5, INV-6). A exceção original sobe.
        $this->transacao->confirmar(
            static fn (): array => $chaveAnexo === null ? [] : [$chaveAnexo],
            'TenantController::novaJustificativaAdmin: atestado do lote',
        );

        if ($isFaltaNaoJustificada) {
            $this->addFlash('success', sprintf('Falta registrada como não justificada para %d dia(s).', count($datasValidas)));
        } else {
            $this->addFlash('success', sprintf('Justificativa lançada para %d dia(s).', count($datasValidas)));
        }

        return $this->redirectToRoute('app_tenant_user_edit_role', [
            'tenantId' => $tenantId,
            'id'       => $user->getId(),
            'tab'      => 'justificativas',
        ]);
    }

    #[Route('/{tenantId}/user/{id}/justificativa/{justificativaId}/anexo', name: 'app_tenant_user_justificativa_anexo', methods: ['GET'])]
    public function downloadAnexoJustificativa(
        int $tenantId,
        User $user,
        int $justificativaId,
        JustificativaPontoRepository $justificativaRepository,
        PermissionChecker $permissionChecker,
        TenantRepository $tenantRepository,
        UserTenantRepository $userTenantRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $tenant = $tenantRepository->find($tenantId);
        if (!$tenant) {
            throw $this->createNotFoundException();
        }

        if (!$userTenantRepository->existeVinculoAtivo($user, $tenant)) {
            throw $this->createNotFoundException();
        }

        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);

        if (!$isSuperAdmin && !($userTenantRepository->existeVinculoAtivo($currentUser, $tenant) && $permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão.');
        }

        // Remendo (B5 frente 3): escopa o filtro no tenant da URL antes de carregar a justificativa
        // (TenantAware) por id. Cinto da trava automática: super-admin sem tenant na sessão não baixa o
        // atestado de outro escritório pela URL errada nem se o listener (TenantUrlScopeListener) cair.
        $this->escoparFiltroNoTenant($entityManager, $tenant);

        $justificativa = $justificativaRepository->find($justificativaId);

        if ($justificativa === null || $justificativa->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Justificativa não encontrada.');
        }

        if ($justificativa->getAnexoPath() === null) {
            throw $this->createNotFoundException('Esta justificativa não possui atestado.');
        }

        $chave = ChavesDePonto::anexoDeJustificativa($justificativa);

        if (!$this->armazenamento->existe($chave)) {
            throw $this->createNotFoundException('Arquivo não encontrado.');
        }

        return $this->entrega->resposta($chave, $justificativa->getAnexoPath(), inline: true);
    }

    private function calcularCargaDiaria(JornadaColaborador $jornada): int
    {
        [$hE1, $mE1] = array_map('intval', explode(':', $jornada->getEntrada1() ?? '09:00'));
        [$hS1, $mS1] = array_map('intval', explode(':', $jornada->getSaida1()   ?? '12:00'));
        [$hE2, $mE2] = array_map('intval', explode(':', $jornada->getEntrada2() ?? '13:00'));
        [$hS2, $mS2] = array_map('intval', explode(':', $jornada->getSaida2()   ?? '18:00'));

        return max(0, (($hS1 * 60 + $mS1) - ($hE1 * 60 + $mE1))
                    + (($hS2 * 60 + $mS2) - ($hE2 * 60 + $mE2)));
    }

    #[Route('/{tenantId}/user/{userId}/resource-access/{raId}/remove', name: 'app_tenant_user_resource_access_remove', methods: ['POST'])]
    public function removeResourceAccess(
        int $tenantId,
        int $userId,
        int $raId,
        Request $request,
        EntityManagerInterface $entityManager,
        ResourceAccessRepository $resourceAccessRepository,
        PermissionChecker $permissionChecker,
        TenantRepository $tenantRepository,
        UserTenantRepository $userTenantRepository,
    ): Response {
        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $tenant = $tenantRepository->find($tenantId);
        if (!$tenant) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('remove_resource_access_' . $raId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $targetUser = $entityManager->find(User::class, $userId);
        if (!$targetUser) {
            throw $this->createNotFoundException('Usuário não encontrado.');
        }

        // Guarda 1: target user pertence ao tenant da URL.
        if (!$userTenantRepository->existeVinculoAtivo($targetUser, $tenant)) {
            throw $this->createNotFoundException('Usuário não encontrado.');
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        $isOwnTenant  = $userTenantRepository->existeVinculoAtivo($currentUser, $tenant);

        // Guarda 2: admin logado pertence ao tenant da URL (SUPER_ADMIN bypass mantido).
        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Você não tem permissão para remover acessos.');
        }

        // ResourceAccess é TenantAware (frente 4/B3): a rota é {tenantId} → a trava (TenantUrlScopeListener)
        // pina o filtro no tenant da URL → este find($raId) é escopado → RA de outro escritório vira null →
        // 404 (fecha o IDOR/write cross-tenant, inclusive p/ usuário multi-tenant). As guardas abaixo
        // (acesso pertence ao $userId, membro do tenant da URL) permanecem como defesa-em-profundidade.
        $resourceAccess = $resourceAccessRepository->find($raId);

        // Garante que o acesso pertence ao usuário correto do tenant
        if ($resourceAccess === null || (int) $resourceAccess->getUser()?->getId() !== $userId) {
            throw $this->createNotFoundException('Acesso não encontrado.');
        }

        $entityManager->remove($resourceAccess);
        $entityManager->flush();

        $this->addFlash('success', $this->tradutor->trans('escritorio.flash.acesso_removido'));

        return $this->redirectToRoute('app_tenant_user_edit_role', [
            'tenantId' => $tenantId,
            'id'       => $userId,
            'tab'      => 'acessos',
        ]);
    }

    #[Route('/{tenantId}/sedes', name: 'app_tenant_sedes', methods: ['GET', 'POST'])]
    public function manageSedes(
        #[MapEntity(id: 'tenantId')] Tenant $tenant,
        Request $request,
        EntityManagerInterface $entityManager,
        SedeRepository $sedeRepository,
        PermissionChecker $permissionChecker,
        UserTenantRepository $userTenantRepository,
    ): Response {
        /** @var \App\Entity\Auth\User $user */
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        // Guarda 2: admin logado pertence ao tenant da URL (SUPER_ADMIN bypass mantido).
        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
        $isOwnTenant  = $userTenantRepository->existeVinculoAtivo($user, $tenant);

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($user, $tenant, 'admin.ponto.manage'))) {
            throw $this->createAccessDeniedException('Você não tem permissão para gerenciar sedes.');
        }

        $sede = new Sede();
        $form = $this->createForm(SedeType::class, $sede);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $sede->setTenant($tenant);

            $entityManager->persist($sede);
            $entityManager->flush();

            $this->addFlash('success', $this->tradutor->trans('escritorio.flash.sede_cadastrada'));
            return $this->redirectToRoute('app_tenant_sedes', ['tenantId' => $tenant->getId()]);
        }

        $sedes = $sedeRepository->findBy(['tenant' => $tenant]);

        return $this->render('tenant/sedes.html.twig', [
            'tenant' => $tenant,
            'sedes'  => $sedes,
            'form'   => $form->createView(),
        ]);
    }

    #[Route('/{tenantId}/sedes/{sedeId}/edit', name: 'app_tenant_sede_edit', methods: ['POST'])]
    public function editSede(
        int $tenantId,
        int $sedeId,
        Request $request,
        EntityManagerInterface $entityManager,
        SedeRepository $sedeRepository,
        TenantRepository $tenantRepository,
        UserTenantRepository $userTenantRepository,
        PermissionChecker $permissionChecker
    ): Response {
        /** @var \App\Entity\Auth\User $user */
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);

        $tenant = $tenantRepository->find($tenantId);
        if (!$tenant) {
            throw $this->createNotFoundException();
        }

        $isOwnTenant  = $userTenantRepository->existeVinculoAtivo($user, $tenant);

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($user, $tenant, 'admin.ponto.manage'))) {
            throw $this->createAccessDeniedException('Você não tem permissão para editar sedes.');
        }

        $sede = $sedeRepository->find($sedeId);

        if (!$sede || $sede->getTenant()?->getId() !== $tenantId) {
            throw $this->createNotFoundException('Sede não encontrada.');
        }

        $editForm = $this->createForm(SedeType::class, $sede, ['block_prefix' => 'sede_edit']);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', $this->tradutor->trans('escritorio.flash.sede_atualizada'));

            return $this->redirectToRoute('app_tenant_sedes', ['tenantId' => $tenantId]);
        }

        // Falha de validação: re-renderizar a página com o form de edição com erros.
        // O form já escreveu o valor inválido na entidade gerenciada; o refresh devolve a
        // cerca gravada para a lista da tela (nada foi gravado, sem flush).
        if ($editForm->isSubmitted()) {
            $entityManager->refresh($sede);
        }
        $newSede  = new Sede();
        $form     = $this->createForm(SedeType::class, $newSede);
        $sedes    = $sedeRepository->findBy(['tenant' => $tenant]);

        return $this->render('tenant/sedes.html.twig', [
            'tenant'   => $tenant,
            'sedes'    => $sedes,
            'form'     => $form->createView(),
            'editForm' => $editForm->createView(),
            'editSede' => $sede,
        ]);
    }

    #[Route('/{tenantId}/sedes/{sedeId}/delete', name: 'app_tenant_sede_delete', methods: ['POST'])]
    public function deleteSede(
        int $tenantId,
        int $sedeId,
        Request $request,
        EntityManagerInterface $entityManager,
        SedeRepository $sedeRepository,
        RegistroPontoRepository $registroPontoRepository,
        PermissionChecker $permissionChecker,
        TenantRepository $tenantRepository,
        UserTenantRepository $userTenantRepository
    ): Response {
        /** @var \App\Entity\Auth\User $user */
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('delete_sede_' . $sedeId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);

        $tenant = $tenantRepository->find($tenantId);
        if (!$tenant) {
            throw $this->createNotFoundException();
        }

        $isOwnTenant = $userTenantRepository->existeVinculoAtivo($user, $tenant);

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($user, $tenant, 'admin.ponto.manage'))) {
            throw $this->createAccessDeniedException('Você não tem permissão para excluir sedes.');
        }

        $sede = $sedeRepository->find($sedeId);

        if (!$sede || $sede->getTenant()?->getId() !== $tenantId) {
            throw $this->createNotFoundException();
        }

        $connection = $entityManager->getConnection();
        try {
            $connection->beginTransaction();
            $totalDesvinculados = $registroPontoRepository->desvincularSede($sede);
            $entityManager->remove($sede);
            $entityManager->flush();
            $connection->commit();

            $this->addFlash('success', $this->tradutor->trans('escritorio.flash.sede_removida'));

            if ($totalDesvinculados > 0) {
                $this->addFlash('info', $this->tradutor->trans('escritorio.flash.sede_registros_desvinculados', ['qtd' => (string) $totalDesvinculados]));
            }
        } catch (\Throwable) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $this->addFlash('danger', $this->tradutor->trans('escritorio.flash.sede_nao_removida'));
        }

        return $this->redirectToRoute('app_tenant_sedes', ['tenantId' => $tenantId]);
    }

}
