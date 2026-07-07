<?php

declare(strict_types=1);

namespace App\Tests\Notificacao\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Permission\Permission;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\Pasta;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Service\NotificacaoService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(NotificacaoService::class)]
final class NotificacaoServiceTest extends KernelTestCase
{
    use Factories;

    private NotificacaoService $service;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = static::getContainer()->get(NotificacaoService::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('notificarNovoChamado notifica só gestores com admin.servicedesk.manage e exclui o solicitante')]
    public function testNotificaApenasGestoresExcluindoSolicitante(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant SD ' . uniqid());
        $this->em->persist($tenant);

        // Papel COM a permissão de gestão de service desk (não-sistema, força o lookup).
        $permissao = $this->obterPermissao('admin.servicedesk.manage');
        $papelGestor = $this->criarPapel($tenant, 'Gestor TI', $permissao);

        // Papel SEM nenhuma permissão de service desk.
        $papelComum = $this->criarPapel($tenant, 'Colaborador', null);

        $gestor      = $this->criarUsuario($tenant, $papelGestor, 'gestor_');
        $comum       = $this->criarUsuario($tenant, $papelComum, 'comum_');
        // Solicitante também é gestor: deve ser excluído mesmo assim.
        $solicitante = $this->criarUsuario($tenant, $papelGestor, 'solic_');

        $chamado = new Chamado();
        $chamado->setTitulo('Impressora não funciona');
        $chamado->setTenant($tenant);
        $chamado->setSolicitante($solicitante);
        $this->em->persist($chamado);
        $this->em->flush();

        $tenantId      = (int) $tenant->getId();
        $chamadoId     = (int) $chamado->getId();
        $gestorId      = (int) $gestor->getId();
        $comumId       = (int) $comum->getId();
        $solicitanteId = (int) $solicitante->getId();

        // Recarrega tudo do banco: o lado inverso (coleção de permissões do papel) só é
        // carregado via lazy-load quando partimos de entidades frescas.
        $this->em->clear();

        $tenant  = $this->em->find(Tenant::class, $tenantId);
        $chamado = $this->em->find(Chamado::class, $chamadoId);
        $url     = '/servicedesk/' . $chamadoId;

        $this->service->notificarNovoChamado($chamado, $tenant, $url);

        self::assertCount(1, $this->notificacoesDe($gestorId), 'gestor com a permissão deve receber 1 notificação');
        self::assertCount(0, $this->notificacoesDe($comumId), 'usuário sem a permissão não recebe');
        self::assertCount(0, $this->notificacoesDe($solicitanteId), 'o solicitante é excluído mesmo sendo gestor');

        $notificacao = $this->notificacoesDe($gestorId)[0];
        self::assertSame(Notificacao::TIPO_SERVICEDESK_NOVO, $notificacao->getTipo());
        self::assertSame($url, $notificacao->getUrl());
        self::assertStringContainsString('Impressora não funciona', $notificacao->getTitulo());
        self::assertSame(Notificacao::CATEGORIA_GESTAO, $notificacao->getCategoria());
    }

    #[TestDox('notificarNovoChamado não vaza para gestores de outro tenant')]
    public function testNaoNotificaGestorDeOutroTenant(): void
    {
        $permissao = $this->obterPermissao('admin.servicedesk.manage');

        $tenantA = new Tenant();
        $tenantA->setName('Tenant A ' . uniqid());
        $this->em->persist($tenantA);

        $tenantB = new Tenant();
        $tenantB->setName('Tenant B ' . uniqid());
        $this->em->persist($tenantB);

        $gestorA = $this->criarUsuario($tenantA, $this->criarPapel($tenantA, 'Gestor A', $permissao), 'gestorA_');
        $gestorB = $this->criarUsuario($tenantB, $this->criarPapel($tenantB, 'Gestor B', $permissao), 'gestorB_');
        $solicitante = $this->criarUsuario($tenantA, $this->criarPapel($tenantA, 'Colab A', null), 'solic_');

        $chamado = new Chamado();
        $chamado->setTitulo('Chamado do tenant A');
        $chamado->setTenant($tenantA);
        $chamado->setSolicitante($solicitante);
        $this->em->persist($chamado);
        $this->em->flush();

        $tenantAId = (int) $tenantA->getId();
        $chamadoId = (int) $chamado->getId();
        $gestorAId = (int) $gestorA->getId();
        $gestorBId = (int) $gestorB->getId();
        $this->em->clear();

        $tenantA = $this->em->find(Tenant::class, $tenantAId);
        $chamado = $this->em->find(Chamado::class, $chamadoId);

        $this->service->notificarNovoChamado($chamado, $tenantA, '/servicedesk/' . $chamadoId);

        self::assertCount(1, $this->notificacoesDe($gestorAId), 'gestor do tenant do chamado recebe');
        self::assertCount(0, $this->notificacoesDe($gestorBId), 'gestor de outro tenant NÃO recebe (isolamento)');
    }

