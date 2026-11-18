<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Sync\Entity\TenantDriveConexao;
use App\Sync\Message\SincronizarPastaNoDrive;
use App\Sync\Service\CifradorDeSegredo;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * `POST /pasta/{id}/restaurar`: restaurar sempre funciona (número livre volta igual; ocupado volta
 * com o próximo livre), com flash no caminho do cabeçalho e JSON no do fetch da Lixeira, e o Drive
 * só é avisado quando o número (logo, o nome da pasta) mudou.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class RestaurarPastaRotaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use CriaUsuarioComPermissoesTrait;

    private function instalarCsrf(): void
    {
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
    }

    private function pasta(Tenant $tenant, string $nup, ?User $excluidaPor = null): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        if ($excluidaPor !== null) {
            $pasta->marcarExcluida($excluidaPor, new \DateTimeImmutable());
        }
        $this->em()->flush();

        return $pasta;
    }

    private function conectarDrive(Tenant $tenant, User $user): void
    {
        $conexao = new TenantDriveConexao($tenant);
        $conexao->registrarCredenciais(static::getContainer()->get(CifradorDeSegredo::class)->cifrar('tok'), 'a@b.com', 'drive', $user);
        $conexao->definirRootFolder('root-abcdefghij');
        $this->em()->persist($conexao);
        $this->em()->flush();
    }

    private function transporteAsync(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

    /** @return list<mixed> */
    private function mensagensDoDrive(): array
    {
        return array_values(array_filter(
            array_map(static fn ($e) => $e->getMessage(), $this->transporteAsync()->getSent()),
            static fn ($m): bool => $m instanceof SincronizarPastaNoDrive,
        ));
    }

    /** @param array<string, string> $cabecalhos */
    private function restaurar(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, Pasta $pasta, array $cabecalhos = [], ?string $token = null): void
    {
        $id = (int) $pasta->getId();
        $this->em()->clear();
        $client->request('POST', '/pasta/' . $id . '/restaurar', ['_token' => $token ?? 'TOKEN_restaurar_pasta_' . $id], [], $cabecalhos);
    }

    private const JSON = ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'];

    #[TestDox('número livre: volta com o mesmo número, flash "Pasta N restaurada." e nada vai ao Drive')]
    public function testNumeroLivreFlashESemDrive(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $this->conectarDrive($tenant, $user);
        $pasta = $this->pasta($tenant, 'R-100', $user);
        $this->instalarCsrf();
        $this->logarComTenant($client, $user, $tenant);

        $this->restaurar($client, $pasta);

        self::assertResponseRedirects('/pasta/' . $pasta->getId());
        $flashes = $client->getRequest()->getSession()->getFlashBag()->peek('success');
        self::assertSame(['Pasta R-100 restaurada.'], $flashes);
        self::assertSame([], $this->mensagensDoDrive());
    }

    #[TestDox('número ocupado: volta com maior + 1, flash com os dois números e o Drive é avisado para renomear')]
    public function testNumeroOcupadoFlashEDrive(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $this->conectarDrive($tenant, $user);
        $lapide  = $this->pasta($tenant, '100', $user);
        $ocupante = $this->pasta($tenant, '100');
        $this->instalarCsrf();
        $this->logarComTenant($client, $user, $tenant);

        $this->restaurar($client, $lapide);

        self::assertResponseRedirects('/pasta/' . $lapide->getId());
        self::assertSame(
            ['Pasta restaurada com o número 101. O número 100 já era de outra pasta.'],
            $client->getRequest()->getSession()->getFlashBag()->peek('success'),
        );
        $this->em()->clear();
        self::assertSame('101', $this->em()->find(Pasta::class, $lapide->getId())->getNup());
        self::assertSame('100', $this->em()->find(Pasta::class, $ocupante->getId())->getNup());

        $mensagens = $this->mensagensDoDrive();
        self::assertCount(1, $mensagens);
        self::assertSame($lapide->getId(), $mensagens[0]->pastaId);
        self::assertTrue($mensagens[0]->renomear);
    }

    #[TestDox('pedido do fetch: 200 com JSON (ok, mensagem, nup, urlPasta) e sem flash')]
    public function testJsonDeSucesso(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $lapide = $this->pasta($tenant, '200', $user);
        $this->pasta($tenant, '200');
        $this->instalarCsrf();
        $this->logarComTenant($client, $user, $tenant);

        $this->restaurar($client, $lapide, self::JSON);

        self::assertResponseStatusCodeSame(200);
        $json = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($json['ok']);
        self::assertSame('Pasta restaurada com o número 201. O número 200 já era de outra pasta.', $json['mensagem']);
        self::assertSame('201', $json['nup']);
        self::assertSame('/pasta/' . $lapide->getId(), $json['urlPasta']);
        self::assertSame([], $client->getRequest()->getSession()->getFlashBag()->peek('success'));
    }

    #[TestDox('JSON de erro: CSRF inválido e pasta que não está excluída dão 422; sem DELETE dá 403')]
    public function testJsonDeErro(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $lapide = $this->pasta($tenant, '300', $user);
        $viva   = $this->pasta($tenant, '301');
        $this->instalarCsrf();
        $this->logarComTenant($client, $user, $tenant);

        $this->restaurar($client, $lapide, self::JSON, 'token-errado');
        self::assertResponseStatusCodeSame(422);
        self::assertFalse(json_decode((string) $client->getResponse()->getContent(), true)['ok']);

        $this->restaurar($client, $viva, self::JSON);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Esta pasta não está excluída.', json_decode((string) $client->getResponse()->getContent(), true)['mensagem']);

        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit']);
        $this->logarComTenant($client, $leitor, $tenant);
        $this->restaurar($client, $lapide, self::JSON);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse(json_decode((string) $client->getResponse()->getContent(), true)['ok']);
        $this->em()->clear();
        self::assertTrue($this->em()->find(Pasta::class, $lapide->getId())->estaExcluida());
    }
}
