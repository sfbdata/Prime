<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Banco\Cora\ClienteCora;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pagamento\Enum\ResultadoDaReconsulta;
use App\Pagamento\UseCase\ConfirmarPagamentoPeloBancoUseCase;
use App\Tests\Contribua\Banco\CoraFalsa;
use App\Tests\Contribua\Banco\ParDeCertificado;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\DemandaExternaController;
use App\TrabalhoExterno\EventListener\ConfirmarDemandaPeloBancoListener;
use App\TrabalhoExterno\UseCase\ConfirmarRecebimentoDoPixUseCase;
use App\TrabalhoExterno\UseCase\PrepararPagamentoDaDemandaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Gateway M1 pela HTTP contra uma Cora falsa (spec `gateway-m1-trabalho-externo.md`): a aba pede o PIX dinâmico, o banco
 * confirma e a demanda vira Paga sozinha; valor menor deixa divergente sem pagar; o botão manual com a cobrança viva
 * respeita a trava. O Master da Central age na demanda de OUTRO escritório (o caso do filtro de escritório).
 * A Cora falsa paga sempre R$ 20,00.
 */
#[CoversClass(PrepararPagamentoDaDemandaUseCase::class)]
#[CoversClass(ConfirmarDemandaPeloBancoListener::class)]
#[CoversClass(ConfirmarRecebimentoDoPixUseCase::class)]
#[CoversClass(DemandaExternaController::class)]
final class AbaPagamentoDinamicoTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    private KernelBrowser $client;
    private CoraFalsa $cora;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->cora = new CoraFalsa();
        $cofre = new CofreDeCertificado(base64_encode(random_bytes(32)));
        static::getContainer()->set(CofreDeCertificado::class, $cofre);
        static::getContainer()->set(ClienteCora::class, new ClienteCora($this->cora->cliente(), new ArrayAdapter(), $cofre));
        $this->instalarCsrf($this->client);
    }

    #[TestDox('Com a chave "te" a aba pede o QR dinâmico; o banco confirma e a demanda vira Paga sozinha, uma vez só')]
    public function testBancoConfirmaADemanda(): void
    {
        [$codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita('20,00');
        $this->conectar($master, $central, true);

        $this->logarComTenant($this->client, $solicitante, $escritorio);
        $antes = $this->detalhe($codigo)['pagamento'];
        self::assertTrue($antes['preparar']);
        self::assertFalse($antes['dinamico']);

        $r = $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/cobranca");
        self::assertResponseIsSuccessful();
        self::assertNull($r['avisoPagamento']);
        $pg = $r['demanda']['pagamento'];
        self::assertTrue($pg['dinamico']);
        self::assertFalse($pg['preparar']);
        self::assertStringStartsWith('00020101021226830014br.gov.bcb.pix', $pg['cod']);
        self::assertSame($pg['cod'], $this->detalhe($codigo)['pagamento']['cod']);
        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/cobranca");
        self::assertSame(1, $this->contar("pagamento WHERE referencia = '$codigo-1'"), 'abrir de novo não cria outra cobrança');

        $idNoBanco = (string) $this->em()->getConnection()->fetchOne('SELECT id_externo FROM pagamento');
        $this->cora->status[$idNoBanco] = 'PAID';
        self::assertSame(ResultadoDaReconsulta::Confirmado, $this->reconsultar($idNoBanco));
        self::assertSame(ResultadoDaReconsulta::Ignorado, $this->reconsultar($idNoBanco), 'aviso repetido: já paga, nada muda');

        $d = $this->detalhe($codigo);
        self::assertSame('pago', $d['pagamento']['estado']);
        self::assertSame(1, $this->contar("trabalho_externo_historico WHERE para = 'pago'"));
        self::assertSame(1, $this->contar("trabalho_externo_mensagem WHERE texto LIKE '%confirmado via PIX%' AND nome_autor = 'Sistema (banco)'"));
        self::assertSame('paga', $this->em()->getConnection()->fetchOne('SELECT status FROM pagamento'));
    }

    #[TestDox('Valor menor no banco: o pagamento fica divergente e a demanda NÃO é paga')]
    public function testValorMenorNaoPaga(): void
    {
        [$codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita('30,00');
        $this->conectar($master, $central, true);
        $this->logarComTenant($this->client, $solicitante, $escritorio);
        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/cobranca");
        $idNoBanco = (string) $this->em()->getConnection()->fetchOne('SELECT id_externo FROM pagamento');
        $this->cora->status[$idNoBanco] = 'PAID';

        self::assertSame(ResultadoDaReconsulta::Divergente, $this->reconsultar($idNoBanco));

        self::assertSame('divergente', $this->em()->getConnection()->fetchOne('SELECT status FROM pagamento'));
        self::assertNotSame('pago', $this->detalhe($codigo)['pagamento']['estado']);
        self::assertSame(0, $this->contar("trabalho_externo_historico WHERE para = 'pago'"));

        // Nunca QR novo por cima do que o banco já recebeu: a aba mostra o estado e nada é emitido.
        $r = $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/cobranca");
        self::assertNull($r['avisoPagamento']);
        self::assertSame(1, $this->contar('pagamento'));
        $pg = $r['demanda']['pagamento'];
        self::assertStringContainsString('valor diferente', $pg['avisoDoBanco']);
        self::assertFalse($pg['mostraQr']);
        self::assertFalse($pg['preparar']);
    }

    #[TestDox('Cobrança paga pelo banco com a demanda ainda sem pago: o valor não pode mais ser ajustado (409) e nenhum QR novo nasce')]
    public function testAjusteComPagaRecusa(): void
    {
        [$codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita('20,00');
        $this->conectar($master, $central, true);
        $this->logarComTenant($this->client, $solicitante, $escritorio);
        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/cobranca");
        $this->em()->getConnection()->executeStatement("UPDATE pagamento SET status = 'paga'");

        $this->logarComTenant($this->client, $master, $central);
        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/valor", ['valor' => '35,00', 'motivo' => 'Acréscimo combinado com o cliente']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, $this->contar("trabalho_externo_historico WHERE para = 'Valor do pagamento alterado'"));
        self::assertSame('paga', $this->em()->getConnection()->fetchOne('SELECT status FROM pagamento'));

        $this->logarComTenant($this->client, $solicitante, $escritorio);
        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/cobranca");
        self::assertSame(1, $this->contar('pagamento'));
    }

    #[TestDox('O banco confirma entre a leitura e o cancelamento do Preparar: nenhum QR novo sai e a cobrança segue paga')]
    public function testPrepararNaoEmiteSeVirouPagaNoMeio(): void
    {
        $espiao = $this->espiarLeituras();

        [$codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita('20,00');
        $this->conectar($master, $central, true);
        $this->logarComTenant($this->client, $solicitante, $escritorio);
        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/cobranca");
        $conexao = $this->em()->getConnection();
        $conexao->executeStatement('UPDATE pagamento SET valor_centavos = 1500');

        $espiao->depoisDaPrimeiraLeitura = static fn () => $conexao->executeStatement("UPDATE pagamento SET status = 'paga'");
        $r = $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/cobranca");

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->contar('pagamento'), 'nenhuma cobrança nova');
        self::assertSame('paga', $conexao->fetchOne('SELECT status FROM pagamento'));
        self::assertNull($r['avisoPagamento']);
    }

    #[TestDox('Outra aba cria uma cobrança mais nova entre a leitura e o cancelamento do Preparar: a nova fica e nada mais é emitido')]
    public function testPrepararNaoCancelaNemEmiteSeOutraAbaCriouUmaNova(): void
    {
        $espiao = $this->espiarLeituras();
        [$codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita('20,00');
        $this->conectar($master, $central, true);
        $this->logarComTenant($this->client, $solicitante, $escritorio);
        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/cobranca");
        $conexao = $this->em()->getConnection();
        $conexao->executeStatement('UPDATE pagamento SET valor_centavos = 1500');

        $espiao->depoisDaPrimeiraLeitura = function () use ($codigo, $conexao): void {
            $conexao->executeStatement('UPDATE pagamento SET status = \'cancelada\'');
            static::getContainer()->get(\App\Pagamento\UseCase\EmitirCobrancaPixUseCase::class)->executar(new \App\Pagamento\DTO\EmitirCobrancaPixInput(
                (int) $conexao->fetchOne('SELECT tenant_id FROM pagamento'),
                \App\Pagamento\Enum\OrigemDoPagamento::TrabalhoExterno,
                (int) $conexao->fetchOne('SELECT origem_id FROM pagamento'),
                $codigo . '-2',
                1500,
                new \DateTimeImmutable('+24 hours'),
                'te',
                'Solicitante',
                '12345678000199',
            ));
        };
        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/cobranca");

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->contar('pagamento'), 'só a criada pela outra aba');
        self::assertSame('aguardando', $conexao->fetchOne("SELECT status FROM pagamento WHERE referencia = '$codigo-2'"));
    }

    #[TestDox('Cobrança aguardando sem QR (banco fora do ar): o botão manual a cancela sem exigir motivo')]
    public function testManualCancelaCobrancaSemQr(): void
    {
        [$codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita('20,00');
        $this->conectar($master, $central, true);
        $this->cora->statusDaCriacao = 500;
        $this->logarComTenant($this->client, $solicitante, $escritorio);
        $r = $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/cobranca");
        self::assertSame(PrepararPagamentoDaDemandaUseCase::AVISO_INDISPONIVEL, $r['avisoPagamento']);
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT br_code FROM pagamento'));

        $this->logarComTenant($this->client, $master, $central);
        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/confirmacao");
        self::assertResponseIsSuccessful();
        self::assertSame('cancelada', $this->em()->getConnection()->fetchOne('SELECT status FROM pagamento'));
    }

    #[TestDox('Sem a chave "te" a aba segue no PIX estático e nada é criado; CNPJ do escritório ausente avisa e não cria')]
    public function testSemChaveESemCnpj(): void
    {
        [$codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita('20,00');
        $this->conectar($master, $central, false);
        $this->logarComTenant($this->client, $solicitante, $escritorio);
        $pg = $this->detalhe($codigo)['pagamento'];
        self::assertFalse($pg['preparar']);
        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/cobranca");
        self::assertSame(0, $this->contar('pagamento'));

        $this->logarComTenant($this->client, $master, $central);
        $this->conectar($master, $central, true);
        $this->em()->getConnection()->executeStatement('UPDATE tenant SET cnpj = NULL WHERE id = ' . (int) $escritorio->getId());
        $this->logarComTenant($this->client, $solicitante, $escritorio);
        $r = $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/cobranca");
        self::assertStringContainsString('Cadastre o CNPJ do escritório', (string) $r['avisoPagamento']);
        self::assertSame(0, $this->contar('pagamento'));
    }

    #[TestDox('Botão manual com a cobrança viva: exige motivo (409 sem ele); com motivo paga e cancela a cobrança; PIX tardio vira divergente')]
    public function testManualComCobrancaViva(): void
    {
        [$codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita('20,00');
        $this->conectar($master, $central, true);
        $this->logarComTenant($this->client, $solicitante, $escritorio);
        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/cobranca");
        $idNoBanco = (string) $this->em()->getConnection()->fetchOne('SELECT id_externo FROM pagamento');

        $this->logarComTenant($this->client, $master, $central);
        self::assertTrue($this->detalhe($codigo)['pagamento']['confirmaComMotivo']);
        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/confirmacao");
        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, $this->contar("trabalho_externo_historico WHERE para = 'pago'"));

        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/confirmacao", ['motivo' => 'Cliente pagou por transferência']);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->contar("trabalho_externo_historico WHERE para = 'pago'"));
        self::assertSame('cancelada', $this->em()->getConnection()->fetchOne('SELECT status FROM pagamento'));

        $this->cora->status[$idNoBanco] = 'PAID';
        self::assertSame(ResultadoDaReconsulta::Divergente, $this->reconsultar($idNoBanco));
        self::assertSame(1, $this->contar("trabalho_externo_historico WHERE para = 'pago'"), 'não paga duas vezes');
    }

    #[TestDox('Master ajusta o valor: a cobrança aguardando é cancelada com o motivo na trilha e a aba gera a tentativa 2 no valor novo')]
    public function testAjusteCancelaACobranca(): void
    {
        [$codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita('20,00');
        $this->conectar($master, $central, true);
        $this->logarComTenant($this->client, $solicitante, $escritorio);
        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/cobranca");

        $this->logarComTenant($this->client, $master, $central);
        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/valor", ['valor' => '35,00', 'motivo' => 'Acréscimo combinado com o cliente']);
        self::assertResponseIsSuccessful();
        self::assertSame('cancelada', $this->em()->getConnection()->fetchOne('SELECT status FROM pagamento'));
        self::assertStringContainsString('de R$ 20,00 para R$ 35,00', (string) $this->em()->getConnection()->fetchOne("SELECT resumo FROM pagamento_evento WHERE tipo = 'cancelado'"));

        $this->logarComTenant($this->client, $solicitante, $escritorio);
        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/pagamento/cobranca");
        self::assertSame([3500, "$codigo-2"], [(int) $this->em()->getConnection()->fetchOne('SELECT valor_centavos FROM pagamento ORDER BY id DESC LIMIT 1'), $this->em()->getConnection()->fetchOne('SELECT referencia FROM pagamento ORDER BY id DESC LIMIT 1')]);
    }

    private function reconsultar(string $idNoBanco): ResultadoDaReconsulta
    {
        $this->em()->clear();
        // O aviso do banco chega sem escritório no contexto (worker/webhook): o filtro fica desligado.
        if ($this->em()->getFilters()->isEnabled('tenant')) {
            $this->em()->getFilters()->disable('tenant');
        }

        return static::getContainer()->get(ConfirmarPagamentoPeloBancoUseCase::class)->executar('cora', $idNoBanco);
    }

    /** @return array<string, mixed> */
    private function detalhe(string $codigo): array
    {
        $this->client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        self::assertResponseIsSuccessful();

        return $this->json($this->client)['demanda'];
    }

    /** @return array{0: string, 1: User, 2: Tenant, 3: User, 4: Tenant} */
    /** Um repositório que roda um gancho logo depois da primeira leitura do último pagamento: a corrida entre abas, em ordem. */
    private function espiarLeituras(): object
    {
        $registros = static::getContainer()->get('doctrine');
        $espiao = new class($registros) extends \App\Pagamento\Repository\PagamentoRepository {
            public ?\Closure $depoisDaPrimeiraLeitura = null;

            public function ultimoDaOrigem(int $tenantId, \App\Pagamento\Enum\OrigemDoPagamento $origem, int $origemId): ?\App\Pagamento\Entity\Pagamento
            {
                $lido = parent::ultimoDaOrigem($tenantId, $origem, $origemId);
                if ($this->depoisDaPrimeiraLeitura !== null) {
                    $gancho = $this->depoisDaPrimeiraLeitura;
                    $this->depoisDaPrimeiraLeitura = null;
                    $gancho();
                }

                return $lido;
            }
        };
        static::getContainer()->set(\App\Pagamento\Repository\PagamentoRepository::class, $espiao);

        return $espiao;
    }

    private function demandaAceita(string $valor): array
    {
        $escritorio = $this->criarTenant('Solicitante');
        $escritorio->setCnpj('12345678000199');
        $this->em()->flush();
        $central = $this->criarTenant('Central');
        $solicitante = $this->criarUsuario($escritorio, nome: 'Solicitante Teste');
        $master = $this->criarMaster($central);

        $this->logarComTenant($this->client, $solicitante, $escritorio);
        $codigo = $this->solicitar($this->client, self::solicitacaoValida())['codigo'];
        $this->logarComTenant($this->client, $master, $central);
        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/acoes/analise");
        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/propostas", ['valor' => $valor, 'pagamento' => 'Pix']);
        $this->logarComTenant($this->client, $solicitante, $escritorio);
        $this->postarJson($this->client, "/trabalho-externo/api/demandas/$codigo/acoes/aceitar");
        self::assertResponseIsSuccessful();
        $this->logarComTenant($this->client, $master, $central);

        return [$codigo, $master, $central, $solicitante, $escritorio];
    }

    private function conectar(User $master, Tenant $central, bool $te): void
    {
        $this->logarComTenant($this->client, $master, $central);
        $par = ParDeCertificado::novo();
        $dir = sys_get_temp_dir() . '/bj-cert-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/cert.pem', $par['certificado']);
        file_put_contents($dir . '/chave.key', $par['chave']);
        $this->client->request('POST', '/contribua/integracao-bancaria/certificado', ['_token' => 'TOKEN_contribua'], ['arquivos' => [
            new UploadedFile($dir . '/cert.pem', 'cert.pem', null, null, true),
            new UploadedFile($dir . '/chave.key', 'chave.key', null, null, true),
        ]]);
        $recursos = ['cob' => '1', 'conc' => '1', 'bol' => '0', 'rel' => '1', 'te' => $te ? '1' : '0'];
        $this->client->request('POST', '/contribua/integracao-bancaria/salvar', ['_token' => 'TOKEN_contribua', 'ambiente' => 'producao', 'clientId' => 'cliente-1', 'recursos' => $recursos], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['ok'], (string) $this->client->getResponse()->getContent());
        $this->client->request('POST', '/contribua/integracao-bancaria/testar', ['_token' => 'TOKEN_contribua'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['conectado']);
    }

    private function contar(string $tabelaEWhere): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $tabelaEWhere);
    }
}
