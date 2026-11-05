<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Achado 2.01 da auditoria: a busca de clientes da pasta (`pasta_clientes_buscar`) existe para VINCULAR cliente,
 * e a tela só a oferece a quem edita a pasta não excluída. Ela percorre a carteira do escritório, então exige a
 * mesma guarda do vincular (EDIT, com o cadeado) e recusa a pasta excluída (lápide) com 403.
 */
#[CoversClass(PastaController::class)]
final class PastaClientesBuscaPermissaoTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    private function cliente(Tenant $tenant, string $nome, string $cpf): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setEmail('permissao' . uniqid() . '@test.com');
        $cliente->setCep('70000-000');
        $cliente->setEndereco('SQS 110 Bloco A');
        $cliente->setCidade('Brasília');
        $cliente->setEstado('DF');
        $cliente->setNumero('101')->setBairro('Asa Sul');
        $cliente->setRg('1234567');
        $cliente->setRgOrgaoExpedidor('SSP/DF');
        $cliente->setTenant($tenant);
        $cliente->setNomeCompleto($nome);
        $cliente->setCpf($cpf);
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    private function buscar(Pasta $pasta, string $termo): int
    {
        $this->client->request('GET', '/pasta/' . $pasta->getId() . '/clientes/buscar', ['q' => $termo, 'incluirVinculados' => '1'], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        return $this->client->getResponse()->getStatusCode();
    }

    /** @return array{0: User, 1: Tenant, 2: Pasta, 3: string} primário, escritório, pasta e o nome do cliente */
    private function cenario(): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $pasta = $this->criarPasta($tenant);
        $nome = 'MARCOLINO PERMISSAO ' . strtoupper(substr(uniqid(), -5));
        $this->cliente($tenant, $nome, '12345678901');

        return [$primario, $tenant, $pasta, $nome];
    }

    #[TestDox('2.01: só VIEW na pasta pelo perfil (sem edit): 403, sem a carteira')]
    public function testSoViewPeloPerfil(): void
    {
        [, $tenant, $pasta, $nome] = $this->cenario();
        $leitor = $this->criarColaborador($tenant, 'Leitor Perfil', ['resources.pasta.view']);
        $this->entrar($leitor, $tenant);

        self::assertSame(403, $this->buscar($pasta, 'marcolino'));
        self::assertStringNotContainsString($nome, (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('2.01: só VIEW na pasta por ResourceAccess: 403')]
    public function testSoViewPorResourceAccess(): void
    {
        [, $tenant, $pasta, $nome] = $this->cenario();
        $leitor = $this->criarColaborador($tenant, 'Leitor Item', []);
        $acesso = new ResourceAccess();
        $acesso->setUser($leitor);
        $acesso->setTenant($tenant);
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId((int) $pasta->getId());
        $acesso->setCanView(true);
        $this->em()->persist($acesso);
        $this->em()->flush();
        $this->entrar($leitor, $tenant);

        self::assertSame(403, $this->buscar($pasta, 'marcolino'));
        self::assertStringNotContainsString($nome, (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('2.01: Visualizar no cadeado (perfil com edit): 403')]
    public function testVisualizarNoCadeado(): void
    {
        [$primario, $tenant, $pasta, $nome] = $this->cenario();
        $ana = $this->criarColaborador($tenant);
        $this->trancar($pasta, $primario, [[$ana, NivelDeAcesso::Visualizar]]);
        $this->entrar($ana, $tenant);

        self::assertSame(403, $this->buscar($pasta, 'marcolino'));
        self::assertStringNotContainsString($nome, (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('2.01: com EDIT na pasta, a busca segue com o documento mascarado')]
    public function testComEditSegue(): void
    {
        [, $tenant, $pasta, $nome] = $this->cenario();
        $ana = $this->criarColaborador($tenant);
        $this->entrar($ana, $tenant);

        self::assertSame(200, $this->buscar($pasta, 'marcolino permissao'));
        $dados = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains($nome, array_column($dados, 'nome'));
        self::assertContains('***.456.789-**', array_column($dados, 'documento'));
        self::assertStringNotContainsString('12345678901', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('2.01: pasta excluída (lápide) responde 403, mesmo para o primário')]
    public function testPastaExcluida(): void
    {
        [$primario, $tenant, $pasta, $nome] = $this->cenario();
        $pasta->marcarExcluida($primario, new \DateTimeImmutable());
        $this->em()->flush();
        $this->entrar($primario, $tenant);

        self::assertSame(403, $this->buscar($pasta, 'marcolino'));
        self::assertStringNotContainsString($nome, (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('2.01: pasta de outro escritório continua respondendo 404')]
    public function testOutroEscritorio(): void
    {
        [, , $pasta] = $this->cenario();
        [$outroPrimario, $outro] = $this->criarEscritorio();
        $this->entrar($outroPrimario, $outro);

        self::assertSame(404, $this->buscar($pasta, 'marcolino'));
    }
}
