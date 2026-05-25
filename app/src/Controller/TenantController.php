<?php

namespace App\Controller;

use App\Entity\Ponto\JornadaColaborador;
use App\Entity\Ponto\JustificativaPonto;
use App\Ponto\Enum\TipoJustificativa;
use App\Entity\Ponto\RegistroPonto;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Form\EditUserTenantRoleType;
use App\Profile\Entity\UserProfile;
use App\Form\RegistroPontoManualType;
use App\Form\SedeType;
use App\Form\TenantCargosLotacoesType;
use App\Form\TenantCargosType;
use App\Form\TenantLotacoesType;
use App\Form\TenantType;
use App\Form\TenantNameType;
use App\Form\TenantPasswordType;
use App\Cliente\Repository\ClienteRepository;
use App\Pasta\Repository\PastaRepository;
use App\Repository\Ponto\FeriadoRepository;
use App\Repository\Ponto\JustificativaPontoRepository;
use App\Repository\Ponto\RegistroPontoRepository;
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
use App\Tenant\DTO\DemitirFuncionarioInput;
use App\Tenant\UseCase\DemitirFuncionarioUseCase;
use App\Tenant\UseCase\GerarCodigoFuncionario;
use App\Service\InvitationService;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Service\Ponto\CalculadoraJornada;
use App\Service\Ponto\FolhaPontoBuilder;
use App\Service\TenantBootstrapService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use App\Shared\Service\ArquivoStorageService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/tenant')]
final class TenantController extends AbstractController
{
    public function __construct(
        private readonly string $justificativasUploadsDir,
        private readonly ArquivoStorageService $storage,
        private readonly TenantContext $tenantContext,
    ) {}

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
                $form->get('adminEmail')->addError(new FormError('Este e-mail já está em uso. Informe outro e-mail para o administrador.'));

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

                $form->get('adminEmail')->addError(new FormError('Este e-mail já está cadastrado. Informe outro e-mail para o administrador.'));

