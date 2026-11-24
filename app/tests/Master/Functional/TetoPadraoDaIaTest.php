<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\UseCase\AlterarTetoPadraoDaIaUseCase;
use App\Master\Controller\IaDoMasterController;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Produto\UseCase\LigarFerramentaUseCase;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Produto\Functional\ComEscritoriosPorProduto;
use App\Tests\Produto\Functional\ComFerramentasNoPerfil;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * Teto padrão da I.A. ligada pelo dono, na aba "I.A." do Master (spec docs/specs/multiproduto-contratacao-e-voto.md,
 * §10, "PR 9, Master (functional)"): só o Master altera, a faixa é de 0 a US$ 100.000,00, a troca entra na trilha, a tela
 * de configurações não mostra nem apaga a chave, e o padrão novo vale só para quem o dono ligar depois (D14).
 */
#[CoversClass(IaDoMasterController::class)]
#[CoversClass(AlterarTetoPadraoDaIaUseCase::class)]
#[CoversClass(ConfiguracaoGlobal::class)]
#[CoversClass(ConfiguracaoGlobalRepository::class)]
final class TetoPadraoDaIaTest extends JusPrimeWebTestCase
{
    use Factories;
    use ComEscritoriosPorProduto;
    use ComFerramentasNoPerfil;

