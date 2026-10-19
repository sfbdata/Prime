<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\Cliente;
use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Service\ClientesDuplicadosDaPasta;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cliente\ClientePJFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Frente pasta-cpf-presenca (desenho 02 VS 18.10.26).
 *
 * Selo "CPF duplicado" (L1509, cálculo L6527-6536): na lista de Clientes da pasta, o cliente cujo
 * documento (só dígitos) aparece em OUTRO cliente vinculado à mesma pasta ganha o selo, ANTES do
 * "Principal". Vale na página e na linha que o XHR de vincular devolve (mesmo partial).
 *
 * Presença do autor (L1398-1399, L1929-1930, L2456-2457): quem desenha é o JS
 * `pasta-presenca-autor.js` a partir do batimento da Central; o servidor só entrega o
 * `data-autor-id` de cada cartão e inclui o JS/CSS. É isso que se prova aqui.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(ClientesDuplicadosDaPasta::class)]
#[Group('pasta')]
final class PastaCpfDuplicadoEPresencaTelaTest extends JusPrimeWebTestCase
{
    use Factories;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{User, Tenant, Pasta} */
    private function cenario(): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant CPF Presenca ' . uniqid());
        $this->em()->persist($tenant);

        $user = new User();
        $user->setEmail('cpf_presenca_' . uniqid() . '@test.com');
        $user->setFullName('Autora Presente');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);
        $this->em()->persist(new UserTenant($user, $tenant));

        $pasta = new Pasta();
        $pasta->setNup('CPF-PRES-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return [$user, $tenant, $pasta];
    }

    private function pf(Tenant $tenant, string $nome, string $cpf): Cliente
    {
        return ClientePFFactory::createOne(['tenant' => $tenant, 'nomeCompleto' => $nome, 'cpf' => $cpf])->_real();
    }

    private function vincular(Pasta $pasta, Cliente ...$clientes): void
    {
        foreach ($clientes as $cliente) {
            $pasta->addCliente($cliente);
        }
        $this->em()->flush();
    }

    private function linhaDe(Crawler $crawler, Cliente $cliente): Crawler
    {
        return $crawler->filter('#clientesList .cliente-linha[data-cliente-id="' . $cliente->getId() . '"]');
    }

    // ───────────────────────── selo na página ─────────────────────────

    #[TestDox('Página: os dois clientes com o mesmo CPF (máscaras diferentes) têm o selo, com texto e title exatos, antes de "Principal"; o outro não')]
    public function testSeloNaPagina(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $principal = $this->pf($tenant, 'Gleisson Bruno', '006.462.251-70');
        $gemeo     = $this->pf($tenant, 'Joaozinho', '00646225170');
        $outro     = $this->pf($tenant, 'Paulo Diferente', '022.547.725-45');
        $this->vincular($pasta, $principal, $gemeo, $outro);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $linhaPrincipal = $this->linhaDe($crawler, $principal);
        self::assertCount(1, $linhaPrincipal);
        $selo = $linhaPrincipal->filter('.cliente-nome > span.ps-selo-duplicado');
        self::assertCount(1, $selo);
        self::assertSame('CPF duplicado', trim($selo->text()));
        self::assertSame('Outro cliente vinculado tem o mesmo CPF', $selo->attr('title'));

        // A ordem do desenho (L1509-1510): o selo de duplicado vem ANTES do "Principal".
        $classesDosSelos = $linhaPrincipal->filter('.cliente-nome > span[class^="ps-selo-"]')->each(static fn (Crawler $s): string => (string) $s->attr('class'));
        self::assertSame(['ps-selo-duplicado', 'ps-selo-principal'], $classesDosSelos);

        self::assertCount(1, $this->linhaDe($crawler, $gemeo)->filter('.cliente-nome > .ps-selo-duplicado'));
        self::assertCount(0, $this->linhaDe($crawler, $outro)->filter('.ps-selo-duplicado'), 'CPF diferente por um dígito não é duplicado');
        self::assertStringNotContainsString('CPF duplicado', $this->linhaDe($crawler, $outro)->text());
    }

    #[TestDox('Página: sem documento repetido, nenhum selo')]
    public function testSemDuplicadoNaoTemSelo(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $this->vincular($pasta, $this->pf($tenant, 'Ana Um', '111.222.333-44'), $this->pf($tenant, 'Bia Dois', '555.666.777-88'));

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        self::assertCount(2, $crawler->filter('#clientesList .cliente-linha'));
        self::assertCount(0, $crawler->filter('#clientesList .ps-selo-duplicado'));
    }

    #[TestDox('Página: o mesmo CPF num cliente de OUTRA pasta não marca ninguém')]
    public function testSoDentroDaPasta(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $this->vincular($pasta, $this->pf($tenant, 'Carlos Aqui', '006.462.251-70'));

        $outraPasta = new Pasta();
        $outraPasta->setNup('CPF-PRES-OUTRA-' . uniqid());
        $outraPasta->setTenant($tenant);
        $this->em()->persist($outraPasta);
        $this->vincular($outraPasta, $this->pf($tenant, 'Carlos La', '00646225170'));

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('#clientesList .ps-selo-duplicado'));
    }

    #[TestDox('Página: PJ com o mesmo CNPJ diz "CNPJ duplicado" (o desenho só fala de CPF; convenção do sistema)')]
    public function testCnpjDuplicadoNaPagina(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        // A coluna tem 14 caracteres: a máscara cheia do CNPJ não cabe, então o par difere só por um separador.
        $a = ClientePJFactory::createOne(['tenant' => $tenant, 'razaoSocial' => 'Empresa A', 'cnpj' => '12345678/0001'])->_real();
        $b = ClientePJFactory::createOne(['tenant' => $tenant, 'razaoSocial' => 'Empresa B', 'cnpj' => '123456780001'])->_real();
        $this->vincular($pasta, $a, $b);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $selo = $this->linhaDe($crawler, $b)->filter('.cliente-nome > .ps-selo-duplicado');
        self::assertCount(1, $selo);
        self::assertSame('CNPJ duplicado', trim($selo->text()));
        self::assertSame('Outro cliente vinculado tem o mesmo CNPJ', $selo->attr('title'));
    }

    // ───────────────────────── selo na linha do XHR ─────────────────────────

    #[TestDox('XHR de vincular: a linha do cliente com CPF repetido na pasta (outra máscara) vem com o selo, texto e title exatos')]
    public function testSeloNaLinhaDoXhr(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $this->vincular($pasta, $this->pf($tenant, 'Farlei Existente', '022.547.725-46'));
        $gemeo = $this->pf($tenant, 'Joao Gemeo', '02254772546');

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $linha = $this->vincularPorXhr($client, $pasta, $gemeo);
        $selo  = $linha->filter('.cliente-nome > span.ps-selo-duplicado');
        self::assertCount(1, $selo);
        self::assertSame('CPF duplicado', trim($selo->text()));
        self::assertSame('Outro cliente vinculado tem o mesmo CPF', $selo->attr('title'));
    }

    #[TestDox('XHR de vincular: a linha do cliente de CPF único na pasta vem sem o selo')]
    public function testSemSeloNaLinhaDoXhr(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $this->vincular($pasta, $this->pf($tenant, 'Farlei Existente', '022.547.725-46'));
        $unico = $this->pf($tenant, 'Paulo Unico', '022.547.725-45');

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $linha = $this->vincularPorXhr($client, $pasta, $unico);
        self::assertCount(0, $linha->filter('.ps-selo-duplicado'));
    }

    #[TestDox('XHR de vincular o PRIMEIRO cliente: vem como "Principal", sem selo de duplicado')]
    public function testPrimeiroClienteDoXhrSemSelo(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $cliente = $this->pf($tenant, 'Unico Da Pasta', '999.888.777-66');

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $linha = $this->vincularPorXhr($client, $pasta, $cliente);
        self::assertCount(1, $linha->filter('.ps-selo-principal'));
        self::assertCount(0, $linha->filter('.ps-selo-duplicado'));
    }

    // ───────────────────────── presença do autor ─────────────────────────

    #[TestDox('Os três cartões (Registro, Financeiro, Detalhes) trazem data-autor-id do autor, e a página inclui o JS e o CSS da presença')]
    public function testCartoesTemAutorIdEPaginaIncluiJsCss(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();

        $msg = (new PastaMensagem())->setPasta($pasta)->setAutor($user)->setTenant($tenant)->setConteudo('<p>Registro da autora.</p>');
        $fin = (new PastaObservacaoFinanceira())->setPasta($pasta)->setAutor($user)->setTenant($tenant)->setConteudo('Honorários combinados.');
        $det = (new PastaObservacaoDetalhes())->setPasta($pasta)->setAutor($user)->setTenant($tenant)->setConteudo('Relato do cliente.');
        $this->em()->persist($msg);
        $this->em()->persist($fin);
        $this->em()->persist($det);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $autorId = (string) $user->getId();
        foreach (['article#pasta-msg-' . $msg->getId(), 'article#financeiro-obs-' . $fin->getId(), 'article#detalhes-obs-' . $det->getId()] as $seletor) {
            $cartao = $crawler->filter($seletor);
            self::assertCount(1, $cartao, $seletor);
            self::assertSame($autorId, $cartao->attr('data-autor-id'), $seletor . ' traz o id do autor');
            // O JS acha o avatar e o nome como filhos diretos do topo do cartão.
            self::assertCount(1, $cartao->filter(':scope > .ps-anotacao-topo > .ps-avatar'), $seletor);
            self::assertCount(1, $cartao->filter(':scope > .ps-anotacao-topo > .ps-anotacao-autor'), $seletor);
        }

        self::assertCount(1, $crawler->filter('script[src*="js/pasta-presenca-autor.js"]'));
        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href*="css/pasta-presenca-autor.css"]'));

        // Os espelhos JS que montam cartão novo também levam o atributo (sem eles o cartão recém-enviado
        // ficaria sem presença até recarregar).
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-do-autor="1" data-autor-id="' . $autorId . '"', $html, 'Registro (show.html.twig, inserirMensagem)');
        self::assertStringContainsString('data-autor-id="${esc(data.autorId ?? \'\')}"', $html, 'Financeiro e Detalhes (data.autorId)');
    }

    #[TestDox('Registro do sistema também mostra a presença de quem o gerou (dc autoReg L6170): leva data-autor-id')]
    public function testRegistroDoSistemaTemAutorId(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $sis = (new PastaMensagem())->setPasta($pasta)->setAutor($user)->setTenant($tenant)
            ->setConteudo('<p><strong>Andamento:</strong> movido.</p>')->setSistema('fase');
        $this->em()->persist($sis);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $cartao = $crawler->filter('article#pasta-msg-' . $sis->getId());
        self::assertCount(1, $cartao);
        self::assertSame((string) $user->getId(), $cartao->attr('data-autor-id'));
    }

    // ───────────────────────── apoio do XHR ─────────────────────────

    private function instalarCsrfStorage(): void
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

    private function vincularPorXhr(KernelBrowser $client, Pasta $pasta, Cliente $cliente): Crawler
    {
        $client->request('POST', "/pasta/{$pasta->getId()}/cliente/vincular", [
            '_token'     => 'TOKEN_pasta_cliente_vincular_' . $pasta->getId(),
            'cliente_id' => (string) $cliente->getId(),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($dados);
        self::assertIsString($dados['html'] ?? null);

        $linha = (new Crawler($dados['html']))->filter('.cliente-linha');
        self::assertCount(1, $linha);
        self::assertSame((string) $cliente->getId(), $linha->attr('data-cliente-id'));

        return $linha;
    }
}
