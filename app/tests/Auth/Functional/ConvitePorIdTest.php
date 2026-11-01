<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\ConviteController;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * O convidado logado abre, aceita e recusa o convite pelo id (achado de auditoria interna F6): a tela não leva o
 * token, que só existe no link do e-mail. O link público `/convite/{token}` segue igual.
 */
#[CoversClass(ConviteController::class)]
final class ConvitePorIdTest extends JusPrimeWebTestCase
{
    #[TestDox('A tela do convite pelo id não leva o token e aponta aceitar e recusar para o id')]
    public function testTelaPeloIdSemToken(): void
    {
        $client = static::createClient();
        [$user, $meu] = $this->pessoaComEscritorio();
        $convite = $this->convite($user->getEmail());
        $this->logarComTenant($client, $user, $meu);

        $client->request('GET', '/convites/' . $convite->getId());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($this->tokenDe($convite), (string) $client->getResponse()->getContent());
        self::assertSelectorExists(sprintf('form[action="/convites/%d/aceitar"]', $convite->getId()));
        self::assertSelectorExists(sprintf('form[action="/convites/%d/recusar"]', $convite->getId()));
    }

    #[TestDox('Aceitar pelo id cria o vínculo e marca o convite como aceito')]
    public function testAceitarPeloId(): void
    {
        $client = static::createClient();
        [$user, $meu] = $this->pessoaComEscritorio();
        $convite = $this->convite($user->getEmail());
        $this->logarComTenant($client, $user, $meu);

        $this->postarDaTela($client, $convite, 'aceitar');

        self::assertResponseRedirects('/inicio');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame('accepted', $em->find(Invitation::class, $convite->getId())->getStatus());
        self::assertNotNull($em->getRepository(UserTenant::class)->findOneBy(['user' => $user->getId(), 'tenant' => $convite->getTenant()->getId()]));
    }

    #[TestDox('Recusar pelo id marca o convite como recusado')]
    public function testRecusarPeloId(): void
    {
        $client = static::createClient();
        [$user, $meu] = $this->pessoaComEscritorio();
        $convite = $this->convite($user->getEmail());
        $this->logarComTenant($client, $user, $meu);

        $this->postarDaTela($client, $convite, 'recusar');

        self::assertResponseRedirects('/inicio');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame('rejected', $em->find(Invitation::class, $convite->getId())->getStatus());
    }

    #[TestDox('Convite de outro e-mail: a tela pelo id é 404')]
    public function testTelaDeConviteDeOutroEmailE404(): void
    {
        $client = static::createClient();
        [$user, $meu] = $this->pessoaComEscritorio();
        $alheio = $this->convite('outra_' . uniqid() . '@adv.com');
        $this->logarComTenant($client, $user, $meu);

        $client->request('GET', '/convites/' . $alheio->getId());

        self::assertResponseStatusCodeSame(404);
    }

    /** @return iterable<string, array{string}> */
    public static function acoes(): iterable
    {
        yield 'aceitar' => ['aceitar'];
        yield 'recusar' => ['recusar'];
    }

    #[DataProvider('acoes')]
    #[TestDox('Convite de outro e-mail: aceitar ou recusar pelo id, mesmo com CSRF válido, dá 404 e não muda nada')]
    public function testConviteDeOutroEmailNaoMuda(string $acao): void
    {
        $client = static::createClient();
        [$user, $meu] = $this->pessoaComEscritorio();
        $alheio = $this->convite('outra_' . uniqid() . '@adv.com');
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $meu);

        $client->request('POST', sprintf('/convites/%d/%s', $alheio->getId(), $acao), [
            '_token' => sprintf('TOKEN_convite_%s_%d', $acao, $alheio->getId()),
        ]);

