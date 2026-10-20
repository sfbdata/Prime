<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Pasta\Controller\PastaAbaOcultaController;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Rotas do "ocultar aba" (spec pasta-ocultar-aba §3): contrato JSON e guarda (outro escritório 404,
 * sem VER 403, não primário 403 com o texto do desenho, aba inválida 404, CSRF 403).
 */
#[CoversClass(PastaAbaOcultaController::class)]
#[Group('pasta')]
final class PastaAbaOcultaControllerTest extends JusPrimeWebTestCase
{
    use CriaCenarioDeAbaOcultaTrait;

    private function regra(int $pastaId, string $aba): ?AbaOcultaDaPasta
    {
        $this->em()->clear();

        return $this->em()->getRepository(AbaOcultaDaPasta::class)->findOneBy(['pasta' => $pastaId, 'aba' => $aba]);
    }

    #[TestDox('Equipe: lista colaboradores (sem o primário nem o Master), com a marca de quem a regra já esconde')]
    public function testEquipe(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $primario = $this->criarPrimario($tenant);
        $ana = $this->criarColaborador($tenant, 'Ana Colaboradora');
        $beto = $this->criarColaborador($tenant, 'Beto Colaborador');
        [$pasta] = $this->criarPastaComPush($tenant);
        $this->ocultarAba($pasta, AbaDaPasta::Push, $primario, [$beto]);

        $this->logarComTenant($client, $primario, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/abas/push/equipe");

        self::assertResponseIsSuccessful();
        $corpo = $this->json($client);
        self::assertSame('push', $corpo['aba']);
        self::assertSame('Push Processual', $corpo['rotulo']);
        self::assertSame(2, $corpo['total']);
        self::assertSame(
            [[(int) $ana->getId(), 'Ana Colaboradora', false], [(int) $beto->getId(), 'Beto Colaborador', true]],
            array_map(static fn (array $p): array => [$p['id'], $p['nome'], $p['oculta']], $corpo['pessoas']),
        );
    }

    #[TestDox('Ocultar para todos, ocultar para alguns e mostrar: gravam no banco e respondem com o aviso do desenho')]
    public function testEscritas(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $primario = $this->criarPrimario($tenant);
        $ana = $this->criarColaborador($tenant);
        $this->criarColaborador($tenant, 'Beto Colaborador');
        $pasta = $this->criarPasta($tenant);
        $id = (int) $pasta->getId();
        $this->instalarCsrfPrevisivel();
        $token = 'TOKEN_pasta_aba_oculta_' . $id;
        $this->logarComTenant($client, $primario, $tenant);

        $client->request('POST', "/pasta/{$id}/abas/financeiro/ocultar-todos", ['_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['sucesso' => true, 'aba' => 'financeiro', 'rotulo' => 'Financeiro', 'paraTodos' => true, 'quantidade' => 0, 'mensagem' => 'Financeiro oculta para toda a equipe'],
            $this->json($client),
        );
        self::assertTrue($this->regra($id, 'financeiro')?->isParaTodos());

        $client->request('POST', "/pasta/{$id}/abas/financeiro/ocultar-para", ['_token' => $token, 'usuarios' => [(string) $ana->getId(), (string) $primario->getId()]]);
        self::assertResponseIsSuccessful();
        self::assertSame('Financeiro oculta para 1 pessoa(s)', $this->json($client)['mensagem']);
        self::assertSame([(int) $ana->getId()], $this->regra($id, 'financeiro')?->getUsuarios(), 'o primário enviado é ignorado');

        $client->request('POST', "/pasta/{$id}/abas/financeiro/mostrar", ['_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSame('Financeiro visível para todos', $this->json($client)['mensagem']);
        self::assertNull($this->regra($id, 'financeiro'));
    }

    #[TestDox('O Master também configura')]
    public function testMasterConfigura(): void
    {
        $client = $this->cliente();
        [$master, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->instalarCsrfPrevisivel();
        $this->logarComTenant($client, $master, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/abas/push/ocultar-todos", ['_token' => 'TOKEN_pasta_aba_oculta_' . $pasta->getId()]);

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->regra((int) $pasta->getId(), 'push'));
    }

    #[TestDox('Colaborador comum (vê e edita a pasta, mas não é primário): 403 com "Só o usuário primário pode ocultar abas", nada gravado')]
    public function testNaoPrimario(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $ana = $this->criarColaborador($tenant);
        $pasta = $this->criarPasta($tenant);
        $this->instalarCsrfPrevisivel();
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/abas/push/ocultar-todos", ['_token' => 'TOKEN_pasta_aba_oculta_' . $pasta->getId()]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Só o usuário primário pode ocultar abas'], $this->json($client));
        self::assertNull($this->regra((int) $pasta->getId(), 'push'));

        $client->request('GET', "/pasta/{$pasta->getId()}/abas/push/equipe");
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Pasta de outro escritório: 404, mesmo para o primário do escritório da sessão')]
    public function testOutroEscritorio(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $primario = $this->criarPrimario($tenant);
        $alheia = $this->criarPasta($this->criarTenant());
        $this->instalarCsrfPrevisivel();
        $this->logarComTenant($client, $primario, $tenant);

        $client->request('POST', "/pasta/{$alheia->getId()}/abas/push/ocultar-todos", ['_token' => 'TOKEN_pasta_aba_oculta_' . $alheia->getId()]);
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->regra((int) $alheia->getId(), 'push'));

        $client->request('GET', "/pasta/{$alheia->getId()}/abas/push/equipe");
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Sem permissão de VER a pasta: 403')]
    public function testSemVer(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $semNada = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($client, $semNada, $tenant);

        $client->request('GET', "/pasta/{$pasta->getId()}/abas/push/equipe");

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Sem permissão para ver esta pasta.'], $this->json($client));
    }

    #[TestDox('Aba que não existe: 404')]
    public function testAbaInvalida(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $primario = $this->criarPrimario($tenant);
        $pasta = $this->criarPasta($tenant);
        $this->instalarCsrfPrevisivel();
        $this->logarComTenant($client, $primario, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/abas/metas/ocultar-todos", ['_token' => 'TOKEN_pasta_aba_oculta_' . $pasta->getId()]);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', "/pasta/{$pasta->getId()}/abas/xyz/equipe");
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido: 403 e nada gravado')]
    public function testCsrf(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $primario = $this->criarPrimario($tenant);
        $pasta = $this->criarPasta($tenant);
        $this->instalarCsrfPrevisivel();
        $this->logarComTenant($client, $primario, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/abas/push/ocultar-todos", ['_token' => 'errado']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Token de segurança inválido.'], $this->json($client));
        self::assertNull($this->regra((int) $pasta->getId(), 'push'));
    }
}
