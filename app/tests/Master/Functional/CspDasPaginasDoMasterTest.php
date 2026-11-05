<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Master\EventListener\CabecalhosDeSegurancaDoMaster;
use App\Tests\Blog\ArtigosDeTeste;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Support\PaginaContraACsp;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Auditoria I-xss 5.04: as páginas HTML do Master que estendem a base (painel e editor do blog) saem com a CSP do
 * painel, sem `'unsafe-inline'` nem CDN em script-src; a prévia do blog sai com a política estrita. E a página
 * cabe na política: todo `<script src>`, `<link>`, script inline (com o nonce da resposta), handler `on*`,
 * imagem, frame e formulário do HTML renderizado é aceito pela CSP (PaginaContraACsp), senão a tela quebraria
 * no navegador.
 */
#[CoversClass(CabecalhosDeSegurancaDoMaster::class)]
final class CspDasPaginasDoMasterTest extends JusPrimeWebTestCase
{
    #[TestDox('Painel do Master: CSP com nonce, sem unsafe-inline nem CDN em script-src, e a página inteira cabe nela')]
    public function testPainel(): void
    {
        $client = $this->clienteMaster();

        $client->request('GET', '/master');

        $this->assertPaginaDoPainelCabeNaCsp($client, '/master');
    }

    #[TestDox('Painel do Master com escritório na sessão (cabeçalho e avisos do escritório) também cabe na CSP')]
    public function testPainelComEscritorio(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $master = $this->master();
        $tenant = (new Tenant())->setName('Escritório CSP ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->persist(new UserTenant($master, $tenant));
        $this->em()->flush();
        $this->logarComTenant($client, $master, $tenant);

        $client->request('GET', '/master');

        $this->assertPaginaDoPainelCabeNaCsp($client, '/master com escritório');
    }

    #[TestDox('Editor do blog (novo e editar): 200, a CSP do painel e o TinyMCE local cabem na política')]
    public function testEditorDoBlog(): void
    {
        $client = $this->clienteMaster();
        $artigo = $this->artigo('<p>Olá <script>alert(1)</script></p>');

        foreach (['/master/blog/artigos/novo', '/master/blog/artigos/' . $artigo->getId()] as $url) {
            $client->request('GET', $url);
            $this->assertPaginaDoPainelCabeNaCsp($client, $url);
            self::assertStringContainsString('src="/js/tinymce/tinymce.min.js"', (string) $client->getResponse()->getContent(), $url);
        }
    }

    #[TestDox('Prévia do blog: 200, CSP estrita (sem script, rede nem formulário) e a página cabe nela')]
    public function testPreviaDoBlog(): void
    {
        $client = $this->clienteMaster();
        $artigo = $this->artigo('<h2>Seção</h2><p>Texto com <a href="https://exemplo.com.br">link</a>.</p>');

        $client->request('GET', '/master/blog/artigos/' . $artigo->getId() . '/previa');

        self::assertResponseIsSuccessful();
        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertSame(CabecalhosDeSegurancaDoMaster::CSP_PREVIA_DO_BLOG, $csp);
        foreach (["default-src 'none'", "script-src 'none'", "base-uri 'none'", "form-action 'none'", "frame-ancestors 'self'"] as $trecho) {
            self::assertStringContainsString($trecho, $csp);
        }
        self::assertSame([], PaginaContraACsp::violacoes((string) $client->getResponse()->getContent(), $csp));
    }

    #[TestDox('O nonce muda a cada resposta')]
    public function testNonceMudaACadaResposta(): void
    {
        $client = $this->clienteMaster();

        $client->request('GET', '/master/blog/artigos/novo');
        $primeiro = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        $client->request('GET', '/master/blog/artigos/novo');

        self::assertNotSame($primeiro, (string) $client->getResponse()->headers->get('Content-Security-Policy'));
    }

    private function assertPaginaDoPainelCabeNaCsp(KernelBrowser $client, string $url): void
    {
        self::assertResponseIsSuccessful($url);
        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\\/]{24}';/", $csp, $url);
        foreach (["object-src 'none'", "frame-ancestors 'self'", "connect-src 'self'", "base-uri 'self'", "form-action 'self'"] as $trecho) {
            self::assertStringContainsString($trecho, $csp, $url);
        }
        $scriptSrc = (string) preg_replace('/^.*script-src ([^;]*);.*$/', '$1', $csp);
        self::assertStringNotContainsString("'unsafe-inline'", $scriptSrc, $url);
        self::assertStringNotContainsString('https:', $scriptSrc, $url . ': nenhuma CDN em script-src');

        $html = (string) $client->getResponse()->getContent();
        self::assertSame([], PaginaContraACsp::violacoes($html, $csp), $url);
        self::assertGreaterThan(0, preg_match_all('/<script nonce="/', $html), $url . ': o script inline do layout leva o nonce');
    }

    private function artigo(string $corpo): ArtigoDoBlog
    {
        $artigo = ArtigosDeTeste::rascunhoCompleto('csp-' . bin2hex(random_bytes(4)));
        $artigo->definirCorpo($corpo, '');
        static::getContainer()->get(ArtigoDoBlogRepository::class)->salvar($artigo, flush: true);

        return $artigo;
    }

    private function clienteMaster(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->loginUser($this->master());
        $this->marcarTermosAceitos($client);

        return $client;
    }

    private function master(): User
    {
        $user = (new User())->setEmail('csp_' . uniqid() . '@test.com')->setFullName('Master CSP')
            ->setRoles(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO'])->setIsActive(true);
        $user->setPassword('x');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
