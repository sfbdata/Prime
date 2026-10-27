<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\EntradaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Véu `/login/entrando` (spec login-desenho-10, comportamento 2). O `?para=` vem da barra de
 * endereço: o que não for caminho deste site cai no Expediente.
 */
#[CoversClass(EntradaController::class)]
final class EntradaControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('Anônimo não vê o véu: volta para o login')]
    public function testAnonimoVoltaParaOLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login/entrando?para=' . rawurlencode('/agenda'));

        self::assertResponseRedirects('/login');
    }

    #[TestDox('A saudação vem do e-mail, não do nome completo: "Dra. Ana Souza" com marina.x@ é a Marina')]
    public function testSaudacaoVemDoEmailENaoDoNomeCompleto(): void
    {
        $client = static::createClient();
        $this->logarComEscritorio($client, 'Dra. Ana Souza', 'marina.x_' . uniqid() . '@adv.com');

        $crawler = $client->request('GET', '/login/entrando');

        self::assertSame('Marina', json_decode((string) $crawler->filter('#veuEntrada')->attr('data-nome'), true));
    }

    #[TestDox('Logado vê o véu com o primeiro nome, o subtítulo e o destino pedido')]
    public function testLogadoVeOVeuComODestino(): void
    {
        $client = static::createClient();
        $this->logarComEscritorio($client, 'Marina Souza Lima', 'marina_' . uniqid() . '@adv.com');

        $crawler = $client->request('GET', '/login/entrando?para=' . rawurlencode('/agenda?dia=2026-10-19'));

        self::assertResponseIsSuccessful();
        self::assertSame('/agenda?dia=2026-10-19', $this->destinoDoVeu($crawler));
        self::assertSame('Marina', json_decode((string) $crawler->filter('#veuEntrada')->attr('data-nome'), true));
        self::assertSelectorTextContains('#veuTitulo', 'Marina');
        self::assertSelectorTextContains('#veuEntrada', 'Abrindo o seu escritório digital…');
        self::assertSame('3;url=/agenda?dia=2026-10-19', $crawler->filter('meta[http-equiv="refresh"]')->attr('content'));
        self::assertSame('/agenda?dia=2026-10-19', $crawler->filter('noscript a')->attr('href'));
        self::assertCount(1, $crawler->filter('img[src="/images/logo/bluejus-simbolo-branco.png"]'));
        self::assertCount(0, $crawler->filter('.main-sidebar, nav.main-header'), 'O véu é página inteira, sem o layout do sistema.');
    }

    #[TestDox('Sem nome completo, o véu também usa o começo do e-mail como o desenho')]
    public function testSemNomeUsaOComecoDoEmail(): void
    {
        $client = static::createClient();
        $this->logarComEscritorio($client, '', 'ana.souza_' . uniqid() . '@adv.com');

        $crawler = $client->request('GET', '/login/entrando?para=' . rawurlencode('/agenda'));

        self::assertResponseIsSuccessful();
        self::assertSame('Ana', json_decode((string) $crawler->filter('#veuEntrada')->attr('data-nome'), true));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function destinosMaliciosos(): iterable
    {
        yield 'outro site' => ['https://evil.com/'];
        yield 'relativo ao protocolo' => ['//evil.com'];
        yield 'barra invertida' => ['/\\evil.com'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'barras codificadas' => ['/%2F%2Fevil.com'];
        yield 'o próprio véu (laço)' => ['/login/entrando?para=/agenda'];
        yield 'vazio' => [''];
    }

    #[DataProvider('destinosMaliciosos')]
    #[TestDox('?para malicioso ou em laço cai no Expediente: $_dataName')]
    public function testDestinoMaliciosoCaiNoExpediente(string $para): void
    {
        $client = static::createClient();
        $this->logarComEscritorio($client, 'Marina Souza');

        $crawler = $client->request('GET', '/login/entrando?para=' . rawurlencode($para));

        self::assertResponseIsSuccessful();
        self::assertSame('/expediente', $this->destinoDoVeu($crawler));
        self::assertSame('3;url=/expediente', $crawler->filter('meta[http-equiv="refresh"]')->attr('content'));
        self::assertStringNotContainsString('evil.com', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Sem ?para, o destino é o Expediente')]
    public function testSemParaVaiParaOExpediente(): void
    {
        $client = static::createClient();
        $this->logarComEscritorio($client, 'Marina Souza');

        $crawler = $client->request('GET', '/login/entrando');

        self::assertResponseIsSuccessful();
        self::assertSame('/expediente', $this->destinoDoVeu($crawler));
    }

    private function destinoDoVeu(Crawler $crawler): mixed
    {
        return json_decode((string) $crawler->filter('#veuEntrada')->attr('data-destino'), true);
    }

    private function logarComEscritorio(KernelBrowser $client, string $nome, ?string $email = null): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Escritório Véu ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail($email ?? 'veu_' . uniqid() . '@adv.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('nao-usada');
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
    }
}
