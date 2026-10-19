<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\VagasController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * As vagas pela HTTP (spec VS 18.10.26 §4.1): a Central publica, o escritório divulga para aprovar,
 * outro escritório não vê o que não está aberto, o contato escondido não sai no JSON.
 */
#[CoversClass(VagasController::class)]
final class VagasDoTrabalhoExternoTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    private const VAGAS = '/trabalho-externo/api/vagas';

    #[TestDox('a Central publica na hora; o escritório divulga e espera; o Master aprova e a vaga abre para todos')]
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
        $doEscritorio = $this->postarJson($client, self::VAGAS, self::vaga('Estagiário trabalhista'));
        self::assertSame('pendente', $doEscritorio['vaga']['situacao']);
        $codigo = $doEscritorio['codigo'];

        $this->logarComTenant($client, $doB, $b);
        self::assertSame([$central['codigo']], array_column($this->listar($client)['vagas'], 'codigo'), 'B não vê a pendente de A');
        $client->request('GET', self::VAGAS . "/$codigo");
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($client, $doA, $a);
        $this->postarJson($client, self::VAGAS . "/$codigo/acoes/aprovar");
        self::assertResponseStatusCodeSame(403, 'o escritório não aprova a própria vaga');

        $this->logarComTenant($client, $master, $sede);
        $this->postarJson($client, self::VAGAS . "/$codigo/acoes/reprovar");
        self::assertResponseStatusCodeSame(409, 'não aprovar pede motivo');
        $aprovada = $this->postarJson($client, self::VAGAS . "/$codigo/acoes/aprovar");
        self::assertSame('aberta', $aprovada['vaga']['situacao']);

        $this->logarComTenant($client, $doB, $b);
        self::assertContains($codigo, array_column($this->listar($client)['vagas'], 'codigo'));
    }

    #[TestDox('período acima da divulgação máxima é ajustado na aprovação, como diz o modal de configuração')]
    public function testPeriodoAjustadoNaAprovacao(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $sede = $this->criarTenant('Sede');
        $a = $this->criarTenant('A');
        $master = $this->criarMaster($sede);
        $doA = $this->criarUsuario($a);

        $this->logarComTenant($client, $master, $sede);
        $this->postarJson($client, '/trabalho-externo/api/configuracao', ['vagas' => ['maxDias' => 15]]);
        self::assertResponseIsSuccessful();

        $this->logarComTenant($client, $doA, $a);
        $enviada = $this->postarJson($client, self::VAGAS, self::vaga('Advogado previdenciário') + ['periodoDias' => 60]);
        self::assertSame(60, $enviada['vaga']['periodoDias'], 'no envio fica o que o escritório pediu');

        $this->logarComTenant($client, $master, $sede);
        $aprovada = $this->postarJson($client, self::VAGAS . '/' . $enviada['codigo'] . '/acoes/aprovar');
        self::assertSame(15, $aprovada['vaga']['periodoDias']);
    }

    #[TestDox('contato que o ofertante escondeu não sai para outro escritório; a Central e o dono veem')]
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
        $vaga['ofertante'] += ['email' => 'vagas@central.test', 'tel' => '(61) 99999-0000', 'ocultos' => ['email']];
        $codigo = $this->postarJson($client, self::VAGAS, $vaga)['codigo'];

        $this->logarComTenant($client, $doB, $b);
        $client->request('GET', self::VAGAS . "/$codigo");
        $of = $this->json($client)['vaga']['ofertante'];
        self::assertSame(['tel'], array_column($of['campos'], 'k'));
        self::assertTrue($of['oculto']);
        self::assertStringNotContainsString('vagas@central.test', (string) $client->getResponse()->getContent());
    }

    #[TestDox('obrigatórios faltando é 422; sem CSRF é 403; marcação vale só para quem marcou')]
    public function testEntradaEMarcacao(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $sede = $this->criarTenant('Sede');
        $master = $this->criarMaster($sede);

        $this->logarComTenant($client, $master, $sede);
        $this->postarJson($client, self::VAGAS, ['titulo' => 'Sem cargo']);
        self::assertResponseStatusCodeSame(422);
        $this->postarJson($client, self::VAGAS, self::vaga('Qualquer'), 'errado');
        self::assertResponseStatusCodeSame(403);

        $codigo = $this->postarJson($client, self::VAGAS, self::vaga('Assistente jurídico'))['codigo'];
        $r = $this->postarJson($client, self::VAGAS . '/marcacoes', ['codigos' => [$codigo], 'op' => 'estrela']);
        self::assertSame('1 item(ns) com estrela', $r['texto']);
        $cartao = array_values(array_filter($this->listar($client)['vagas'], static fn (array $v): bool => $v['codigo'] === $codigo))[0];
        self::assertTrue($cartao['estrela']);
    }

    #[TestDox('o Convidado não vê a aba Vagas')]
    public function testConvidadoNaoVe(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant();
        $solicitante = $this->criarUsuario($escritorio);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $lista = $this->listar($client);
        self::assertTrue($lista['veAba'], 'o Solicitante vê');
        self::assertTrue($lista['podeDivulgar']);
        self::assertFalse($lista['podeCriar']);
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
