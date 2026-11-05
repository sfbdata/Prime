<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Functional;

use App\Cliente\Controller\ClienteController;
use App\Cliente\Entity\ClientePF;
use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Auditoria, lote F1 (baixas do cliente):
 *  - 1.06: a ficha do cliente só lista as pastas que a pessoa pode ver (a mesma regra da janela do cliente);
 *  - 3.15: a recusa da ficha, da edição e da exclusão não devolve o nome do cliente na URL do pedido de acesso;
 *  - 3.17: vincular cliente à pasta exige poder ver o cliente; quem não pode recebe o 404 de id inexistente.
 */
#[CoversClass(ClienteController::class)]
#[CoversClass(PastaController::class)]
#[Group('cliente')]
final class ClienteBaixasDaAuditoriaTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const NOME = 'MARIA SIGILOSA DE TAL';
    private const CPF = '52998224725';

    private User $primario;
    private Tenant $tenant;
    private ClientePF $cliente;

    private function escritorioComCliente(): void
    {
        $this->prepararCliente();
        [$this->primario, $this->tenant] = $this->criarEscritorio();
        $this->cliente = new ClientePF();
        $this->cliente->setNomeCompleto(self::NOME);
        $this->cliente->setCpf(self::CPF);
        $this->cliente->setRg('1234567');
        $this->cliente->setRgOrgaoExpedidor('SSP/DF');
        $this->cliente->setEmail('f1_' . uniqid() . '@test.com');
        $this->cliente->setCep('70000-000');
        $this->cliente->setEndereco('SQS 110 Bloco A');
        $this->cliente->setCidade('Brasília');
        $this->cliente->setEstado('DF');
        $this->cliente->setTenant($this->tenant);
        $this->em()->persist($this->cliente);
        $this->em()->flush();
    }

    private function pastaDoCliente(string $prefixo): Pasta
    {
        $pasta = $this->criarPasta($this->tenant, self::NOME);
        $pasta->setNup($prefixo . strtoupper(substr(uniqid(), -5)));
        $pasta->setCriadoPor($this->primario);
        $pasta->addCliente($this->cliente);
        $this->em()->flush();

        return $pasta;
    }

    private function conceder(User $user, string $tipo, int $recursoId): void
    {
        $ra = new ResourceAccess();
        $ra->setUser($user);
        $ra->setTenant($this->tenant);
        $ra->setResourceType($tipo);
        $ra->setResourceId($recursoId);
        $ra->setCanView(true);
        $this->em()->persist($ra);
        $this->em()->flush();
    }

    // 1.06 ------------------------------------------------------------------------------------------------------

    #[Test]
    public function fichaSoListaAsPastasQueAPessoaPodeVer(): void
    {
        $this->escritorioComCliente();
        $liberada = $this->pastaDoCliente('LIBF1');
        $this->pastaDoCliente('PROIBF1');
        $ana = $this->criarColaborador($this->tenant, 'Ana Equipe', []);
        $this->conceder($ana, ResourceAccess::RESOURCE_CLIENTE, (int) $this->cliente->getId());
        $this->conceder($ana, ResourceAccess::RESOURCE_PASTA, (int) $liberada->getId());
        $this->entrar($ana, $this->tenant);

        $this->client->request('GET', '/clientes/' . $this->cliente->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('LIBF1', $html);
        self::assertStringNotContainsString('PROIBF1', $html, 'número da pasta que ela não pode ver não aparece');
    }

    #[Test]
    public function quemVePastasEOPrimarioVeemTodasNaFicha(): void
    {
        $this->escritorioComCliente();
        $this->pastaDoCliente('UMAF1');
        $this->pastaDoCliente('OUTRAF1');
        $bia = $this->criarColaborador($this->tenant, 'Bia Equipe', ['resources.cliente.view', 'resources.pasta.view']);

        foreach ([$bia, $this->primario] as $pessoa) {
            $this->entrar($pessoa, $this->tenant);
            $this->client->request('GET', '/clientes/' . $this->cliente->getId());
            $html = (string) $this->client->getResponse()->getContent();
            self::assertStringContainsString('UMAF1', $html);
            self::assertStringContainsString('OUTRAF1', $html);
        }
    }

    // 3.15 ------------------------------------------------------------------------------------------------------

    #[Test]
    public function recusaDaFichaEdicaoEExclusaoNaoLevaONomeNaUrl(): void
    {
        $this->escritorioComCliente();
        $semNada = $this->criarColaborador($this->tenant, 'Caio Equipe', []);
        $this->entrar($semNada, $this->tenant);
        $id = (int) $this->cliente->getId();

        foreach ([['GET', "/clientes/$id"], ['GET', "/clientes/$id/editar"], ['POST', "/clientes/$id/deletar"]] as [$metodo, $rota]) {
            $this->client->request($metodo, $rota);
            self::assertResponseRedirects(null, null, $rota);
            $destino = urldecode((string) $this->client->getResponse()->headers->get('Location'));
            self::assertStringContainsString('requestAccess=1', $destino, $rota);
            self::assertStringContainsString('resourceId=' . $id, $destino, $rota);
            self::assertStringNotContainsString('SIGILOSA', $destino, $rota . ': o nome do cliente não sai na URL');
        }
    }

    // 3.17 ------------------------------------------------------------------------------------------------------

    #[Test]
    public function vincularClienteSemPoderVerOClienteResponde404SemGravar(): void
    {
        $this->escritorioComCliente();
        $pasta = $this->criarPasta($this->tenant, 'OUTRO CLIENTE');
        $dani = $this->criarColaborador($this->tenant, 'Dani Equipe', ['resources.pasta.view', 'resources.pasta.edit']);
        $this->entrar($dani, $this->tenant);

        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/cliente/vincular', [
            '_token' => 'TOKEN_pasta_cliente_vincular_' . $pasta->getId(),
            'cliente_id' => (string) $this->cliente->getId(),
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::CPF, (string) $this->client->getResponse()->getContent());
        $this->em()->clear();
        self::assertCount(0, $this->em()->find(Pasta::class, $pasta->getId())->getClientes());
    }

    #[Test]
    public function quemVeOClienteContinuaVinculando(): void
    {
        $this->escritorioComCliente();
        $pasta = $this->criarPasta($this->tenant, 'OUTRO CLIENTE');
        $eva = $this->criarColaborador($this->tenant, 'Eva Equipe', ['resources.pasta.view', 'resources.pasta.edit', 'resources.cliente.view']);
        $this->entrar($eva, $this->tenant);

        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/cliente/vincular', [
            '_token' => 'TOKEN_pasta_cliente_vincular_' . $pasta->getId(),
            'cliente_id' => (string) $this->cliente->getId(),
        ]);

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($json['sucesso']);
        self::assertNotEmpty($json['html']);
    }

    #[Test]
    public function vincularClienteDeOutroEscritorioResponde404(): void
    {
        $this->escritorioComCliente();
        $deFora = $this->cliente;
        [, $meuEscritorio] = $this->criarEscritorio();
        $pasta = $this->criarPasta($meuEscritorio, 'OUTRO CLIENTE');
        $fabi = $this->criarColaborador($meuEscritorio, 'Fabi Equipe', ['resources.pasta.view', 'resources.pasta.edit', 'resources.cliente.view']);
        $this->entrar($fabi, $meuEscritorio);
        // Sem `em->clear()`: o cliente de fora segue no identity map, e o `find()` sem tenant o devolveria sem consultar o filtro.

        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/cliente/vincular', [
            '_token' => 'TOKEN_pasta_cliente_vincular_' . $pasta->getId(),
            'cliente_id' => (string) $deFora->getId(),
        ]);

        self::assertResponseStatusCodeSame(404);
    }
}
