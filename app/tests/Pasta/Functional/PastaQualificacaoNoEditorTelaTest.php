<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Service\QualificacaoDoCliente;
use App\Controller\PastaController;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * EDT-014: a página da pasta leva a qualificação do cliente principal para o menu do editor
 * (`[data-editor-qualificacao]`) SÓ para quem pode ver o cliente — é PII.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaQualificacaoNoEditorTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function clienteDa(Pasta $pasta, Tenant $tenant): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setEmail('q' . uniqid() . '@test.com');
        $cliente->setCep('80000-000');
        $cliente->setEndereco('Rua Um, 1');
        $cliente->setCidade('Curitiba');
        $cliente->setEstado('PR');
        $cliente->setTenant($tenant);
        $cliente->setNomeCompleto('Joao Batista Moreira');
        $cliente->setCpf('12345678901');
        $cliente->setRg('12.345.678-9');
        $cliente->setRgOrgaoExpedidor('SSP');
        $this->em()->persist($cliente);
        $pasta->addCliente($cliente);
        $pasta->definirClientePrincipal($cliente);
        $this->em()->flush();

        return $cliente;
    }

    #[TestDox('quem vê o cliente recebe o texto do QualificacaoDoCliente, o nome e as pendências')]
    public function testQuemVeOClienteRecebeAQualificacao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $cliente         = $this->clienteDa($pasta, $tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $fonte = $crawler->filter('[data-editor-qualificacao]');
        self::assertCount(1, $fonte);
        self::assertNotNull($fonte->attr('hidden'));
        $esperado = static::getContainer()->get(QualificacaoDoCliente::class)->de($cliente);
        self::assertNotSame('', $esperado);
        self::assertSame($esperado, $fonte->attr('data-texto'));
        self::assertSame($cliente->getNomeExibicao(), $fonte->attr('data-nome'));
        self::assertMatchesRegularExpression('/^\d+$/', (string) $fonte->attr('data-pendencias'));
    }

    #[TestDox('quem só vê a pasta, sem acesso ao cliente, não recebe a qualificação (nem o item no editor)')]
    public function testSemAcessoAoClienteNaoRecebe(): void
    {
        $client          = static::createClient();
        [, $tenant]      = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $cliente         = $this->clienteDa($pasta, $tenant);
        $leitor          = $this->criarUsuarioSemPermissaoDoModulo($tenant);

        $this->logarComTenant($client, $leitor, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('[data-editor-qualificacao]'));
        self::assertStringNotContainsString('12345678901', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString((string) static::getContainer()->get(QualificacaoDoCliente::class)->de($cliente), (string) $client->getResponse()->getContent());
    }

    #[TestDox('pasta sem cliente cadastrado: a fonte vem vazia e o editor só avisa "Cliente sem cadastro"')]
    public function testSemClienteFonteVazia(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        $fonte = $crawler->filter('[data-editor-qualificacao]');
        self::assertCount(1, $fonte);
        self::assertSame('', $fonte->attr('data-texto'));
    }
}
