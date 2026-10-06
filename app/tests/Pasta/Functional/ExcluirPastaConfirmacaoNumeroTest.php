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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Excluir pasta exige o NÚMERO dela digitado (desenho 1.2.3, `menuPasta('excluir')`) — e quem
 * confere é o SERVIDOR. Se só o prompt do navegador conferisse, a confirmação seria cosmética:
 * qualquer POST com o token CSRF excluiria. Os casos negativos provam a recusa E que a pasta
 * ficou intacta; o positivo prova que o caminho normal (lápide) continua o mesmo.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class ExcluirPastaConfirmacaoNumeroTest extends JusPrimeWebTestCase
{
    /** @return array{0: User, 1: Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Confirma Exclusao ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_confirma_excl_' . uniqid() . '@test.com');
        $user->setFullName('Quem Exclui');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant, string $nup): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setNomeCliente('CLIENTE DA CONFIRMAÇÃO');
        $pasta->setTenant($tenant);
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

    private function recarregar(int $id): ?Pasta
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(Pasta::class)->find($id);
    }

    /** @return iterable<string, array{0: array<string, string>}> */
    public static function confirmacoesErradas(): iterable
    {
        yield 'sem o campo (POST forjado só com o token)' => [[]];
        yield 'campo vazio (prompt confirmado em branco)'  => [['confirmar_nup' => '']];
        yield 'número de outra pasta'                      => [['confirmar_nup' => '1239']];
        yield 'número quase igual'                         => [['confirmar_nup' => '123']];
    }

    /** @param array<string, string> $payload */
    #[DataProvider('confirmacoesErradas')]
    #[TestDox('Confirmação errada ($_dataName): recusa, volta para a pasta e ela fica intacta')]
    public function testConfirmacaoErradaRecusa(array $payload): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $doMeio          = $this->criarPasta($tenant, '1238');
        $this->criarPasta($tenant, '1239');
        $id = (int) $doMeio->getId();

        $client->disableReboot();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$id}/deletar", $payload + ['_token' => 'TOKEN_delete_pasta_' . $id]);

        self::assertResponseRedirects("/pasta/{$id}");
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Número não confere', $crawler->text());
        self::assertCount(0, $crawler->filter('.ps-cab-excluida'), 'A tela não pode mostrar a faixa de excluída.');

        $pasta = $this->recarregar($id);
        self::assertNotNull($pasta, 'A pasta não pode ter sido apagada.');
        self::assertFalse($pasta->estaExcluida(), 'A pasta não pode ter virado lápide.');
        self::assertSame('ativo', $pasta->getSituacao(), 'A situação não pode ter mudado.');
    }

    #[TestDox('A última pasta da sequência também é protegida: número errado não a apaga de verdade')]
    public function testUltimaDaSequenciaComNumeroErradoNaoSome(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $ultima          = $this->criarPasta($tenant, '500');
        $id              = (int) $ultima->getId();

        $client->disableReboot();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$id}/deletar", ['_token' => 'TOKEN_delete_pasta_' . $id, 'confirmar_nup' => '499']);

        self::assertResponseRedirects("/pasta/{$id}");
        self::assertNotNull($this->recarregar($id), 'Número errado não pode apagar a última pasta.');
    }

    #[TestDox('Número certo: exclui como sempre — a pasta do meio vira lápide')]
    public function testNumeroCertoViraLapide(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $doMeio          = $this->criarPasta($tenant, '1238');
        $this->criarPasta($tenant, '1239');
        $id = (int) $doMeio->getId();

        $client->disableReboot();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$id}/deletar", ['_token' => 'TOKEN_delete_pasta_' . $id, 'confirmar_nup' => '1238']);

        self::assertResponseRedirects("/pasta/{$id}");
        $pasta = $this->recarregar($id);
        self::assertNotNull($pasta);
        self::assertTrue($pasta->estaExcluida(), 'Com o número certo a pasta precisa virar lápide.');
    }

    #[TestDox('O número é comparado como a pasta o grava: aparado e em maiúsculas ("1240a " vale para 1240A)')]
    public function testComparacaoNormalizada(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $doMeio          = $this->criarPasta($tenant, '1240A');
        $this->criarPasta($tenant, '1241');
        $id = (int) $doMeio->getId();

        $client->disableReboot();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$id}/deletar", ['_token' => 'TOKEN_delete_pasta_' . $id, 'confirmar_nup' => ' 1240a ']);

        self::assertResponseRedirects("/pasta/{$id}");
        self::assertTrue($this->recarregar($id)?->estaExcluida());
    }
}
