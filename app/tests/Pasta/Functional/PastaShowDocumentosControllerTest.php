<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
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
}
