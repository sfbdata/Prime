<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(PastaController::class)]
final class VincularProcessoControllerTest extends JusPrimeWebTestCase
{
    private const XHR = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

    #[TestDox('POST vincular sem autenticação redireciona para login')]
    public function testSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/processo/vincular');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('Vincular processo existente por id retorna 200 e marca como principal')]
    public function testVincularProcessoExistentePorId(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, '11111111111111111111');

        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", [
            '_token'      => $this->gerarCsrf('vincular_processo_' . $pasta->getId()),
            'processo_id' => $processo->getId(),
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        // A resposta traz o HTML da lista atualizada para troca in-place (sem reload).
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('html', $data);
        self::assertStringContainsString('11111111111111111111', $data['html']);

        $pastaFresca = $this->recarregarPasta($pasta->getId());
        self::assertCount(1, $pastaFresca->getPastaProcessos());
        self::assertSame($processo->getId(), $pastaFresca->getProcessoPrincipal()?->getId());
    }

    #[TestDox('Vincular por número inexistente cria o processo e o vincula')]
    public function testVincularNovoViaNumeroCriaProcesso(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", [
            '_token'         => $this->gerarCsrf('vincular_processo_' . $pasta->getId()),
            'numeroProcesso' => '1234567-89.2024.1.23.4567',
            'siglaTribunal'  => 'tjdft',
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        $pastaFresca = $this->recarregarPasta($pasta->getId());
        self::assertCount(1, $pastaFresca->getPastaProcessos());
        self::assertSame('12345678920241234567', $pastaFresca->getProcessoPrincipal()?->getNumeroProcesso());
    }

    #[TestDox('Vincular o mesmo processo duas vezes é idempotente')]
    public function testVincularDuplicadoNaoDuplica(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, '22222222222222222222');
        $this->vincular($pasta, $processo, principal: true); // já vinculado

        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", [
            '_token'      => $this->gerarCsrf('vincular_processo_' . $pasta->getId()),
            'processo_id' => $processo->getId(),
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->recarregarPasta($pasta->getId())->getPastaProcessos());
    }

    #[TestDox('Vincular processo de outro escritório é negado (isolamento de tenant)')]
    public function testVincularProcessoDeOutroTenantNegado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $outroTenant     = $this->criarTenant();
        $processoAlheio  = $this->criarProcesso($outroTenant, '33333333333333333333');

        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", [
            '_token'      => $this->gerarCsrf('vincular_processo_' . $pasta->getId()),
            'processo_id' => $processoAlheio->getId(),
        ], [], self::XHR);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->recarregarPasta($pasta->getId())->getPastaProcessos());
    }

    #[TestDox('Desvincular um processo específico o remove e promove um novo principal')]
    public function testDesvincularPromoveNovoPrincipal(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $procA           = $this->criarProcesso($tenant, '44444444444444444444');
        $procB           = $this->criarProcesso($tenant, '55555555555555555555');
        $this->vincular($pasta, $procA, principal: true);
        $this->vincular($pasta, $procB, principal: false);

        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/desvincular", [
            '_token'      => $this->gerarCsrf('pasta_desvincular_processo_' . $pasta->getId()),
            'processo_id' => $procA->getId(),
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        $pastaFresca = $this->recarregarPasta($pasta->getId());
        self::assertCount(1, $pastaFresca->getPastaProcessos());
        self::assertSame($procB->getId(), $pastaFresca->getProcessoPrincipal()?->getId());
    }

    #[TestDox('Definir processo principal troca o principal da pasta')]
    public function testDefinirProcessoPrincipal(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $procA           = $this->criarProcesso($tenant, '66666666666666666666');
        $procB           = $this->criarProcesso($tenant, '77777777777777777777');
        $this->vincular($pasta, $procA, principal: true);
        $this->vincular($pasta, $procB, principal: false);

        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/principal", [
            '_token'      => $this->gerarCsrf('pasta_definir_principal_processo_' . $pasta->getId()),
            'processo_id' => $procB->getId(),
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        self::assertSame($procB->getId(), $this->recarregarPasta($pasta->getId())->getProcessoPrincipal()?->getId());
    }

    #[TestDox('Buscar processos retorna só os do tenant e exclui os já vinculados')]
    public function testBuscarRetornaSoDoTenantExcluindoVinculados(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $vinculado       = $this->criarProcesso($tenant, '88888888888888888888', 'EXECUCAO FISCAL');
        $disponivel      = $this->criarProcesso($tenant, '99999999999999999999', 'EXECUCAO FISCAL');
        $alheio          = $this->criarProcesso($this->criarTenant(), '10101010101010101010', 'EXECUCAO FISCAL');
        $this->vincular($pasta, $vinculado, principal: true);

        $this->autenticar($client, $user, $tenant);

        $client->request('GET', "/pasta/{$pasta->getId()}/processo/buscar?q=execucao", [], [], self::XHR);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $ids  = array_column($data['results'], 'id');
        self::assertContains($disponivel->getId(), $ids);
        self::assertNotContains($vinculado->getId(), $ids);
        self::assertNotContains($alheio->getId(), $ids);
    }

    // ── Helpers ──

    private function autenticar($client, User $user, Tenant $tenant): void
    {
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
    }

    /** @return array{0: User, 1: Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Vinculo ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_vinculo_' . uniqid() . '@test.com');
        $user->setFullName('Admin Vinculo');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Alheio ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('TEST-VINC-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarProcesso(Tenant $tenant, string $numero, string $classe = 'PROCEDIMENTO'): Processo
    {
        $em       = static::getContainer()->get(EntityManagerInterface::class);
        $processo = new Processo();
        $processo->setTenant($tenant);
        $processo->setNumeroProcesso($numero);
        $processo->setClasseProcessual($classe);
        $em->persist($processo);
        $em->flush();

        return $processo;
    }

    private function vincular(Pasta $pasta, Processo $processo, bool $principal): void
    {
        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $vinculo = $pasta->vincularProcesso($processo);
        $vinculo->setPrincipal($principal);
        $em->flush();
    }

    private function recarregarPasta(int $id): Pasta
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(Pasta::class)->find($id);
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
}