    #[TestDox('notificarNovoChamado não cria notificações quando não há gestores no tenant')]
    public function testSemGestoresNaoCriaNotificacoes(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant SD ' . uniqid());
        $this->em->persist($tenant);

        $papelComum  = $this->criarPapel($tenant, 'Colaborador', null);
        $comum       = $this->criarUsuario($tenant, $papelComum, 'comum_');
        $solicitante = $this->criarUsuario($tenant, $papelComum, 'solic_');

        $chamado = new Chamado();
        $chamado->setTitulo('Sem gestores');
        $chamado->setTenant($tenant);
        $chamado->setSolicitante($solicitante);
        $this->em->persist($chamado);
        $this->em->flush();

        $tenantId  = (int) $tenant->getId();
        $chamadoId = (int) $chamado->getId();
        $comumId   = (int) $comum->getId();
        $this->em->clear();

        $tenant  = $this->em->find(Tenant::class, $tenantId);
        $chamado = $this->em->find(Chamado::class, $chamadoId);

        $this->service->notificarNovoChamado($chamado, $tenant, '/servicedesk/' . $chamadoId);

        self::assertCount(0, $this->notificacoesDe($comumId));
    }

    #[TestDox('notificarTarefaCriada cria uma notificação TAREFA_CRIADA para cada responsável')]
    public function testNotificarTarefaCriadaNotificaCadaResponsavel(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Meta ' . uniqid());
        $this->em->persist($tenant);
        $papel = $this->criarPapel($tenant, 'Colab', null);

        $autor = $this->criarUsuario($tenant, $papel, 'autor_');
        $resp1 = $this->criarUsuario($tenant, $papel, 'resp1_');
        $resp2 = $this->criarUsuario($tenant, $papel, 'resp2_');
        $tarefa = $this->criarTarefa($tenant, $autor, [$resp1, $resp2]);

        $tarefaId = (int) $tarefa->getId();
        $resp1Id  = (int) $resp1->getId();
        $resp2Id  = (int) $resp2->getId();
        $this->em->clear();

        $tarefa = $this->em->find(Tarefa::class, $tarefaId);
        $this->service->notificarTarefaCriada($tarefa);

        self::assertCount(1, $this->notificacoesDe($resp1Id), 'responsável 1 deve receber 1 notificação');
        self::assertCount(1, $this->notificacoesDe($resp2Id), 'responsável 2 deve receber 1 notificação');
        self::assertSame(Notificacao::TIPO_TAREFA_CRIADA, $this->notificacoesDe($resp1Id)[0]->getTipo());
    }

    #[TestDox('notificarTarefaPendente cria uma notificação TAREFA_PENDENTE para cada responsável')]
    public function testNotificarTarefaPendenteNotificaCadaResponsavel(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Meta ' . uniqid());
        $this->em->persist($tenant);
        $papel = $this->criarPapel($tenant, 'Colab', null);

        $autor = $this->criarUsuario($tenant, $papel, 'autor_');
        $resp  = $this->criarUsuario($tenant, $papel, 'resp_');
        $tarefa = $this->criarTarefa($tenant, $autor, [$resp]);

        $tarefaId = (int) $tarefa->getId();
        $respId   = (int) $resp->getId();
        $this->em->clear();

        $tarefa = $this->em->find(Tarefa::class, $tarefaId);
        $this->service->notificarTarefaPendente($tarefa);

        self::assertCount(1, $this->notificacoesDe($respId));
        self::assertSame(Notificacao::TIPO_TAREFA_PENDENTE, $this->notificacoesDe($respId)[0]->getTipo());
    }

    #[TestDox('notificarTarefaConcluida cria uma notificação TAREFA_CONCLUIDA para cada responsável')]
    public function testNotificarTarefaConcluidaNotificaCadaResponsavel(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Meta ' . uniqid());
        $this->em->persist($tenant);
        $papel = $this->criarPapel($tenant, 'Colab', null);

        $autor = $this->criarUsuario($tenant, $papel, 'autor_');
        $resp  = $this->criarUsuario($tenant, $papel, 'resp_');
        $tarefa = $this->criarTarefa($tenant, $autor, [$resp]);

        $tarefaId = (int) $tarefa->getId();
        $respId   = (int) $resp->getId();
        $this->em->clear();

        $tarefa = $this->em->find(Tarefa::class, $tarefaId);
        $this->service->notificarTarefaConcluida($tarefa);

        self::assertCount(1, $this->notificacoesDe($respId));
        self::assertSame(Notificacao::TIPO_TAREFA_CONCLUIDA, $this->notificacoesDe($respId)[0]->getTipo());
    }

