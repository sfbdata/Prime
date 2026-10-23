<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Form\TenantRoleType;
use App\Repository\PermissionRepository;
use App\Repository\TenantRoleRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use App\Tenant\DTO\PedirAcessoAosPerfisInput;
use App\Tenant\DTO\PromoverPrimarioSocioInput;
use App\Tenant\DTO\RemoverPrimarioSocioInput;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use App\Tenant\Service\GruposDePermissaoDoPerfil;
use App\Tenant\Service\HierarquiaDePerfis;
use App\Tenant\Repository\ConfiguracaoDeEdicaoRepository;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tenant\UseCase\PedirAcessoAosPerfisUseCase;
use App\Tenant\UseCase\PromoverPrimarioSocioUseCase;
use App\Tenant\UseCase\RemoverPrimarioSocioUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Perfis do escritório (desenho "05 - Perfis 1.0", spec docs/specs/perfis-master-1.0.md §4.1/§4.2).
 *
 * Três classificações: MASTER (linha virtual, só para ROLE_SUPER_ADMIN, abre o Painel do
 * Master), PRIMÁRIO (o perfil isSystem do escritório: acesso total, caixas travadas, D5) e
 * SECUNDÁRIO (os demais perfis, editáveis).
 *
 * O texto que vai à tela (flash, erro do formulário, recusa da hierarquia, descrições da lista) sai do catálogo
 * (perfis.*, spec idioma-do-sistema) no idioma da requisição. A mensagem das exceções 403/404 não chega à tela
 * (a página de erro é a padrão) e fica em português.
 */
