<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\VagasController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * As vagas pela HTTP, com as regras do desenho 06 (spec VS 18.10.26 §4.1): a Central publica e age; o
 * externo divulga para aprovar e só edita a dele; outro escritório não vê o que não está aberto; só o
 * que o ofertante deixou visível sai no detalhe.
 */
#[CoversClass(VagasController::class)]
final class VagasDoTrabalhoExternoTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    private const VAGAS = '/trabalho-externo/api/vagas';

    #[TestDox('fluxo: a Central publica; o escritório divulga e espera; outro escritório não vê a pendente; o dono não aprova nem pausa; o Master aprova')]
    public function testFluxoDeDivulgacao(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $sede = $this->criarTenant('Sede');
        $a = $this->criarTenant('A');
        $b = $this->criarTenant('B');
        $master = $this->criarMaster($sede);
        $doA = $this->criarUsuario($a);
        $doB = $this->criarUsuario($b);

        $this->logarComTenant($client, $master, $sede);
        $central = $this->postarJson($client, self::VAGAS, self::vaga('Advogado cível'));
        self::assertSame('aberta', $central['vaga']['situacao']);

        $this->logarComTenant($client, $doA, $a);
        $pedido = $this->postarJson($client, self::VAGAS, self::vaga('Estagiário trabalhista') + ['oferta' => true]);
        self::assertSame('pendente', $pedido['vaga']['situacao']);
        self::assertSame('Aguardando aprovação do BlueJus para ser publicada', $pedido['vaga']['pendTxt']);
        $codigo = $pedido['codigo'];

        $this->logarComTenant($client, $doB, $b);
        self::assertSame([$central['codigo']], array_column($this->listar($client)['vagas'], 'codigo'));
        $client->request('GET', self::VAGAS . "/$codigo");
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($client, $doA, $a);
        $this->postarJson($client, self::VAGAS . "/$codigo/acoes/aprovar");
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($client, $master, $sede);
        $aprovada = $this->postarJson($client, self::VAGAS . "/$codigo/acoes/aprovar");
        self::assertSame('aberta', $aprovada['vaga']['situacao']);
        self::assertStringStartsWith('Aprovada e publicada por', $aprovada['vaga']['historico'][0]['txt']);

        $this->logarComTenant($client, $doA, $a);
        $this->postarJson($client, self::VAGAS . "/$codigo/acoes/pausar");
        self::assertResponseStatusCodeSame(403, 'o dono não pausa');
        $client->request('GET', self::VAGAS . "/$codigo");
        self::assertSame([], $this->json($client)['vaga']['historico'], 'o histórico é só do interno');
    }

    #[TestDox('o escritório não define oferta com tempo; a aprovação aplica o tempo padrão (em minutos) da configuração')]
    public function testOfertaSoDoMaster(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $sede = $this->criarTenant('Sede');
        $a = $this->criarTenant('A');
        $master = $this->criarMaster($sede);
        $doA = $this->criarUsuario($a);

        $this->logarComTenant($client, $master, $sede);
        $this->postarJson($client, '/trabalho-externo/api/configuracao', ['vagas' => ['dur' => 0, 'maxDias' => 15]]);
        self::assertResponseIsSuccessful();

        $this->logarComTenant($client, $doA, $a);
        $pedido = $this->postarJson($client, self::VAGAS, self::vaga('Correspondente') + ['oferta' => true, 'periodoDias' => 60]);
        self::assertSame(15, $pedido['vaga']['formulario']['periodoDias'], 'teto de dias já no envio');
        self::assertFalse($pedido['vaga']['formulario']['oferta']);

        $this->logarComTenant($client, $master, $sede);
        $aprovada = $this->postarJson($client, self::VAGAS . '/' . $pedido['codigo'] . '/acoes/aprovar');
        self::assertFalse($aprovada['vaga']['vivo'], 'dur 0 = vaga comum');
    }

    #[TestDox('o que o ofertante esconde não sai para ninguém no detalhe; e-mail e telefone nascem ocultos')]
    public function testContatoOculto(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $sede = $this->criarTenant('Sede');
        $b = $this->criarTenant('B');
        $master = $this->criarMaster($sede);
        $doB = $this->criarUsuario($b);

        $this->logarComTenant($client, $master, $sede);
        $vaga = self::vaga('Correspondente em Campinas');
        $vaga['ofertante'] += ['email' => 'vagas@central.test', 'tel' => '(61) 99999-0000', 'resp' => 'Ana'];
        $codigo = $this->postarJson($client, self::VAGAS, $vaga)['codigo'];

        $this->logarComTenant($client, $doB, $b);
        $client->request('GET', self::VAGAS . "/$codigo");
        $vista = $this->json($client)['vaga'];
        self::assertSame(['bi-person'], array_column($vista['ofertante']['info'], 'ic'));
        self::assertTrue($vista['ofertante']['oculto']);
        self::assertStringNotContainsString('vagas@central.test', (string) $client->getResponse()->getContent());
        self::assertNull($vista['formulario'], 'quem não edita não recebe os dados inteiros');
    }

    #[TestDox('editar: o dono corrige a reprovada e ela volta à aprovação; a Central exclui; sem CSRF é 403')]
    public function testEditarEExcluir(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $sede = $this->criarTenant('Sede');
        $a = $this->criarTenant('A');
        $master = $this->criarMaster($sede);
        $doA = $this->criarUsuario($a);

        $this->logarComTenant($client, $doA, $a);
        $codigo = $this->postarJson($client, self::VAGAS, self::vaga('Assistente jurídico'))['codigo'];
        $this->logarComTenant($client, $master, $sede);
        $this->postarJson($client, self::VAGAS . "/$codigo/acoes/reprovar", ['motivo' => 'Falta o local']);

        $this->logarComTenant($client, $doA, $a);
        $corrigida = $this->postarJson($client, self::VAGAS . "/$codigo/editar", self::vaga('Assistente jurídico em Brasília'));
        self::assertSame('pendente', $corrigida['vaga']['situacao']);
        $this->postarJson($client, self::VAGAS . "/$codigo/excluir");
        self::assertResponseStatusCodeSame(403, 'o dono não exclui');

        $this->logarComTenant($client, $master, $sede);
        $this->postarJson($client, self::VAGAS . "/$codigo/excluir", [], 'errado');
        self::assertResponseStatusCodeSame(403);
        $this->postarJson($client, self::VAGAS . "/$codigo/excluir");
        self::assertResponseIsSuccessful();
        $client->request('GET', self::VAGAS . "/$codigo");
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('"NOVA" é desde a última visita; marcar estrela não conta como "vista"')]
    public function testNovaEVista(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $sede = $this->criarTenant('Sede');
        $b = $this->criarTenant('B');
        $master = $this->criarMaster($sede);
        $doB = $this->criarUsuario($b);

        $this->logarComTenant($client, $doB, $b);
        $this->postarJson($client, self::VAGAS . '/visita');
        self::assertResponseIsSuccessful();
        sleep(1);

        $this->logarComTenant($client, $master, $sede);
        $codigo = $this->postarJson($client, self::VAGAS, self::vaga('Controladoria'))['codigo'];

        $this->logarComTenant($client, $doB, $b);
        $this->postarJson($client, self::VAGAS . '/marcacoes', ['codigos' => [$codigo], 'op' => 'estrela']);
        $cartao = $this->cartao($client, $codigo);
        self::assertTrue($cartao['nova']);
        self::assertTrue($cartao['estrela']);
        self::assertFalse($cartao['vista']);
    }

    #[TestDox('o Solicitante vê a aba e divulga, mas não publica nem é interno')]
    public function testQuemVe(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant();
        $solicitante = $this->criarUsuario($escritorio);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $lista = $this->listar($client);
        self::assertTrue($lista['veAba']);
        self::assertTrue($lista['podeDivulgar']);
        self::assertFalse($lista['podeCriar']);
        self::assertFalse($lista['interno']);
    }

    /** @return array<string, mixed> */
    private function cartao(KernelBrowser $client, string $codigo): array
    {
        return array_values(array_filter($this->listar($client)['vagas'], static fn (array $v): bool => $v['codigo'] === $codigo))[0];
    }

    /** @return array<string, mixed> */
    private function listar(KernelBrowser $client): array
    {
        $client->request('GET', self::VAGAS);

        return $this->json($client)['vagas'];
    }

    /** @return array<string, mixed> */
    private static function vaga(string $titulo): array
    {
        return ['titulo' => $titulo, 'cargo' => 'Advogado(a)', 'contratacao' => 'CLT', 'cidade' => 'Brasília', 'uf' => 'DF', 'areas' => ['Cível'], 'ofertante' => ['nome' => 'Escritório que oferece']];
    }
}
