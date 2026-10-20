<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Controller\EncaminhamentoController;
use App\Central\Enum\TipoDeItemEncaminhado;
use App\Central\Exception\ItemEncaminhadoNaoEncontradoException;
use App\Central\UseCase\ObterItemDoEncaminhamentoUseCase;
use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * O cartão da tela "Despachar via Chat I.A" (BC:6224, `docs/specs/encaminhar-via-chat-ia.md` §4.1) pela rota
 * `GET /central/encaminhar/item`: o servidor relê o item com as guardas do envio; de fora, que eu não vejo ou que não
 * existe é 404. E a janela da Central traz as URLs e a tela de despacho que o `central.js` usa.
 */
#[CoversClass(EncaminhamentoController::class)]
#[CoversClass(ObterItemDoEncaminhamentoUseCase::class)]
final class ItemDoEncaminhamentoTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    use CentralHttpTrait;

    private const VE_PASTA = ['resources.pasta.view'];

    #[TestDox('Registro de Dados e a própria pasta: 200 com {titulo, resumo} relidos pelo servidor, sem nada gravado')]
    public function testCartaoDoItem(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $pasta = $this->criarPasta($tenant);
        $registro = $this->criarAnotacao($tenant, $pasta, $ana, '<p>Ligar para o <b>cliente</b> &amp; @[Zé](user:3)</p>');
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('GET', '/central/encaminhar/item?pasta=' . $pasta->getId() . '&tipo=registro_dados&item=' . $registro->getId());
        self::assertResponseIsSuccessful();
        $cartao = $this->json($client);
        self::assertSame(['titulo', 'resumo'], array_keys($cartao));
        self::assertStringStartsWith('Registro de Usuário ana · ', $cartao['titulo']);
        self::assertSame('Ligar para o cliente & @Zé', $cartao['resumo']);

        $client->request('GET', '/central/encaminhar/item?pasta=' . $pasta->getId() . '&tipo=pasta');
        self::assertResponseIsSuccessful();
        self::assertSame(['titulo' => 'Pasta ' . $pasta->getNup(), 'resumo' => ''], $this->json($client));

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'despacho' AND tenant_id = ?",
            [$tenant->getId()],
        ), 'ver o cartão não despacha nem audita');
    }

    #[TestDox('Item de outra pasta, inexistente ou sem id; pasta de outro escritório, que eu não vejo ou excluída: 404')]
    public function testDeForaEh404(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $semVer = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta = $this->criarPasta($tenant);
        $irma = $this->criarPasta($tenant);
        $daIrma = $this->criarAnotacao($tenant, $irma, $ana, 'da outra pasta');
        $deFora = $this->criarPasta($outro);

        $this->logarComTenant($client, $ana, $tenant);
        foreach ([
            'registro de outra pasta' => '?pasta=' . $pasta->getId() . '&tipo=registro_dados&item=' . $daIrma->getId(),
            'registro inexistente' => '?pasta=' . $pasta->getId() . '&tipo=registro_dados&item=2147483647',
            'registro sem id' => '?pasta=' . $pasta->getId() . '&tipo=registro_dados',
            'id torto' => '?pasta=' . $pasta->getId() . '&tipo=meta&item=abc',
            'pasta de outro escritório' => '?pasta=' . $deFora->getId() . '&tipo=pasta',
            'pasta ausente' => '?tipo=pasta',
        ] as $caso => $query) {
            $client->request('GET', '/central/encaminhar/item' . $query);
            self::assertResponseStatusCodeSame(404, $caso);
            self::assertArrayHasKey('erro', $this->json($client), $caso);
        }

        $this->em()->find(Pasta::class, $irma->getId())?->marcarExcluida($this->em()->find(User::class, $ana->getId()), new \DateTimeImmutable());
        $this->em()->flush();
        $client->request('GET', '/central/encaminhar/item?pasta=' . $irma->getId() . '&tipo=pasta');
        self::assertResponseStatusCodeSame(404, 'pasta excluída (lápide)');

        $this->logarComTenant($client, $semVer, $tenant);
        $client->request('GET', '/central/encaminhar/item?pasta=' . $pasta->getId() . '&tipo=pasta');
        self::assertResponseStatusCodeSame(404, 'quem não vê a pasta não vê o cartão');
    }

    #[TestDox('Tipo desconhecido: 422 com o erro, como no envio')]
    public function testTipoDesconhecido(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('GET', '/central/encaminhar/item?pasta=' . $pasta->getId() . '&tipo=planilha&item=1');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Tipo de item desconhecido.', $this->json($client)['erro']);
    }

    #[TestDox('Sem o TenantFilter, nem o administrador (papel de sistema, vê toda pasta) lê o cartão da pasta de outro escritório')]
    public function testIsolamentoSemFiltro(): void
    {
        static::createClient();
        [$admin, $escritorioA] = $this->criarAdmin();
        [$adminB, $escritorioB] = $this->criarAdmin();
        $pastaB = $this->criarPasta($escritorioB);
        $registroB = $this->criarAnotacao($escritorioB, $pastaB, $adminB, 'do B');
        if ($this->em()->getFilters()->isEnabled('tenant')) {
            $this->em()->getFilters()->disable('tenant');
        }
        $useCase = static::getContainer()->get(ObterItemDoEncaminhamentoUseCase::class);

        self::assertSame('do B', $useCase->executar($adminB, $escritorioB, (int) $pastaB->getId(), TipoDeItemEncaminhado::RegistroDados, (int) $registroB->getId())->resumo, 'controle');
        $this->expectException(ItemEncaminhadoNaoEncontradoException::class);
        $useCase->executar($admin, $escritorioA, (int) $pastaB->getId(), TipoDeItemEncaminhado::RegistroDados, (int) $registroB->getId());
    }

    #[TestDox('A janela da Central traz as URLs do encaminhar e a tela de despacho, vazia e oculta, ao lado das outras telas')]
    public function testJanelaTrazATelaDeDespacho(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('GET', '/notificacoes');
        self::assertResponseIsSuccessful();
        $raiz = '[data-central][data-modo="flutuante"]'
            . '[data-url-encaminhar-item="/central/encaminhar/item"]'
            . '[data-url-encaminhar-destinos="/central/encaminhar/destinos"]'
            . '[data-url-encaminhar="/central/encaminhar"]'
            . '[data-url-pasta-base="/pasta/"]';
        self::assertSelectorExists($raiz);
        self::assertSelectorExists($raiz . ' > [data-central-tela-info] + [data-central-tela-despacho].bj-central-despacho[hidden]');
        self::assertSame('', trim($client->getCrawler()->filter('[data-central-tela-despacho]')->text('')), 'montada pelo central.js');
    }
}
