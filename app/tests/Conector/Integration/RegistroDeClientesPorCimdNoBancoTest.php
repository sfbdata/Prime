<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\Entity\ClienteDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Repository\ClienteDoConectorRepository;
use App\Conector\Service\HostsDeClientePermitidos;
use App\Conector\Service\ListaDeRedirects;
use App\Conector\Service\RegistroDeClientes;
use App\Conector\Service\RegistroDeClientesPorCimd;
use App\Tests\Conector\Unit\MontaBuscaCimd;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * A gravação do cliente CIMD no banco (spec mcp-remoto-oauth 3.4 e 13.2; teste 12.12): o upsert pelo `identificador`,
 * a corrida de unique (dois `/oauth/authorize` do mesmo app ao mesmo tempo) sem exceção e sem fechar o EntityManager,
 * e o parâmetro dos hosts lido do ambiente.
 */
#[CoversClass(RegistroDeClientesPorCimd::class)]
#[CoversClass(ClienteDoConectorRepository::class)]
final class RegistroDeClientesPorCimdNoBancoTest extends KernelTestCase
{
    use MontaBuscaCimd;

    private const RETORNO = 'https://chatgpt.com/connector_platform_oauth_redirect';

    private EntityManagerInterface $em;
    private MockClock $relogio;
    private string $url;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->relogio = self::relogio();
        $this->url = 'https://chatgpt.com/oauth/' . uniqid() . '/client.json';
    }

    private function repositorio(): ClienteDoConectorRepository
    {
        return static::getContainer()->get(ClienteDoConectorRepository::class);
    }

    private function sut(MockHttpClient $http, ?ClienteDoConectorRepository $clientes = null): RegistroDeClientesPorCimd
    {
        return new RegistroDeClientesPorCimd($clientes ?? $this->repositorio(), $this->relogio, new HostsDeClientePermitidos(['chatgpt.com']), new ListaDeRedirects(), self::busca($http, $this->relogio), self::loggerQueGuarda());
    }

    private function linhas(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_cliente WHERE identificador = ?', [$this->url]);
    }

    private function clienteDoOutroPedido(string $nome): ClienteDoConector
    {
        $agora = $this->relogio->now();
        $cliente = new ClienteDoConector($this->url, TipoDeClienteDoConector::Cimd, $nome, [self::RETORNO], $agora);
        $cliente->renovarMetadados($nome, [self::RETORNO], $agora, $agora->modify('+1 hour'));

        return $cliente;
    }

    #[TestDox('Upsert: o cliente novo é inserido; no prazo não busca; vencido busca de novo e atualiza a mesma linha')]
    public function testUpsertNoBanco(): void
    {
        $http = new MockHttpClient([
            self::resposta(self::documento($this->url, [self::RETORNO], 'ChatGPT'), 200, ['Cache-Control: max-age=600']),
            self::resposta(self::documento($this->url, [self::RETORNO, 'https://chatgpt.com/connector/oauth/abc'], 'ChatGPT novo'), 200, ['Cache-Control: max-age=900']),
        ]);
        $sut = $this->sut($http);

        $cliente = $sut->resolver($this->url, self::RETORNO);
        self::assertNotNull($cliente->getId());
        self::assertTrue($this->em->contains($cliente));
        self::assertSame(1, $this->linhas());
        self::assertSame($cliente, $sut->resolver($this->url, self::RETORNO));
        self::assertSame(1, $http->getRequestsCount());

        $this->relogio->modify('+600 seconds');
        self::assertSame($cliente->getId(), $sut->resolver($this->url, 'https://chatgpt.com/connector/oauth/abc')->getId());
        self::assertSame(2, $http->getRequestsCount());

        $this->em->clear();
        $relido = $this->repositorio()->buscarPorIdentificador($this->url);
        self::assertNotNull($relido);
        self::assertSame(1, $this->linhas());
        self::assertSame('ChatGPT novo', $relido->getNome());
        self::assertSame(TipoDeClienteDoConector::Cimd, $relido->getTipo());
        self::assertSame([self::RETORNO, 'https://chatgpt.com/connector/oauth/abc'], $relido->getRedirectUris());
        self::assertEquals(new \DateTimeImmutable('2026-11-16 12:10:00'), $relido->getMetadadosBuscadosEm());
        self::assertEquals(new \DateTimeImmutable('2026-11-16 12:25:00'), $relido->getMetadadosValidosAte());
        self::assertTrue($relido->isAtivo());
    }

    #[TestDox('inserirSeAusente: o segundo com o mesmo identificador não grava, não lança e não fecha o EntityManager')]
    public function testInserirSeAusenteNaCorrida(): void
    {
        $repositorio = $this->repositorio();

        self::assertTrue($repositorio->inserirSeAusente($this->clienteDoOutroPedido('Primeiro')));
        self::assertFalse($repositorio->inserirSeAusente($this->clienteDoOutroPedido('Segundo')));

        self::assertTrue($this->em->isOpen());
        self::assertSame(1, $this->linhas());
        self::assertSame('Primeiro', $repositorio->buscarPorIdentificador($this->url)?->getNome());
    }

    #[TestDox('Corrida de unique no registro: outro pedido grava entre a leitura e o INSERT; o registro relê a linha dele')]
    public function testCorridaDeUniqueNoRegistroRele(): void
    {
        $doOutro = $this->clienteDoOutroPedido('Do outro pedido');
        $repositorio = new class (static::getContainer()->get(ManagerRegistry::class), $doOutro) extends ClienteDoConectorRepository {
            private bool $primeiraLeitura = true;

            public function __construct(ManagerRegistry $registry, private readonly ClienteDoConector $doOutro)
            {
                parent::__construct($registry);
            }

            /** A primeira leitura não acha nada e, logo depois, o outro pedido grava a linha. */
            public function buscarPorIdentificador(string $identificador): ?ClienteDoConector
            {
                if ($this->primeiraLeitura) {
                    $this->primeiraLeitura = false;
                    $this->inserirSeAusente($this->doOutro);

                    return null;
                }

                return parent::buscarPorIdentificador($identificador);
            }
        };
        $http = new MockHttpClient(self::resposta(self::documento($this->url, [self::RETORNO], 'Deste pedido')));

        $cliente = $this->sut($http, $repositorio)->resolver($this->url, self::RETORNO);

        self::assertSame('Do outro pedido', $cliente->getNome());
        self::assertNotNull($cliente->getId());
        self::assertSame(1, $this->linhas());
        self::assertTrue($this->em->isOpen());
        $this->em->flush();
    }

    #[TestDox('Fiação: o RegistroDeClientes do container é o RegistroDeClientesPorCimd, que busca e guarda o documento CIMD')]
    public function testContainerEntregaORegistroPorCimd(): void
    {
        self::assertInstanceOf(RegistroDeClientesPorCimd::class, static::getContainer()->get(RegistroDeClientes::class));
    }

    #[TestDox('A lista de hosts vem de CONECTOR_CIMD_HOSTS, lida em tempo de execução: trocar a variável troca a lista')]
    public function testParametroDosHostsVemDoAmbiente(): void
    {
        self::assertSame(['claude.ai', 'chatgpt.com'], static::getContainer()->getParameter('conector.cimd_hosts'));

        $antes = [$_ENV['CONECTOR_CIMD_HOSTS'] ?? null, $_SERVER['CONECTOR_CIMD_HOSTS'] ?? null];
        try {
            self::ensureKernelShutdown();
            $_ENV['CONECTOR_CIMD_HOSTS'] = $_SERVER['CONECTOR_CIMD_HOSTS'] = 'exemplo.com.br,Outro.App';
            self::bootKernel();

            $hosts = static::getContainer()->getParameter('conector.cimd_hosts');
            self::assertSame(['exemplo.com.br', 'Outro.App'], $hosts);
            self::assertTrue((new HostsDeClientePermitidos($hosts))->permite('outro.app'));
            self::assertFalse((new HostsDeClientePermitidos($hosts))->permite('claude.ai'));
        } finally {
            self::ensureKernelShutdown();
            [$env, $server] = $antes;
            if ($env === null) {
                unset($_ENV['CONECTOR_CIMD_HOSTS']);
            } else {
                $_ENV['CONECTOR_CIMD_HOSTS'] = $env;
            }
            if ($server === null) {
                unset($_SERVER['CONECTOR_CIMD_HOSTS']);
            } else {
                $_SERVER['CONECTOR_CIMD_HOSTS'] = $server;
            }
        }
    }
}
