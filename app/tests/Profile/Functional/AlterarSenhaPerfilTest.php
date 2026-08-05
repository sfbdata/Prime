<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Profile\Controller\ProfileController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Troca de senha do usuário autenticado, na tela de perfil.
 */
#[CoversClass(ProfileController::class)]
final class AlterarSenhaPerfilTest extends JusPrimeWebTestCase
{
    private const SENHA_ATUAL = 'senha-atual-123';
    private const SENHA_NOVA  = 'senha-nova-456';

    #[TestDox('POST /perfil/senha sem autenticação redireciona para login')]
    public function testSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/perfil/senha');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET /perfil/senha retorna 405 (só POST)')]
    public function testGetRetorna405(): void
    {
        $client = static::createClient();
        $client->request('GET', '/perfil/senha');

        self::assertResponseStatusCodeSame(405);
    }

    #[TestDox('Com a senha atual correta, a senha é trocada e o usuário CONTINUA logado')]
    public function testTrocaComSucesso(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarUsuarioComTenant();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('#formSenha')->form();
        $form['alterar_senha[senhaAtual]']     = self::SENHA_ATUAL;
        $form['alterar_senha[senha][first]']   = self::SENHA_NOVA;
        $form['alterar_senha[senha][second]']  = self::SENHA_NOVA;
        $client->submit($form);

        self::assertResponseRedirects('/perfil');

        // O smoke de 2026-08-05 mostrou o que este teste não via: mandar para /login não
        // desloga ninguém — o token é regravado na sessão com o hash novo ao fim da própria
        // requisição — e o usuário era jogado de volta para dentro sem entender o desvio.
        $client->followRedirect();
        self::assertResponseIsSuccessful('Quem troca a própria senha continua conectado.');

        $hasher      = static::getContainer()->get(UserPasswordHasherInterface::class);
        $recarregado = $this->recarregar($user);

        self::assertTrue($hasher->isPasswordValid($recarregado, self::SENHA_NOVA));
        self::assertFalse($hasher->isPasswordValid($recarregado, self::SENHA_ATUAL));
    }

    #[TestDox('Senha atual errada não troca nada e volta ao perfil com erro')]
    public function testSenhaAtualErradaNaoTroca(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarUsuarioComTenant();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/perfil');
        $form    = $crawler->filter('#formSenha')->form();
        $form['alterar_senha[senhaAtual]']    = 'chute-errado';
        $form['alterar_senha[senha][first]']  = self::SENHA_NOVA;
        $form['alterar_senha[senha][second]'] = self::SENHA_NOVA;
        $client->submit($form);

        self::assertResponseRedirects('/perfil');

        $hasher      = static::getContainer()->get(UserPasswordHasherInterface::class);
        $recarregado = $this->recarregar($user);

        self::assertTrue($hasher->isPasswordValid($recarregado, self::SENHA_ATUAL), 'A senha não pode mudar sem a atual correta.');
        self::assertFalse($hasher->isPasswordValid($recarregado, self::SENHA_NOVA));
    }

    #[TestDox('Confirmação divergente não troca a senha')]
    public function testConfirmacaoDivergenteNaoTroca(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarUsuarioComTenant();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/perfil');
        $form    = $crawler->filter('#formSenha')->form();
        $form['alterar_senha[senhaAtual]']    = self::SENHA_ATUAL;
        $form['alterar_senha[senha][first]']  = self::SENHA_NOVA;
        $form['alterar_senha[senha][second]'] = 'outra-coisa-789';
        $client->submit($form);

        self::assertResponseRedirects('/perfil');

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($this->recarregar($user), self::SENHA_ATUAL));
    }

    /** @return array{0: User, 1: Tenant} */
    private function criarUsuarioComTenant(): array
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Senha ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('senha_' . uniqid() . '@test.com');
        $user->setFullName('João da Silva');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, self::SENHA_ATUAL));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function recarregar(User $user): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $id = $user->getId();
        $em->clear();

        return $em->getRepository(User::class)->find($id);
    }
}