                return $this->render('tenant/new.html.twig', [
                    'tenant' => $tenant,
                    'form' => $form,
                ]);
            }

            if (!$result['sent']) {
                $this->addFlash('warning', 'Tenant criado, mas não foi possível enviar o e-mail de ativação do administrador agora. Verifique SMTP e reenvie o convite.');

                if ($this->getParameter('kernel.environment') === 'dev') {
                    $this->addFlash('info', sprintf('Link de confirmação (dev): %s', $result['link']));
                }

                return $this->redirectToRoute('app_tenant_index', [], Response::HTTP_SEE_OTHER);
            }

            $this->addFlash('success', 'Tenant criado com sucesso! O administrador receberá um e-mail para criar a senha.');

            return $this->redirectToRoute('app_tenant_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('tenant/new.html.twig', [
            'tenant' => $tenant,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_tenant_show', methods: ['GET'])]
    public function show(Tenant $tenant, PermissionChecker $permissionChecker, UserTenantRepository $userTenantRepository): Response
    {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
        $isOwnTenant  = $userTenantRepository->existeVinculoAtivo($user, $tenant);

        if (!($isSuperAdmin || ($isOwnTenant && $permissionChecker->canAdminister($user, $tenant, 'admin.tenant.settings.manage')))) {
            throw $this->createAccessDeniedException('Você não tem permissão para ver este Tenant.');
        }

        return $this->render('tenant/show.html.twig', ['tenant' => $tenant]);
    }

    #[Route('/{id}/edit', name: 'app_tenant_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Tenant $tenant,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        PermissionChecker $permissionChecker,
        TenantRoleRepository $tenantRoleRepository,
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
            $this->addFlash('success', 'Nome do Tenant atualizado com sucesso!');
            return $this->redirectToRoute('app_tenant_edit', ['id' => $tenant->getId()]);
        }

        // Form para alterar senha
        $passwordForm = $this->createForm(TenantPasswordType::class, $tenant);
        $passwordForm->handleRequest($request);
        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            $newPassword = $passwordForm->get('password')->getData();
            $confirmPassword = $passwordForm->get('confirm_password')->getData();

            if ($newPassword !== $confirmPassword) {
                $this->addFlash('error', 'As senhas não coincidem.');
            } else {
                // Busca o usuário com perfil de sistema (Administrador do Escritório) no tenant
                $adminRole = null;
                foreach ($tenantRoleRepository->findByTenantId($tenant->getId()) as $role) {
                    if ($role->isSystem()) {
                        $adminRole = $role;
                        break;
                    }
                }

                $adminUser = $adminRole
                    ? $entityManager->getRepository(User::class)->findOneBy(['tenantRole' => $adminRole])
                    : null;

                if ($adminUser) {
                    $adminUser->setPassword($passwordHasher->hashPassword($adminUser, $newPassword));
                    $entityManager->flush();
                    $this->addFlash('success', 'Senha do administrador alterada com sucesso!');
                }
            }
            return $this->redirectToRoute('app_tenant_edit', ['id' => $tenant->getId()]);
        }

        // Form para gerenciar cargos
        $cargosForm = $this->createForm(TenantCargosType::class, $tenant);
        $cargosForm->handleRequest($request);
        if ($cargosForm->isSubmitted() && $cargosForm->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Cargos atualizados com sucesso!');
            return $this->redirectToRoute('app_tenant_edit', ['id' => $tenant->getId(), '_fragment' => 'tab-cargos']);
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
            $this->addFlash('success', 'Lotações atualizadas com sucesso!');
            return $this->redirectToRoute('app_tenant_edit', ['id' => $tenant->getId(), '_fragment' => 'tab-lotacoes']);
        }

        return $this->render('tenant/edit.html.twig', [
            'tenant'        => $tenant,
            'nameForm'      => $nameForm->createView(),
            'passwordForm'  => $passwordForm->createView(),
            'cargosForm'    => $cargosForm->createView(),
            'lotacoesForm'  => $lotacoesForm->createView(),
        ]);
    }

    #[Route('/{id}', name: 'app_tenant_delete', methods: ['POST'])]
    public function delete(Request $request, Tenant $tenant, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        if (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            throw $this->createAccessDeniedException('Somente SUPER_ADMIN pode excluir Tenants.');
        }

        if ($this->isCsrfTokenValid('delete'.$tenant->getId(), $request->request->get('_token'))) {
            $entityManager->remove($tenant);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_tenant_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/users', name: 'app_tenant_users', methods: ['GET'])]
    public function listUsers(
        Tenant $tenant,
        PermissionChecker $permissionChecker,
        ResourceAccessRepository $resourceAccessRepository,
        ClienteRepository $clienteRepository,
        PastaRepository $pastaRepository,
        ProcessoRepository $processoRepository,
        UserTenantRepository $userTenantRepository,
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

        $vinculos = $userTenantRepository->findBy(['tenant' => $tenant]);
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

    // --- FEATURE FLAG TEMPORÁRIA: remover quando não for mais necessário ---
    private function deveMostrarSegundosBatida(): bool
    {
        $user = $this->getUser();
        return $user instanceof \App\Entity\Auth\User
            && $user->getEmail() === 'jusprime.samuel@gmail.com';
    }
    // --- FIM FEATURE FLAG ---

    /**
     * @return array{cargaHorariaSemanal: int, fonte: string}|null
     */
    private function resolverJornadaInfoAdmin(User $user, ?\App\Entity\Ponto\JornadaTenant $jornadaTenant): ?array
    {
        $jornada = $user->getJornadaColaborador();

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
        PermissionChecker $permissionChecker,
        FolhaPontoBuilder $folhaPontoBuilder,
        CargoRepository $cargoRepository,
        LotacaoRepository $lotacaoRepository,
        GerarCodigoFuncionario $gerarCodigo,
        ObterOuCriarPerfilUseCase $obterOuCriarPerfil,
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

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        $isOwnTenant  = $userTenantRepository->existeVinculoAtivo($currentUser, $tenant);

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Você não tem permissão para editar perfis de usuário.');
        }

        $userTenant = $userTenantRepository->findAtivoPorUserETenant($user, $tenant);
        if ($userTenant === null) {
            throw $this->createNotFoundException();
        }

        $tenantRoles = $tenantRoleRepository->findByTenantId($tenantId);
        $cargos      = $cargoRepository->findBy(['tenant' => $tenant], ['nome' => 'ASC']);
        $lotacoes    = $lotacaoRepository->findBy(['tenant' => $tenant], ['nome' => 'ASC']);

        if ($userTenant->getCodigoFuncionario() === null) {
            $userTenant->setCodigoFuncionario($gerarCodigo->executar($tenant));
        }

        $form = $this->createForm(EditUserTenantRoleType::class, $userTenant, [
            'tenant_roles'    => $tenantRoles,
            'tenant_cargos'   => $cargos,
            'tenant_lotacoes' => $lotacoes,
            'data_admissao'   => $userTenant->getDataAdmissao(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = $form->get('newPassword')->getData();

            if (is_string($newPassword) && trim($newPassword) !== '') {
                $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
            }

            $userTenant->setDataAdmissao($form->get('dataAdmissao')->getData());

            $entityManager->flush();
            $this->addFlash('success', 'Usuário atualizado com sucesso!');
            return $this->redirectToRoute('app_tenant_users', ['id' => $tenantId]);
        }

        $jornada                  = $user->getJornadaColaborador();
        $userHasCustomEscala      = $jornada !== null && !$jornada->getBlocos()->isEmpty();

        $userAccesses   = $resourceAccessRepository->findByUsers([$user])[(int) $user->getId()] ?? [];
        $resourceLabels = $this->resolveResourceLabels(
            [(int) $user->getId() => $userAccesses],
            $clienteRepository,
            $pastaRepository,
            $processoRepository,
        );

        $competenciasPonto = $registroPontoRepository->findCompetenciasComRegistroPorUsuario($user);
        $competenciaSelecionada = (string) $request->query->get('competencia', '');
        $competenciasDisponiveis = array_column($competenciasPonto, 'valor');

        if ($competenciaSelecionada === '' && !empty($competenciasPonto)) {
            $competenciaSelecionada = (string) $competenciasPonto[0]['valor'];
        }

        $jornadaTenant = $tenant->getJornadaTenant();

        $folhaRowsPonto = [];
        $mesCompetenciaPonto = null;
        $anoCompetenciaPonto = null;
        if ($competenciaSelecionada !== '' && in_array($competenciaSelecionada, $competenciasDisponiveis, true)) {
            [$anoSelecionado, $mesSelecionado] = array_map('intval', explode('-', $competenciaSelecionada));
            $batidasPonto = $registroPontoRepository->findByUserAndCompetencia($user, $anoSelecionado, $mesSelecionado);

            $inicioMes = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $anoSelecionado, $mesSelecionado));
            $fimMes = $inicioMes->modify('last day of this month')->setTime(23, 59, 59);
            $feriados = $feriadoRepository->findByTenant($tenant);
            $justificativasDoMes = $justificativaRepository->findByUserAndCompetenciaIndexed($user, $anoSelecionado, $mesSelecionado);
            $folhaRowsPonto = $folhaPontoBuilder->buildRows($inicioMes, $fimMes, $batidasPonto, true, false, $jornada, $feriados, $justificativasDoMes, $jornadaTenant);
            $mesCompetenciaPonto = $mesSelecionado;
            $anoCompetenciaPonto = $anoSelecionado;
        }

        $jornadaInfoUsuario = $this->resolverJornadaInfoAdmin($user, $jornadaTenant);
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
            'userAccesses'           => $userAccesses,
            'resourceLabels'         => $resourceLabels,
            'competenciasPonto'      => $competenciasPonto,
            'competenciaSelecionada' => $competenciaSelecionada,
            'folhaRowsPonto'         => $folhaRowsPonto,
            'mesCompetenciaPonto'    => $mesCompetenciaPonto,
            'anoCompetenciaPonto'    => $anoCompetenciaPonto,
            'jornadaInfo'            => $jornadaInfoUsuario,
            'justificativas'         => $justificativas,
            'tiposJustificativa'     => TipoJustificativa::asPlanarChoices(),
            'comSegundos'            => $this->deveMostrarSegundosBatida(),
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
            throw $this->createAccessDeniedException('Você não tem permissão para editar nomes de usuário.');
        }

        if (!$this->isCsrfTokenValid('edit_user_name_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('app_tenant_user_edit_role', ['tenantId' => $tenantId, 'id' => $user->getId()]);
        }

        $novoNome = trim($request->request->get('fullName', ''));

        if ($novoNome === '') {
            $this->addFlash('error', 'O nome não pode ser vazio.');
            return $this->redirectToRoute('app_tenant_user_edit_role', ['tenantId' => $tenantId, 'id' => $user->getId()]);
        }

        $user->setFullName($novoNome);
        $entityManager->flush();
        $this->addFlash('success', 'Nome atualizado com sucesso!');

        return $this->redirectToRoute('app_tenant_user_edit_role', ['tenantId' => $tenantId, 'id' => $user->getId()]);
    }

    #[Route('/{tenantId}/user/{userId}/demitir', name: 'app_tenant_user_demitir', methods: ['POST'])]
    public function demitirFuncionario(
        int $tenantId,
        int $userId,
        Request $request,
        DemitirFuncionarioUseCase $useCase,
        PermissionChecker $permissionChecker,
        EntityManagerInterface $em,
        TenantRepository $tenantRepository,
        UserTenantRepository $userTenantRepository,
    ): Response {
        $executor = $this->getUser();

        if (!$executor) {
            throw $this->createAccessDeniedException();
        }

        $tenant = $tenantRepository->find($tenantId);
        if (!$tenant) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('demitir_' . $userId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $funcionario = $em->find(User::class, $userId);

        if (!$funcionario) {
            throw $this->createNotFoundException('Funcionário não encontrado.');
        }

        if (!$userTenantRepository->existeVinculoAtivo($funcionario, $tenant)) {
            throw $this->createNotFoundException('Funcionário não encontrado.');
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $executor->getRoles(), true);
        $isOwnTenant  = $userTenantRepository->existeVinculoAtivo($executor, $tenant);

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($executor, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException();
        }

        $substitutoId = $request->request->getInt('substituto_id') ?: null;
        $substituto   = $substitutoId ? $em->find(User::class, $substitutoId) : null;

        if ($substituto !== null && !$userTenantRepository->existeVinculoAtivo($substituto, $tenant)) {
            throw $this->createNotFoundException('Funcionário não encontrado.');
        }

        try {
            $useCase->executar(new DemitirFuncionarioInput($executor, $funcionario, $tenant, $substituto));
            $this->addFlash('success', 'Funcionário demitido com sucesso.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_tenant_users', ['id' => $tenantId]);
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

        $form = $this->createForm(RegistroPontoManualType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var \DateTime $data */
            $data = $form->get('data')->getData();
            /** @var \DateTime $hora */
            $hora = $form->get('hora')->getData();

            $dataHora = new \DateTime(
                $data->format('Y-m-d') . ' ' . $hora->format('H:i:s')
            );

            $registro = new RegistroPonto();
            $registro->setUser($user);
            $registro->setDataHora($dataHora);
            $registro->setTipo($form->get('tipo')->getData());
            $registro->setObservacao($form->get('observacao')->getData());
            $registro->setSedeNomeSnapshot('Lançamento manual');

            $entityManager->persist($registro);
            $entityManager->flush();

            $this->addFlash('success', 'Batida registrada com sucesso.');
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

        $registro = $registroPontoRepository->find($registroId);

        if ($registro === null || $registro->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Registro não encontrado.');
        }

        $dataHoraAtual = $registro->getDataHora();

        $comSegundos = $this->deveMostrarSegundosBatida();
        $form = $this->createForm(RegistroPontoManualType::class, null, ['with_seconds' => $comSegundos]);

        // Pré-popular campos virtuais
        $form->get('data')->setData($dataHoraAtual instanceof \DateTimeInterface ? \DateTime::createFromInterface($dataHoraAtual) : null);
        $form->get('hora')->setData($dataHoraAtual instanceof \DateTimeInterface ? \DateTime::createFromInterface($dataHoraAtual) : null);
        $form->get('tipo')->setData($registro->getTipo());
        $form->get('observacao')->setData($registro->getObservacao());

        $form->handleRequest($request);

        $competencia = $request->query->get('competencia', $registro->getDataHora()?->format('Y-m') ?? '');

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var \DateTime $data */
            $data = $form->get('data')->getData();
            /** @var \DateTime $hora */
            $hora = $form->get('hora')->getData();

            $dataHora = new \DateTime(
                $data->format('Y-m-d') . ' ' . $hora->format('H:i:s')
            );

            $registro->setDataHora($dataHora);
            $registro->setTipo($form->get('tipo')->getData());
            $registro->setObservacao($form->get('observacao')->getData());
            $registro->setSedeNomeSnapshot('Lançamento manual');

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
            'comSegundos' => $comSegundos,
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
            throw $this->createAccessDeniedException('Sem permissão para abonar justificativas.');
        }

        if (!$this->isCsrfTokenValid('justificativa_aprovar_' . $justificativaId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $justificativa = $justificativaRepository->find($justificativaId);

        if ($justificativa === null || $justificativa->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Justificativa não encontrada.');
        }

        if ($justificativa->getStatus() !== 'pendente') {
            $this->addFlash('warning', 'Apenas justificativas pendentes podem ser abonadas.');
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

        if ($justificativa->getTipo() === 'esquecimento_registro'
            && $justificativa->getTipoRegistroEsquecido() !== null
            && $justificativa->getHoraRegistroEsquecido() !== null
            && $justificativa->getData() !== null
        ) {
            $dataHora = new \DateTime(
                $justificativa->getData()->format('Y-m-d') . ' '
                . $justificativa->getHoraRegistroEsquecido()->format('H:i:s')
            );
            $registro = new RegistroPonto();
            $registro->setUser($justificativa->getUser());
            $registro->setDataHora($dataHora);
            $registro->setTipo($justificativa->getTipoRegistroEsquecido());
            $registro->setObservacao('Criado por aprovação de justificativa (Esquecimento de Registro)');
            $entityManager->persist($registro);
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
            throw $this->createAccessDeniedException('Sem permissão para abonar justificativas.');
        }

        if (!$this->isCsrfTokenValid('justificativa_aprovar_todos_' . $batchId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $pendentes = $justificativaRepository->findBy(['user' => $user, 'batchId' => $batchId, 'status' => 'pendente']);

        if (empty($pendentes)) {
            $this->addFlash('warning', 'Nenhuma justificativa pendente encontrada neste lote.');
            return $this->redirectToRoute('app_tenant_user_edit_role', ['tenantId' => $tenantId, 'id' => $user->getId(), 'tab' => 'justificativas']);
        }

        $urlPonto = $this->generateUrl('ponto_index');
        foreach ($pendentes as $justificativa) {
            $justificativa->setStatus('abonado');
            $justificativa->setDataAnalise(new \DateTime());
            $justificativa->setAnalisadoPor($currentUser);
            $notificacaoService->notificarJustificativaAprovada($justificativa, $urlPonto);
        }

        $entityManager->flush();

        $this->addFlash('success', sprintf('%d justificativa(s) abonada(s) com sucesso.', count($pendentes)));

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

    #[Route('/{tenantId}/user/{id}/justificativa/nova', name: 'app_tenant_user_justificativa_nova', methods: ['POST'])]
    public function novaJustificativaAdmin(
        int $tenantId,
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
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
            if ($dataObj > $hoje) {
                $this->addFlash('warning', sprintf('A data %s é futura e foi ignorada.', $dataObj->format('d/m/Y')));
                continue;
            }
            $datasValidas[] = $dataObj;
        }

        if (empty($datasValidas)) {
            return $redirect();
        }

        $anexoPath = null;
        $anexoFile = $request->files->get('anexo');
        if ($anexoFile !== null) {
            $anexoPath = $this->storage->salvar($anexoFile, $this->justificativasUploadsDir);
        }

        $batchId    = bin2hex(random_bytes(16));
        $isFaltaNaoJustificada = $tipo === 'falta_nao_justificada';

        foreach ($datasValidas as $dataObj) {
            $justificativa = new JustificativaPonto();
            $justificativa->setUser($user);
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
                $registro->setDataHora($dataHora);
                $registro->setTipo($tipoRegEsquecido);
                $registro->setObservacao('Criado pelo administrador via justificativa (Esquecimento de Registro)');
                $entityManager->persist($registro);
            }
        }

        $entityManager->flush();

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
            throw $this->createAccessDeniedException('Sem permissão.');
        }

        $justificativa = $justificativaRepository->find($justificativaId);

        if ($justificativa === null || $justificativa->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Justificativa não encontrada.');
        }

        if ($justificativa->getAnexoPath() === null) {
            throw $this->createNotFoundException('Esta justificativa não possui atestado.');
        }

        $filePath = $this->storage->caminho($this->justificativasUploadsDir, $justificativa->getAnexoPath());

        if (!$this->storage->existe($filePath)) {
            throw $this->createNotFoundException('Arquivo não encontrado.');
        }

        return $this->storage->servir($filePath, $justificativa->getAnexoPath(), inline: true);
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

        $resourceAccess = $resourceAccessRepository->find($raId);

        // Garante que o acesso pertence ao usuário correto do tenant
        if ($resourceAccess === null || (int) $resourceAccess->getUser()?->getId() !== $userId) {
            throw $this->createNotFoundException('Acesso não encontrado.');
        }

        $entityManager->remove($resourceAccess);
        $entityManager->flush();

        $this->addFlash('success', 'Acesso específico removido com sucesso.');

        return $this->redirectToRoute('app_tenant_user_edit_role', [
            'tenantId' => $tenantId,
            'id'       => $userId,
            'tab'      => 'acessos',
        ]);
    }

    #[Route('/{id}/sedes', name: 'app_tenant_sedes', methods: ['GET', 'POST'])]
    public function manageSedes(
        Tenant $tenant,
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

            $this->addFlash('success', 'Sede cadastrada com sucesso!');
            return $this->redirectToRoute('app_tenant_sedes', ['id' => $tenant->getId()]);
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
            $this->addFlash('success', 'Sede atualizada com sucesso!');

            return $this->redirectToRoute('app_tenant_sedes', ['id' => $tenantId]);
        }

        // Falha de validação: re-renderizar a página com o form de edição com erros
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

            $this->addFlash('success', 'Sede removida com sucesso.');

            if ($totalDesvinculados > 0) {
                $this->addFlash('info', sprintf('%d registro(s) de ponto foram desvinculados automaticamente da sede removida.', $totalDesvinculados));
            }
        } catch (\Throwable) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $this->addFlash('danger', 'Não foi possível remover a sede neste momento. Tente novamente.');
        }

        return $this->redirectToRoute('app_tenant_sedes', ['id' => $tenantId]);
    }

}
