<?php

declare(strict_types=1);

namespace App\Tests\Blog\Functional;

use App\Blog\Controller\ArtigosDoBlogMasterController;
use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Blog\ArtigosDeTeste;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * "Artigos dos blogs" do Painel do Master pela HTTP (spec painel-artigos-blogs, seções 2 e 5): só o Master, CSRF no
 * header X-CSRF-Token em todo POST.
 *
 * As páginas GET /master/blog/artigos/novo, /master/blog/artigos/{id} e /master/blog/artigos/{id}/previa renderizam
 * blog/editor.html.twig e blog/previa.html.twig (testes "Páginas" abaixo).
 */
#[CoversClass(ArtigosDoBlogMasterController::class)]
final class ArtigosDoBlogMasterControllerTest extends JusPrimeWebTestCase
{
    private const CSRF = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_blog'];

    #[TestDox('Anônimo é mandado para o login')]
    public function testAnonimo(): void
    {
        $client = static::createClient();

        $client->request('GET', '/master/blog/artigos');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('Usuário de escritório (não Master): 403 na lista, nas sugestões e nos POST')]
    public function testUsuarioComum(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $tenant = (new Tenant())->setName('Escritório Blog ' . uniqid());
        $em->persist($tenant);
        $usuario = $this->usuario(['ROLE_USER']);
        $em->persist(new UserTenant($usuario, $tenant));
        $em->flush();
        $this->logarComTenant($client, $usuario, $tenant);
        $this->trocarTokens();

        foreach (['/master/blog/artigos', '/master/blog/marcadores'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $client->request('POST', '/master/blog/artigos', [], [], self::CSRF, (string) json_encode(['slug' => 'x', 'blogs' => 'normal']));
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->repositorio()->porSlug('x'));
    }

    #[TestDox('Master: a lista em JSON, com busca')]
    public function testListaDoMaster(): void
    {
        $client = $this->clienteMaster();
        $marca = 'lista-' . bin2hex(random_bytes(4));
        $this->novoArtigo($marca, 'Artigo da lista');

        $client->request('GET', '/master/blog/artigos?busca=' . $marca . '&situacao=inexistente');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $dados = $this->json($client);
        self::assertSame(['itens', 'total', 'pagina', 'porPagina'], array_keys($dados));
        self::assertSame(1, $dados['total']);
        self::assertSame($marca, $dados['itens'][0]['slug']);
        self::assertSame('Artigo da lista', $dados['itens'][0]['titulo']);
    }

    #[TestDox('POST sem o X-CSRF-Token (ou com token errado): 403 e nada gravado')]
    public function testSemToken(): void
    {
        $client = $this->clienteMaster();
        $corpo = (string) json_encode(['slug' => 'sem-token', 'blogs' => 'normal']);

        $client->request('POST', '/master/blog/artigos', [], [], ['CONTENT_TYPE' => 'application/json'], $corpo);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Sessão expirada. Recarregue a página.'], $this->json($client));

        $client->request('POST', '/master/blog/artigos', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'errado'], $corpo);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->repositorio()->porSlug('sem-token'));
    }

    #[TestDox('Criar rascunho com marcadores: 200, grava normalizado (sem espaço extra nem repetido)')]
    public function testCriarComMarcadores(): void
    {
        $client = $this->clienteMaster();
        $slug = 'novo-' . bin2hex(random_bytes(4));

        $this->postar($client, '/master/blog/artigos', [
            'slug' => $slug,
            'blogs' => 'ambos',
            'titulo' => 'Novo artigo',
            'marcadores' => [' Prazo  em dobro ', 'Recesso', 'prazo em dobro', ''],
        ]);

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);
        self::assertTrue($dados['ok']);
        self::assertSame('1 min', $dados['leitura']);
        $artigo = $this->repositorio()->porId((int) $dados['id']);
        self::assertNotNull($artigo);
        self::assertSame($slug, $artigo->getSlug());
        self::assertSame(['Prazo em dobro', 'Recesso'], $artigo->getMarcadores());
    }

    #[TestDox('Criar com 9 marcadores: 422 com o erro em marcadores, nada gravado')]
    public function testNoveMarcadores(): void
    {
        $client = $this->clienteMaster();
        $slug = 'nove-' . bin2hex(random_bytes(4));

        $this->postar($client, '/master/blog/artigos', [
            'slug' => $slug,
            'blogs' => 'normal',
            'marcadores' => ['um', 'dois', 'tres', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Use até 8 marcadores.'], $this->json($client)['erros']['marcadores']);
        self::assertNull($this->repositorio()->porSlug($slug));
    }

    #[TestDox('Sugestões de marcadores: os já usados, com a busca')]
    public function testSugestoes(): void
    {
        $client = $this->clienteMaster();
        $marca = 'Zq' . bin2hex(random_bytes(3));
        $this->novoArtigo('sug-a-' . strtolower($marca), 'A', ["$marca prazo", 'Outro']);
        $this->novoArtigo('sug-b-' . strtolower($marca), 'B', ["$marca prazo"]);

        $client->request('GET', '/master/blog/marcadores?busca=' . strtolower($marca));

        self::assertResponseIsSuccessful();
        self::assertSame([['marcador' => "$marca prazo", 'usos' => 2]], $this->json($client));
    }

    #[TestDox('Duplicar: 200 com o id da cópia, um rascunho com slug -copia')]
    public function testDuplicar(): void
    {
        $client = $this->clienteMaster();
        $slug = 'dup-' . bin2hex(random_bytes(4));
        $origem = $this->novoArtigo($slug, 'Original', ['Prazos']);

        $this->postar($client, '/master/blog/artigos/' . $origem->getId() . '/duplicar', []);

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);
        self::assertTrue($dados['ok']);
        $copia = $this->repositorio()->porId((int) $dados['id']);
        self::assertNotNull($copia);
        self::assertSame($slug . '-copia', $copia->getSlug());
        self::assertSame(['Prazos'], $copia->getMarcadores());
    }

    #[TestDox('Excluir: rascunho some (200); publicado recusa com 409 e fica')]
    public function testExcluir(): void
    {
        $client = $this->clienteMaster();
        $rascunho = $this->novoArtigo('exc-' . bin2hex(random_bytes(4)), 'Rascunho');
        $id = (int) $rascunho->getId();

        $this->postar($client, "/master/blog/artigos/$id/excluir", []);
        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true], $this->json($client));
        $this->em()->clear();
        self::assertNull($this->repositorio()->porId($id));

        $publicado = ArtigosDeTeste::controleDePrazos();
        $publicado->trocarSlug('exc-pub-' . bin2hex(random_bytes(4)));
        $this->repositorio()->salvar($publicado, flush: true);

        $this->postar($client, '/master/blog/artigos/' . $publicado->getId() . '/excluir', []);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['erro' => 'Despublique o artigo antes de excluir.'], $this->json($client));
        self::assertNotNull($this->repositorio()->porId((int) $publicado->getId()));
    }

    #[TestDox('Id inexistente: 404 em salvar, verificar, duplicar e excluir')]
    public function testIdInexistente(): void
    {
        $client = $this->clienteMaster();

        $this->postar($client, '/master/blog/artigos/999999999', ['slug' => 'nao-existe', 'blogs' => 'normal']);
        self::assertResponseStatusCodeSame(404);
        foreach (['verificar', 'duplicar', 'excluir'] as $acao) {
            $this->postar($client, "/master/blog/artigos/999999999/$acao", []);
            self::assertResponseStatusCodeSame(404, $acao);
            self::assertArrayHasKey('erro', $this->json($client));
        }
    }

    #[TestDox('Verificar: os erros do zod, sem o da data de publicação')]
    public function testVerificar(): void
    {
        $client = $this->clienteMaster();
        $artigo = $this->novoArtigo('ver-' . bin2hex(random_bytes(4)), '');

        $this->postar($client, '/master/blog/artigos/' . $artigo->getId() . '/verificar', []);

        self::assertResponseIsSuccessful();
        $erros = $this->json($client)['erros'];
        self::assertSame(['Informe o título.'], $erros['titulo']);
        self::assertArrayNotHasKey('publicarEm', $erros);
    }

    #[TestDox('Páginas: novo artigo abre o editor vazio, com o token e o TinyMCE')]
    public function testPaginaNovo(): void
    {
        $client = $this->clienteMaster();

        $client->request('GET', '/master/blog/artigos/novo');

        self::assertResponseIsSuccessful();
        self::assertNotSame('', (string) $client->getCrawler()->filter('#blog-editor')->attr('data-token'));
        self::assertSelectorTextContains('h1', 'Novo artigo');
        self::assertSelectorExists('textarea[data-be-corpo]');
        self::assertSelectorExists('[data-be-verificar][disabled]');
        self::assertStringContainsString('js/tinymce/tinymce.min.js', (string) $client->getResponse()->getContent());
        $artigo = json_decode((string) $client->getCrawler()->filter('#blog-editor')->attr('data-artigo'), true);
        self::assertNull($artigo['id']);
        self::assertSame('rascunho', $artigo['situacao']);
    }

    #[TestDox('Páginas: editar mostra o artigo (título, marcadores e o corpo como texto, nunca HTML cru)')]
    public function testPaginaEditar(): void
    {
        $client = $this->clienteMaster();
        $artigo = $this->novoArtigo('pag-' . bin2hex(random_bytes(4)), 'Prazo em dobro na prática', ['INSS']);
        $artigo->definirCorpo('<p>Olá <script>alert(1)</script></p>', 'Olá');
        $this->repositorio()->salvar($artigo, flush: true);

        $client->request('GET', '/master/blog/artigos/' . $artigo->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Editar artigo');
        self::assertSame('Prazo em dobro na prática', $client->getCrawler()->filter('input[data-campo="titulo"]')->attr('value'));
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        $dados = json_decode((string) $client->getCrawler()->filter('#blog-editor')->attr('data-artigo'), true);
        self::assertSame($artigo->getId(), $dados['id']);
        self::assertSame(['INSS'], $dados['marcadores']);
        self::assertSame('<p>Olá <script>alert(1)</script></p>', $client->getCrawler()->filter('textarea[data-be-corpo]')->text());
    }

    #[TestDox('Páginas: id inexistente dá 404 no editor e na prévia')]
    public function testPaginasInexistente(): void
    {
        $client = $this->clienteMaster();

        $client->request('GET', '/master/blog/artigos/999999999');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/master/blog/artigos/999999999/previa');
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Páginas: a prévia mostra título, linha fina, categoria, autor e o corpo limpo pela lista branca')]
    public function testPaginaPrevia(): void
    {
        $client = $this->clienteMaster();
        $artigo = $this->novoArtigo('prev-' . bin2hex(random_bytes(4)), 'Artigo da prévia');
        $artigo->editarTextos('Artigo da prévia', 'Curto', 'SEO', 'Descrição', 'A linha fina da prévia', 'Resumo');
        $artigo->classificar('prazos-processuais', 'equipe-editorial-bluejus', null, null, '4 min', false);
        $artigo->definirCorpo('<h2>Seção um</h2><p>Texto <script>alert(1)</script></p>', '');
        $this->repositorio()->salvar($artigo, flush: true);

        $client->request('GET', '/master/blog/artigos/' . $artigo->getId() . '/previa');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Artigo da prévia');
        self::assertSelectorTextContains('.pv-linha-fina', 'A linha fina da prévia');
        self::assertSelectorTextContains('.pv-cat', 'Prazos Processuais e Controle de Processos');
        self::assertSelectorTextContains('.pv-meta', 'Equipe editorial BlueJus');
        self::assertSelectorTextContains('.pv-meta', '4 min');
        self::assertSelectorTextContains('.pv-corpo h2', 'Seção um');
        self::assertStringNotContainsString('alert(1)', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Páginas: usuário de escritório (não Master) recebe 403 no editor e na prévia')]
    public function testPaginasUsuarioComum(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $tenant = (new Tenant())->setName('Escritório Blog ' . uniqid());
        $em->persist($tenant);
        $usuario = $this->usuario(['ROLE_USER']);
        $em->persist(new UserTenant($usuario, $tenant));
        $em->flush();
        $this->logarComTenant($client, $usuario, $tenant);
        $artigo = $this->novoArtigo('pag-403-' . bin2hex(random_bytes(4)), 'Fechado');

        foreach (['/master/blog/artigos/novo', '/master/blog/artigos/' . $artigo->getId(), '/master/blog/artigos/' . $artigo->getId() . '/previa'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    #[TestDox('Painel do Master: leva as URLs e o token da seção Artigos dos blogs')]
    public function testPainelComASecao(): void
    {
        $client = $this->clienteMaster();

        $client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        $urls = json_decode((string) $client->getCrawler()->filter('#master-painel')->attr('data-urls'), true);
        self::assertSame('/master/blog/artigos', $urls['blogArtigos']);
        self::assertNotSame('', (string) $urls['blogToken']);
        self::assertStringContainsString('js/master/blog-artigos.js', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('master.blog.js.secao', (string) $client->getResponse()->getContent());
    }

    /** @param list<string> $marcadores */
    private function novoArtigo(string $slug, string $titulo, array $marcadores = []): ArtigoDoBlog
    {
        $artigo = new ArtigoDoBlog($slug, BlogsDoArtigo::Normal, null, new \DateTimeImmutable());
        $artigo->editarTextos($titulo, $titulo, $titulo, '', '', '');
        $artigo->definirMarcadores($marcadores);
        $this->repositorio()->salvar($artigo, flush: true);

        return $artigo;
    }

    /** @param array<string, mixed> $corpo */
    private function postar(KernelBrowser $client, string $url, array $corpo): void
    {
        $client->request('POST', $url, [], [], self::CSRF, (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    private function clienteMaster(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->trocarTokens();
        $client->loginUser($this->usuario(['ROLE_SUPER_ADMIN']));
        $this->marcarTermosAceitos($client);

        return $client;
    }

    /** O token CSRF de cada id vira "TOKEN_<id>" (mesmo recurso dos testes do Comercial do Master). */
    private function trocarTokens(): void
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

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $user = (new User())->setEmail('blog_' . uniqid() . '@test.com')->setFullName('Pessoa Blog')->setRoles($papeis)->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function repositorio(): ArtigoDoBlogRepository
    {
        return static::getContainer()->get(ArtigoDoBlogRepository::class);
    }
}
