<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\Controller\PublicacaoPastaController;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaUsuarioComPermissoesTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(PublicacaoPastaController::class)]
final class VincularPublicacaoAPastaTest extends JusPrimeWebTestCase
{
    use CriaFixturesDjenTrait;
    use CriaUsuarioComPermissoesTrait;

    private const NUMERO = '07011111111111111111';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarPastaDoEscritorio(Tenant $tenant, string $nup, string $cliente = 'MARIA DA SILVA'): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setNomeCliente($cliente);
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function tokenDaLista(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): string
    {
        return $client->request('GET', '/push-processual')->filter('[data-pp-lote] input[name="_token"]')->attr('value');
    }

    #[Test]
    public function linhaAvulsaOferecePastaEBuscaDevolveAsPastasDoEscritorio(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarPublicacao($tenant, '970001', self::NUMERO);
        $this->criarPastaDoEscritorio($tenant, 'NUP-A1', 'JOAO DE SOUZA');
        $this->criarPastaDoEscritorio($this->criarTenant(), 'NUP-OUTRO', 'JOAO ALHEIO');
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual');
        self::assertCount(1, $crawler->filter('.pp-c-acoes > button[data-pp-vincular][title="Vincular a uma pasta"]'));
        self::assertCount(0, $crawler->filter('button[data-pp-desvincular]'));
        self::assertCount(1, $crawler->filter('[data-pp-modal-pasta] [data-pp-modal-q]'));

        $client->request('GET', '/push-processual/publicacoes/pastas', ['q' => 'joao']);
        $lista = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertCount(1, $lista);
        self::assertStringContainsString('NUP-A1', $lista[0]['texto']);
    }

    #[Test]
    public function vincularCadastraOProcessoLigaAPastaEAsPublicacoesViramVinculadas(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarPublicacao($tenant, '970002', self::NUMERO);
        $pasta = $this->criarPastaDoEscritorio($tenant, 'NUP-B2');
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();
        $token = $this->tokenDaLista($client);
        $publicacaoId = (int) $client->getCrawler()->filter('[data-pp-linha]')->attr('data-id');

        $client->request('POST', sprintf('/push-processual/publicacoes/%d/vincular', $publicacaoId), ['_token' => $token, 'pasta_id' => $pasta->getId(), 'numero' => '0701111-11.1111.1.11.1111']);
        $crawler = $client->followRedirect();

        self::assertStringContainsString('vinculado à Pasta NUP-B2', $crawler->text());
        self::assertCount(1, $crawler->filter('.pp-selo--vinc[title="Pasta NUP-B2"]'));
        self::assertCount(1, $crawler->filter('button[data-pp-desvincular]'));
        self::assertSame('NUP-B2', $crawler->filter('[data-pp-linha]')->attr('data-pasta-nup'));
        self::assertSame('1', trim($crawler->filter('[data-pp-kpi="vinculadas"] .pp-kpi__valor')->text()));
        $this->limparIdentityMap();
        $processo = $this->em()->getRepository(Processo::class)->findOneBy(['numeroProcesso' => self::NUMERO]);
        self::assertNotNull($processo, 'o processo nasceu da publicação');
        self::assertTrue($this->em()->find(Pasta::class, $pasta->getId())->temProcesso($processo));
    }

    #[Test]
    public function vincularPeloNumeroDigitadoUsaONupDaPasta(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarPublicacao($tenant, '970010', self::NUMERO);
        $this->criarPastaDoEscritorio($tenant, '4321');
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();
        $token = $this->tokenDaLista($client);
        $id = (int) $client->getCrawler()->filter('[data-pp-linha]')->attr('data-id');

        $client->request('POST', "/push-processual/publicacoes/$id/vincular", ['_token' => $token, 'nup' => '4321', 'numero' => self::NUMERO]);

        self::assertStringContainsString('vinculado à Pasta 4321', $client->followRedirect()->text());
    }

    #[Test]
    public function numeroDePastaInexistenteNaoCriaPastaEMandaAbrirNova(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarPublicacao($tenant, '970011', self::NUMERO);
        $this->criarPastaDoEscritorio($this->criarTenant(), '777');
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();
        $token = $this->tokenDaLista($client);
        $id = (int) $client->getCrawler()->filter('[data-pp-linha]')->attr('data-id');

        $client->request('POST', "/push-processual/publicacoes/$id/vincular", ['_token' => $token, 'nup' => '777']);
        $crawler = $client->followRedirect();

        self::assertStringContainsString('A Pasta 777 não existe neste escritório', $crawler->text());
        self::assertCount(1, $crawler->filter('button[data-pp-vincular]'));
    }

    #[Test]
    public function modalTrazPartesAtalhoDoNumeroEAbrirNovaPasta(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $pub = $this->criarPublicacao($tenant, '970012', self::NUMERO);
        $pub->setPayloadDjen(['destinatarios' => [['nome' => 'MARIA DA SILVA', 'polo' => 'A'], ['nome' => 'BANCO X', 'polo' => 'P']]]);
        $this->em()->flush();
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual');
        self::assertCount(1, $crawler->filter('[data-pp-modal-pasta] [data-pp-modal-partes]'));
        self::assertCount(1, $crawler->filter('[data-pp-modal-pasta] button[data-pp-modal-usar]'));
        self::assertCount(1, $crawler->filter('[data-pp-modal-pasta] button[data-pp-modal-nova]'));
        $id = (int) $crawler->filter('[data-pp-linha]')->attr('data-id');

        $client->request('GET', "/push-processual/publicacoes/$id/partes");
        self::assertSame('MARIA DA SILVA × BANCO X', json_decode((string) $client->getResponse()->getContent(), true)['partes']);
    }

