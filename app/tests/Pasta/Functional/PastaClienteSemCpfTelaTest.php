<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Controller\PastaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Pasta de cliente PF com o CPF vazio: a lista de clientes da janela "Editar dados" decidia o nome por
 * `cliente.cpf` e, sem CPF, lia `cliente.razaoSocial`, que o PF não tem. A página inteira dava 500.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaClienteSemCpfTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    #[TestDox('Pasta de cliente PF com CPF vazio abre com 200 e mostra o nome do cliente na lista da janela')]
    public function testClientePfSemCpfAbre(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $cliente = new ClientePF();
        $cliente->setTenant($tenant);
        $cliente->setNomeCompleto('MARIA SEM CPF');
        $cliente->setCpf('');
        $cliente->setRg('');
        $cliente->setRgOrgaoExpedidor('');
        $cliente->setEmail('maria_' . uniqid() . '@test.com');
        $cliente->setCep('');
        $cliente->setEndereco('');
        $cliente->setCidade('');
        $cliente->setEstado('');
        $this->em()->persist($cliente);
        $pasta->addCliente($cliente);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('MARIA SEM CPF', $crawler->filter('#modalEditarPasta')->text());
    }
}
