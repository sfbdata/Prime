<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Controller\IaDaCentralController;
use App\Central\UseCase\ConsultarStatusDaPerguntaDaIaUseCase;
use App\Central\UseCase\PerguntarABlueJusIaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * As rotas da pergunta ao BlueJus IA na Central (F3, PR 3a) pela HTTP: sucesso (201) e em andamento (202), CSRF, sessão,
 * texto inválido (422) sem gastar o limitador, limite (429), IA indisponível (409), e o isolamento do polling entre
 * pessoas e entre escritórios. Nenhuma resposta leva o texto da pergunta nem da resposta.
 */
#[CoversClass(IaDaCentralController::class)]
#[CoversClass(PerguntarABlueJusIaUseCase::class)]
#[CoversClass(ConsultarStatusDaPerguntaDaIaUseCase::class)]
final class IaDaCentralControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    private const SEGREDO = 'SEGREDO-DA-PERGUNTA-123';

    /** @return array{0: KernelBrowser, 1: Tenant, 2: User, 3: User} cliente, escritório, Ana e Bruno (já com a IA ligada) */
    private function cenario(): array
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant, $admin);
        $ana = $this->criarUsuarioComPermissoes($tenant, ['modules.inteligencia.view'], 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, ['modules.inteligencia.view'], 'bruno');

        return [$client, $tenant, $ana, $bruno];
    }

    /** @param array<string, mixed> $corpo */
    private function perguntar(KernelBrowser $client, array $corpo): void
    {
        $this->post($client, '/central/ia/perguntas', $corpo);
    }

    private function analises(): int
    {
        return (int) $this->em()->getConnection()->fetchOne("SELECT count(*) FROM inteligencia_analise WHERE tipo = 'chat_central'");
    }

    private function mensagens(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM central_mensagem');
    }

    #[TestDox('Pergunta nova: 201 só com ids e estado, mensagem e análise gravadas, uma mensagem na fila, limitador pela chave do usuário e NENHUM texto no corpo')]
    public function testPerguntaNova(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $espiao = new RateLimiterFactoryEspiao();
        static::getContainer()->set('limiter.inteligencia_solicitar', $espiao);
        $this->logarComTenant($client, $ana, $tenant);

        $this->perguntar($client, ['conteudo' => self::SEGREDO]);

        self::assertResponseStatusCodeSame(201);
        $json = $this->json($client);
        self::assertSame(['analiseId', 'conversaId', 'mensagemId', 'status', 'emAndamento'], array_keys($json));
        self::assertSame('pendente', $json['status']);
        self::assertFalse($json['emAndamento']);
        self::assertStringNotContainsString(self::SEGREDO, (string) $client->getResponse()->getContent());
        self::assertSame(1, $this->analises());
        self::assertSame(1, $this->mensagens());
        self::assertCount(1, $this->transporteAsync()->getSent());
        self::assertSame([(string) $ana->getId()], $espiao->chavesConsumidas);
    }

    #[TestDox('Segunda pergunta com a primeira em andamento: 202 com a MESMA, sem criar nada e sem fila nova')]
    public function testEmAndamento(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $client->disableReboot();
        $this->logarComTenant($client, $ana, $tenant);
        $this->perguntar($client, ['conteudo' => 'Primeira']);
        $primeira = $this->json($client);
        self::assertCount(1, $this->transporteAsync()->getSent());

        $this->perguntar($client, ['conteudo' => self::SEGREDO]);

        self::assertResponseStatusCodeSame(202);
        $json = $this->json($client);
        self::assertTrue($json['emAndamento']);
        self::assertSame($primeira['analiseId'], $json['analiseId']);
        self::assertSame($primeira['mensagemId'], $json['mensagemId']);
        self::assertSame(1, $this->analises());
        self::assertSame(1, $this->mensagens());
        // O transporte em memória zera a cada requisição: depois da segunda, nada novo entrou na fila.
        self::assertCount(0, $this->transporteAsync()->getSent());
        self::assertStringNotContainsString(self::SEGREDO, (string) $client->getResponse()->getContent());
    }

    #[TestDox('Sem o token CSRF: 403 e nada gravado')]
    public function testSemCsrf(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('POST', '/central/ia/perguntas', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['conteudo' => 'Oi'], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->analises());
        self::assertSame(0, $this->mensagens());
    }

    #[TestDox('Sem sessão: nem a pergunta nem o status respondem (redireciona ao login ou 401), e nada é gravado')]
    public function testSemSessao(): void
    {
        [$client] = $this->cenario();

        $this->perguntar($client, ['conteudo' => 'Oi']);
        self::assertContains($client->getResponse()->getStatusCode(), [Response::HTTP_FOUND, Response::HTTP_UNAUTHORIZED]);
        $client->request('GET', '/central/ia/perguntas/1');
        self::assertContains($client->getResponse()->getStatusCode(), [Response::HTTP_FOUND, Response::HTTP_UNAUTHORIZED]);
        self::assertSame(0, $this->analises());
    }

    #[TestDox('Texto vazio, só espaços, sem o campo, não-texto ou com mais de 2000 caracteres: 422, nada gravado e o limitador intocado')]
    public function testTextoInvalido(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $espiao = new RateLimiterFactoryEspiao();
        static::getContainer()->set('limiter.inteligencia_solicitar', $espiao);
        $this->logarComTenant($client, $ana, $tenant);

        $corpos = [['conteudo' => ''], ['conteudo' => '   '], [], ['conteudo' => 123], ['conteudo' => str_repeat('a', 2001)], ['conteudo' => str_repeat('a', 20_000)]];
        foreach ($corpos as $corpo) {
            $this->perguntar($client, $corpo);

            self::assertResponseStatusCodeSame(422);
            self::assertSame('pergunta_invalida', $this->json($client)['motivo']);
        }

        self::assertSame(0, $this->analises());
        self::assertSame(0, $this->mensagens());
        self::assertSame([], $espiao->chavesConsumidas, 'o limitador só roda quando a pergunta é nova e válida');
        self::assertCount(0, $this->transporteAsync()->getSent());
    }

    #[TestDox('2000 caracteres com acento é o limite aceito')]
    public function testTextoNoLimite(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);

        $this->perguntar($client, ['conteudo' => str_repeat('é', 2000)]);

        self::assertResponseStatusCodeSame(201);
    }

    #[TestDox('Limitador estourado: 429 muitas_solicitacoes, nada gravado')]
    public function testLimitadorEstourado(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        static::getContainer()->set('limiter.inteligencia_solicitar', new RateLimiterFactoryEspiao(aceita: false));
        $this->logarComTenant($client, $ana, $tenant);

        $this->perguntar($client, ['conteudo' => self::SEGREDO]);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('muitas_solicitacoes', $this->json($client)['motivo']);
        self::assertSame(0, $this->analises());
        self::assertSame(0, $this->mensagens());
        self::assertStringNotContainsString(self::SEGREDO, (string) $client->getResponse()->getContent());
    }

    #[TestDox('IA não liberada no escritório: 409 com o motivo e a mensagem da disponibilidade, nada gravado')]
    public function testIaIndisponivel(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $this->ligarChaveGeralDaIa($admin);   // a plataforma tem chave; este escritório não foi liberado pelo Master
        $ana = $this->criarUsuarioComPermissoes($tenant, ['modules.inteligencia.view'], 'ana');
        $this->logarComTenant($client, $ana, $tenant);

        $this->perguntar($client, ['conteudo' => 'Oi']);

        self::assertResponseStatusCodeSame(409);
        $json = $this->json($client);
        self::assertNotEmpty($json['motivo']);
        self::assertNotEmpty($json['mensagem']);
        self::assertSame(0, $this->analises());
        self::assertSame(0, $this->mensagens());
    }

    #[TestDox('Status: o dono vê só estado (sem texto); outra pessoa do escritório e quem é de outro escritório recebem 404, e a análise de uma não vaza na de outra')]
    public function testStatusIsolado(): void
    {
        [$client, $tenant, $ana, $bruno] = $this->cenario();
        [$adminB, $tenantB] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenantB, $adminB);
        $dora = $this->criarUsuarioComPermissoes($tenantB, ['modules.inteligencia.view'], 'dora');

        $this->logarComTenant($client, $ana, $tenant);
        $this->perguntar($client, ['conteudo' => self::SEGREDO]);
        $daAna = $this->json($client);
        $client->request('GET', '/central/ia/perguntas/' . $daAna['analiseId']);
        self::assertResponseIsSuccessful();
        $status = $this->json($client);
        self::assertSame(['analiseId', 'conversaId', 'status', 'emAndamento', 'terminal', 'mensagem'], array_keys($status));
        self::assertSame('pendente', $status['status']);
        self::assertTrue($status['emAndamento']);
        self::assertFalse($status['terminal']);
        self::assertNull($status['mensagem']);
        self::assertSame($daAna['conversaId'], $status['conversaId']);
        self::assertStringNotContainsString(self::SEGREDO, (string) $client->getResponse()->getContent());

        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('GET', '/central/ia/perguntas/' . $daAna['analiseId']);
        self::assertResponseStatusCodeSame(404, 'colega do mesmo escritório');
        $this->perguntar($client, ['conteudo' => 'Do Bruno']);
        self::assertResponseStatusCodeSame(201);
        $doBruno = $this->json($client);
        self::assertNotSame($daAna['analiseId'], $doBruno['analiseId'], 'a pergunta em andamento da Ana não trava a do Bruno');
        self::assertNotSame($daAna['conversaId'], $doBruno['conversaId']);

        $this->logarComTenant($client, $dora, $tenantB);
        $client->request('GET', '/central/ia/perguntas/' . $daAna['analiseId']);
        self::assertResponseStatusCodeSame(404, 'outro escritório');

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', '/central/ia/perguntas/' . $doBruno['analiseId']);
        self::assertResponseStatusCodeSame(404, 'a análise do Bruno não é da Ana');
        $client->request('GET', '/central/ia/perguntas/999999');
        self::assertResponseStatusCodeSame(404, 'id que não existe');
    }

    #[TestDox('Status de análise que não é do chat (de pasta) é 404 mesmo sendo do mesmo usuário e escritório')]
    public function testStatusDeAnaliseDePasta(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analiseDePasta = $this->criarAnalisePendente($tenant, $ana, $pasta);
        $this->logarComTenant($client, $ana, $tenant);
        $this->perguntar($client, ['conteudo' => 'Abre a conversa']);

        $client->request('GET', '/central/ia/perguntas/' . $analiseDePasta->getId());

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Status de análise que falhou: terminal com a mensagem FIXA, sem o motivo técnico')]
    public function testStatusFalhou(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $this->perguntar($client, ['conteudo' => 'Oi']);
        $id = (int) $this->json($client)['analiseId'];
        $this->em()->getConnection()->executeStatement("UPDATE inteligencia_analise SET status = 'falhou', erro_motivo = 'TOKEN-INTERNO-DO-PROVEDOR' WHERE id = ?", [$id]);

        $client->request('GET', '/central/ia/perguntas/' . $id);

        self::assertResponseIsSuccessful();
        $json = $this->json($client);
        self::assertSame('falhou', $json['status']);
        self::assertTrue($json['terminal']);
        self::assertFalse($json['emAndamento']);
        self::assertSame(ConsultarStatusDaPerguntaDaIaUseCase::MENSAGEM_FALHA, $json['mensagem']);
        self::assertStringNotContainsString('TOKEN-INTERNO', (string) $client->getResponse()->getContent());
    }
}
