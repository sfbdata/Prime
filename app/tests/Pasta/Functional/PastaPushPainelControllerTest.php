<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Djen\DTO\ConsultaComunicacoesQuery;
use App\Djen\DTO\RespostaComunicacoes;
use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Service\DjenClient;
use App\Djen\Service\DjenClientInterface;
use App\Pasta\Controller\PastaPushPainelController;
use App\Processo\Entity\Processo;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * O switch do monitoramento (`pasta_push_monitoramento`) e o "Buscar agora" (`pasta_push_buscar`)
 * do painel do Push (spec push-painel-monitoramento §1 e §2): guarda de escritório (404),
 * permissão (403), CSRF (403), o DJEN dublado e o limite de uma busca por minuto (429).
 */
#[CoversClass(PastaPushPainelController::class)]
#[Group('pasta')]
final class PastaPushPainelControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use CriaUsuarioComPermissoesTrait;

    private const NUMERO = '07011345720258070007';

    private function instalarCsrfStorage(): void
    {
        $storage = new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function postar(KernelBrowser $client, int $pastaId, string $acao, ?string $token = null, ?int $processoId = null): void
    {
        $dados = ['_token' => $token ?? 'TOKEN_pasta_push_' . $acao . '_' . $pastaId];
        if ($processoId !== null) {
            $dados['processo'] = (string) $processoId;
        }
        $client->request('POST', "/pasta/{$pastaId}/push/{$acao}", $dados);
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function processoNoBanco(int $id): Processo
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();
        $processo = $em->find(Processo::class, $id);
        self::assertNotNull($processo);

        return $processo;
    }

    /** O DJEN dublado: devolve uma intimação para o número da pasta. */
    private function dublarDjen(): void
    {
        $mock = $this->createMock(DjenClientInterface::class);
        $mock->method('consultarComunicacoes')->willReturnCallback(
            static fn (ConsultaComunicacoesQuery $q): RespostaComunicacoes => $q->numeroProcesso === self::NUMERO
                ? new RespostaComunicacoes(1, [[
                    'id' => 990001,
                    'numero_processo' => self::NUMERO,
                    'siglaTribunal' => 'TJDFT',
                    'tipoComunicacao' => 'Intimação',
                    'data_disponibilizacao' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
                ]])
                : new RespostaComunicacoes(0, []),
        );
        static::getContainer()->set(DjenClient::class, $mock);
    }

    /** @return array{0: KernelBrowser, 1: \App\Entity\Auth\User, 2: \App\Entity\Tenant\Tenant, 3: \App\Pasta\Entity\Pasta, 4: Processo} */
    private function cenario(): array
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $this->instalarCsrfStorage();

        return [$client, $user, $tenant, $pasta, $processo];
    }

    #[TestDox('Alternar o principal: pausa e retoma (quem mexeu por último fica gravado), com os textos do desenho no JSON')]
    public function testAlternar(): void
    {
        [$client, $user, $tenant, $pasta, $processo] = $this->cenario();
        $this->logarComTenant($client, $user, $tenant);
        $id = (int) $processo->getId();

        $this->postar($client, (int) $pasta->getId(), 'monitoramento', processoId: $id);

        self::assertResponseIsSuccessful();
        $json = $this->json($client);
        self::assertFalse($json['ativo']);
        self::assertSame('Monitoramento pausado', $json['statusTxt']);
        self::assertSame('A busca pelo número do processo fica parada; intimações pela OAB continuam chegando.', $json['statusSub']);
        self::assertSame($id, $json['processo']['id']);
        self::assertTrue($json['processo']['principal']);
        self::assertFalse($json['processo']['ativo']);
        self::assertStringStartsWith('Pausado por Admin Push em ', (string) $json['processo']['dica']);
        $noBanco = $this->processoNoBanco($id);
        self::assertNotNull($noBanco->getMonitoramentoPausadoEm());
        self::assertSame($user->getId(), $noBanco->getMonitoramentoAlteradoPor()?->getId());

        $this->postar($client, (int) $pasta->getId(), 'monitoramento', processoId: $id);

        self::assertResponseIsSuccessful();
        self::assertSame('Monitoramento ativo', $this->json($client)['statusTxt']);
        $noBanco = $this->processoNoBanco($id);
        self::assertTrue($noBanco->monitoramentoAtivo());
        self::assertSame($user->getId(), $noBanco->getMonitoramentoAlteradoPor()?->getId(), 'retomar não apaga quem mexeu');
        self::assertNotNull($noBanco->getMonitoramentoAlteradoEm());
    }

    #[TestDox('Alternar o outro processo da pasta: só ele pausa, com "Apensado: … · pausado"; o principal segue ativo')]
    public function testAlternarApensado(): void
    {
        [$client, $user, $tenant, $pasta, $processo] = $this->cenario();
        $apensado = $this->criarProcesso($tenant, '00000904420265180301');
        $this->vincular($pasta, $apensado);
        $this->logarComTenant($client, $user, $tenant);

        $this->postar($client, (int) $pasta->getId(), 'monitoramento', processoId: (int) $apensado->getId());

        self::assertResponseIsSuccessful();
        $json = $this->json($client);
        self::assertTrue($json['ativo'], 'o cabeçalho é o do principal');
        self::assertSame('Captura automática nos diários e no PJe', $json['statusSub'], 'desenho 18/10: apensado pausado sai da contagem');
        self::assertFalse($json['processo']['principal']);
        self::assertSame('Apensado: PROCEDIMENTO COMUM · pausado', $json['processo']['sub']);
        self::assertTrue($this->processoNoBanco((int) $processo->getId())->monitoramentoAtivo());
        self::assertFalse($this->processoNoBanco((int) $apensado->getId())->monitoramentoAtivo());
    }

    #[TestDox('Alternar processo que não é desta pasta (do mesmo escritório) ou sem processo: 404 e nada muda')]
    public function testAlternarProcessoDeOutraPasta(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $outraPasta = $this->criarPasta($tenant);
        $alheio = $this->criarProcesso($tenant, '07099999999999999999');
        $this->vincular($outraPasta, $alheio);
        $this->logarComTenant($client, $user, $tenant);

        $this->postar($client, (int) $pasta->getId(), 'monitoramento', processoId: (int) $alheio->getId());
        self::assertResponseStatusCodeSame(404);
        self::assertTrue($this->processoNoBanco((int) $alheio->getId())->monitoramentoAtivo());

        $this->postar($client, (int) $pasta->getId(), 'monitoramento');
        self::assertResponseStatusCodeSame(404, 'sem o campo processo');
    }

    #[TestDox('Alternar sem CSRF válido: 403 e nada muda')]
    public function testAlternarSemCsrf(): void
    {
        [$client, $user, $tenant, $pasta, $processo] = $this->cenario();
        $this->logarComTenant($client, $user, $tenant);

        $this->postar($client, (int) $pasta->getId(), 'monitoramento', 'errado', (int) $processo->getId());

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->processoNoBanco((int) $processo->getId())->monitoramentoAtivo());
    }

    #[TestDox('Alternar sem EDITAR a pasta (só ver): 403 e nada muda')]
    public function testAlternarSemEdit(): void
    {
        [$client, , $tenant, $pasta, $processo] = $this->cenario();
        $this->logarComTenant($client, $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']), $tenant);

        $this->postar($client, (int) $pasta->getId(), 'monitoramento', processoId: (int) $processo->getId());

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->processoNoBanco((int) $processo->getId())->monitoramentoAtivo());
    }

    #[TestDox('Alternar pasta de outro escritório: 404, e o mesmo pedido na pasta certa passa')]
    public function testAlternarOutroEscritorio(): void
    {
        [$client, , , $pasta, $processo] = $this->cenario();
        [$intruso, $outroTenant] = $this->criarAdmin();
        $suaPasta = $this->criarPasta($outroTenant);
        $seuProcesso = $this->criarProcesso($outroTenant, self::NUMERO);
        $this->vincular($suaPasta, $seuProcesso);
        $seuProcessoId = (int) $seuProcesso->getId();
        $this->logarComTenant($client, $intruso, $outroTenant);

        $this->postar($client, (int) $pasta->getId(), 'monitoramento', processoId: (int) $processo->getId());

        self::assertResponseStatusCodeSame(404);
        self::assertTrue($this->processoNoBanco((int) $processo->getId())->monitoramentoAtivo());

        $this->postar($client, (int) $suaPasta->getId(), 'monitoramento', processoId: $seuProcessoId);
        self::assertResponseIsSuccessful();
    }

    #[TestDox('Buscar agora: consulta o DJEN, grava a publicação nova e a última verificação, e avisa "1 movimentação(ões) nova(s)"')]
    public function testBuscarAgora(): void
    {
        [$client, , $tenant, $pasta, $processo] = $this->cenario();
        $this->dublarDjen();
        // Ver a pasta basta para buscar.
        $this->logarComTenant($client, $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']), $tenant);

        $this->postar($client, (int) $pasta->getId(), 'buscar');

        self::assertResponseIsSuccessful();
        self::assertSame(['sucesso' => true, 'novas' => 1, 'mensagem' => '1 movimentação(ões) nova(s)'], $this->json($client));
        $noBanco = $this->processoNoBanco((int) $processo->getId());
        self::assertNotNull($noBanco->getUltimaVerificacaoEm(), 'grava a última verificação');
        $publicacao = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(PublicacaoDjen::class)->findOneBy(['djenId' => '990001', 'tenant' => $tenant->getId()]);
        self::assertNotNull($publicacao);
        self::assertSame((int) $processo->getId(), $publicacao->getProcesso()?->getId());

        $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertStringContainsString('1 movimentação(ões) nova(s)', (string) $client->getResponse()->getContent(), 'o aviso chega como flash na aba recarregada');
    }

    #[TestDox('Limite gasto: 429 "Aguarde um minuto para buscar de novo." antes de consultar o DJEN; a chave é escritório + pasta')]
    public function testBuscarAgoraNoLimite(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $mock = $this->createMock(DjenClientInterface::class);
        $mock->expects(self::never())->method('consultarComunicacoes');
        static::getContainer()->set(DjenClient::class, $mock);
        // O 429 real não é observável por HTTP em teste: `cache.rate_limiter` é ArrayAdapter e o
        // services_resetter o zera a cada request. O dublê (padrão do repo) simula a cota gasta;
        // a configuração do limite se prova no teste seguinte.
        $espiao = new RateLimiterFactoryEspiao(aceita: false);
        static::getContainer()->set('limiter.pasta_push_buscar', $espiao);
        $this->logarComTenant($client, $user, $tenant);

        $this->postar($client, (int) $pasta->getId(), 'buscar');

        self::assertResponseStatusCodeSame(429);
        self::assertSame(['erro' => 'Aguarde um minuto para buscar de novo.'], $this->json($client));
        self::assertSame([$tenant->getId() . '-' . $pasta->getId()], $espiao->chavesConsumidas);
    }

    #[TestDox('O limitador da busca aceita 1 por minuto para a mesma pasta, e a outra pasta tem a sua cota')]
    public function testLimitadorUmPorMinuto(): void
    {
        self::bootKernel();
        /** @var \Symfony\Component\RateLimiter\RateLimiterFactoryInterface $fabrica */
        $fabrica = static::getContainer()->get('limiter.pasta_push_buscar');

        self::assertTrue($fabrica->create('1-10')->consume()->isAccepted());
        self::assertFalse($fabrica->create('1-10')->consume()->isAccepted(), 'a segunda no mesmo minuto');
        self::assertTrue($fabrica->create('1-11')->consume()->isAccepted(), 'outra pasta');
    }

    #[TestDox('Buscar agora com o monitoramento pausado: 422 e o DJEN não é consultado')]
    public function testBuscarPausado(): void
    {
        [$client, $user, $tenant, $pasta, $processo] = $this->cenario();
        $processo->pausarMonitoramento($user, new \DateTimeImmutable());
        $this->em()->flush();
        $mock = $this->createMock(DjenClientInterface::class);
        $mock->expects(self::never())->method('consultarComunicacoes');
        static::getContainer()->set(DjenClient::class, $mock);
        $this->logarComTenant($client, $user, $tenant);

        $this->postar($client, (int) $pasta->getId(), 'buscar');

        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Buscar agora: sem CSRF 403, sem ver a pasta 403, outro escritório 404')]
    public function testBuscarGuardas(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $mock = $this->createMock(DjenClientInterface::class);
        $mock->expects(self::never())->method('consultarComunicacoes');
        static::getContainer()->set(DjenClient::class, $mock);

        $this->logarComTenant($client, $user, $tenant);
        $this->postar($client, (int) $pasta->getId(), 'buscar', 'errado');
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($client, $this->criarUsuarioSemNenhumaPermissao($tenant), $tenant);
        $this->postar($client, (int) $pasta->getId(), 'buscar');
        self::assertResponseStatusCodeSame(403);

        [$intruso, $outroTenant] = $this->criarAdmin();
        $this->logarComTenant($client, $intruso, $outroTenant);
        $this->postar($client, (int) $pasta->getId(), 'buscar');
        self::assertResponseStatusCodeSame(404);
    }
}