    #[TestDox('notificarTarefaEmRevisao notifica o criador da tarefa com TAREFA_EM_REVISAO')]
    public function testNotificarTarefaEmRevisaoNotificaCriador(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Meta ' . uniqid());
        $this->em->persist($tenant);
        $papel = $this->criarPapel($tenant, 'Colab', null);

        $autor = $this->criarUsuario($tenant, $papel, 'autor_');
        $resp  = $this->criarUsuario($tenant, $papel, 'resp_');
        $tarefa = $this->criarTarefa($tenant, $autor, [$resp]);

        $tarefaId = (int) $tarefa->getId();
        $autorId  = (int) $autor->getId();
        $respId   = (int) $resp->getId();
        $this->em->clear();

        $tarefa = $this->em->find(Tarefa::class, $tarefaId);
        $this->service->notificarTarefaEmRevisao($tarefa);

        self::assertCount(1, $this->notificacoesDe($autorId), 'o criador deve receber 1 notificação');
        self::assertSame(Notificacao::TIPO_TAREFA_EM_REVISAO, $this->notificacoesDe($autorId)[0]->getTipo());
        self::assertCount(0, $this->notificacoesDe($respId), 'o responsável que enviou não é notificado');
    }

    #[TestDox('notificarTarefaCriada não vaza notificação para responsável de outro tenant')]
    public function testNotificarTarefaCriadaNaoVazaEntreTenants(): void
    {
        $tenantA = new Tenant();
        $tenantA->setName('Tenant A ' . uniqid());
        $this->em->persist($tenantA);
        $tenantB = new Tenant();
        $tenantB->setName('Tenant B ' . uniqid());
        $this->em->persist($tenantB);

        $autor = $this->criarUsuario($tenantA, $this->criarPapel($tenantA, 'Colab A', null), 'autor_');
        $respA = $this->criarUsuario($tenantA, $this->criarPapel($tenantA, 'Resp A', null), 'respA_');
        $tarefa = $this->criarTarefa($tenantA, $autor, [$respA]);

        $tarefaId = (int) $tarefa->getId();
        $respAId  = (int) $respA->getId();
        $tenantAId = (int) $tenantA->getId();
        $this->em->clear();

        $tarefa = $this->em->find(Tarefa::class, $tarefaId);
        $this->service->notificarTarefaCriada($tarefa);

        $notificacao = $this->notificacoesDe($respAId)[0];
        self::assertSame($tenantAId, (int) $notificacao->getTenant()->getId(), 'a notificação é do tenant da tarefa');
    }

    /**
     * @param User[] $responsaveis
     */
    private function criarTarefa(Tenant $tenant, User $autor, array $responsaveis): Tarefa
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-NOTIF-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($autor);
        $this->em->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta de teste');
        $tarefa->setDescricao('Descrição');
        $tarefa->setStatus(Tarefa::STATUS_PENDENTE);
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setCriadoPor($autor);
        foreach ($responsaveis as $responsavel) {
            $tarefa->addResponsavel($responsavel);
        }
        $this->em->persist($tarefa);
        $this->em->flush();

        return $tarefa;
    }

    private function obterPermissao(string $code): Permission
    {
        $permissao = $this->em->getRepository(Permission::class)->findOneBy(['code' => $code]);
        if ($permissao === null) {
            $permissao = new Permission();
            $permissao->setCode($code);
            $permissao->setDescription('Gestão de chamados Service Desk (TI)');
            $permissao->setGroup('admin');
            $this->em->persist($permissao);
        }

        return $permissao;
    }

    private function criarPapel(Tenant $tenant, string $nome, ?Permission $permissao): TenantRole
    {
        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName($nome . ' ' . uniqid());
        $papel->setIsSystem(false);
        $this->em->persist($papel);

        if ($permissao !== null) {
            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($papel);
            $vinculo->setPermission($permissao);
            $this->em->persist($vinculo);
        }

        return $papel;
    }

    private function criarUsuario(Tenant $tenant, TenantRole $papel, string $prefixo): User
    {
        $user = new User();
        $user->setEmail($prefixo . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $this->em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($papel);
        $this->em->persist($userTenant);

        return $user;
    }

    /**
     * @return Notificacao[]
     */
    private function notificacoesDe(int $usuarioId): array
    {
        $usuario = $this->em->find(User::class, $usuarioId);

        return $this->em->getRepository(Notificacao::class)->findBy(['usuario' => $usuario]);
    }
}
