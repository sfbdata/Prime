<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\Controller\DescadastroDeEmailController;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A rota pública do link "Não quero mais receber estes avisos" (spec push-avisar-quando-chegar D3):
 * sem login, assinatura válida grava e confirma; inválida, 404; repetir não duplica; quem está logado
 * em OUTRO escritório também consegue (nem termo, nem escritório, nem TenantFilter atrapalham).
 */
#[CoversClass(DescadastroDeEmailController::class)]
final class DescadastroDeEmailControllerTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function tenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome);
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function link(Tenant $tenant, string $email = 'ana@exemplo.com'): string
    {
        return static::getContainer()->get(DescadastroDeEmailInterface::class)->gerarLink($tenant, $email, 'push_aviso_equipe');
    }

    private function contar(Tenant $tenant): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM email_descadastro WHERE tenant_id = ?', [$tenant->getId()]);
    }

    #[TestDox('Sem login, link válido: grava, mostra a confirmação com o nome do escritório; o segundo clique não duplica')]
    public function testLinkValido(): void
    {
        $tenant = $this->tenant('Silva Advogados');
        $url = $this->link($tenant);

        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Descadastro confirmado');
        self::assertSelectorTextContains('main', 'Você não vai mais receber estes avisos por e-mail de Silva Advogados.');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        self::assertTrue(static::getContainer()->get(DescadastroDeEmailInterface::class)->estaDescadastrado($tenant, 'ana@exemplo.com', 'push_aviso_equipe'));

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->contar($tenant), 'idempotente');
        self::assertStringNotContainsString('—', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('POST one-click (List-Unsubscribe-Post) grava com origem one_click')]
    public function testOneClick(): void
    {
        $tenant = $this->tenant('Escritório One Click');

        $this->client->request('POST', $this->link($tenant), ['List-Unsubscribe' => 'One-Click']);

        self::assertResponseIsSuccessful();
        self::assertSame('one_click', $this->em()->getConnection()->fetchOne('SELECT origem FROM email_descadastro WHERE tenant_id = ?', [$tenant->getId()]));
    }

    #[TestDox('Assinatura inválida, ausente ou de escritório que não existe: 404 e nada gravado')]
    public function testLinkInvalido(): void
    {
        $tenant = $this->tenant('Escritório Inválido');
        $url = $this->link($tenant);

        $this->client->request('GET', str_replace('d=', 'd=A', $url));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', (string) preg_replace('/&?_hash=[^&]+/', '', $url));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/email/descadastro');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->contar($tenant));
    }

    #[TestDox('Logado em OUTRO escritório: o link do primeiro escritório grava no primeiro (TenantFilter não interfere)')]
    public function testLogadoEmOutroEscritorio(): void
    {
        $dono = $this->tenant('Escritório do Link');
        $outro = $this->tenant('Escritório da Sessão');
        $user = new User();
        $user->setEmail('sessao_' . uniqid() . '@test.com');
        $user->setFullName('Logado');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em()->persist($user);
        $this->em()->persist(new UserTenant($user, $outro));
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $outro);
        $url = $this->link($dono);

        $this->client->request('GET', $url);
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->contar($dono));
        self::assertSame(0, $this->contar($outro));
    }
}