        self::assertResponseStatusCodeSame(404);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame('pending', $em->find(Invitation::class, $alheio->getId())->getStatus());
        self::assertNull($em->getRepository(UserTenant::class)->findOneBy(['user' => $user->getId(), 'tenant' => $alheio->getTenant()->getId()]));
    }

    #[TestDox('Convite de plataforma do próprio e-mail: a tela pelo id é 404')]
    public function testTelaDeConviteDePlataformaE404(): void
    {
        $client = static::createClient();
        [$user, $meu] = $this->pessoaComEscritorio();
        $plataforma = $this->convite($user->getEmail(), Invitation::TIPO_PLATAFORMA);
        $this->logarComTenant($client, $user, $meu);

        $client->request('GET', '/convites/' . $plataforma->getId());

        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('acoes')]
    #[TestDox('Convite de plataforma do próprio e-mail: aceitar ou recusar pelo id dá 404 e não muda nada')]
    public function testConviteDePlataformaNaoMuda(string $acao): void
    {
        $client = static::createClient();
        [$user, $meu] = $this->pessoaComEscritorio();
        $plataforma = $this->convite($user->getEmail(), Invitation::TIPO_PLATAFORMA);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $meu);

        $client->request('POST', sprintf('/convites/%d/%s', $plataforma->getId(), $acao), [
            '_token' => sprintf('TOKEN_convite_%s_%d', $acao, $plataforma->getId()),
        ]);

        self::assertResponseStatusCodeSame(404);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame('pending', $em->find(Invitation::class, $plataforma->getId())->getStatus());
    }

    #[DataProvider('acoes')]
    #[TestDox('CSRF inválido: aceitar ou recusar pelo id dá 403 e não muda nada')]
    public function testCsrfInvalidoNaoMuda(string $acao): void
    {
        $client = static::createClient();
        [$user, $meu] = $this->pessoaComEscritorio();
        $convite = $this->convite($user->getEmail());
        $this->logarComTenant($client, $user, $meu);

        $client->request('POST', sprintf('/convites/%d/%s', $convite->getId(), $acao), ['_token' => 'invalido']);

        self::assertResponseStatusCodeSame(403);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame('pending', $em->find(Invitation::class, $convite->getId())->getStatus());
        self::assertNull($em->getRepository(UserTenant::class)->findOneBy(['user' => $user->getId(), 'tenant' => $convite->getTenant()->getId()]));
    }

    #[TestDox('O link público do e-mail (/convite/{token}) segue abrindo o formulário de criar conta pelo token')]
    public function testLinkPublicoPeloTokenSegue(): void
    {
        $client = static::createClient();
        $convite = $this->convite('nova_' . uniqid() . '@adv.com');

        $crawler = $client->request('GET', '/convite/' . $this->tokenDe($convite));

        self::assertResponseIsSuccessful();
        self::assertSame('/convite/' . $this->tokenDe($convite) . '/criar-conta', $crawler->filter('form[method="post"]')->attr('action'));
    }

    #[TestDox('O link público, aberto pela própria pessoa logada, segue aceitando pelo token')]
    public function testLinkPublicoLogadoAceitaPeloToken(): void
    {
        $client = static::createClient();
        [$user, $meu] = $this->pessoaComEscritorio();
        $convite = $this->convite($user->getEmail());
        $this->logarComTenant($client, $user, $meu);

        $crawler = $client->request('GET', '/convite/' . $this->tokenDe($convite));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter(sprintf('form[action="/convite/%s/aceitar"]', $this->tokenDe($convite)));
        $client->request('POST', (string) $form->attr('action'), ['_token' => (string) $form->filter('input[name="_token"]')->attr('value')]);

        self::assertResponseRedirects('/inicio');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame('accepted', $em->find(Invitation::class, $convite->getId())->getStatus());
    }

    private function postarDaTela(KernelBrowser $client, Invitation $convite, string $acao): void
    {
        $crawler = $client->request('GET', '/convites/' . $convite->getId());
        $form    = $crawler->filter(sprintf('form[action="/convites/%d/%s"]', $convite->getId(), $acao));
        $client->request('POST', (string) $form->attr('action'), ['_token' => (string) $form->filter('input[name="_token"]')->attr('value')]);
    }

    /** CSRF previsível ('TOKEN_<id>'), como nos outros testes de convite: o POST forjado leva um CSRF válido. */
    private function instalarCsrfStorage(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }
            public function setToken(string $tokenId, string $token): void
            {
            }
            public function removeToken(string $tokenId): ?string
            {
                return null;
            }
            public function hasToken(string $tokenId): bool
            {
                return true;
            }
            public function clear(): void
            {
            }
        });
    }

    /** @return array{User, Tenant} */
    private function pessoaComEscritorio(): array
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('convidada_' . uniqid() . '@adv.com')->setFullName('Pessoa Convidada')->setPassword('x')->setRoles(['ROLE_USER']);
        $em->persist($user);
        $meu = (new Tenant())->setName('Meu escritório ' . uniqid());
        $em->persist($meu);
        $em->persist(new UserTenant($user, $meu));
        $em->flush();

        return [$user, $meu];
    }

    /** @var array<int, string> o token puro de cada convite criado aqui: o banco só tem o hash */
    private array $tokens = [];

    private function tokenDe(Invitation $convite): string
    {
        return $this->tokens[spl_object_id($convite)];
    }

    private function convite(string $email, string $tipo = Invitation::TIPO_ESCRITORIO): Invitation
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório que convida ' . uniqid());
        $em->persist($tenant);
        $token   = bin2hex(random_bytes(32));
        $convite = new Invitation(email: $email, tokenHash: Invitation::hashDoToken($token), type: $tipo, expiresAt: new \DateTimeImmutable('+7 days'));
        if ($tipo === Invitation::TIPO_ESCRITORIO) {
            $convite->setTenant($tenant);
        }
        $this->tokens[spl_object_id($convite)] = $token;
        $em->persist($convite);
        $em->flush();

        return $convite;
    }
}
