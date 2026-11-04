<?php

declare(strict_types=1);

namespace App\Tests\Blog\Functional;

use App\Blog\Armazenamento\ChavesDoBlog;
use App\Blog\Controller\ArtigosDoBlogMasterController;
use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Entity\ImagemDoArtigo;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Repository\ImagemDoArtigoRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Tests\Blog\ArtigosDeTeste;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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

    /** @var list<ChaveDeArquivo> arquivos gravados pelos testes de imagem: saem no tearDown, para o disco de teste não acumular */
    private array $chavesCriadas = [];
    private string $dirTemp = '';

    protected function tearDown(): void
    {
        if ($this->chavesCriadas !== [] && static::$booted) {
            static::getContainer()->get(RemocaoAposTransacao::class)->remover($this->chavesCriadas, 'teste_blog_imagem');
        }
        if ($this->dirTemp !== '') {
            foreach (glob($this->dirTemp . '/*') ?: [] as $arquivo) {
                @unlink($arquivo);
            }
            @rmdir($this->dirTemp);
        }
        parent::tearDown();
    }

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

    #[TestDox('Usuário de escritório (não Master): 403 no envio (POST artigos/{id}/imagens) e na entrega (GET imagens/{id}), mesmo com o token certo')]
    public function testImagensUsuarioComum(): void
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

        $artigo = $this->novoArtigo('img-comum-' . bin2hex(random_bytes(4)), 'Artigo');
        $imagem = new ImagemDoArtigo($artigo, 'nao-existe.png', 'a.png', 'image/png', 1, 1, 1, new \DateTimeImmutable());
        $this->imagens()->salvar($imagem, flush: true);
        $client->request('POST', '/master/blog/artigos/' . $artigo->getId() . '/imagens', [], ['arquivo' => $this->upload(self::png(), 'a.png')], ['HTTP_X_CSRF_TOKEN' => 'TOKEN_master_blog']);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->imagens()->doArtigo($artigo));
        $client->request('GET', '/master/blog/imagens/' . $imagem->getId());
        self::assertResponseStatusCodeSame(403);
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

    #[TestDox('Criar com marcador só de símbolos: 422 já no salvar, um erro para cada marcador')]
    public function testMarcadorSemSlug(): void
    {
        $client = $this->clienteMaster();
        $slug = 'simbolos-' . bin2hex(random_bytes(4));

        $this->postar($client, '/master/blog/artigos', ['slug' => $slug, 'blogs' => 'normal', 'marcadores' => ['INSS', '!!!', '???']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['O marcador "!!!" precisa ter letras ou números.', 'O marcador "???" precisa ter letras ou números.'],
            $this->json($client)['erros']['marcadores'],
        );
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

    #[TestDox('Páginas: o editor traz Revisar texto, Localizar, recuo da lista e símbolo do editor da pasta')]
    public function testPaginaComOsBotoesDaPasta(): void
    {
        $client = $this->clienteMaster();

        $client->request('GET', '/master/blog/artigos/novo');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        // O módulo comum da revisão vem antes do blog-editor.js, que o usa; as janelas usam o CSS do editor da pasta.
        $posModulo = strpos($html, '/js/revisao-de-texto.js');
        self::assertIsInt($posModulo, 'a página do editor não carrega o revisao-de-texto.js');
        self::assertLessThan(strpos($html, '/js/master/blog-editor.js'), $posModulo, 'o revisao-de-texto.js precisa vir antes do blog-editor.js');
        self::assertStringContainsString('/css/editor-rico.css', $html);
        // As janelas abrem dentro da casca .editor-rico que envolve o corpo.
        self::assertSelectorExists('.editor-rico[data-be-casca] > textarea[data-be-corpo]');

        $js = (string) file_get_contents(__DIR__ . '/../../../public/js/master/blog-editor.js');
        self::assertSame(1, preg_match("/toolbar: '([^']*)'/", $js, $m), 'toolbar do TinyMCE não encontrada');
        self::assertStringContainsString('bullist numlist bjdiminuirrecuo bjaumentarrecuo blockquote bjsimbolo', $m[1]);
        self::assertStringContainsString('| bjrevisar bjlocalizar | bjblocos', $m[1]);
        self::assertSame(1, preg_match("/plugins: '([^']*)'/", $js, $p));
        self::assertContains('searchreplace', explode(' ', $p[1]));
        foreach (['bjrevisar', 'bjlocalizar', 'bjdiminuirrecuo', 'bjaumentarrecuo', 'bjsimbolo'] as $botao) {
            self::assertStringContainsString("'" . $botao . "'", $js, "botão {$botao} sem registro");
        }
        // As dicas são as do editor da pasta (editor-rico.js), e o Ctrl+H abre o Localizar.
        $pasta = (string) file_get_contents(__DIR__ . '/../../../public/js/editor-rico.js');
        foreach (["'Revisar texto'", "'Localizar e substituir (Ctrl+H)'", "'Diminuir recuo (Shift+Tab)'", "'Aumentar recuo (Tab)'", "'Inserir símbolo'"] as $dica) {
            self::assertStringContainsString($dica, $js);
            self::assertStringContainsString($dica, $pasta);
        }
        self::assertStringContainsString("ed.addShortcut('meta+h'", $js);
        self::assertStringContainsString('browser_spellcheck: true', $js);
        // Cada correção da revisão é um passo de desfazer.
        self::assertStringContainsString('editor.undoManager.transact(', $js);
    }

    #[TestDox('Páginas: o editor traz o botão Inserir tabela, só com tabela simples e as ações da pasta')]
    public function testPaginaComOBotaoDeTabela(): void
    {
        $client = $this->clienteMaster();

        $client->request('GET', '/master/blog/artigos/novo');

        self::assertResponseIsSuccessful();
        $js = (string) file_get_contents(__DIR__ . '/../../../public/js/master/blog-editor.js');
        self::assertSame(1, preg_match("/toolbar: '([^']*)'/", $js, $m), 'toolbar do TinyMCE não encontrada');
        self::assertStringContainsString('blockquote bjsimbolo bjtabela |', $m[1]);
        self::assertSame(1, preg_match("/plugins: '([^']*)'/", $js, $p));
        self::assertContains('table', explode(' ', $p[1]));
        self::assertSame(1, preg_match("/valid_elements: '([^']*)'/", $js, $v));
        $elementos = explode(',', $v[1]);
        foreach (['table', 'thead', 'tbody', 'tr', 'th', 'td'] as $tag) {
            self::assertContains($tag, $elementos, "{$tag} precisa entrar sem atributo no valid_elements");
        }
        self::assertStringContainsString("tabela: 'Inserir tabela'", $js);
        self::assertStringContainsString("'bjtabela'", $js);
        self::assertStringContainsString("headerRows: 1", $js);
        self::assertStringContainsString("table_header_type: 'sectionCells'", $js);
        foreach (['table_appearance_options', 'table_advtab', 'table_cell_advtab', 'table_row_advtab', 'table_resize_bars', 'table_use_colgroups'] as $opcao) {
            self::assertStringContainsString($opcao . ': false', $js);
        }
        self::assertStringContainsString('table_default_styles: {}', $js);
        self::assertStringContainsString('table_default_attributes: {}', $js);
        self::assertStringContainsString('table_toolbar: ACOES_DA_TABELA', $js);
        // As ações, os textos e os ícones (Bootstrap Icons) são os da tabela da pasta (ACOES_DA_TABELA do editor-rico.js).
        foreach ([['arrow-bar-up', 'Inserir linha acima'], ['arrow-bar-down', 'Inserir linha abaixo'], ['arrow-bar-left', 'Inserir coluna à esquerda'],
            ['arrow-bar-right', 'Inserir coluna à direita'], ['dash-square', 'Remover linha'], ['dash-square-dotted', 'Remover coluna'], ['trash3', 'Remover tabela']] as [$icone, $texto]) {
            self::assertStringContainsString("'bj-" . $icone . "', '" . $texto . "'", $js);
            self::assertStringContainsString("'bj-" . $icone . "': svgBi(", $js);
        }
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

    #[TestDox('Imagem: o Master envia (201, {id, url}) e vê inline pelo endereço devolvido, com o tipo do conteúdo')]
    public function testEnviarEVerImagem(): void
    {
        $client = $this->clienteMaster();
        $artigo = $this->novoArtigo('img-' . bin2hex(random_bytes(4)), 'Artigo com imagem');
        $png = self::png(4, 3);

        $dados = $this->enviar($client, (int) $artigo->getId(), $png, 'tela.png', 'Tela do painel');

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['id', 'url'], array_keys($dados));
        self::assertSame('/master/blog/imagens/' . $dados['id'], $dados['url']);
        $this->em()->clear();
        $imagem = $this->imagens()->porId((int) $dados['id']);
        self::assertNotNull($imagem);
        self::assertSame($artigo->getId(), $imagem->getArtigo()->getId());
        self::assertSame(['image/png', 4, 3, \strlen($png), 'tela.png', 'Tela do painel'], [$imagem->getMime(), $imagem->getLarguraPx(), $imagem->getAlturaPx(), $imagem->getTamanhoBytes(), $imagem->getNomeOriginal(), $imagem->getAlt()]);

        $client->request('GET', $dados['url']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertStringStartsWith('inline', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertSame($png, $client->getInternalResponse()->getContent());
    }

    #[TestDox('Imagem: sem o X-CSRF-Token, 403 e nada gravado; tipo fora da lista e envio sem arquivo, 422 no campo imagem')]
    public function testEnviarImagemRecusada(): void
    {
        $client = $this->clienteMaster();
        $artigo = $this->novoArtigo('img-rec-' . bin2hex(random_bytes(4)), 'Artigo');
        $url = '/master/blog/artigos/' . $artigo->getId() . '/imagens';

        $client->request('POST', $url, [], ['arquivo' => $this->upload(self::png(), 'a.png')]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Sessão expirada. Recarregue a página.'], $this->json($client));

        $this->enviar($client, (int) $artigo->getId(), "<html><script>alert(1)</script></html>\n", 'falsa.png');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erros' => ['imagem' => ['Envie uma imagem JPG, PNG ou WEBP.']]], $this->json($client));

        $client->request('POST', $url, [], [], ['HTTP_X_CSRF_TOKEN' => 'TOKEN_master_blog']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erros' => ['imagem' => ['Escolha uma imagem para enviar.']]], $this->json($client));

        self::assertSame([], $this->imagens()->doArtigo($artigo));
    }

    #[TestDox('Imagem: 404 para artigo que não existe (envio), imagem que não existe e imagem cujo arquivo sumiu (entrega)')]
    public function testImagemInexistente(): void
    {
        $client = $this->clienteMaster();

        $this->enviar($client, 999999999, self::png(), 'a.png');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/master/blog/imagens/999999999');
        self::assertResponseStatusCodeSame(404);

        $artigo = $this->novoArtigo('img-sem-arq-' . bin2hex(random_bytes(4)), 'Artigo');
        $semArquivo = new ImagemDoArtigo($artigo, 'nao-existe-' . bin2hex(random_bytes(4)) . '.png', 'a.png', 'image/png', 1, 1, 1, new \DateTimeImmutable());
        $this->imagens()->salvar($semArquivo, flush: true);
        $client->request('GET', '/master/blog/imagens/' . $semArquivo->getId());
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Imagem: salvar sem a imagem no corpo nem na capa apaga a linha e o arquivo; a usada e a recém-enviada ficam; capa de outro artigo, 422')]
    public function testSalvarLimpaImagemSemUso(): void
    {
        $client = $this->clienteMaster();
        $slug = 'img-limpa-' . bin2hex(random_bytes(4));
        $artigo = $this->novoArtigo($slug, 'Artigo');
        $id = (int) $artigo->getId();
        $noCorpo = (int) $this->enviar($client, $id, self::png(), 'corpo.png')['id'];
        $capa = (int) $this->enviar($client, $id, self::png(), 'capa.png')['id'];
        $semUso = (int) $this->enviar($client, $id, self::png(), 'sobra.png')['id'];
        $chaveDaSobra = $this->chaveDe($semUso);
        $this->envelhecer($noCorpo, $capa, $semUso);
        $recente = (int) $this->enviar($client, $id, self::png(), 'subindo.png')['id'];

        $this->postar($client, "/master/blog/artigos/$id", ['slug' => $slug, 'blogs' => 'normal', 'imagemChave' => (string) $capa, 'imagemAlt' => 'Capa',
            'corpoHtml' => '<p>Texto</p><p><img src="/master/blog/imagens/' . $noCorpo . '" alt="Tela"></p>']);

        self::assertResponseIsSuccessful();
        $this->em()->clear();
        self::assertNull($this->imagens()->porId($semUso));
        self::assertFalse($this->armazenamento()->existe($chaveDaSobra), 'o arquivo sem uso sai depois do COMMIT');
        self::assertTrue($this->armazenamento()->existe($this->chaveDe($noCorpo)));
        self::assertTrue($this->armazenamento()->existe($this->chaveDe($capa)));
        self::assertNotNull($this->imagens()->porId($recente), 'enviada há menos de 1 hora: fica (envio em andamento)');
        self::assertTrue($this->armazenamento()->existe($this->chaveDe($recente)));
        $salvo = $this->repositorio()->porId($id);
        self::assertSame((string) $capa, $salvo?->getImagemChave());
        self::assertStringContainsString('![Tela](./imagens/' . $noCorpo . '.png)', (string) $salvo?->getCorpoMarkdown());

        $outro = $this->novoArtigo('img-outro-' . bin2hex(random_bytes(4)), 'Outro');
        $this->postar($client, '/master/blog/artigos/' . $outro->getId(), ['slug' => $outro->getSlug(), 'blogs' => 'normal', 'imagemChave' => (string) $capa]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['imagemChave'], array_keys($this->json($client)['erros']));
    }

    #[TestDox('Imagem: excluir o artigo apaga as imagens (linhas e arquivos); duplicar não copia nem a capa nem as do corpo')]
    public function testExcluirEDuplicarComImagens(): void
    {
        $client = $this->clienteMaster();
        $slug = 'img-exc-' . bin2hex(random_bytes(4));
        $artigo = $this->novoArtigo($slug, 'Artigo');
        $id = (int) $artigo->getId();
        $imagem = (int) $this->enviar($client, $id, self::png(), 'a.png')['id'];
        $chave = $this->chaveDe($imagem);
        $this->postar($client, "/master/blog/artigos/$id", ['slug' => $slug, 'blogs' => 'normal', 'imagemChave' => (string) $imagem, 'imagemAlt' => 'Capa',
            'corpoHtml' => '<p><img src="/master/blog/imagens/' . $imagem . '" alt="A"></p>']);
        self::assertResponseIsSuccessful();

        $this->postar($client, "/master/blog/artigos/$id/duplicar", []);
        self::assertResponseIsSuccessful();
        $this->em()->clear();
        $copia = $this->repositorio()->porId((int) $this->json($client)['id']);
        self::assertNotNull($copia);
        self::assertNull($copia->getImagemChave());
        self::assertStringNotContainsString('<img', $copia->getCorpoHtml());
        self::assertSame([], $this->imagens()->doArtigo($copia));

        $this->postar($client, "/master/blog/artigos/$id/excluir", []);
        self::assertResponseIsSuccessful();
        $this->em()->clear();
        self::assertNull($this->imagens()->porId($imagem));
        self::assertFalse($this->armazenamento()->existe($chave));
    }

    /** @return array<string, mixed> */
    private function enviar(KernelBrowser $client, int $artigoId, string $conteudo, string $nome, ?string $alt = null): array
    {
        $client->request('POST', "/master/blog/artigos/$artigoId/imagens", $alt === null ? [] : ['alt' => $alt], ['arquivo' => $this->upload($conteudo, $nome)], ['HTTP_X_CSRF_TOKEN' => 'TOKEN_master_blog']);
        $dados = $this->json($client);
        if (isset($dados['id'])) {
            $this->chavesCriadas[] = $this->chaveDe((int) $dados['id']);
        }

        return $dados;
    }

    /** Põe a imagem como enviada há 2 horas: fora da carência, a limpeza do salvar pode levá-la. */
    private function envelhecer(int ...$ids): void
    {
        foreach ($ids as $id) {
            $this->em()->getConnection()->executeStatement(
                'UPDATE artigo_blog_imagem SET criada_em = ? WHERE id = ?',
                [(new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'), $id],
            );
        }
        $this->em()->clear();
    }

    private function chaveDe(int $imagemId): ChaveDeArquivo
    {
        $imagem = $this->imagens()->porId($imagemId);
        self::assertNotNull($imagem);

        return ChavesDoBlog::imagem($imagem);
    }

    /** Upload em modo de teste sobre um arquivo temporário com o conteúdo dado. */
    private function upload(string $conteudo, string $nome): UploadedFile
    {
        if ($this->dirTemp === '') {
            $this->dirTemp = sys_get_temp_dir() . '/blog-imagem-func-' . bin2hex(random_bytes(6));
            mkdir($this->dirTemp, 0o700, true);
        }
        $caminho = $this->dirTemp . '/' . bin2hex(random_bytes(6));
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, null, null, true);
    }

    private static function png(int $largura = 2, int $altura = 2): string
    {
        $gd = imagecreatetruecolor($largura, $altura);
        self::assertNotFalse($gd);
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }

    private function imagens(): ImagemDoArtigoRepository
    {
        return static::getContainer()->get(ImagemDoArtigoRepository::class);
    }

    private function armazenamento(): ArmazenamentoDeArquivos
    {
        return static::getContainer()->get(ArmazenamentoDeArquivos::class);
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
