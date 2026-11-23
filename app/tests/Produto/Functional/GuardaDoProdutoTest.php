<?php

declare(strict_types=1);

namespace App\Tests\Produto\Functional;

use App\Produto\Enum\ModoDoMultiproduto;
use App\Produto\Enum\Produto;
use App\Produto\EventListener\GuardaDoProduto;
use App\Produto\Service\ChaveDoMultiproduto;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * Guarda do produto nos três modos da chave `MULTIPRODUTO_MODO` (spec multiproduto, §6 e §12 "PR 6"). O escritório
 * "só Voto" tem uma linha ativa do Voto e nenhuma do Expediente; o escritório sem linha é completo.
 */
#[CoversClass(GuardaDoProduto::class)]
#[CoversClass(ChaveDoMultiproduto::class)]
#[CoversClass(ModoDoMultiproduto::class)]
final class GuardaDoProdutoTest extends JusPrimeWebTestCase
{
    use Factories;
    use ComEscritoriosPorProduto;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    #[TestDox('desligado (o padrão): o escritório só Voto abre o Expediente como hoje')]
    public function testDesligadoNadaMuda(): void
    {
        self::assertSame(ModoDoMultiproduto::Desligado, static::getContainer()->get(ChaveDoMultiproduto::class)->modo(), 'o padrão da instalação');
        $soVoto = $this->escritorio(Produto::Voto);
        $this->logarComTenant($this->client, $this->primario($soVoto), $soVoto);

        $this->client->request('GET', '/expediente');

        self::assertResponseIsSuccessful();
    }

    #[TestDox('bloqueio, escritório só Voto: Expediente e Clientes dão 404 ao primário; Voto e Meu Perfil abrem')]
    public function testBloqueioSoVoto(): void
    {
        $this->modo('bloqueio');
        $soVoto = $this->escritorio(Produto::Voto);
        $this->liberarOVoto($soVoto);
        $cliente = ClientePFFactory::createOne(['tenant' => $soVoto])->_real();
        $this->logarComTenant($this->client, $this->primario($soVoto), $soVoto);

        $this->client->request('GET', '/expediente');
        self::assertResponseStatusCodeSame(404, 'o primário (isSystem) também leva 404: a guarda fica fora do PermissionChecker');
        $this->client->request('GET', '/clientes/' . $cliente->getId());
        self::assertResponseStatusCodeSame(404, 'Clientes abre só com Expediente ou Cobranças');
        $this->client->request('GET', '/voto');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();
    }

    #[TestDox('bloqueio: chamada JSON (Accept e XHR) também dá 404, sem 403 nem 503')]
    public function testBloqueioJsonDa404(): void
    {
        $this->modo('bloqueio');
        $soVoto = $this->escritorio(Produto::Voto);
        $this->logarComTenant($this->client, $this->primario($soVoto), $soVoto);

        $this->client->request('GET', '/expediente', [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('bloqueio: o Master passa no escritório só Voto')]
    public function testBloqueioMasterPassa(): void
    {
        $this->modo('bloqueio');
        $soVoto = $this->escritorio(Produto::Voto);
        $master = $this->pessoa(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO'], false, $soVoto);
        $this->logarComTenant($this->client, $master, $soVoto);

        $this->client->request('GET', '/expediente');

        self::assertResponseIsSuccessful();
    }

    #[TestDox('bloqueio, escritório com Expediente e sem I.A.: Chat I.A., configuração da I.A. e pergunta da Central dão 404; o Expediente abre')]
    public function testBloqueioSemIa(): void
    {
        $this->modo('bloqueio');
        $semIa = $this->escritorio(Produto::Expediente);
        $this->logarComTenant($this->client, $this->primario($semIa), $semIa);

        $this->client->request('GET', '/chat-ia');
        self::assertResponseStatusCodeSame(404, 'chat_ia_pagina');
        $this->client->request('GET', '/admin/inteligencia');
        self::assertResponseStatusCodeSame(404, 'inteligencia_admin_config');
        $this->client->request('POST', '/central/ia/perguntas', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(404, 'central_ia_pergunta (JSON)');
        $this->client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();
    }

    #[TestDox('bloqueio: a rota com {tenantId} usa o escritório da URL, não o da sessão')]
    public function testEscritorioDaUrl(): void
    {
        $this->modo('bloqueio');
        $completo = $this->escritorio();
        $soVoto = $this->escritorio(Produto::Voto);
        $primario = $this->primario($completo, $soVoto);

        $this->logarComTenant($this->client, $primario, $soVoto);
        $this->client->request('GET', '/tenant/' . $completo->getId() . '/sedes');
        self::assertResponseIsSuccessful('sessão só Voto, URL do escritório completo: abre');

        $this->logarComTenant($this->client, $primario, $completo);
        $this->client->request('GET', '/tenant/' . $soVoto->getId() . '/sedes');
        self::assertResponseStatusCodeSame(404, 'sessão completa, URL do escritório só Voto (sem Ponto): 404');
    }

    #[TestDox('observacao: deixa passar e registra no canal multiproduto a rota, o escritório e os produtos exigidos, sem dado pessoal')]
    public function testObservacaoRegistraEPassa(): void
    {
        $this->modo('observacao');
        $registro = new TestHandler();
        $logger = static::getContainer()->get('monolog.logger.multiproduto');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($registro);
        $soVoto = $this->escritorio(Produto::Voto);
        $this->logarComTenant($this->client, $this->primario($soVoto), $soVoto);

        $this->client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $registro->getRecords(), 'só a rota de produto registra; o Meu Perfil é do núcleo');
        self::assertSame(
            ['rota' => 'expediente_index', 'tenant' => $soVoto->getId(), 'produtos' => ['expediente']],
            $registro->getRecords()[0]->context,
        );
    }

    #[TestDox('valor desconhecido na chave vale desligado')]
    public function testValorDesconhecidoValeDesligado(): void
    {
        $this->modo('bloqueado');
        $soVoto = $this->escritorio(Produto::Voto);
        $this->logarComTenant($this->client, $this->primario($soVoto), $soVoto);

        $this->client->request('GET', '/expediente');

        self::assertResponseIsSuccessful();
        self::assertSame(ModoDoMultiproduto::Bloqueio, ModoDoMultiproduto::doTexto(' Bloqueio '));
        self::assertSame(ModoDoMultiproduto::Desligado, ModoDoMultiproduto::doTexto(null));
    }
}
