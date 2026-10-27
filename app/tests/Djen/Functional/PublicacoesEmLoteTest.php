<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\Controller\DjenController;
use App\Djen\Entity\PublicacaoDjen;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(DjenController::class)]
final class PublicacoesEmLoteTest extends JusPrimeWebTestCase
{
    use CriaFixturesDjenTrait;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function recarregar(int $id): PublicacaoDjen
    {
        $this->limparIdentityMap();

        return $this->em()->find(PublicacaoDjen::class, $id);
    }

    #[Test]
    public function tabelaTemABarraEACaixinhaPorLinhaDoDesenho(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->criarPublicacao($tenant, '960001', '07011111111111111111');
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual');

        self::assertCount(1, $crawler->filter('.pp-tabela > .pp-barra > button[data-pp-selecionar-todas]'));
        self::assertCount(1, $crawler->filter('.pp-barra > .pp-barra__limpar[data-pp-acao-lote="limpar"]'));
        self::assertCount(1, $crawler->filter('.pp-barra > .pp-barra__sel[hidden] > button[data-pp-acao-lote="excluir"]'));
        self::assertCount(1, $crawler->filter('.pp-linha--dado[data-pp-linha] > .pp-c-data > button[data-pp-marca]'));
        self::assertSame('Botão direito para mais opções', $crawler->filter('.pp-linha--dado')->attr('title'));
    }

    #[Test]
    public function marcarLidaENaoLidaMudaSoAPublicacaoDoEscritorio(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $minha = $this->criarPublicacao($tenant, '960002', '07011111111111111111');
        $outra = $this->criarPublicacao($this->criarTenant(), '960003', '07022222222222222222');
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();
        $token = $client->request('GET', '/push-processual')->filter('[data-pp-lote] input[name="_token"]')->attr('value');
        $outraId = $outra->getId();
        $ids = [$minha->getId(), $outraId];

        $client->request('POST', '/push-processual/lote/leitura', ['_token' => $token, 'ids' => $ids, 'lida' => '1', 'voltar' => '/push-processual?leitura=lidas']);

        self::assertResponseRedirects('/push-processual?leitura=lidas');
        self::assertTrue($this->recarregar($minha->getId())->isLida());
        self::assertFalse((bool) $this->em()->getConnection()->fetchOne('SELECT lida FROM publicacao_djen WHERE id = ?', [$outraId]), 'outro escritório intacto');

        $client->request('POST', '/push-processual/lote/leitura', ['_token' => $token, 'ids' => [$minha->getId()], 'lida' => '0']);
        self::assertFalse($this->recarregar($minha->getId())->isLida());
    }

    #[Test]
    public function excluirTiraDaCaixaMasMantemALinhaParaASincronizacao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $pub = $this->criarPublicacao($tenant, '960004', '07011111111111111111');
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();
        $token = $client->request('GET', '/push-processual')->filter('[data-pp-lote] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/lote/excluir', ['_token' => $token, 'ids' => [$pub->getId()]]);
        $crawler = $client->followRedirect();

        self::assertStringContainsString('1 publicação(ões) excluída(s).', $crawler->text());
        self::assertCount(0, $crawler->filter('.pp-linha--dado'));
        self::assertTrue($this->recarregar($pub->getId())->isExcluida(), 'a linha continua para o djen_id não voltar');
    }

    #[Test]
    public function limparLidasExcluiSoAsLidas(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $lida = $this->criarPublicacao($tenant, '960005', '07011111111111111111');
        $lida->setLida(true);
        $nova = $this->criarPublicacao($tenant, '960006', '07022222222222222222');
        $this->em()->flush();
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();
        $token = $client->request('GET', '/push-processual')->filter('[data-pp-lote] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/lote/limpar-lidas', ['_token' => $token]);
        $client->followRedirect();

        self::assertTrue($this->recarregar($lida->getId())->isExcluida());
        self::assertFalse($this->recarregar($nova->getId())->isExcluida());
    }

    #[Test]
    public function tokenInvalidoNaoMudaNada(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $pub = $this->criarPublicacao($tenant, '960007', '07011111111111111111');
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $client->request('POST', '/push-processual/lote/excluir', ['_token' => 'errado', 'ids' => [$pub->getId()]]);

        self::assertFalse($this->recarregar($pub->getId())->isExcluida());
    }

    #[Test]
    public function voltarParaOutroSiteEIgnorado(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();
        $token = $client->request('GET', '/push-processual')->filter('[data-pp-lote] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/lote/limpar-lidas', ['_token' => $token, 'voltar' => 'https://mau.example/x']);

        self::assertResponseRedirects('/push-processual');
    }
}
