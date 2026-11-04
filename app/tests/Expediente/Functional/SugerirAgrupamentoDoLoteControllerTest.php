<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Controller\ImportarLoteController;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\StatusDaAnalise;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Tests\Inteligencia\Support\ChaveGeralDaIaNoBancoTrait;
use App\Tests\Inteligencia\Support\ProvedorFalso;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * "Sugerir agrupamento com IA" do Importar pastas em lote (spec importar-pastas-em-lote, etapa 2): a rota
 * `expediente_importar_lote_sugerir`, com o {@see ProvedorFalso} no lugar da Anthropic.
 */
#[CoversClass(ImportarLoteController::class)]
final class SugerirAgrupamentoDoLoteControllerTest extends BuscaRapidaWebTestCase
{
    use ChaveGeralDaIaNoBancoTrait;

    private const URL = '/expediente/importar-lote/sugerir';
    private const CAMINHOS = ['Clientes/MARIA DA SILVA/procuracao.pdf', 'Clientes/JOAO/rg.pdf', 'Clientes/solto.pdf'];

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        // O ProvedorFalso programado precisa ser o mesmo da requisição.
        $client->disableReboot();

        return $client;
    }

    private function provedor(): ProvedorFalso
    {
        return static::getContainer()->get(ProvedorFalso::class);
    }

    private function ligarIa(Tenant $tenant, User $por, int $limiteDiario = 50): void
    {
        $this->ligarChaveGeralDaIa($por);
        $configuracao = new ConfiguracaoDeInteligencia($tenant);
        $configuracao->liberarPeloMaster();
        $configuracao->atualizar(true, $limiteDiario, 500, true, $por);
        $this->em()->persist($configuracao);
        $this->em()->flush();
    }

    private function tokenDaTela(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('#il-raiz')->attr('data-token');
    }

    /** @param array<mixed> $caminhos */
    private function sugerir(KernelBrowser $client, string $token, array $caminhos = self::CAMINHOS): void
    {
        $client->request(
            'POST',
            self::URL,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
            (string) json_encode(['_token' => $token, 'caminhos' => $caminhos]),
        );
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function analisesDo(Tenant $tenant): int
    {
        return $this->em()->getRepository(AnaliseDeInteligencia::class)->count(['tenant' => $tenant, 'tipo' => TipoDeAnalise::AgrupamentoDoLote]);
    }

    #[TestDox('sem o módulo Pastas: 403 e o provedor não é chamado')]
    public function testSemModuloPastasDa403(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComPermissoes($tenant, []);
        $this->provedor()->limpar();

        $this->logarComTenant($client, $user, $tenant);
        $this->sugerir($client, 'x');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Você não tem permissão para acessar o módulo de pastas.', $this->json($client)['mensagem']);
        self::assertFalse($this->provedor()->foiChamado());
    }

    #[TestDox('token inválido: 403 e nenhuma análise gravada')]
    public function testTokenInvalidoDa403(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);
        $this->ligarIa($tenant, $admin);
        $this->provedor()->limpar();

        $this->logarComTenant($client, $admin, $tenant);
        $this->sugerir($client, 'falso');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->analisesDo($tenant));
        self::assertFalse($this->provedor()->foiChamado());
    }

    #[TestDox('IA não liberada no escritório: 409 com a mensagem do sistema e o botão não aparece na tela')]
    public function testIaIndisponivelDa409(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);
        // Plataforma ligada, mas o Master não liberou este escritório.
        $this->ligarChaveGeralDaIa($admin);
        $this->provedor()->limpar();

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->request('GET', '/expediente');
        self::assertCount(0, $crawler->filter('#il-raiz [data-il="sugerir"]'));
        $this->sugerir($client, (string) $crawler->filter('#il-raiz')->attr('data-token'));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['status' => 'erro', 'mensagem' => 'A BlueJus IA ainda não foi liberada para este escritório'], $this->json($client));
        self::assertSame(0, $this->analisesDo($tenant));
        self::assertFalse($this->provedor()->foiChamado());
    }

    #[TestDox('cota diária esgotada: 409 com a mensagem da cota, sem chamar o provedor')]
    public function testCotaEsgotadaDa409(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);
        $this->ligarIa($tenant, $admin, 1);
        $this->provedor()->limpar();

        $this->logarComTenant($client, $admin, $tenant);
        $token = $this->tokenDaTela($client);
        $this->provedor()->responderCom('{"casos": []}');
        $this->sugerir($client, $token);
        self::assertResponseIsSuccessful();

        $this->sugerir($client, $token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Limite diário de análises atingido', $this->json($client)['mensagem']);
        self::assertSame(1, $this->analisesDo($tenant));
        self::assertCount(1, $this->provedor()->pedidos);
    }

    #[TestDox('entrada vazia: 422 sem gravar análise')]
    public function testEntradaVaziaDa422(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);
        $this->ligarIa($tenant, $admin);
        $this->provedor()->limpar();

        $this->logarComTenant($client, $admin, $tenant);
        $this->sugerir($client, $this->tokenDaTela($client), []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('erro', $this->json($client)['status']);
        self::assertSame(0, $this->analisesDo($tenant));
    }

    #[TestDox('sucesso: 200 com casos em caminhos, botão na tela e análise concluída sem a resposta guardada')]
    public function testSucessoDa200(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);
        $this->ligarIa($tenant, $admin);
        $this->provedor()->limpar();

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->request('GET', '/expediente');
        self::assertCount(1, $crawler->filter('#il-raiz .il-cab [data-il="sugerir"] .bi-stars'));
        self::assertSame(self::URL, $crawler->filter('#il-raiz')->attr('data-url-sugerir'));

        // 1 Clientes/ · 2 Clientes/JOAO/ · 3 rg.pdf · 4 Clientes/MARIA DA SILVA/ · 5 procuracao.pdf · 6 Clientes/solto.pdf
        $this->provedor()->responderCom((string) json_encode([
            'casos' => [
                ['no' => 4, 'cliente' => 'MARIA DA SILVA', 'mesmoClienteQue' => null, 'motivo' => 'procuração'],
                ['no' => 3, 'cliente' => 'ARQUIVO NAO VALE', 'mesmoClienteQue' => null, 'motivo' => ''],
            ],
            'soltos' => [],
            'duvidas' => [['no' => 2, 'texto' => 'sem documentos de identificação']],
        ]));
        $this->sugerir($client, (string) $crawler->filter('#il-raiz')->attr('data-token'));

        self::assertResponseIsSuccessful();
        self::assertSame([
            'status' => 'ok',
            'casos' => [['pasta' => 'Clientes/MARIA DA SILVA', 'cliente' => 'MARIA DA SILVA', 'mesmoClienteQue' => null, 'motivo' => 'procuração']],
            'soltos' => ['Clientes/JOAO/rg.pdf', 'Clientes/solto.pdf'],
            'duvidas' => [['caminho' => 'Clientes/JOAO', 'texto' => 'sem documentos de identificação']],
            'truncado' => false,
            'enviados' => 6,
        ], $this->json($client));

        $analise = $this->em()->getRepository(AnaliseDeInteligencia::class)->findOneBy(['tenant' => $tenant, 'tipo' => TipoDeAnalise::AgrupamentoDoLote]);
        self::assertNotNull($analise);
        self::assertSame(StatusDaAnalise::Concluida, $analise->getStatus());
        self::assertSame('', $analise->getTextoBruto());
        self::assertSame('1 caso sugerido', $analise->getResumo());
        self::assertSame(['caminhos' => 3, 'linhas' => 6, 'pastas' => 3, 'truncado' => false], $analise->getContextoResumo());
        self::assertSame('claude-haiku-5-5', $this->provedor()->ultimoPedido()?->modelo);
    }

    #[TestDox('falha do provedor: 502 com a mensagem fixa e análise falhou')]
    public function testFalhaDoProvedorDa502(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);
        $this->ligarIa($tenant, $admin);
        $this->provedor()->limpar();

        $this->logarComTenant($client, $admin, $tenant);
        $token = $this->tokenDaTela($client);
        $this->provedor()->falharDefinitivamente();
        $this->sugerir($client, $token);

        self::assertResponseStatusCodeSame(502);
        self::assertSame(['status' => 'erro', 'mensagem' => 'A IA não conseguiu sugerir agora. O agrupamento por pasta continua valendo.'], $this->json($client));
        $analise = $this->em()->getRepository(AnaliseDeInteligencia::class)->findOneBy(['tenant' => $tenant, 'tipo' => TipoDeAnalise::AgrupamentoDoLote]);
        self::assertSame(StatusDaAnalise::Falhou, $analise?->getStatus());
    }
}