    #[Test]
    public function desvincularTiraOProcessoDaPastaEAPublicacaoVoltaAvulsa(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarPublicacao($tenant, '970003', self::NUMERO);
        $pasta = $this->criarPastaDoEscritorio($tenant, 'NUP-C3');
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();
        $token = $this->tokenDaLista($client);
        $id = (int) $client->getCrawler()->filter('[data-pp-linha]')->attr('data-id');
        $client->request('POST', "/push-processual/publicacoes/$id/vincular", ['_token' => $token, 'pasta_id' => $pasta->getId(), 'numero' => self::NUMERO]);

        $client->request('POST', "/push-processual/publicacoes/$id/desvincular", ['_token' => $token, 'pasta_id' => $pasta->getId(), 'numero' => self::NUMERO]);
        $crawler = $client->followRedirect();

        self::assertStringContainsString('desvinculado da Pasta NUP-C3', $crawler->text());
        self::assertCount(1, $crawler->filter('button[data-pp-vincular]'));
        self::assertSame('0', trim($crawler->filter('[data-pp-kpi="vinculadas"] .pp-kpi__valor')->text()));
    }

    #[Test]
    public function pastaDeOutroEscritorioNaoVincula(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarPublicacao($tenant, '970004', self::NUMERO);
        $alheia = $this->criarPastaDoEscritorio($this->criarTenant(), 'NUP-ALHEIA');
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();
        $token = $this->tokenDaLista($client);
        $id = (int) $client->getCrawler()->filter('[data-pp-linha]')->attr('data-id');

        $client->request('POST', "/push-processual/publicacoes/$id/vincular", ['_token' => $token, 'pasta_id' => $alheia->getId()]);
        $crawler = $client->followRedirect();

        self::assertStringContainsString('Pasta não encontrada.', $crawler->text());
        self::assertCount(1, $crawler->filter('button[data-pp-vincular]'));
    }

    #[Test]
    public function quemNaoEditaPastasNaoVincula(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarPublicacao($tenant, '970005', self::NUMERO);
        $pasta = $this->criarPastaDoEscritorio($tenant, 'NUP-D4');
        $this->logarComTenant($client, $this->criarUsuarioComPermissoes($tenant, ['modules.djen.view']), $tenant);
        $this->limparIdentityMap();
        $token = $this->tokenDaLista($client);
        $id = (int) $client->getCrawler()->filter('[data-pp-linha]')->attr('data-id');

        $client->request('POST', "/push-processual/publicacoes/$id/vincular", ['_token' => $token, 'pasta_id' => $pasta->getId()]);
        $crawler = $client->followRedirect();

        self::assertStringContainsString('permissão', $crawler->text());
        self::assertCount(1, $crawler->filter('button[data-pp-vincular]'));
    }

    #[Test]
    public function tokenInvalidoNaoVincula(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarPublicacao($tenant, '970006', self::NUMERO);
        $pasta = $this->criarPastaDoEscritorio($tenant, 'NUP-E5');
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();
        $this->tokenDaLista($client);
        $id = (int) $client->getCrawler()->filter('[data-pp-linha]')->attr('data-id');

        $client->request('POST', "/push-processual/publicacoes/$id/vincular", ['_token' => 'errado', 'pasta_id' => $pasta->getId()]);
        $crawler = $client->followRedirect();

        self::assertCount(1, $crawler->filter('button[data-pp-vincular]'));
    }

    #[Test]
    public function abaProcessosVinculaEDesvinculaPeloNumeroDoProcesso(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarPublicacao($tenant, '970020', self::NUMERO);
        $pasta = $this->criarPastaDoEscritorio($tenant, 'NUP-C3');
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();
        $token = $this->tokenDaLista($client);

        $client->request('POST', '/push-processual/publicacoes/processo/vincular', ['_token' => $token, 'numero' => self::NUMERO, 'pasta_id' => $pasta->getId()]);

        self::assertStringContainsString('vinculado à Pasta NUP-C3', $client->followRedirect()->text());
        $this->limparIdentityMap();
        $processo = $this->em()->getRepository(Processo::class)->findOneBy(['numeroProcesso' => self::NUMERO]);
        self::assertNotNull($processo);
        self::assertTrue($this->em()->find(Pasta::class, $pasta->getId())->temProcesso($processo));

        $client->request('POST', '/push-processual/publicacoes/processo/desvincular', ['_token' => $token, 'numero' => self::NUMERO, 'pasta_id' => $pasta->getId()]);

        self::assertStringContainsString('desvinculado da Pasta NUP-C3', $client->followRedirect()->text());
        $this->limparIdentityMap();
        self::assertFalse($this->em()->find(Pasta::class, $pasta->getId())->temProcesso($processo));
    }

    #[Test]
    public function abaProcessosRecusaNumeroIncompletoENupInexistente(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarPublicacao($tenant, '970021', self::NUMERO);
        $pasta = $this->criarPastaDoEscritorio($tenant, 'NUP-D4');
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();
        $token = $this->tokenDaLista($client);

        $client->request('POST', '/push-processual/publicacoes/processo/vincular', ['_token' => $token, 'numero' => '123', 'pasta_id' => $pasta->getId()]);
        self::assertStringContainsString('20 dígitos', $client->followRedirect()->text());

        $client->request('POST', '/push-processual/publicacoes/processo/vincular', ['_token' => $token, 'numero' => self::NUMERO, 'nup' => '999999']);
        self::assertStringContainsString('Use "Abrir nova pasta"', $client->followRedirect()->text());
    }
}
