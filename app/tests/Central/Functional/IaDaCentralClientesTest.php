<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Controller\IaDaCentralController;
use App\Central\Service\PastasDosClientesDaIa;
use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Pasta\Entity\Pasta;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * O escopo `clientes` da pergunta ao BlueJus IA (PR 4c, D12) pela HTTP e pelo banco: validação do escopo, aceite do
 * escritório, e a seleção das pastas (só as que a pessoa vê, do mesmo escritório, não excluídas, teto 5 com a aberta).
 */
#[CoversClass(IaDaCentralController::class)]
#[CoversClass(PastasDosClientesDaIa::class)]
final class IaDaCentralClientesTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    private const SEGREDO = 'SEGREDO-DA-PERGUNTA-456';

    private function darAcessoDeLeitura(User $user, Tenant $tenant, Pasta $pasta): void
    {
        $acesso = new ResourceAccess();
        $acesso->setUser($user);
        $acesso->setTenant($tenant);
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId((int) $pasta->getId());
        $acesso->setCanView(true);
        $this->em()->persist($acesso);
        $this->em()->flush();
    }

    private function criarCliente(Tenant $tenant, string $nome): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setTenant($tenant);
        $cliente->setNomeCompleto($nome);
        $cliente->setCpf('123.456.789-09');
        $cliente->setRg('12.345.678-9');
        $cliente->setRgOrgaoExpedidor('SSP');
        $cliente->setEmail('c_' . uniqid() . '@test.com');
        $cliente->setTelefoneCelular('(61) 99999-1234');
        $cliente->setCep('70000-000');
        $cliente->setEndereco('Rua A, 1');
        $cliente->setCidade('Brasília');
        $cliente->setEstado('DF');
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    private function pastaDe(Tenant $tenant, ClientePF $cliente, string $abertaEm, ?User $verPor = null): Pasta
    {
        $pasta = $this->criarPasta($tenant);
        $pasta->setDataAbertura(new \DateTimeImmutable($abertaEm));
        $pasta->addCliente($cliente);
        $this->em()->flush();
        if ($verPor !== null) {
            $this->darAcessoDeLeitura($verPor, $tenant, $pasta);
        }

        return $pasta;
    }

    /** @return array{0: KernelBrowser, 1: Tenant, 2: User, 3: ClientePF, 4: Pasta} Ana com a IA ligada e a pasta aberta (que ela vê) */
    private function cenario(): array
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant, $admin);
        $ana = $this->criarUsuarioComPermissoes($tenant, ['modules.inteligencia.view'], 'ana');
        $cliente = $this->criarCliente($tenant, 'Maria Cliente');
        $aberta = $this->pastaDe($tenant, $cliente, '2026-01-01', $ana);
        $this->logarComTenant($client, $ana, $tenant);

        return [$client, $tenant, $ana, $cliente, $aberta];
    }

    /** @param array<string, mixed> $corpo */
    private function perguntar(KernelBrowser $client, array $corpo): void
    {
        $this->post($client, '/central/ia/perguntas', $corpo);
    }

    private function resumoDaUltima(): array
    {
        return json_decode((string) $this->em()->getConnection()->fetchOne("SELECT contexto_resumo FROM inteligencia_analise WHERE tipo = 'chat_central' ORDER BY id DESC LIMIT 1"), true, 512, JSON_THROW_ON_ERROR);
    }

    private function analises(): int
    {
        return (int) $this->em()->getConnection()->fetchOne("SELECT count(*) FROM inteligencia_analise WHERE tipo = 'chat_central'");
    }

    /** @return list<int> */
    private function idsAdicionais(User $ana, Tenant $tenant, Pasta $aberta): array
    {
        $saida = static::getContainer()->get(PastasDosClientesDaIa::class)->adicionais($ana, $tenant, (int) $aberta->getId());

        return array_map(static fn (array $p): int => $p['pastaId'], $saida['pastas']);
    }

    #[TestDox('escopo clientes sem pastaId: 422 pergunta_invalida, nada gravado, limitador intocado')]
    public function testClientesSemPasta(): void
    {
        [$client] = $this->cenario();
        $espiao = new RateLimiterFactoryEspiao();
        static::getContainer()->set('limiter.inteligencia_solicitar', $espiao);

        $this->perguntar($client, ['conteudo' => self::SEGREDO, 'escopo' => 'clientes']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('pergunta_invalida', $this->json($client)['motivo']);
        self::assertSame(0, $this->analises());
        self::assertSame([], $espiao->chavesConsumidas);
    }

    #[TestDox('escopo desconhecido ou de tipo errado: 422 pergunta_invalida')]
    public function testEscopoInvalido(): void
    {
        [$client, , , , $aberta] = $this->cenario();

        foreach (['tudo', '', 7, true, ['clientes']] as $escopo) {
            $this->perguntar($client, ['conteudo' => self::SEGREDO, 'pastaId' => (int) $aberta->getId(), 'escopo' => $escopo]);
            self::assertResponseStatusCodeSame(422);
        }
        self::assertSame(0, $this->analises());
    }

    #[TestDox('escopo "pasta" (ou ausente) fica como na 4a: prompt v2, resumo sem os campos do 4c')]
    public function testEscopoPastaInalterado(): void
    {
        [$client, , , , $aberta] = $this->cenario();

        $this->perguntar($client, ['conteudo' => self::SEGREDO, 'pastaId' => (int) $aberta->getId(), 'escopo' => 'pasta']);

        self::assertResponseStatusCodeSame(201);
        $resumo = $this->resumoDaUltima();
        self::assertArrayNotHasKey('escopo', $resumo);
        self::assertArrayNotHasKey('pastasIds', $resumo);
        self::assertSame('chat-central-v2', $this->em()->getConnection()->fetchOne("SELECT versao_do_prompt FROM inteligencia_analise WHERE tipo = 'chat_central'"));
    }

    #[TestDox('escopo clientes: 201 só com ids e estado; resumo com escopo, pastasIds (aberta primeiro) e decisão por pasta, sem NUP nem texto; prompt v3')]
    public function testClientes(): void
    {
        [$client, $tenant, $ana, $cliente, $aberta] = $this->cenario();
        $outra = $this->pastaDe($tenant, $cliente, '2026-06-01', $ana);

        $this->perguntar($client, ['conteudo' => self::SEGREDO, 'pastaId' => (int) $aberta->getId(), 'escopo' => 'clientes']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['analiseId', 'conversaId', 'mensagemId', 'status', 'emAndamento'], array_keys($this->json($client)));
        $resumo = $this->resumoDaUltima();
        self::assertSame('clientes', $resumo['escopo']);
        self::assertSame([(int) $aberta->getId(), (int) $outra->getId()], $resumo['pastasIds']);
        self::assertSame(0, $resumo['naoConsultadas']);
        self::assertCount(2, $resumo['pastas']);
        $json = (string) json_encode($resumo);
        self::assertStringNotContainsString($aberta->getNup(), $json);
        self::assertStringNotContainsString($outra->getNup(), $json);
        self::assertStringNotContainsString(self::SEGREDO, $json);
        self::assertSame('chat-central-v3', $this->em()->getConnection()->fetchOne("SELECT versao_do_prompt FROM inteligencia_analise WHERE tipo = 'chat_central'"));
    }

    #[TestDox('escritório sem aceite da IA: 409 cruzamento_sem_consentimento com mensagem fixa, nada gravado; o escopo "pasta" segue passando')]
    public function testSemAceite(): void
    {
        [$client, $tenant, , , $aberta] = $this->cenario();
        $configuracao = $this->em()->getRepository(ConfiguracaoDeInteligencia::class)->findOneBy(['tenant' => $tenant]);
        (new \ReflectionProperty(ConfiguracaoDeInteligencia::class, 'consentimentoEnvioExternoEm'))->setValue($configuracao, null);
        $this->em()->flush();
        $espiao = new RateLimiterFactoryEspiao();
        static::getContainer()->set('limiter.inteligencia_solicitar', $espiao);

        $this->perguntar($client, ['conteudo' => self::SEGREDO, 'pastaId' => (int) $aberta->getId(), 'escopo' => 'clientes']);

        self::assertResponseStatusCodeSame(409);
        $json = $this->json($client);
        self::assertSame('cruzamento_sem_consentimento', $json['motivo']);
        self::assertStringNotContainsString($aberta->getNup(), (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->analises());
        self::assertSame([], $espiao->chavesConsumidas);

        $this->perguntar($client, ['conteudo' => 'Só a pasta', 'pastaId' => (int) $aberta->getId()]);
        self::assertResponseStatusCodeSame(201);
    }

    #[TestDox('pasta aberta com segredo de justiça continua 409 contexto_bloqueado também no escopo clientes')]
    public function testAbertaComSegredo(): void
    {
        [$client, , , , $aberta] = $this->cenario();
        $processo = $this->criarProcesso($aberta->getTenant(), '07011345720258070007');
        $this->vincular($aberta, $processo);
        $processo->setNivelSigilo(1);
        $this->em()->flush();

        $this->perguntar($client, ['conteudo' => self::SEGREDO, 'pastaId' => (int) $aberta->getId(), 'escopo' => 'clientes']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('contexto_bloqueado', $this->json($client)['motivo']);
        self::assertSame(0, $this->analises());
    }

    #[TestDox('adicional com segredo de justiça: omitida e contada; a resposta HTTP é 201 normal, sem 409 nem NUP')]
    public function testAdicionalComSegredoOmitida(): void
    {
        [$client, $tenant, $ana, $cliente, $aberta] = $this->cenario();
        $segredo = $this->pastaDe($tenant, $cliente, '2026-06-01', $ana);
        $processo = $this->criarProcesso($tenant, '08022456720258070008');
        $this->vincular($segredo, $processo);
        $processo->setNivelSigilo(1);
        $this->em()->flush();
        $livre = $this->pastaDe($tenant, $cliente, '2026-05-01', $ana);

        $this->perguntar($client, ['conteudo' => self::SEGREDO, 'pastaId' => (int) $aberta->getId(), 'escopo' => 'clientes']);

        self::assertResponseStatusCodeSame(201);
        self::assertStringNotContainsString('08022456720258070008', (string) $client->getResponse()->getContent());
        $resumo = $this->resumoDaUltima();
        self::assertSame([(int) $aberta->getId(), (int) $livre->getId()], $resumo['pastasIds']);
        self::assertSame(1, $resumo['naoConsultadas']);
    }

    #[TestDox('seleção: pasta do cliente que a pessoa NÃO vê, excluída, de outro cliente e de outro escritório não entram (e não são contadas)')]
    public function testSelecao(): void
    {
        [, $tenant, $ana, $cliente, $aberta] = $this->cenario();
        $visivel = $this->pastaDe($tenant, $cliente, '2026-03-01', $ana);
        $this->pastaDe($tenant, $cliente, '2026-04-01');   // mesma pessoa não vê (sem acesso)
        $excluida = $this->pastaDe($tenant, $cliente, '2026-05-01', $ana);
        $excluida->marcarExcluida($ana, new \DateTimeImmutable('2026-06-01'));
        $outroCliente = $this->criarCliente($tenant, 'Outro Cliente');
        $this->pastaDe($tenant, $outroCliente, '2026-07-01', $ana);
        // Pasta de OUTRO escritório presa (indevidamente) ao mesmo cliente: o tenant da consulta a barra.
        [, $tenantB] = $this->criarAdmin();
        $deB = $this->pastaDe($tenantB, $cliente, '2026-08-01');
        $this->darAcessoDeLeitura($ana, $tenantB, $deB);
        $this->em()->flush();

        $saida = static::getContainer()->get(PastasDosClientesDaIa::class)->adicionais($ana, $tenant, (int) $aberta->getId());

        self::assertSame([(int) $visivel->getId()], array_map(static fn (array $p): int => $p['pastaId'], $saida['pastas']));
        self::assertSame(0, $saida['naoConsultadas'], 'pasta que a pessoa não vê não revela que existe');
    }

    #[TestDox('teto: a aberta mais no máximo 4 adicionais, as mais recentes primeiro (dataAbertura DESC)')]
    public function testTeto(): void
    {
        [, $tenant, $ana, $cliente, $aberta] = $this->cenario();
        $criadas = [];
        foreach (['2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01', '2026-07-01'] as $data) {
            $criadas[$data] = (int) $this->pastaDe($tenant, $cliente, $data, $ana)->getId();
        }

        $ids = $this->idsAdicionais($ana, $tenant, $aberta);

        self::assertSame(PastasDosClientesDaIa::LIMITE_PASTAS - 1, \count($ids));
        self::assertSame([$criadas['2026-07-01'], $criadas['2026-06-01'], $criadas['2026-05-01'], $criadas['2026-04-01']], $ids);
    }

    #[TestDox('pasta compartilhada por dois clientes aparece uma vez só')]
    public function testSemDuplicar(): void
    {
        [, $tenant, $ana, $cliente, $aberta] = $this->cenario();
        $outro = $this->criarCliente($tenant, 'Segundo Cliente');
        $aberta->addCliente($outro);
        $compartilhada = $this->pastaDe($tenant, $cliente, '2026-03-01', $ana);
        $compartilhada->addCliente($outro);
        $this->em()->flush();

        self::assertSame([(int) $compartilhada->getId()], $this->idsAdicionais($ana, $tenant, $aberta));
    }
}