    private const TOKEN = 'TOKEN_' . IaDoMasterController::TOKEN;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFixo();
    }

    #[TestDox('só o Master altera: o dono do escritório leva 403 e nada é gravado')]
    public function testSoOMaster(): void
    {
        $tenant = $this->escritorio();
        $dono = $this->dono($tenant);
        $this->logarComTenant($this->client, $dono, $tenant);

        $this->postarTeto('7.50');

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->gravado());
    }

    #[TestDox('CSRF errado: 403 e nada é gravado')]
    public function testCsrfErrado(): void
    {
        $this->comoMaster();

        $this->postarTeto('7.50', 'errado');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Token CSRF inválido. Recarregue a página.', $this->respostaJson()['erro']);
        self::assertNull($this->gravado());
    }

    #[TestDox('o Master grava o teto: a resposta traz o estado novo, o banco guarda os centavos e a trilha mostra quem mexeu')]
    public function testGrava(): void
    {
        $master = $this->comoMaster();
        $this->client->request('GET', '/master/ia', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame('5.00', $this->respostaJson()['tetoPadrao'], 'sem ninguém ter mexido, o painel mostra a reserva de US$ 5,00');

        $this->postarTeto('7,50');

        self::assertResponseIsSuccessful();
        self::assertSame('7.50', $this->respostaJson()['tetoPadrao']);
        self::assertSame(750, $this->gravado());
        $trilha = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT changes FROM audit_log WHERE entity_class = ? AND actor_user_id = ? ORDER BY id DESC LIMIT 1',
            [ConfiguracaoGlobal::class, $master->getId()],
        );
        self::assertCount(1, $trilha);
        self::assertStringContainsString('ia.tetoPadraoCentavos', (string) $trilha[0]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function tetosForaDaFaixa(): iterable
    {
        yield 'negativo' => ['-1'];
        yield 'acima do máximo' => ['100000.01'];
        yield 'texto' => ['abc'];
        yield 'três casas' => ['1.234'];
        yield 'vazio' => [''];
        yield 'nulo' => [null];
        yield 'lista' => [[5]];
    }

    #[DataProvider('tetosForaDaFaixa')]
    #[TestDox('teto fora da faixa ou que não é valor em dólares: 422 e nada é gravado')]
    public function testForaDaFaixa(mixed $teto): void
    {
        $this->comoMaster();
        $this->postarTeto('5');
        self::assertSame(500, $this->gravado());

        $this->postarTeto($teto);

        self::assertResponseStatusCodeSame(422);
        self::assertNotEmpty($this->respostaJson()['erro']);
        self::assertSame(500, $this->gravado(), 'o valor anterior continua');
    }

    #[TestDox('os limites da faixa passam: US$ 0,00 e US$ 100.000,00')]
    public function testLimites(): void
    {
        $this->comoMaster();

        $this->postarTeto('0');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->gravado());

        $this->postarTeto('100000.00');
        self::assertResponseIsSuccessful();
        self::assertSame(ConfiguracaoDeInteligencia::ORCAMENTO_MAXIMO_CENTAVOS, $this->gravado());
    }

    #[TestDox('a tela de configurações não mostra a chave e salvar não a apaga')]
    public function testTelaDeConfiguracoesNaoMostraNemApaga(): void
    {
        $this->comoMaster();
        $this->postarTeto('7.50');

        $this->client->request('GET', '/master/configuracoes', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('tetoPadrao', (string) $this->client->getResponse()->getContent());
        $valores = $this->respostaJson()['valores'];

        $this->client->request('POST', '/master/configuracoes', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_configuracoes',
        ], content: (string) json_encode(['valores' => $valores]));
        self::assertResponseIsSuccessful();

        self::assertSame(750, $this->gravado(), 'salvar o catálogo preserva o teto padrão');
        $linha = static::getContainer()->get(ConfiguracaoGlobalRepository::class)->buscarUnica();
        self::assertNotNull($linha);
        self::assertArrayNotHasKey('ia.tetoPadraoCentavos', $linha->getValores());
        self::assertSame(750, $linha->getTetoPadraoDaIa());
    }

    #[TestDox('mudar o padrão não altera o orçamento já gravado de nenhum escritório')]
    public function testNaoMudaOrcamentoGravado(): void
    {
        $tenant = $this->escritorio();
        $configuracao = new ConfiguracaoDeInteligencia($tenant);
        $configuracao->definirCobranca(ModoDeCobrancaDaIa::Incluso, 900);
        $this->em()->persist($configuracao);
        $this->em()->flush();
        $this->comoMaster();

        $this->postarTeto('12.34');

        self::assertResponseIsSuccessful();
        $this->em()->clear();
        $depois = static::getContainer()->get(ConfiguracaoDeInteligenciaRepository::class)->findDoTenantParaOMaster($this->em()->getReference(Tenant::class, $tenant->getId()));
        self::assertSame(900, $depois?->getOrcamentoMensalCentavos());
    }

    #[TestDox('o próximo escritório em que o dono liga a I.A. recebe o padrão novo, no modo Incluso; sem ninguém mexer, vale a reserva')]
    public function testProximoLigadoRecebeOPadraoNovo(): void
    {
        $antes = $this->escritorio();
        $depois = $this->escritorio();
        $donoAntes = $this->dono($antes);
        $donoDepois = $this->dono($depois);

        $this->logarComTenant($this->client, $donoAntes, $antes);
        $this->postarFerramenta('ia', ['ligar' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame(LigarFerramentaUseCase::TETO_PADRAO_DE_RESERVA, $this->orcamentoDo($antes), 'sem padrão gravado vale a reserva');

        $this->comoMaster();
        $this->postarTeto('12.34');
        self::assertResponseIsSuccessful();

        $this->logarComTenant($this->client, $donoDepois, $depois);
        $this->postarFerramenta('ia', ['ligar' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame(1234, $this->orcamentoDo($depois), 'quem ligou depois recebe o padrão novo');
        self::assertSame(LigarFerramentaUseCase::TETO_PADRAO_DE_RESERVA, $this->orcamentoDo($antes), 'quem ligou antes fica como estava');
    }

    private function comoMaster(): User
    {
        $master = UserFactory::createOne(['fullName' => 'Master do teto', 'roles' => ['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']])->_real();
        $this->client->loginUser($master);
        $sessao = $this->client->getSession();
        $sessao->remove('current_tenant_id');
        $sessao->save();
        $this->marcarTermosAceitos($this->client);

        return $master;
    }

    private function postarTeto(mixed $teto, string $token = self::TOKEN): void
    {
        $this->client->request('POST', '/master/ia/teto-padrao', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: (string) json_encode(['tetoPadrao' => $teto]));
    }

    private function gravado(): ?int
    {
        return static::getContainer()->get(ConfiguracaoGlobalRepository::class)->tetoPadraoDaIaCentavos();
    }

    private function orcamentoDo(Tenant $tenant): ?int
    {
        $this->em()->clear();

        return static::getContainer()->get(ConfiguracaoDeInteligenciaRepository::class)
            ->findDoTenantParaOMaster($this->em()->getReference(Tenant::class, $tenant->getId()))?->getOrcamentoMensalCentavos();
    }
}
