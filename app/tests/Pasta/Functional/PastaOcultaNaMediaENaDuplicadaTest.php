<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Lote F2 da auditoria: o que vaza de pasta oculta (cadeado) ou de aba oculta por fora da própria pasta.
 *
 * - 1.07: o aviso de pasta duplicada (cliente e ação) não pode citar pasta oculta para quem envia.
 * - 1.11: a média do valor da causa por cliente não soma pasta oculta para quem olha.
 * - 2.04: com a aba Financeiro oculta, as respostas das rotas de cliente não trazem a média.
 */
#[Group('pasta')]
final class PastaOcultaNaMediaENaDuplicadaTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES = ['modules.pastas.view', 'modules.expediente.view', 'resources.pasta.view', 'resources.pasta.edit', 'resources.cliente.view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    private function pastaDe(Tenant $tenant, ClientePF $cliente, string $valor, string $acao = 'COBRANÇA'): Pasta
    {
        $pasta = $this->criarPasta($tenant, 'MARIA DA SILVA');
        $pasta->setNomeAcao($acao);
        $pasta->setValorCausa($valor);
        $pasta->addCliente($cliente);
        $this->em()->flush();

        return $pasta;
    }

    /** @return array{User, User, Tenant, Pasta, Pasta, ClientePF} primário, Ana, escritório, A (visível), B (oculta para Ana), cliente */
    private function cenario(): array
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana = $this->criarColaborador($tenant, 'Ana Equipe', self::PERMISSOES);
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant, 'nomeCompleto' => 'Maria da Silva'])->_real();
        $a = $this->pastaDe($tenant, $cliente, '10000.00', 'INVENTÁRIO');
        $b = $this->pastaDe($tenant, $cliente, '90000.00');
        $this->trancar($b, $primario, [[$ana, NivelDeAcesso::Oculto]]);

        return [$primario, $ana, $tenant, $a, $b, $cliente];
    }

    /** @param array<string, mixed> $corpo @return array<string, mixed> */
    private function postJson(string $rota, array $corpo): array
    {
        $this->client->request('POST', $rota, $corpo, [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function mediaNaTela(Pasta $pasta): string
    {
        $crawler = $this->client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        return trim($crawler->filter('#financeiro-media-cpf')->text());
    }

    #[TestDox('1.07: quem está Oculto em B e envia o cliente e a ação de B não recebe o aviso de duplicada')]
    public function testDuplicadaNaoCitaPastaOculta(): void
    {
        [, $ana, $tenant] = $this->cenario();

        $this->entrar($ana, $tenant);
        $json = $this->postJson('/pasta/nova', ['nome_cliente' => 'MARIA DA SILVA', 'nome_acao' => 'COBRANÇA']);

        self::assertNotSame('duplicada', $json['status'] ?? null);
    }

    #[TestDox('1.07: o primário continua recebendo o aviso com a pasta B')]
    public function testPrimarioContinuaRecebendoODuplicado(): void
    {
        [$primario, , $tenant] = $this->cenario();

        $this->entrar($primario, $tenant);
        $json = $this->postJson('/pasta/nova', ['nome_cliente' => 'MARIA DA SILVA', 'nome_acao' => 'COBRANÇA']);

        self::assertSame('duplicada', $json['status']);
    }

    #[TestDox('1.07: a contagem do repositório não conta a pasta oculta passada a ele')]
    public function testContagemSemAPastaOculta(): void
    {
        [, , $tenant, , $b] = $this->cenario();
        $repositorio = static::getContainer()->get(PastaRepository::class);

        self::assertSame(1, $repositorio->contarSemelhantesPorClienteEAcao($tenant, 'MARIA DA SILVA', 'COBRANÇA'));
        self::assertSame(0, $repositorio->contarSemelhantesPorClienteEAcao($tenant, 'MARIA DA SILVA', 'COBRANÇA', [(int) $b->getId()]));
        self::assertSame([], $repositorio->findSemelhantesPorClienteEAcao($tenant, 'MARIA DA SILVA', 'COBRANÇA', 5, [(int) $b->getId()]));
    }

    #[TestDox('1.11: a média por cliente exibida em A não soma B, oculta para Ana; o primário vê a média das duas')]
    public function testMediaNaTelaSemAPastaOculta(): void
    {
        [$primario, $ana, $tenant, $a] = $this->cenario();

        $this->entrar($ana, $tenant);
        self::assertSame('R$ 10.000,00', $this->mediaNaTela($a));

        $this->entrar($primario, $tenant);
        self::assertSame('R$ 50.000,00', $this->mediaNaTela($a));
    }

    #[TestDox('1.11: valor da causa e cliente principal devolvem a mesma média filtrada')]
    public function testMediaNasRespostasSemAPastaOculta(): void
    {
        [, $ana, $tenant, $a, , $cliente] = $this->cenario();
        $idA = $a->getId();

        $this->entrar($ana, $tenant);
        $valor = $this->postJson("/pasta/{$idA}/valor-causa", ['valor_causa' => '10000,00', '_token' => 'TOKEN_pasta_valor_causa_' . $idA]);
        self::assertSame('R$ 10.000,00', $valor['mediaFormatada']);

        $principal = $this->postJson("/pasta/{$idA}/cliente/{$cliente->getId()}/principal", ['_token' => "TOKEN_pasta_cliente_principal_{$idA}_{$cliente->getId()}"]);
        self::assertSame('R$ 10.000,00', $principal['mediaFormatada']);
    }

    #[TestDox('2.04: com a aba Financeiro oculta, principal, vincular e desvincular respondem sem a média')]
    public function testFinanceiroOcultoTiraAMediaDasRotasDeCliente(): void
    {
        [$primario, $ana, $tenant, $a, , $cliente] = $this->cenario();
        $idA = (int) $a->getId();
        $outro = ClientePFFactory::createOne(['tenant' => $tenant, 'nomeCompleto' => 'Outro Cliente'])->_real();
        $this->em()->persist(AbaOcultaDaPasta::paraAlguns($tenant, $a, AbaDaPasta::Financeiro, [(int) $ana->getId()], $primario, new \DateTimeImmutable()));
        $this->em()->flush();

        $this->entrar($ana, $tenant);
        $respostas = [
            'principal'    => $this->postJson("/pasta/{$idA}/cliente/{$cliente->getId()}/principal", ['_token' => "TOKEN_pasta_cliente_principal_{$idA}_{$cliente->getId()}"]),
            'vincular'     => $this->postJson("/pasta/{$idA}/cliente/vincular", ['cliente_id' => $outro->getId(), '_token' => 'TOKEN_pasta_cliente_vincular_' . $idA])['principal'] ?? null,
            // Tirar o principal promove o outro e devolve o novo principal (com a média, se visível).
            'desvincular'  => $this->postJson("/pasta/{$idA}/cliente/{$cliente->getId()}/desvincular", ['_token' => "TOKEN_pasta_cliente_desvincular_{$idA}_{$cliente->getId()}"])['principal'] ?? null,
        ];

        foreach ($respostas as $rota => $resposta) {
            self::assertIsArray($resposta, $rota);
            self::assertNotNull($resposta['clienteId'], $rota);
            self::assertNull($resposta['mediaFormatada'], $rota);
            self::assertNull($resposta['mediaRotulo'], $rota);
        }
    }

    #[TestDox('2.04: com a aba Financeiro visível, a resposta do principal continua trazendo a média')]
    public function testFinanceiroVisivelMantemAMedia(): void
    {
        [$primario, , $tenant, $a, , $cliente] = $this->cenario();
        $idA = $a->getId();

        $this->entrar($primario, $tenant);
        $principal = $this->postJson("/pasta/{$idA}/cliente/{$cliente->getId()}/principal", ['_token' => "TOKEN_pasta_cliente_principal_{$idA}_{$cliente->getId()}"]);

        self::assertSame('R$ 50.000,00', $principal['mediaFormatada']);
        self::assertNotNull($principal['mediaRotulo']);
    }
}
