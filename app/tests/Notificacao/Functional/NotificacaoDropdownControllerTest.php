<?php

declare(strict_types=1);

namespace App\Tests\Notificacao\Functional;

use App\Controller\NotificacaoController;
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

#[CoversClass(NotificacaoController::class)]
final class NotificacaoDropdownControllerTest extends JusPrimeWebTestCase
{
    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Notif ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('notif_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Notif');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
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

    private function getHtmlDropdown(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): string
    {
        $client->request('GET', '/notificacoes/lista-dropdown');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('html', $data);

        return (string) $data['html'];
    }

    #[TestDox('Dropdown usa o campo url no href e escapa título (anti-XSS); não duplica mensagem igual ao título')]
    public function testDropdownUsaUrlEscapaENaoDuplica(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $notif = new Notificacao();
        $notif->setUsuario($user);
        $notif->setTipo('servicedesk');
        $notif->setTitulo('Chamado <b>#5</b>');   // título == mensagem (caso ponto/servicedesk)
        $notif->setMensagem('Chamado <b>#5</b>');
        $notif->setUrl('/servicedesk/5');
        $em->persist($notif);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $html = $this->getHtmlDropdown($client);

        // Item 4a: usa a url, não cai em '#'
        self::assertStringContainsString('href="/servicedesk/5"', $html);
        // Anti-XSS: título escapado, sem tag crua
        self::assertStringContainsString('&lt;b&gt;#5&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>#5</b>', $html);
        // Item 3: mensagem igual ao título não é renderizada
        self::assertStringNotContainsString('notif-msg', $html);
    }

    #[TestDox('Dropdown sem url usa tarefa_show; mensagem distinta do título é exibida')]
    public function testDropdownTarefaHrefEMensagemDistinta(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);

        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('NOTIF-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta X');
        $tarefa->setDescricao('...');
        $tarefa->setPasta($pasta);
        $em->persist($tarefa);

        $notif = new Notificacao();
        $notif->setUsuario($user);
        $notif->setTipo(Notificacao::TIPO_TAREFA_CRIADA);
        $notif->setTitulo('Nova tarefa atribuída');
        $notif->setMensagem('A tarefa "Meta X" foi atribuída a você.');
        $notif->setTarefa($tarefa);
        $em->persist($notif);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $html = $this->getHtmlDropdown($client);

        self::assertStringContainsString('href="/tarefas/' . $tarefa->getId() . '"', $html);
        self::assertStringContainsString('notif-msg', $html);
    }

    #[TestDox('Aba Gestão é bloqueada (403) para usuário sem notificações de gestão')]
    public function testGestaoBloqueadaSemPermissao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $pessoal = (new Notificacao())
            ->setUsuario($user)
            ->setTipo(Notificacao::TIPO_TAREFA_CRIADA)
            ->setTitulo('Minha demanda');
        $em->persist($pessoal);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/notificacoes/lista-dropdown?categoria=gestao');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Aba Gestão retorna só notificações de gestão; aba pessoal não as inclui')]
    public function testSeparacaoPorCategoria(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $pessoal = (new Notificacao())
            ->setUsuario($user)
            ->setTipo(Notificacao::TIPO_TAREFA_CRIADA)
            ->setTitulo('Minha demanda');
        $gestao = (new Notificacao())
            ->setUsuario($user)
            ->setTipo(Notificacao::TIPO_PONTO_JUSTIFICATIVA_ENVIADA)
            ->setTitulo('Justificativa para aprovar')
            ->setUrl('/tenant/1/user/2/edit-role?tab=justificativas');
        $em->persist($pessoal);
        $em->persist($gestao);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        // Aba Gestão: só a de gestão
        $client->request('GET', '/notificacoes/lista-dropdown?categoria=gestao');
        self::assertResponseIsSuccessful();
        $g = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('Justificativa para aprovar', $g['html']);
        self::assertStringNotContainsString('Minha demanda', $g['html']);
        self::assertSame(1, $g['count']);

        // Aba Minhas: só a pessoal
        $client->request('GET', '/notificacoes/lista-dropdown?categoria=pessoal');
        self::assertResponseIsSuccessful();
        $p = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('Minha demanda', $p['html']);
        self::assertStringNotContainsString('Justificativa para aprovar', $p['html']);
        self::assertSame(1, $p['count']);
    }
}
