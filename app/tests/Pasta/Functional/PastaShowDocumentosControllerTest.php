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

    #[TestDox('Cobrança ajuste 10 B2: o botão Documentos vive dentro de um .nav-tabs com irmãos — contrato do clear da flag')]
    public function testBotaoDocumentosViveDentroDeNavTabsComIrmaos(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertResponseIsSuccessful();
        // O `pasta-arquivos.js` é COMPARTILHADO entre esta página e a do objeto de Cobrança. O clear da
        // flag `fmTab_<id>` deixou de procurar `#pastaTabs` fixo e passou a subir do próprio botão
        // (`closest('.nav-tabs')`), porque lá o container é `#objetoTabs`. Este teste trava o contrato do
        // lado de Pastas: aqui o clear JÁ funcionava e não pode regredir (spec §2.1).
        self::assertCount(1, $crawler->filter('ul.nav-tabs #documentos-tab'), 'o botão tem de estar dentro do .nav-tabs');
        self::assertGreaterThan(
            1,
            $crawler->filter('#pastaTabs [data-bs-toggle="tab"]')->count(),
            'o container precisa ter outras abas — são elas que limpam a flag',
        );
    }
}
