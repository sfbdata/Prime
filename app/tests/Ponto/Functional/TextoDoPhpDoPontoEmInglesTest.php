<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Profile\EventListener\IdiomaDaRequisicaoListener;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Spec idioma-do-sistema §30: o texto fixo que o controller do Ponto devolve sai no idioma da pessoa (o da sessão,
 * posto pelo `IdiomaDaRequisicaoListener`). Uma resposta JSON (home office) e um flash (abonar da equipe), em en e em pt_BR.
 */
final class TextoDoPhpDoPontoEmInglesTest extends JusPrimeWebTestCase
{
    /** @return iterable<string, array{0: string, 1: string}> idioma da sessão => texto esperado */
    public static function idiomas(): iterable
    {
        yield 'en' => ['en', 'The start date of the validity period cannot be after the end date.'];
        yield 'pt-BR' => ['pt-BR', 'A data inicial da vigência não pode ser maior que a final.'];
    }

    #[DataProvider('idiomas')]
    #[TestDox('JSON de erro do home office no idioma da pessoa ($idioma)')]
    public function testJsonNoIdiomaDaPessoa(string $idioma, string $esperado): void
    {
        $client = $this->clientePreparado();
        $tenant = $this->criarTenant();
        $admin = $this->criarUsuario($tenant, ['ROLE_SUPER_ADMIN']);
        $alvo = $this->criarUsuario($tenant, ['ROLE_USER']);
        $this->logarNoIdioma($client, $admin, $tenant, $idioma);

        $client->request(
            'POST',
            sprintf('/tenant/%d/user/%d/home-office', $tenant->getId(), $alvo->getId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'],
            (string) json_encode(['diasSemana' => [1], 'vigenciaInicio' => '2026-07-31', 'vigenciaFim' => '2026-07-01']),
        );

        self::assertResponseStatusCodeSame(422);
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($esperado, $json['erro']);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function idiomasDoFlash(): iterable
    {
        yield 'en' => ['en', 'Select at least one justification.'];
        yield 'pt-BR' => ['pt-BR', 'Selecione ao menos uma justificativa.'];
    }

    #[DataProvider('idiomasDoFlash')]
    #[TestDox('Flash do abonar da equipe no idioma da pessoa ($idioma)')]
    public function testFlashNoIdiomaDaPessoa(string $idioma, string $esperado): void
    {
        $client = $this->clientePreparado();
        $tenant = $this->criarTenant();
        $admin = $this->criarUsuario($tenant, ['ROLE_SUPER_ADMIN']);
        $this->logarNoIdioma($client, $admin, $tenant, $idioma);

        $client->request('POST', '/ponto/equipe/abonar', ['_token' => 'TOKEN_ponto_equipe_abonar']);

        self::assertResponseRedirects();
        self::assertSame([$esperado], $client->getRequest()->getSession()->getFlashBag()->peek('warning'));
    }

    private function logarNoIdioma(KernelBrowser $client, User $user, Tenant $tenant, string $idioma): void
    {
        $this->logarComTenant($client, $user, $tenant);
        $sessao = $client->getSession();
        $sessao->set(IdiomaDaRequisicaoListener::CHAVE_DA_SESSAO, ['usuario' => $user->getId(), 'idioma' => $idioma]);
        $sessao->save();
    }

    private function clientePreparado(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $storage = new class () implements ClearableTokenStorageInterface {
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
        };
        static::getContainer()->set('security.csrf.token_storage', $storage);

        return $client;
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant idioma ponto ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /** @param string[] $roles */
    private function criarUsuario(Tenant $tenant, array $roles): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('idioma_ponto_' . uniqid() . '@test.com');
        $user->setFullName('User Idioma');
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }
}
