<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * FPN-012: o título do cartão do Push é o trecho curto do teor (desenho 1.2.3, dc L.2514/5378,
 * `titulo: m.texto`), não o órgão. O órgão não some: vai para a dica da linha da fonte.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaPushTituloTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO_DA_PASTA = '07011345720258070007';

    #[TestDox('O título do cartão é o trecho do teor em texto puro; o órgão vai para a dica da fonte')]
    public function testTituloEhOTrechoDoTeor(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '72000001', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $pub->setTexto('<p>Fica a parte <b>autora</b> intimada</p><p>para manifestação em 15 dias.</p>');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $cab = '.ps-push-lista > .ps-push-item[data-push-id="' . $pub->getId() . '"] > .ps-push-cartao > .ps-push-cab';
        self::assertSame(
            'Fica a parte autora intimada para manifestação em 15 dias.',
            trim($crawler->filter($cab . ' > .ps-push-assunto > .ps-push-assunto-txt')->text()),
        );
        self::assertSame('1ª Vara Cível de Brasília', $crawler->filter($cab . ' > .ps-push-corpo > .ps-push-meta')->attr('title'));
    }

    #[TestDox('Teor longo sai cortado na palavra, com reticências, e o resto do teor não vai para a página')]
    public function testTeorLongoCortado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '72000002', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $pub->setTexto(str_repeat('palavra ', 40) . 'FINALDOTEORQUENAOAPARECE');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $titulo = trim($crawler->filter('.ps-push-item[data-push-id="' . $pub->getId() . '"] .ps-push-assunto-txt')->text());
        self::assertStringEndsWith('palavra…', $titulo);
        self::assertLessThanOrEqual(221, mb_strlen($titulo));
        self::assertStringNotContainsString('FINALDOTEORQUENAOAPARECE', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Marcação do teor sai escapada: um <script> escrito no teor vira texto, não elemento')]
    public function testScriptDoTeorSaiEscapado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '72000003', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $pub->setTexto('Teor &lt;script&gt;alert("x")&lt;/script&gt; fim <script>alert("y")</script>');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $txt = $crawler->filter('.ps-push-item[data-push-id="' . $pub->getId() . '"] .ps-push-assunto-txt');
        self::assertSame('Teor <script>alert("x")</script> fim', trim($txt->text()));
        self::assertCount(0, $txt->filter('script'), 'o texto do teor não pode virar elemento');
        self::assertStringNotContainsString('alert("y")', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Sem teor, o título cai no órgão, como era; aí a fonte não repete o órgão na dica')]
    public function testSemTeorCaiNoOrgao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '72000004', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $pub->setTexto(null);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $item = $crawler->filter('.ps-push-item[data-push-id="' . $pub->getId() . '"]');
        self::assertSame('1ª Vara Cível de Brasília', trim($item->filter('.ps-push-assunto-txt')->text()));
        self::assertNull($item->filter('.ps-push-meta')->attr('title'));
    }
}