#[Route('/tenant/{tenantId}/roles')]
final class TenantRoleController extends AbstractController
{
    private const DESCRICAO_MASTER   = 'perfis.lista.descricao_master';
    private const DESCRICAO_PRIMARIO = 'perfis.lista.descricao_primario';
    private const DESCRICAO_SECUNDARIO_VAZIA = 'perfis.lista.descricao_secundario_vazia';


    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly GruposDePermissaoDoPerfil $gruposDePermissao,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly HierarquiaDePerfis $hierarquia,
        private readonly TranslatorInterface $tradutor,
        private readonly ConfiguracaoDeEdicaoRepository $configuracaoDeEdicao,
    ) {}

    /** @param array<string, mixed> $parametros */
    private function traduzir(string $chave, array $parametros = []): string
    {
        return $this->tradutor->trans($chave, $parametros);
    }

    /** C6: a recusa do desenho, no idioma de quem lê. */
    private function recusaDeNivel(): string
    {
        return $this->traduzir(HierarquiaDePerfis::CHAVE_DA_RECUSA[HierarquiaDePerfis::RECUSA_MESMO_NIVEL_OU_ACIMA]);
    }

    private function assertAccess(int $tenantId): Tenant
    {
        $tenant = $this->tenantDaRota($tenantId);

        // C2 (spec perfis-1.2-permissoes-e-hierarquia): primário, Master ou `admin.roles.manage`.
        if (!$this->hierarquia->podeVerALista($this->ator(), $tenant)) {
            throw $this->createAccessDeniedException('Você não tem permissão para gerenciar perfis.');
        }

        return $tenant;
    }

    private function tenantDaRota(int $tenantId): Tenant
    {
        $this->ator();
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $tenant->getId() !== $tenantId) {
            throw $this->createAccessDeniedException('Você não tem acesso aos perfis deste escritório.');
        }

        return $tenant;
    }

    private function ator(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * Tela "Acesso restrito" (desenho L496), no lugar do 403 seco, para quem não pode ver os Perfis (C2). Continua
     * respondendo 403.
     */
    private function acessoRestrito(Tenant $tenant, PedirAcessoAosPerfisUseCase $pedirAcesso): Response
    {
        return $this->render('tenant_role/acesso_restrito.html.twig', [
            'tenantId'    => $tenant->getId(),
            'jaPediuHoje' => $pedirAcesso->jaPediuHoje($tenant, $this->ator()),
        ], new Response('', Response::HTTP_FORBIDDEN));
    }

    /**
     * Promover e remover sócios é do PRIMÁRIO (ou do Master), não de qualquer secundário com
     * `admin.roles.manage`: senão um secundário com essa permissão poderia se promover a primário
     * e ganhar o acesso total que o perfil de sistema dá (spec D4: "o primário promove").
     */
    private function assertPrimarioOuMaster(Tenant $tenant): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->regraDosPrimarios->podeGerirPrimarios($user, $tenant)) {
            throw $this->createAccessDeniedException('Só o primário do escritório pode promover ou remover primários.');
        }

        return $user;
    }

    #[Route('', name: 'app_tenant_role_index', methods: ['GET'])]
    public function index(
        int $tenantId,
        TenantRoleRepository $roleRepository,
        PermissionRepository $permissionRepository,
        UserRepository $userRepository,
        UserTenantRepository $userTenantRepository,
        PedirAcessoAosPerfisUseCase $pedirAcesso
    ): Response {
        $tenant = $this->tenantDaRota($tenantId);
        if (!$this->hierarquia->podeVerALista($this->ator(), $tenant)) {
            return $this->acessoRestrito($tenant, $pedirAcesso);
        }

        $totalDoCatalogo = $this->gruposDePermissao->total(
            $this->gruposDePermissao->agrupar($permissionRepository->findAllGrouped())
        );

        // Contagem no banco, não no lado inverso da associação (TenantRole::userTenants), que em
        // memória pode estar desatualizado.
        $usuariosPorPerfil = $userTenantRepository->contarAtivosPorPerfil($tenant);
        // A confirmação de exclusão fala de TODOS os vínculos que o delete solta, inativos inclusive.
        $vinculosPorPerfil = $userTenantRepository->contarVinculosPorPerfil($tenant);

        $perfis = [];
        foreach ($this->perfisVisiveis($tenant, $roleRepository) as $role) {
            $usuarios = $usuariosPorPerfil[(int) $role->getId()] ?? 0;

            $descricao = trim((string) $role->getDescription());
            $perfis[] = [
                'id'            => $role->getId(),
                'nome'          => $role->getNomeExibido(),
                'classificacao' => $role->isSystem() ? 'primario' : 'secundario',
                'descricao'     => $role->isSystem()
                    ? $this->traduzir(self::DESCRICAO_PRIMARIO)
                    : ($descricao !== '' ? $descricao : $this->traduzir(self::DESCRICAO_SECUNDARIO_VAZIA)),
                'permissoes'    => $role->isSystem() ? $totalDoCatalogo : $role->getTenantRolePermissions()->count(),
                'usuarios'      => $usuarios,
                'vinculos'      => $vinculosPorPerfil[(int) $role->getId()] ?? 0,
            ] + $this->acoesDoPerfil($tenant, $role);
        }

        // A linha Master só existe para o Master (D2): nada dela chega ao HTML de quem não é.
        $master = null;
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            $master = [
                'nome'      => $this->traduzir('perfis.lista.master_nome', ['papel' => $this->traduzir('termos.master')]),
                'descricao' => $this->traduzir(self::DESCRICAO_MASTER),
                'usuarios'  => count($userRepository->findComPapelGlobal('ROLE_SUPER_ADMIN')),
            ];
        }

        return $this->renderLista($tenant, [
            'tenantId'        => $tenantId,
            'perfis'          => $perfis,
            'master'          => $master,
            'totalDoCatalogo' => $totalDoCatalogo,
            'resumo'          => $this->resumoDaLista($perfis, $master),
        ]);
    }

    /**
     * C2: só os perfis de nível até o do ator (o secundário não vê a linha do primário).
     *
     * @return list<TenantRole>
     */
    private function perfisVisiveis(Tenant $tenant, TenantRoleRepository $roleRepository): array
    {
        return array_values(array_filter(
            $roleRepository->findByTenantId((int) $tenant->getId()),
            fn (TenantRole $role): bool => $this->hierarquia->podeVerOPerfil($this->ator(), $tenant, $role)
        ));
    }

    /**
     * C3/C6: editar e excluir só perfil de nível abaixo do ator; a tela desabilita, o servidor recusa.
     *
     * @return array{fixo: bool, podeEditar: bool, podeExcluir: bool}
     */
    private function acoesDoPerfil(Tenant $tenant, TenantRole $role): array
    {
        return [
            'fixo'        => $role->isSystem(),
            'podeEditar'  => $this->hierarquia->podeEditarPerfil($this->ator(), $tenant, $role),
            'podeExcluir' => $this->hierarquia->podeExcluirPerfil($this->ator(), $tenant, $role),
        ];
    }

    /**
     * A lista com o "Novo Perfil" (C3: só quem está acima do secundário cria) e a mensagem do desenho (C6).
     *
     * @param array<string, mixed> $dados
     */
    private function renderLista(Tenant $tenant, array $dados): Response
    {
        return $this->render('tenant_role/index.html.twig', $dados + [
            'podeCriar'     => $this->hierarquia->podeCriarPerfil($this->ator(), $tenant),
            'recusaDeNivel' => $this->recusaDeNivel(),
        ]);
    }

    /** C3: recusa no servidor o que a hierarquia não deixa (criar, editar, excluir), com a mensagem do desenho. */
    private function garantirNivel(bool $pode): void
    {
        if (!$pode) {
            throw $this->createAccessDeniedException($this->recusaDeNivel());
        }
    }

    /**
     * Chips do resumo da lista (desenho Perfis 1.2, `pfResumo`). A visão "primario" conta só os
     * perfis do escritório; a "master" soma a linha Master e só existe para o Master (D2), para
     * o "Ver como Primário" trocar os números sem nada do Master chegar ao HTML de quem não é.
     *
     * @param list<array{classificacao: string, usuarios: int}> $perfis
     * @param array{usuarios: int}|null                         $master
     *
     * @return array{primario: array<string, int>, master: array<string, int>|null}
     */
    private function resumoDaLista(array $perfis, ?array $master): array
    {
        $fixos    = count(array_filter($perfis, static fn (array $p): bool => $p['classificacao'] !== 'secundario'));
        $primario = [
            'perfis'         => count($perfis),
            'usuarios'       => array_sum(array_column($perfis, 'usuarios')),
            'fixos'          => $fixos,
            'personalizados' => count($perfis) - $fixos,
        ];

        return [
            'primario' => $primario,
            'master'   => $master === null ? null : [
                'perfis'         => $primario['perfis'] + 1,
                'usuarios'       => $primario['usuarios'] + $master['usuarios'],
                'fixos'          => $primario['fixos'] + 1,
                'personalizados' => $primario['personalizados'],
            ],
        ];
    }

    #[Route('/new', name: 'app_tenant_role_new', methods: ['GET', 'POST'])]
    public function new(
        int $tenantId,
        Request $request,
        EntityManagerInterface $em,
        PermissionRepository $permissionRepository,
        TenantRoleRepository $roleRepository,
        PedirAcessoAosPerfisUseCase $pedirAcesso
    ): Response {
        $tenant = $this->tenantDaRota($tenantId);
        if (!$this->hierarquia->podeVerALista($this->ator(), $tenant)) {
            return $this->acessoRestrito($tenant, $pedirAcesso);
        }
        $this->garantirNivel($this->hierarquia->podeCriarPerfil($this->ator(), $tenant)); // C3: o novo é secundário

        $role = new TenantRole();
        $role->setTenant($tenant);

        $allPermissions = $permissionRepository->findAllGrouped();

        $form = $this->createForm(TenantRoleType::class, $role, [
            'permissions' => $allPermissions,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->recusarNomeRepetido($form, $role, $roleRepository, $tenant);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedCodes = $form->get('permissions')->getData() ?? [];
            $this->syncPermissions($role, $selectedCodes, $permissionRepository, $em);

            $em->persist($role);
            $em->flush();

            $this->addFlash('success', $this->traduzir('perfis.flash.criado'));

            return $this->redirectToRoute('app_tenant_role_index', ['tenantId' => $tenantId]);
        }

        $cartoes = $this->gruposDePermissao->agrupar($allPermissions);

        return $this->render('tenant_role/new.html.twig', [
            'tenantId' => $tenantId,
            'form'     => $form,
            'cartoes'  => $cartoes,
            'total'    => $this->gruposDePermissao->total($cartoes),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_tenant_role_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $tenantId,
        TenantRole $role,
        Request $request,
        EntityManagerInterface $em,
        PermissionRepository $permissionRepository,
        TenantRoleRepository $roleRepository,
        UserTenantRepository $userTenantRepository,
        PedirAcessoAosPerfisUseCase $pedirAcesso
    ): Response {
        $tenant = $this->tenantDaRota($tenantId);
        if (!$this->hierarquia->podeVerALista($this->ator(), $tenant)) {
            return $this->acessoRestrito($tenant, $pedirAcesso);
        }

        if ($role->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Perfil não encontrado neste escritório.');
        }
        $this->garantirNivel($this->hierarquia->podeEditarPerfil($this->ator(), $tenant, $role)); // C3, também o próprio

        $allPermissions = $permissionRepository->findAllGrouped();
        $cartoes        = $this->gruposDePermissao->agrupar($allPermissions);

        // Perfil primário: acesso total, caixas travadas (D5), nome fixo. Não há formulário, então
        // um POST aqui não muda nada: as caixas desabilitadas nem seriam enviadas, e sincronizar
        // "nada marcado" apagaria as permissões gravadas do perfil.
        if ($role->isSystem()) {
            return $this->renderPrimario($tenant, $role, $cartoes, $roleRepository, $userTenantRepository);
        }

        // Códigos já associados ao perfil (para pré-marcar checkboxes)
        $currentCodes = [];
        foreach ($role->getTenantRolePermissions() as $trp) {
            $currentCodes[] = $trp->getPermission()?->getCode();
        }

        $form = $this->createForm(TenantRoleType::class, $role, [
            'permissions'    => $allPermissions,
            'selected_codes' => $currentCodes,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->recusarNomeRepetido($form, $role, $roleRepository, $tenant);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedCodes = $form->get('permissions')->getData() ?? [];
            $this->syncPermissions($role, $selectedCodes, $permissionRepository, $em);
            $role->setUpdatedAt(new \DateTimeImmutable());

            $em->flush();

            $this->addFlash('success', $this->traduzir('perfis.flash.atualizado'));

            return $this->redirectToRoute('app_tenant_role_index', ['tenantId' => $tenantId]);
        }

        return $this->render('tenant_role/edit.html.twig', [
            'tenantId' => $tenantId,
            'role'     => $role,
            'form'     => $form,
            'cartoes'  => $cartoes,
            'total'    => $this->gruposDePermissao->total($cartoes),
        ]);
    }

    #[Route('/primarios/promover', name: 'app_tenant_role_primario_promover', methods: ['POST'])]
    public function promoverPrimario(
        int $tenantId,
        Request $request,
        UserRepository $userRepository,
        TenantRoleRepository $roleRepository,
        PromoverPrimarioSocioUseCase $promover
    ): Response {
        $tenant = $this->assertAccess($tenantId);
        $ator   = $this->assertPrimarioOuMaster($tenant);

        if (!$this->isCsrfTokenValid('promover_primario_' . $tenant->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $alvoId = $request->request->getInt('usuario');
        $alvo   = $alvoId > 0 ? $userRepository->findPorIdETenant($alvoId, $tenant) : null;

        if ($alvo === null) {
            $this->addFlash('error', $this->traduzir('perfis.flash.escolha_secundario'));

            return $this->voltarAoPerfilPrimario($tenant, $roleRepository);
        }

        try {
            $promover->executar(new PromoverPrimarioSocioInput($tenant, $alvo, $ator));
            $this->addFlash('success', $this->traduzir('perfis.flash.promovido', ['nome' => (string) $alvo->getFullName()]));
        } catch (OperacaoDePrimarioRecusada $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->voltarAoPerfilPrimario($tenant, $roleRepository);
    }

    #[Route('/primarios/{userId}/remover', name: 'app_tenant_role_primario_remover', requirements: ['userId' => '\d+'], methods: ['POST'])]
    public function removerPrimario(
        int $tenantId,
        int $userId,
        Request $request,
        UserRepository $userRepository,
        TenantRoleRepository $roleRepository,
        RemoverPrimarioSocioUseCase $remover
    ): Response {
        $tenant = $this->assertAccess($tenantId);
        $ator   = $this->assertPrimarioOuMaster($tenant);

        if (!$this->isCsrfTokenValid('remover_primario_' . $userId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $alvo = $userRepository->findPorIdETenant($userId, $tenant);
        if ($alvo === null) {
            throw $this->createNotFoundException('Usuário não encontrado neste escritório.');
        }

        // O destino é buscado PELO escritório: id de perfil de outra conta não encontra nada.
        $destinoId = $request->request->getInt('perfil_destino');
        $destino   = $destinoId > 0
            ? $roleRepository->findOneBy(['id' => $destinoId, 'tenant' => $tenant])
            : null;

        try {
            $remover->executar(new RemoverPrimarioSocioInput($tenant, $alvo, $destino, $ator));
            $this->addFlash('success', $this->traduzir('perfis.flash.removido', ['nome' => (string) $alvo->getFullName()]));
        } catch (OperacaoDePrimarioRecusada $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->voltarAoPerfilPrimario($tenant, $roleRepository);
    }

    /**
     * "Pedir acesso" da tela "Acesso restrito" (C2): avisa os primários ativos, um pedido por pessoa por dia.
     * Declarada antes do delete (`/{id}`), que também casaria o caminho.
     */
    #[Route('/pedir-acesso', name: 'app_tenant_role_pedir_acesso', methods: ['POST'])]
    public function pedirAcesso(int $tenantId, Request $request, PedirAcessoAosPerfisUseCase $pedirAcesso): Response
    {
        $tenant = $this->tenantDaRota($tenantId);
        $ator   = $this->ator();

        if (!$this->isCsrfTokenValid('pedir_acesso_perfis_' . $tenant->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Quem já vê os Perfis não tem o que pedir; quem já pediu hoje volta para a tela com o pedido enviado.
        if (!$this->hierarquia->podeVerALista($ator, $tenant) && !$pedirAcesso->jaPediuHoje($tenant, $ator)) {
            if ($pedirAcesso->executar(new PedirAcessoAosPerfisInput($tenant, $ator)) === 0) {
                $this->addFlash('error', $this->traduzir('perfis.flash.sem_primario'));
            }
        }

        return $this->redirectToRoute('app_tenant_role_index', ['tenantId' => $tenantId]);
    }

    #[Route('/{id}', name: 'app_tenant_role_delete', methods: ['POST'])]
    public function delete(
        int $tenantId,
        TenantRole $role,
        Request $request,
        EntityManagerInterface $em,
        UserTenantRepository $userTenantRepository
    ): Response {
        $tenant = $this->assertAccess($tenantId);

        if ($role->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Perfil não encontrado neste escritório.');
        }

        if ($role->isSystem()) {
            $this->addFlash('error', $this->traduzir('perfis.flash.sistema_nao_exclui'));
            return $this->redirectToRoute('app_tenant_role_index', ['tenantId' => $tenantId]);
        }
        $this->garantirNivel($this->hierarquia->podeExcluirPerfil($this->ator(), $tenant, $role)); // C3, também o próprio

        if ($this->isCsrfTokenValid('delete_role_' . $role->getId(), $request->request->get('_token'))) {
            // O desenho promete: "os usuários com este perfil ficam sem perfil até você atribuir
            // outro". A FK user_tenant.tenant_role_id não tem ON DELETE: sem soltar os vínculos
            // antes, excluir perfil em uso estourava erro de integridade (500).
            // Busca no banco, não no lado inverso da associação: a coleção em memória pode estar
            // desatualizada (vínculo criado nesta mesma unidade de trabalho).
            foreach ($userTenantRepository->findBy(['tenantRole' => $role]) as $vinculo) {
                $vinculo->setTenantRole(null);
            }

            $em->remove($role);
            $em->flush();
            $this->addFlash('success', $this->traduzir('perfis.flash.excluido'));
        }

        return $this->redirectToRoute('app_tenant_role_index', ['tenantId' => $tenantId]);
    }

    /**
     * Tela do perfil primário: bloco "Primários do escritório", promoção de sócio e as caixas
     * de permissão marcadas e travadas (D5).
     *
     * @param list<array{rotulo: string, icone: string, itens: list<array{codigo: string, rotulo: string}>}> $cartoes
     */
    private function renderPrimario(
        Tenant $tenant,
        TenantRole $role,
        array $cartoes,
        TenantRoleRepository $roleRepository,
        UserTenantRepository $userTenantRepository
    ): Response {
        $ator        = $this->getUser();
        $primarios   = [];
        $secundarios = [];
        foreach ($userTenantRepository->findAtivosComPerfilPorTenant($tenant) as $vinculo) {
            // Conta do Master: para os outros, sem lixeira e fora do select de promover (D2).
            $protegido = !$ator instanceof User
                || !$this->regraDosPrimarios->podeAlterarConta($ator, $vinculo->getUser());

            if ($this->regraDosPrimarios->ehPrimario($vinculo)) {
                $primarios[] = $this->pessoa($vinculo) + [
                    'titular'   => $this->regraDosPrimarios->ehTitular($vinculo),
                    'protegido' => $protegido,
                ];

                continue;
            }

            if ($vinculo->getUser()->isActive() && !$protegido) {
                $secundarios[] = $this->pessoa($vinculo) + [
                    'perfil' => $vinculo->getTenantRole()?->getName() ?? $this->traduzir('perfis.editar.sem_perfil'),
                ];
            }
        }

        // Titular primeiro (o desenho abre o bloco por ele), depois os sócios por nome.
        usort($primarios, static fn (array $a, array $b): int => [$b['titular'], $a['nome']] <=> [$a['titular'], $b['nome']]);

        $perfisSecundarios = array_map(
            static fn (TenantRole $r): array => ['id' => $r->getId(), 'nome' => $r->getName()],
            $roleRepository->findSecundarios($tenant)
        );

        return $this->render('tenant_role/edit.html.twig', [
            'tenantId'          => $tenant->getId(),
            'role'              => $role,
            'podeGerirPrimarios' => $ator instanceof User && $this->regraDosPrimarios->podeGerirPrimarios($ator, $tenant),
            'form'              => null,
            'cartoes'           => $cartoes,
            'total'             => $this->gruposDePermissao->total($cartoes),
            'primarios'         => $primarios,
            'secundarios'       => $secundarios,
            'perfisSecundarios' => $perfisSecundarios,
            // Edição sem prazo (spec edicao-sem-prazo §3): a opção do escritório; só um primário ativo muda.
            'edicaoSemPrazo'    => $this->configuracaoDeEdicao->primarioSemPrazo($tenant),
            'podeMudarSemPrazo' => $ator instanceof User && $this->ehPrimarioAtivo($ator, $tenant, $userTenantRepository),
        ]);
    }

    private function ehPrimarioAtivo(User $ator, Tenant $tenant, UserTenantRepository $vinculos): bool
    {
        $vinculo = $vinculos->findAtivoPorUserETenant($ator, $tenant);

        return $vinculo !== null && $this->regraDosPrimarios->ehPrimario($vinculo);
    }

    /**
     * @return array{userId: int|null, nome: string, email: string, oab: string, iniciais: string}
     */
    private function pessoa(UserTenant $vinculo): array
    {
        $user = $vinculo->getUser();
        $nome = trim((string) $user->getFullName());
        if ($nome === '') {
            $nome = (string) $user->getEmail();
        }

        $oab = '';
        if ($user->getOabNumero() !== null && $user->getOabNumero() !== '') {
            $oab = $user->getOabNumero() . ($user->getOabUf() ? '/' . $user->getOabUf() : '');
        }

        $partes   = array_slice(array_values(array_filter(preg_split('/\s+/u', $nome) ?: [])), 0, 2);
        $iniciais = mb_strtoupper(implode('', array_map(static fn (string $p): string => mb_substr($p, 0, 1), $partes)));

        return [
            'userId'   => $user->getId(),
            'nome'     => $nome,
            'email'    => (string) $user->getEmail(),
            'oab'      => $oab,
            'iniciais' => $iniciais,
        ];
    }

    private function voltarAoPerfilPrimario(Tenant $tenant, TenantRoleRepository $roleRepository): Response
    {
        $perfil = $roleRepository->findPerfilDoPrimario($tenant);
        if ($perfil === null) {
            return $this->redirectToRoute('app_tenant_role_index', ['tenantId' => $tenant->getId()]);
        }

        return $this->redirectToRoute('app_tenant_role_edit', ['tenantId' => $tenant->getId(), 'id' => $perfil->getId()]);
    }

    /**
     * "Já existe um perfil com esse nome." (desenho). Além de ser confuso, um secundário com o
     * nome do perfil de sistema enganaria o TenantBootstrapService, que acha o primário pelo nome.
     */
    private function recusarNomeRepetido(
        FormInterface $form,
        TenantRole $role,
        TenantRoleRepository $roleRepository,
        Tenant $tenant
    ): void {
        $nome = mb_strtolower(trim((string) $role->getName()));
        if ($nome === '') {
            return;
        }

        foreach ($roleRepository->findByTenantId((int) $tenant->getId()) as $outro) {
            if ($outro !== $role && mb_strtolower(trim((string) $outro->getName())) === $nome) {
                $form->get('name')->addError(new FormError($this->traduzir('perfis.form.nome_repetido')));

                return;
            }
        }
    }

    /**
     * Sincroniza as TenantRolePermissions com os códigos selecionados no form.
     * Remove as não selecionadas e adiciona as novas.
     *
     * @param string[] $selectedCodes
     */
    private function syncPermissions(
        TenantRole $role,
        array $selectedCodes,
        PermissionRepository $permissionRepository,
        EntityManagerInterface $em
    ): void {
        // Remove permissões não selecionadas
        foreach ($role->getTenantRolePermissions() as $trp) {
            $code = $trp->getPermission()?->getCode();
            if (!in_array($code, $selectedCodes, true)) {
                $role->getTenantRolePermissions()->removeElement($trp);
                $em->remove($trp);
            }
        }

        // Códigos já presentes
        $existingCodes = [];
        foreach ($role->getTenantRolePermissions() as $trp) {
            $existingCodes[] = $trp->getPermission()?->getCode();
        }

        // Adiciona as novas
        foreach ($selectedCodes as $code) {
            if (in_array($code, $existingCodes, true)) {
                continue;
            }
            $permission = $permissionRepository->findOneBy(['code' => $code]);
            if (!$permission) {
                continue;
            }
            $trp = new TenantRolePermission();
            $trp->setTenantRole($role);
            $trp->setPermission($permission);
            $em->persist($trp);
            $role->getTenantRolePermissions()->add($trp);
        }
    }
}
