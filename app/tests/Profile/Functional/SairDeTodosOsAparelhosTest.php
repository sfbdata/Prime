<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Profile\Controller\ProfileController;
use App\Security\SessaoEncerradaEmTodosOsAparelhosListener;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * "Sair de todos os aparelhos" no Meu Perfil (aprovado pelo Samuel em 05/11/2026). Dois aparelhos da mesma pessoa
 * são dois potes de cookies no mesmo KernelBrowser: o B entra pelo formulário com "Manter conectado"; o A aperta o
 * botão. O B cai no pedido seguinte, o cookie do B não entra mais e o A continua.
 */
#[CoversClass(ProfileController::class)]
#[CoversClass(SessaoEncerradaEmTodosOsAparelhosListener::class)]
final class SairDeTodosOsAparelhosTest extends JusPrimeWebTestCase
{
    private const SENHA = 'senha-forte-123';

    #[TestDox('O botão fica na Segurança e abre a confirmação: Cancelar e "Sair de todos", com o token do formulário')]
    public function testBotaoEConfirmacao(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarUsuarioComTenant();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/perfil');

        $botao = $crawler->filter('.mp-seguranca > button.mp-btn-contorno[data-bs-target="#modalSairTodos"]');
        self::assertSame('Sair de todos os aparelhos', trim($botao->text()));
        $modal = $crawler->filter('#modalSairTodos.mp-modal');
        self::assertSame('Sair de todos os aparelhos', trim($modal->filter('.modal-content > .mp-modal-topo > b')->text()));
        self::assertSame(['Cancelar', 'Sair de todos'], $modal->filter('#formSairTodos > .mp-modal-rodape > button')->each(static fn ($b): string => trim($b->text())));
        self::assertSame('/perfil/sair-de-todos-os-aparelhos', $modal->filter('#formSairTodos')->attr('action'));
        self::assertCount(1, $modal->filter('#formSairTodos input[name="_token"]'));
    }

    #[TestDox('Confirmado: o outro aparelho cai no pedido seguinte com o aviso, o "Manter conectado" dele não entra mais e este aparelho continua')]
    public function testEncerraOsOutrosEMantemEste(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarUsuarioComTenant();
        $aparelhoB = $this->entrarPeloFormulario($client, $user);
        $lembrarB = $client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($lembrarB, 'o aparelho B entrou com "Manter conectado"');

        $client->getCookieJar()->clear();
        $this->logarComTenant($client, $user, $tenant);
        $client->submit($client->request('GET', '/perfil')->filter('#formSairTodos')->form());
        self::assertResponseRedirects('/perfil');
        self::assertStringContainsString('Você saiu de todos os outros aparelhos', $client->followRedirect()->filter('body')->text());
        self::assertSame(0, $this->seriesDe($user), 'todas as séries do "Manter conectado" saem');
        self::assertNotNull($this->usuario($user)->getSessoesEncerradasEm());

        $client->request('GET', '/perfil');
        self::assertResponseIsSuccessful('o aparelho A, que apertou o botão, continua conectado');

        $this->trocarPara($client, $aparelhoB);
        $client->request('GET', '/termos/aceitar');
        self::assertResponseRedirects('/login', null, 'o aparelho B cai no pedido seguinte');
        self::assertStringContainsString('Sua sessão foi encerrada em todos os aparelhos.', $client->followRedirect()->filter('body')->text());

        $this->trocarPara($client, [$lembrarB]);
        $client->request('GET', '/termos/aceitar');
        self::assertResponseRedirects('http://localhost/login', null, 'o cookie "Manter conectado" do B não entra mais');
    }

    #[TestDox('Pedido de dados do outro aparelho recebe 401 em JSON, sem redirecionar')]
    public function testPedidoDeDadosRecebe401(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarUsuarioComTenant();
        $aparelhoB = $this->entrarPeloFormulario($client, $user);
        $this->cortar($user, '+1 minute');

        $this->trocarPara($client, $aparelhoB);
        $client->request('GET', '/termos/aceitar', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['erro' => 'Sua sessão terminou. Entre de novo.'], json_decode((string) $client->getResponse()->getContent(), true));
    }

    #[TestDox('Quem entra depois do corte continua conectado')]
    public function testEntrarDepoisDoCorteFunciona(): void
    {
        $client = static::createClient();
        [$user] = $this->criarUsuarioComTenant();
        $this->cortar($user, '-1 minute');

        $this->entrarPeloFormulario($client, $user);
        $client->request('GET', '/termos/aceitar');

        self::assertResponseIsSuccessful();
    }

    #[TestDox('Sem o token do formulário, nada muda')]
    public function testSemTokenNadaMuda(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarUsuarioComTenant();
        $this->entrarPeloFormulario($client, $user);
        $client->getCookieJar()->clear();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/perfil/sair-de-todos-os-aparelhos', ['_token' => 'errado']);

        self::assertResponseRedirects('/perfil');
        self::assertSame(1, $this->seriesDe($user), 'a série continua');
        self::assertNull($this->usuario($user)->getSessoesEncerradasEm());
    }

    #[TestDox('Sem estar logado, vai para o login; GET não existe')]
    public function testSemLoginEGet(): void
    {
        $client = static::createClient();
        $client->request('POST', '/perfil/sair-de-todos-os-aparelhos');
        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));

        $client->request('GET', '/perfil/sair-de-todos-os-aparelhos');
        self::assertResponseStatusCodeSame(405);
    }

    /**
     * Entra pelo formulário com "Manter conectado" (dispara o LoginSuccessEvent, que marca a sessão) e devolve os
     * cookies deste aparelho.
     *
     * @return list<Cookie>
     */
    private function entrarPeloFormulario(KernelBrowser $client, User $user): array
    {
        $client->getCookieJar()->clear();
        $form = $client->request('GET', '/login')->selectButton('Entrar')->form();
        $form['email'] = (string) $user->getEmail();
        $form['password'] = self::SENHA;
        $form['_remember_me']->tick();
        $client->submit($form);

        return $client->getCookieJar()->all();
    }

    /** @param list<Cookie> $cookies */
    private function trocarPara(KernelBrowser $client, array $cookies): void
    {
        $client->getCookieJar()->clear();
        foreach ($cookies as $cookie) {
            $client->getCookieJar()->set($cookie);
        }
    }

    private function cortar(User $user, string $quando): void
    {
        static::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE "user" SET sessoes_encerradas_em = ? WHERE id = ?',
            [(new \DateTimeImmutable($quando))->format('Y-m-d H:i:s'), $user->getId()],
        );
    }

    private function usuario(User $user): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $recarregado = $em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $recarregado);

        return $recarregado;
    }

    private function seriesDe(User $user): int
    {
        return (int) static::getContainer()->get(Connection::class)
            ->fetchOne('SELECT COUNT(*) FROM rememberme_token WHERE username = ?', [$user->getEmail()]);
    }

    /** @return array{User, Tenant} */
    private function criarUsuarioComTenant(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório Sair ' . uniqid());
        $em->persist($tenant);
        $user = (new User())->setEmail('sair_todos_' . uniqid() . '@adv.com')->setFullName('Ana Souza')
            ->setRoles(['ROLE_USER'])->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::SENHA));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }
}
