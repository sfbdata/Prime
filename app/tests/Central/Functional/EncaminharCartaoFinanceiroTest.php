<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Service\ItensEncaminhaveisDaPasta;
use App\Central\UseCase\EncaminharItemDaPastaUseCase;
use App\Central\UseCase\ObterItemDoEncaminhamentoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * O cartão do sistema de pró-bono ou de contrato no Registro de Dados é dado financeiro
 * (`RegistroDoSistemaNaPasta::DO_FINANCEIRO`). Encaminhado como `registro_dados`, ele segue a regra do registro do
 * Financeiro: quem envia, quem recebe e quem lê depois precisam ver o financeiro da pasta. O ↪ aparece no cartão para
 * quem vê o Financeiro, então o furo não exigia POST forjado: bastava mandar a um colega que não vê.
 */
#[CoversClass(ItensEncaminhaveisDaPasta::class)]
#[CoversClass(EncaminharItemDaPastaUseCase::class)]
#[CoversClass(ObterItemDoEncaminhamentoUseCase::class)]
final class EncaminharCartaoFinanceiroTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    use CentralHttpTrait;

    private const VE_PASTA = ['resources.pasta.view'];
    private const SEGREDO = 'Pró-bono: pasta marcada como pró-bono. MARCA-FINANCEIRA';

    /** @var list<int> ids de quem não vê o financeiro */
    private array $semFinanceiro = [];

    #[TestDox('Cartão de pró-bono para colega sem o financeiro: 422 e nada gravado; para quem vê: 201, gravado como registro_financeiro')]
    public function testDestinatarioSemFinanceiro(): void
    {
        $client = $this->cliente();
        [$tenant, $pasta, $ana, $bruno, $carla] = $this->cenario();
        $cartao = $this->cartaoDoSistema($tenant, $pasta, 'probono', $ana);
        $this->semFinanceiro = [(int) $bruno->getId()];

        $this->logarComTenant($client, $ana, $tenant);
        $this->encaminhar($client, $pasta, 'registro_dados', $cartao->getId(), $bruno);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Este colega não vê esta pasta.', $this->json($client)['erro']);
        self::assertSame(0, $this->mensagensDaCentral($tenant));

        $this->encaminhar($client, $pasta, 'registro_dados', $cartao->getId(), $carla);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('registro_financeiro', $this->em()->getConnection()->fetchOne(
            'SELECT despacho_tipo FROM central_mensagem WHERE tenant_id = ?',
            [$tenant->getId()],
        ), 'a referência leva a regra do Financeiro para a leitura');
    }

    #[TestDox('Quem recebeu e perdeu o financeiro depois lê "Item de pasta sem acesso", sem o texto do cartão')]
    public function testLeituraDepoisDePerderOFinanceiro(): void
    {
        $client = $this->cliente();
        [$tenant, $pasta, $ana, , $carla] = $this->cenario();
        $cartao = $this->cartaoDoSistema($tenant, $pasta, 'contrato', $ana);

        $this->logarComTenant($client, $ana, $tenant);
        $this->encaminhar($client, $pasta, 'registro_dados', $cartao->getId(), $carla);
        self::assertResponseStatusCodeSame(201);
        $conversa = (int) $this->json($client)['conversaId'];

        $this->semFinanceiro = [(int) $carla->getId()];
        $this->logarComTenant($client, $carla, $tenant);
        $client->request('GET', "/central/conversas/$conversa/mensagens");
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('MARCA-FINANCEIRA', (string) $client->getResponse()->getContent());
        self::assertTrue($this->json($client)['mensagens'][0]['ocultoPorPermissao']);
    }

    #[TestDox('POST forjado de quem não vê o financeiro: 404 no envio e na prévia, para o cartão do Registro e para o registro do Financeiro')]
    public function testRemetenteSemFinanceiro(): void
    {
        $client = $this->cliente();
        [$tenant, $pasta, $ana, , $carla] = $this->cenario();
        $cartao = $this->cartaoDoSistema($tenant, $pasta, 'probono', $ana);
        $registroFinanceiro = (new PastaObservacaoFinanceira())->setTenant($tenant)->setPasta($pasta)->setAutor($carla)->setConteudo(self::SEGREDO);
        $this->em()->persist($registroFinanceiro);
        $this->em()->flush();
        $this->semFinanceiro = [(int) $ana->getId()];

        $this->logarComTenant($client, $ana, $tenant);
        $this->encaminhar($client, $pasta, 'registro_dados', $cartao->getId(), $carla);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->mensagensDaCentral($tenant));

        foreach (['registro_dados' => $cartao->getId(), 'registro_financeiro' => $registroFinanceiro->getId()] as $tipo => $id) {
            $client->request('GET', '/central/encaminhar/item', ['pasta' => $pasta->getId(), 'tipo' => $tipo, 'item' => $id]);
            self::assertResponseStatusCodeSame(404, "prévia de $tipo sem o financeiro");
            self::assertStringNotContainsString('MARCA-FINANCEIRA', (string) $client->getResponse()->getContent());
        }
    }

    #[TestDox('Registro comum da aba Dados e cartão de fase: seguem a regra da pasta (o colega sem o financeiro recebe)')]
    public function testRegistroComumNaoMuda(): void
    {
        $client = $this->cliente();
        [$tenant, $pasta, $ana, $bruno] = $this->cenario();
        $comum = $this->criarAnotacao($tenant, $pasta, $ana, '<p>ligar para o cliente</p>');
        $fase = $this->cartaoDoSistema($tenant, $pasta, 'fase', $ana);
        $this->semFinanceiro = [(int) $bruno->getId()];

        $this->logarComTenant($client, $ana, $tenant);
        foreach ([$comum, $fase] as $registro) {
            $this->encaminhar($client, $pasta, 'registro_dados', $registro->getId(), $bruno);
            self::assertResponseStatusCodeSame(201);
        }
        $client->request('GET', '/central/encaminhar/item', ['pasta' => $pasta->getId(), 'tipo' => 'registro_dados', 'item' => $fase->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame(['registro_dados'], array_values(array_unique($this->em()->getConnection()->fetchFirstColumn(
            'SELECT despacho_tipo FROM central_mensagem WHERE tenant_id = ? AND despacho_tipo IS NOT NULL',
            [$tenant->getId()],
        ))));
    }

    /** @return array{Tenant, Pasta, User, User, User} */
    private function cenario(): array
    {
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'bruno');
        $carla = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'carla');
        $pasta = $this->criarPasta($tenant);

        // Hoje o financeiro tem a mesma regra da pasta; o dublê aperta para separar as duas perguntas.
        $financeiro = $this->createStub(VisibilidadeDoFinanceiroDaPasta::class);
        $financeiro->method('podeVer')->willReturnCallback(fn (User $u): bool => !\in_array((int) $u->getId(), $this->semFinanceiro, true));
        static::getContainer()->set(VisibilidadeDoFinanceiroDaPasta::class, $financeiro);

        return [$tenant, $pasta, $ana, $bruno, $carla];
    }

    private function cartaoDoSistema(Tenant $tenant, Pasta $pasta, string $sistema, User $autor): PastaMensagem
    {
        $cartao = (new PastaMensagem())->setTenant($tenant)->setPasta($pasta)->setAutor($autor)->setConteudo(self::SEGREDO)->setSistema($sistema);
        $this->em()->persist($cartao);
        $this->em()->flush();

        return $cartao;
    }

    private function encaminhar(KernelBrowser $client, Pasta $pasta, string $tipo, ?int $itemId, User $para): void
    {
        $this->post($client, '/central/encaminhar', ['pastaId' => $pasta->getId(), 'tipo' => $tipo, 'itemId' => $itemId, 'usuarioId' => $para->getId()]);
    }

    private function mensagensDaCentral(Tenant $tenant): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM central_mensagem WHERE tenant_id = ?', [$tenant->getId()]);
    }
}
