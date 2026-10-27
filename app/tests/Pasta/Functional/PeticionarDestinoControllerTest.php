<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PeticionarController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/** `POST /pasta/documento/{id}/destino`: o processo de destino da peça (F7 do Peticionar). */
#[CoversClass(PeticionarController::class)]
final class PeticionarDestinoControllerTest extends JusPrimeWebTestCase
{
    /** @return array{\Symfony\Bundle\FrameworkBundle\KernelBrowser, Pasta, PastaDocumento} */
    private function cenario(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('security.csrf.token_storage', new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        });

        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant DESTINO ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('destino_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Destino');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));

        $pasta = new Pasta();
        $pasta->setNup('DEST-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);

        $doc = new PastaDocumento();
        $pasta->addDocumento($doc);
        $doc->setTenant($tenant);
        $doc->setTitulo('Peça');
        $doc->setCategoria(PastaDocumento::CATEGORIA_PECA);
        $doc->setNomeOriginal('peca.pdf');
        $doc->setCaminhoArquivo('peca.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(10);
        $em->persist($doc);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$client, $pasta, $doc];
    }

    #[TestDox('vínculo vazio grava destino nulo; vínculo que não é da pasta é recusado; token errado é 403')]
    public function testRota(): void
    {
        [$client, $pasta, $doc] = $this->cenario();
        $url   = "/pasta/documento/{$doc->getId()}/destino";
        $token = 'TOKEN_peticionar_destino_' . $pasta->getId();

        $client->request('POST', $url, ['_token' => $token, 'vinculo' => '']);
        self::assertResponseIsSuccessful();

        $client->request('POST', $url, ['_token' => $token, 'vinculo' => '99999999']);
        self::assertResponseStatusCodeSame(422);

        $client->request('POST', $url, ['_token' => $token, 'vinculo' => 'abc']);
        self::assertResponseStatusCodeSame(400);

        $client->request('POST', $url, ['_token' => 'errado', 'vinculo' => '']);
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('a tela leva a rota, o token e o destino de cada peça')]
    public function testTelaTrazOsDados(): void
    {
        [$client, $pasta] = $this->cenario();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();

        $raiz = $crawler->filter('#peticProc');
        self::assertCount(1, $raiz);
        self::assertStringContainsString('/destino', (string) $raiz->attr('data-url-destino'));
        self::assertNotSame('', (string) $raiz->attr('data-csrf'));
        self::assertCount(1, $crawler->filter('.petic-tl-row'), 'a peça da pasta aparece na lista');
        self::assertSame('', $crawler->filter('.petic-tl-row')->attr('data-destino'));
    }
}
