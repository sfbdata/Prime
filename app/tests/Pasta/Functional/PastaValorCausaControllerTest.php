<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(PastaController::class)]
final class PastaValorCausaControllerTest extends JusPrimeWebTestCase
{
    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Valor Causa ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_valor_causa_' . uniqid() . '@test.com');
        $user->setFullName('Admin Valor Causa');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant, ?string $valorCausa = null): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('TEST-VC-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setValorCausa($valorCausa);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
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

    #[TestDox('POST valor-causa sem autenticação redireciona para login')]
    public function testSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/valor-causa');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET em rota POST retorna 405 Method Not Allowed')]
    public function testGetEmRotaPostRetorna405(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request('GET', "/pasta/{$pasta->getId()}/valor-causa");

        self::assertResponseStatusCodeSame(405);
    }

    #[TestDox('POST valor-causa com CSRF inválido retorna 403 e não grava')]
    public function testCsrfInvalidoRetorna403(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request('POST', "/pasta/{$pasta->getId()}/valor-causa", [
            '_token'      => 'token_invalido',
            'valor_causa' => '12.860,00',
        ]);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('erro', $data);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->refresh($pasta);
        self::assertNull($pasta->getValorCausa());
    }

    #[TestDox('POST valor-causa grava o valor digitado em pt-BR e devolve formatado')]
    public function testGravaValorEDevolveFormatado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request('POST', "/pasta/{$pasta->getId()}/valor-causa", [
            '_token'      => $this->gerarCsrf('pasta_valor_causa_' . $pasta->getId()),
            'valor_causa' => '12.860,00',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('12860.00', $data['valor']);
        self::assertSame('R$ 12.860,00', $data['valorFormatado']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->refresh($pasta);
        self::assertSame('12860.00', $pasta->getValorCausa());
    }

    #[TestDox('POST valor-causa em branco limpa o valor e a tela volta ao travessão')]
    public function testValorEmBrancoLimpa(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, '12860.00');

        $this->instalarCsrfStorage();
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request('POST', "/pasta/{$pasta->getId()}/valor-causa", [
            '_token'      => $this->gerarCsrf('pasta_valor_causa_' . $pasta->getId()),
            'valor_causa' => '',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertNull($data['valor']);
        self::assertSame('—', $data['valorFormatado']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->refresh($pasta);
        self::assertNull($pasta->getValorCausa());
    }

    #[TestDox('POST valor-causa com texto inválido retorna 422 e preserva o valor anterior')]
    public function testValorInvalidoRetorna422(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, '12860.00');

        $this->instalarCsrfStorage();
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request('POST', "/pasta/{$pasta->getId()}/valor-causa", [
            '_token'      => $this->gerarCsrf('pasta_valor_causa_' . $pasta->getId()),
            'valor_causa' => 'mil reais',
        ]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('erro', $data);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->refresh($pasta);
        self::assertSame('12860.00', $pasta->getValorCausa());
    }

    #[TestDox('POST valor-causa negativo retorna 422')]
    public function testValorNegativoRetorna422(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request('POST', "/pasta/{$pasta->getId()}/valor-causa", [
            '_token'      => $this->gerarCsrf('pasta_valor_causa_' . $pasta->getId()),
            'valor_causa' => '-1.000,00',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Com um escritório ativo na sessão — que é o estado de qualquer usuário
     * logado de verdade — o TenantFilter nem deixa a pasta do outro escritório
     * ser encontrada, e a rota morre no 404 antes de qualquer gravação.
     *
     * (Sem escritório ativo o filtro fica desligado de propósito, para o super
     * admin de plataforma; por isso o login aqui é o `logarComTenant`, não o
     * `loginUser` cru dos demais testes deste arquivo.)
     *
     * O `clear()` antes da requisição não é ritual: sem ele a pasta criada no
     * setup continua no cache de objetos do Doctrine e o `find` nem chega ao
     * banco — o filtro não teria o que filtrar e o teste passaria a mentir. Em
     * produção cada requisição começa com esse cache vazio.
     */
    #[TestDox('POST valor-causa em pasta de outro escritório não encontra a pasta')]
    public function testPastaDeOutroTenantNaoEncontrada(): void
    {
        $client            = static::createClient();
        [$user, $tenantA]  = $this->criarUsuarioAdmin();
        [, $tenantB]       = $this->criarUsuarioAdmin();
        $pastaB            = $this->criarPasta($tenantB, '90000.00');
        $idPastaB          = (int) $pastaB->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenantA);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $client->request('POST', "/pasta/{$idPastaB}/valor-causa", [
            '_token'      => $this->gerarCsrf('pasta_valor_causa_' . $idPastaB),
            'valor_causa' => '1,00',
        ]);

        self::assertResponseStatusCodeSame(404);

        // Depois da requisição o filtro ficou ligado no escritório A, então nem a
        // conferência enxerga a pasta B. Desligar aqui é o único jeito de olhar o
        // dado cru e provar que ele continua intacto.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $pastaBRecarregada = $em->find(Pasta::class, $idPastaB);
        self::assertSame(
            '90000.00',
            $pastaBRecarregada?->getValorCausa(),
            'a pasta do outro escritório não pode ser alterada'
        );
    }
}
