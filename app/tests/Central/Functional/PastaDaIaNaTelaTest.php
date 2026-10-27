<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Controller\IaDaCentralController;
use App\Central\UseCase\DescreverPastaDoContextoDaIaUseCase;
use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * PR 4b: `GET /central/ia/pasta/{pastaId}` dá o rótulo da barra "Pasta N" da conversa com o BlueJus IA. Só NUP, cliente
 * principal e o link; qualquer pasta que a pessoa não possa ver é o MESMO 404 de mensagem fixa.
 */
#[CoversClass(IaDaCentralController::class)]
#[CoversClass(DescreverPastaDoContextoDaIaUseCase::class)]
final class PastaDaIaNaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    private const MENSAGEM = 'Não encontrei essa pasta ou você não tem acesso.';
    private const VE_PASTA = ['resources.pasta.view'];

    #[TestDox('Pasta que eu vejo: 200 com {pastaId, nup, cliente, url} e nada mais; Cache-Control private, no-store')]
    public function testRotuloDaPasta(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $pasta = $this->criarPasta($tenant);
        $this->criarClientePF($tenant, $pasta, 'Maria da Silva', '529.982.247-25');
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('GET', '/central/ia/pasta/' . $pasta->getId());

        self::assertResponseStatusCodeSame(200);
        $json = $this->json($client);
        self::assertSame(['pastaId', 'nup', 'cliente', 'url'], array_keys($json));
        self::assertSame((int) $pasta->getId(), $json['pastaId']);
        self::assertSame($pasta->getNup(), $json['nup']);
        self::assertSame('Maria da Silva', $json['cliente']);
        self::assertSame('/pasta/' . $pasta->getId(), $json['url']);
        $cabecalho = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cabecalho);
        self::assertStringContainsString('no-store', $cabecalho);
        self::assertStringNotContainsString('529.982.247-25', (string) $client->getResponse()->getContent(), 'CPF nunca sai');
    }

    #[TestDox('Pasta sem cliente: 200 com cliente nulo')]
    public function testSemCliente(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('GET', '/central/ia/pasta/' . $pasta->getId());

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->json($client)['cliente']);
    }

    #[TestDox('Inexistente, de outro escritório, excluída ou que eu não vejo: o MESMO 404 com a mensagem fixa e no-store')]
    public function testDeForaEh404(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $semVer = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta = $this->criarPasta($tenant);
        $excluida = $this->criarPasta($tenant);
        $deFora = $this->criarPasta($outro);
        $this->em()->find(Pasta::class, $excluida->getId())?->marcarExcluida($this->em()->find(User::class, $ana->getId()), new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $ana, $tenant);
        $corpos = [];
        foreach (['inexistente' => 2147483000, 'outro escritório' => $deFora->getId(), 'excluída' => $excluida->getId()] as $caso => $id) {
            $client->request('GET', '/central/ia/pasta/' . $id);
            self::assertResponseStatusCodeSame(404, $caso);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'), $caso);
            $corpos[$caso] = $this->json($client);
        }
        $this->logarComTenant($client, $semVer, $tenant);
        $client->request('GET', '/central/ia/pasta/' . $pasta->getId());
        self::assertResponseStatusCodeSame(404, 'sem acesso');
        $corpos['sem acesso'] = $this->json($client);

        foreach ($corpos as $caso => $json) {
            self::assertSame(['motivo' => 'pasta_nao_encontrada', 'mensagem' => self::MENSAGEM], $json, $caso);
        }
    }

    #[TestDox('Sem sessão: redireciona ao login ou 401, sem rótulo')]
    public function testSemSessao(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);

        $client->request('GET', '/central/ia/pasta/' . $pasta->getId());

        self::assertContains($client->getResponse()->getStatusCode(), [302, 401]);
        self::assertStringNotContainsString((string) $pasta->getNup(), (string) $client->getResponse()->getContent());
    }

    #[TestDox('Sem vínculo ativo no escritório: 403, mesmo vendo a pasta pelo papel')]
    public function testSemVinculo(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($client, $ana, $tenant);
        $this->sairDoEscritorio($ana, $tenant);

        $client->request('GET', '/central/ia/pasta/' . $pasta->getId());

        self::assertContains($client->getResponse()->getStatusCode(), [302, 403]);
        self::assertStringNotContainsString((string) $pasta->getNup(), (string) $client->getResponse()->getContent());
    }
}
