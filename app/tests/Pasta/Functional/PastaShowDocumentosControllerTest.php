<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(PastaController::class)]
final class PastaShowDocumentosControllerTest extends JusPrimeWebTestCase
{
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Show Docs ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_show_docs_' . uniqid() . '@test.com');
        $user->setFullName('Admin Show Docs');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        $em->persist($userTenant);

        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('NUP-SHOW-DOCS-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarSecao(Pasta $pasta, Tenant $tenant): PastaSecao
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);
        $secao->setNome('Seção Teste');
        $secao->setOrdem(1);
        $em->persist($secao);
        $em->flush();

        return $secao;
    }

    private function criarDocumento(Pasta $pasta, Tenant $tenant, ?PastaSecao $secao, string $nomeOriginal): PastaDocumento
    {
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $doc = new PastaDocumento();
        $doc->setTitulo($nomeOriginal);
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('uploads/fake/' . $nomeOriginal);
        $doc->setNomeOriginal($nomeOriginal);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(1024);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $doc->setSecao($secao);
        $pasta->addDocumento($doc);
        $em->persist($doc);
        $em->flush();

        return $doc;
    }

    #[TestDox('GET /pasta/{id} — doc de seção fica vinculado à seção, não à raiz (gerenciador de arquivos)')]
    public function testDocEmSecaoNaoAparececEmDocumentacaoGeral(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $secao           = $this->criarSecao($pasta, $tenant);
        $secaoId         = (string) $secao->getId();

        $this->criarDocumento($pasta, $tenant, $secao, 'doc-na-secao.pdf');
        $this->criarDocumento($pasta, $tenant, null, 'doc-geral.pdf');

        // Limpa o mapa de identidade para que o request use dados frescos do banco
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertResponseIsSuccessful();

        // No gerenciador de arquivos cada arquivo é uma .fm-arquivo com data-secao:
        // "geral" para os arquivos da raiz e o id da seção para os que estão nela.
        $crawler = $client->getCrawler();

        $linhaGeral = $crawler->filter('.fm-arquivo[data-nome="doc-geral.pdf"]');
        $linhaSecao = $crawler->filter('.fm-arquivo[data-nome="doc-na-secao.pdf"]');

        self::assertCount(1, $linhaGeral, 'O documento geral deve aparecer no gerenciador.');
        self::assertCount(1, $linhaSecao, 'O documento da seção deve aparecer no gerenciador.');
        self::assertSame('geral', $linhaGeral->attr('data-secao'));
        self::assertSame($secaoId, $linhaSecao->attr('data-secao'));
    }

    #[TestDox('Cobrança ajuste 10 B2: o botão Documentos vive dentro de um [role=tablist] com irmãos — contrato do clear da flag')]
    public function testBotaoDocumentosViveDentroDeTablistComIrmaos(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertResponseIsSuccessful();
        // O `pasta-arquivos.js` é COMPARTILHADO entre esta página e a do objeto de Cobrança. O clear da
        // flag `fmTab_<id>` deixou de procurar `#pastaTabs` fixo e passou a subir do próprio botão,
        // porque lá o container é `#objetoTabs`. Este teste trava o contrato do lado de Pastas: aqui o
        // clear JÁ funcionava e não pode regredir (spec §2.1).
        //
        // O gancho é `[role="tablist"]` e não `.nav-tabs`: o redesenho de 26/08 trocou o `<ul
        // class="nav-tabs">` desta tela pelo controle segmentado `.ps-abas`, e a classe do Bootstrap
        // sumiu daqui. Com o seletor antigo o clear parava de achar o container — a flag entraria e
        // nunca sairia, e a aba Documentos grudaria a cada reload. Foi ESTE teste que pegou.
        self::assertCount(
            1,
            $crawler->filter('[role="tablist"] #documentos-tab'),
            'o botão tem de estar dentro de um container com role="tablist"'
        );
        self::assertGreaterThan(
            1,
            $crawler->filter('#pastaTabs [data-bs-toggle="tab"]')->count(),
            'o container precisa ter outras abas — são elas que limpam a flag',
        );
    }

    #[TestDox('a subpasta chega ao HTML com o pai declarado')]
    public function testSubpastaTrazPaiNoHtml(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);

        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $pai = new PastaSecao();
        $pai->setPasta($pasta);
        $pai->setTenant($tenant);
        $pai->setNome('PAI');
        $pai->setOrdem(1);
        $em->persist($pai);

        $filha = new PastaSecao();
        $filha->setPasta($pasta);
        $filha->setTenant($tenant);
        $filha->setNome('FILHA');
        $filha->setOrdem(1);
        $filha->setPai($pai);
        $em->persist($filha);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertResponseIsSuccessful();

        $noPai = $crawler->filter('.fm-pasta[data-secao-id="' . $pai->getId() . '"]');
        self::assertSame('', $noPai->attr('data-pai-id'), 'a pasta de topo não tem pai');

        $noFilha = $crawler->filter('.fm-pasta[data-secao-id="' . $filha->getId() . '"]');
        self::assertSame((string) $pai->getId(), $noFilha->attr('data-pai-id'));
    }

    #[TestDox('D3: o cartão traz data-subpastas/data-arquivos da árvore inteira, para o aviso de exclusão contar ANTES do clique')]
    public function testCartaoTrazContagemDaArvoreParaOAvisoDeExclusao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);

        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $pai = new PastaSecao();
        $pai->setPasta($pasta);
        $pai->setTenant($tenant);
        $pai->setNome('PAI');
        $pai->setOrdem(1);
        $em->persist($pai);

        $filha = new PastaSecao();
        $filha->setPasta($pasta);
        $filha->setTenant($tenant);
        $filha->setNome('FILHA');
        $filha->setOrdem(1);
        $filha->setPai($pai);
        $em->persist($filha);

        $neta = new PastaSecao();
        $neta->setPasta($pasta);
        $neta->setTenant($tenant);
        $neta->setNome('NETA');
        $neta->setOrdem(1);
        $neta->setPai($filha);
        $em->persist($neta);
        $em->flush();

        // Um arquivo em cada nível: o PAI acumula os 3 (recursivo), a FILHA acumula 2, a NETA só o dela.
        $this->criarDocumento($pasta, $tenant, $pai, 'doc-pai.pdf');
        $this->criarDocumento($pasta, $tenant, $filha, 'doc-filha.pdf');
        $this->criarDocumento($pasta, $tenant, $neta, 'doc-neta.pdf');

        $em->clear();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertResponseIsSuccessful();

        $noPai = $crawler->filter('.fm-pasta[data-secao-id="' . $pai->getId() . '"]');
        self::assertSame('2', $noPai->attr('data-subpastas'), 'FILHA e NETA');
        self::assertSame('3', $noPai->attr('data-arquivos'), 'os 3 da árvore inteira do PAI');

        $noFilha = $crawler->filter('.fm-pasta[data-secao-id="' . $filha->getId() . '"]');
        self::assertSame('1', $noFilha->attr('data-subpastas'), 'só a NETA');
        self::assertSame('2', $noFilha->attr('data-arquivos'), 'o dela mais o da NETA');

        $noNeta = $crawler->filter('.fm-pasta[data-secao-id="' . $neta->getId() . '"]');
        self::assertSame('0', $noNeta->attr('data-subpastas'), 'a NETA é folha');
        self::assertSame('1', $noNeta->attr('data-arquivos'));
    }

    #[TestDox('clicar no nome do arquivo abre o pré-visualizador, e não baixa direto')]
    public function testNomeDoArquivoAbreOPreVisualizador(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumento($pasta, $tenant, null, 'contrato-assinado.pdf');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $nome = $crawler->filter('#fmArquivos .fm-arquivo .fm-arq-nome');
        self::assertCount(1, $nome);

        /* Baixar sem olhar obriga o usuário a abrir o arquivo FORA do sistema só
           para descobrir se era o certo. O clique agora abre o modal, que já tem
           o botão Baixar no rodapé. */
        self::assertSame(
            '/pasta/documento/' . $doc->getId() . '/visualizar',
            $nome->attr('href'),
            'o href aponta para a visualização, não para o download'
        );
        self::assertStringContainsString(
            'fm-arq-preview',
            (string) $nome->attr('class'),
            'é a classe que o pasta-arquivos.js intercepta para abrir o modal'
        );

        // O modal lê estes três; sem qualquer um deles ele abre vazio ou sem nome.
        self::assertSame('/pasta/documento/' . $doc->getId() . '/visualizar', $nome->attr('data-url'));
        self::assertSame('contrato-assinado.pdf', $nome->attr('data-nome'));
        self::assertSame('application/pdf', $nome->attr('data-mime'));

        /* Sem JS o link ainda leva ao arquivo, em outra aba — degradação, não
           beco sem saída. E baixar continua a UM clique, pelo menu da linha. */
        self::assertSame('_blank', $nome->attr('target'));
        self::assertCount(
            1,
            $crawler->filter('#fmArquivos .fm-arquivo a[href="/pasta/documento/' . $doc->getId() . '/download"]'),
            'o menu ⋮ da linha continua tendo o Baixar direto'
        );
    }

    #[TestDox('o pré-visualizador traz o botão Baixar no rodapé — é ele que substitui o clique no nome')]
    public function testPreVisualizadorTemBotaoBaixar(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarDocumento($pasta, $tenant, null, 'peticao.pdf');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        /* Se este botão sumir, tirar o download do clique no nome deixa o usuário
           SEM caminho nenhum dentro do modal. */
        self::assertCount(
            1,
            $crawler->filter('#previewDocModal .modal-footer #previewDocDownload'),
            'o modal de preview tem de continuar com o Baixar no rodapé'
        );
    }

    #[TestDox('cada coluna ordenável do gerenciador é um botão, com a chave que o JS usa para ordenar')]
    public function testCabecalhoDasColunasEhOrdenavel(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarDocumento($pasta, $tenant, null, 'a.pdf');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        /* Grip e a coluna de ações NÃO entram: não há o que ordenar num punho de
           arrastar nem num menu. Se um deles virar botão, o usuário clica e nada
           acontece — que é pior que não poder clicar. */
        self::assertSame(
            ['nome', 'categoria', 'tamanho', 'data'],
            $crawler->filter('#fmArquivos .fm-lista-head .fmh-btn')->each(fn ($n) => $n->attr('data-ordenar')),
            'as quatro colunas com conteúdo ordenável, na ordem em que aparecem'
        );

        // `aria-sort` nasce em "none": nenhuma coluna manda até alguém clicar.
        self::assertSame(
            ['none', 'none', 'none', 'none'],
            $crawler->filter('#fmArquivos .fm-lista-head [aria-sort]')->each(fn ($n) => $n->attr('aria-sort'))
        );
    }

    #[TestDox('a coluna Categoria ordena pelo rótulo que a tela mostra, não pela chave do enum')]
    public function testCategoriaOrdenaPeloRotuloExibido(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumento($pasta, $tenant, null, 'x.pdf');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $linha = $crawler->filter('#fmArquivos .fm-arquivo[data-doc-id="' . $doc->getId() . '"]');
        self::assertCount(1, $linha);

        /* Ordenar pela chave (`demais`) agruparia certo e listaria numa ordem que
           a tela não exibe: o usuário veria "Demais documentos" fora do alfabeto
           e não teria como saber por quê. O `data-categoria` carrega o RÓTULO. */
        $rotuloNaTela = trim($linha->filter('.fm-arq-tipo .fm-badge-cat')->text());
        self::assertNotSame('', $rotuloNaTela);
        self::assertSame(
            $rotuloNaTela,
            $linha->attr('data-categoria'),
            'o atributo que ordena tem de ser o mesmo texto que a coluna imprime'
        );
    }

    #[TestDox('o seletor Ordenar sabe dizer tudo que as colunas dizem — os dois compartilham um estado só')]
    public function testSeletorCobreAsMesmasChavesDasColunas(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $valores = $crawler->filter('#fmOrdenar option')->each(fn ($n) => $n->attr('value'));

        /* Clicar na coluna grava o mesmo `fmOrdem` que o seletor lê. Se a coluna
           produzir uma chave que o <select> não tem, o seletor fica em branco e
           passa a dizer que NÃO há ordenação — mentindo sobre o que a lista faz. */
        foreach (['nome', 'nome_desc', 'data', 'data_desc', 'tamanho', 'tamanho_desc', 'categoria', 'categoria_desc'] as $chave) {
            self::assertContains($chave, $valores, "o seletor precisa saber representar \"{$chave}\"");
        }
        self::assertContains('manual', $valores, 'e o modo manual, que o arraste liga');
    }

    #[TestDox('o checklist é a coluna da DIREITA do gerenciador, irmã da coluna de pastas e arquivos')]
    public function testChecklistNaColunaDaDireita(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarDocumento($pasta, $tenant, null, 'a.pdf');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        /* Combinador de FILHO DIRETO nas duas colunas. É o que distingue "está
           ao lado" de "existe em algum lugar da aba" — que continuaria verdade
           com o checklist empilhado de volta no topo. E, porque roda sobre o DOM
           já PARSEADO, é também o que pega `</div>` sobrando: tag desbalanceada
           faz o parser fechar os ancestrais cedo e a relação pai→filho some. */
        self::assertCount(1, $crawler->filter('#fmBody > .fm-col-principal'), 'a coluna principal é filha direta do corpo');
        self::assertCount(1, $crawler->filter('#fmBody > #fmChecklist'), 'o checklist é IRMÃO dela, não filho');

        foreach (['fmGrupoPastas', 'fmGrupoArquivos'] as $grupo) {
            self::assertCount(
                1,
                $crawler->filter('#fmBody > .fm-col-principal > #' . $grupo),
                "#{$grupo} tem de estar na coluna principal"
            );
        }
        self::assertCount(
            0,
            $crawler->filter('.fm-col-principal #fmChecklist'),
            'o checklist NÃO pode ter caído dentro da coluna principal'
        );

        // Os id que o JS do checklist procura seguem todos dentro do bloco.
        foreach (['checklistLista', 'checklistBadge', 'btnChecklistEditar', 'btnChecklistAdicionar', 'checklistFormAdicionar'] as $id) {
            self::assertCount(
                1,
                $crawler->filter('#fmChecklist #' . $id),
                "#{$id} é contrato com o JS e tem de continuar dentro do checklist"
            );
        }
    }

    #[TestDox('a barra de progresso do checklist reflete os itens concluídos, e não um número solto')]
    public function testBarraDeProgressoDoChecklist(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $em              = static::getContainer()->get(EntityManagerInterface::class);

        // 4 itens, 1 concluído → 25%.
        foreach ([true, false, false, false] as $i => $concluido) {
            $item = new PastaChecklistItem();
            $item->setPasta($pasta);
            $item->setTenant($tenant);
            $item->setTitulo('Item ' . $i);
            $item->setOrdem($i);
            if ($concluido) {
                $item->toggle();
            }
            $em->persist($item);
        }
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertSame('1/4 itens', trim($crawler->filter('#checklistBadge')->text()));
        self::assertStringContainsString(
            'width: 25%',
            (string) $crawler->filter('#checklistBarra')->attr('style'),
            'a barra vem preenchida do servidor — não pode depender do JS para deixar de mentir no primeiro paint'
        );
        self::assertStringContainsString('incompleto', (string) $crawler->filter('#checklistBarra')->attr('class'));
        self::assertStringContainsString('incompleto', (string) $crawler->filter('#checklistBadge')->attr('class'));
    }

    #[TestDox('checklist sem item nenhum não divide por zero nem nasce dizendo 100%')]
    public function testChecklistVazioNaoDividePorZero(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        /* 0/0 é o estado de toda pasta nova. "Completo" ali seria dizer que a
           documentação está conferida quando ninguém listou o que conferir. */
        self::assertSame('0/0 itens', trim($crawler->filter('#checklistBadge')->text()));
        self::assertStringContainsString('width: 0%', (string) $crawler->filter('#checklistBarra')->attr('style'));

        /* Compara a LISTA de classes, não substring: "incompleto" contém
           "completo", e um assertStringNotContainsString('completo') falharia
           para o caso certo — foi exatamente o que aconteceu ao escrever isto. */
        $classes = preg_split('/\s+/', trim((string) $crawler->filter('#checklistBadge')->attr('class')));
        self::assertContains('incompleto', $classes);
        self::assertNotContains('completo', $classes, '0/0 não é documentação conferida: é documentação não listada');
    }
}
