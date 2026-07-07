<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Cobre a lacuna reportada: responsáveis não recebiam notificação nos eventos de meta.
 */
#[CoversClass(TarefaController::class)]
final class TarefaNotificacaoControllerTest extends JusPrimeWebTestCase
{
    private function criarUsuario(Tenant $tenant, string $prefixo): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($prefixo . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Notif ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarPasta(Tenant $tenant, User $criador): Pasta
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('TEST-NOTIF-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    /**
     * @param User[] $responsaveis
     */
    private function criarTarefa(Pasta $pasta, User $autor, array $responsaveis, string $status): Tarefa
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta de teste');
        $tarefa->setDescricao('Descrição');
        $tarefa->setStatus($status);
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($pasta->getTenant());
        $tarefa->setCriadoPor($autor);
        foreach ($responsaveis as $responsavel) {
            $tarefa->addResponsavel($responsavel);
        }
        $em->persist($tarefa);
        $em->flush();

        return $tarefa;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function gerarCsrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }

    /**
     * @return Notificacao[]
     */
    private function notificacoesDe(int $usuarioId, string $tipo): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $usuario = $em->find(User::class, $usuarioId);

        return $em->getRepository(Notificacao::class)->findBy(['usuario' => $usuario, 'tipo' => $tipo]);
    }

    #[TestDox('Criar meta gera notificação TAREFA_CRIADA para cada responsável')]
    public function testCriarNotificaResponsaveis(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_');
        $resp1  = $this->criarUsuario($tenant, 'resp1_');
        $resp2  = $this->criarUsuario($tenant, 'resp2_');
        $pasta  = $this->criarPasta($tenant, $autor);
        $pastaId = (int) $pasta->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
            '_token'       => $this->gerarCsrf('tarefa_criar_' . $pastaId),
            'titulo'       => 'Meta com notificação',
            'descricao'    => 'Deve notificar os responsáveis',
            'responsaveis' => [(int) $resp1->getId(), (int) $resp2->getId()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->notificacoesDe((int) $resp1->getId(), Notificacao::TIPO_TAREFA_CRIADA));
        self::assertCount(1, $this->notificacoesDe((int) $resp2->getId(), Notificacao::TIPO_TAREFA_CRIADA));
    }

    #[TestDox('Reatribuir responsáveis notifica apenas os recém-adicionados')]
    public function testReatribuirNotificaApenasNovos(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_');
        $resp1  = $this->criarUsuario($tenant, 'resp1_');
        $resp2  = $this->criarUsuario($tenant, 'resp2_');
        $pasta  = $this->criarPasta($tenant, $autor);
        $tarefa = $this->criarTarefa($pasta, $autor, [$resp1], Tarefa::STATUS_PENDENTE);
        $tarefaId = (int) $tarefa->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/{$tarefaId}/responsaveis", [
            '_token'       => $this->gerarCsrf('update_responsaveis_' . $tarefaId),
            'responsaveis' => [(int) $resp1->getId(), (int) $resp2->getId()],
        ]);

        self::assertResponseRedirects();
        self::assertCount(1, $this->notificacoesDe((int) $resp2->getId(), Notificacao::TIPO_TAREFA_CRIADA), 'novo responsável é notificado');
        self::assertCount(0, $this->notificacoesDe((int) $resp1->getId(), Notificacao::TIPO_TAREFA_CRIADA), 'quem já era responsável não recebe de novo');
    }

    #[TestDox('Devolver meta para ajustes gera notificação TAREFA_PENDENTE')]
    public function testDevolverNotificaResponsaveis(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_');
        $resp   = $this->criarUsuario($tenant, 'resp_');
        $pasta  = $this->criarPasta($tenant, $autor);
        $tarefa = $this->criarTarefa($pasta, $autor, [$resp], Tarefa::STATUS_EM_REVISAO);
        $tarefaId = (int) $tarefa->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/{$tarefaId}/mensagem", [
            '_token'   => $this->gerarCsrf('mensagem_tarefa_' . $tarefaId),
            'mensagem' => 'Precisa ajustar isso aqui',
            'acao'     => 'devolver',
        ]);

        self::assertResponseRedirects();
        self::assertCount(1, $this->notificacoesDe((int) $resp->getId(), Notificacao::TIPO_TAREFA_PENDENTE));
    }

    #[TestDox('Enviar meta para revisão notifica o criador com TAREFA_EM_REVISAO')]
    public function testEnviarParaRevisaoNotificaCriador(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_');
        $resp   = $this->criarUsuario($tenant, 'resp_');
        $pasta  = $this->criarPasta($tenant, $autor);
        $tarefa = $this->criarTarefa($pasta, $autor, [$resp], Tarefa::STATUS_PENDENTE);
        $tarefaId = (int) $tarefa->getId();

        $this->instalarCsrfStorage();
        // Quem envia para revisão é o responsável, não o criador.
        $this->logarComTenant($client, $resp, $tenant);

        $client->request('POST', "/tarefas/{$tarefaId}/mensagem", [
            '_token'   => $this->gerarCsrf('mensagem_tarefa_' . $tarefaId),
            'mensagem' => 'Concluí, favor revisar',
            'acao'     => 'enviar',
        ]);

        self::assertResponseRedirects();
        self::assertCount(1, $this->notificacoesDe((int) $autor->getId(), Notificacao::TIPO_TAREFA_EM_REVISAO), 'criador é notificado');
        self::assertCount(0, $this->notificacoesDe((int) $resp->getId(), Notificacao::TIPO_TAREFA_EM_REVISAO), 'quem enviou não é notificado');
    }

    #[TestDox('Concluir meta gera notificação TAREFA_CONCLUIDA')]
    public function testConcluirNotificaResponsaveis(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_');
        $resp   = $this->criarUsuario($tenant, 'resp_');
        $pasta  = $this->criarPasta($tenant, $autor);
        $tarefa = $this->criarTarefa($pasta, $autor, [$resp], Tarefa::STATUS_PENDENTE);
        $tarefaId = (int) $tarefa->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/{$tarefaId}/concluir", [
            '_token' => $this->gerarCsrf('concluir_tarefa_' . $tarefaId),
        ]);

        self::assertResponseRedirects();
        self::assertCount(1, $this->notificacoesDe((int) $resp->getId(), Notificacao::TIPO_TAREFA_CONCLUIDA));
    }
}
