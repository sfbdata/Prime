<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Controller\AnalisePushController;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\UseCase\ListarAuditoriaDaPastaUseCase;
use App\Inteligencia\UseCase\RegistrarExportacaoDaAnaliseUseCase;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * C6-IA-01: baixar e imprimir a análise deixam linha no `audit_log` (desenho, dc L5742-5743) e
 * aparecem no "Registro de auditoria (N)" do drawer. O registro é um POST com CSRF da análise
 * (`inteligencia_analise_registro`); o GET do .txt continua puro. Nenhuma recusa grava linha:
 * token, ação, outro escritório, pasta sem acesso.
 */
#[CoversClass(AnalisePushController::class)]
#[CoversClass(RegistrarExportacaoDaAnaliseUseCase::class)]
#[CoversClass(ListarAuditoriaDaPastaUseCase::class)]
final class RegistroDeExportacaoControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private const SIGILOSO = 'CONTEUDO-SIGILOSO-DA-ANALISE';
    private const RODAPE = 'footer.ia-drawer-audit[data-ia-auditoria]';

    private bool $csrfInstalado = false;

    /** Token previsível (`TOKEN_<id>`), como no SemAcessoAPastaControllerTest: o teste não lê a tela para obtê-lo. */
    private function instalarCsrfStorage(): void
    {
        if ($this->csrfInstalado) {
            return;
        }
        $this->csrfInstalado = true;

        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        return $client;
    }

    private function registrar(KernelBrowser $client, Pasta $pasta, AnaliseDeInteligencia $analise, ?string $acao, ?string $token = null): void
    {
        $corpo = ['_token' => $token ?? 'TOKEN_inteligencia_analise_registro_' . $analise->getId()];
        if ($acao !== null) {
            $corpo['acao'] = $acao;
        }

        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/ia/analises/{$analise->getId()}/registro",
            $corpo,
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );
    }

    /** @return list<array{action: string, tenant_id: int|string|null, actor_user_id: int|string|null, actor_email: string|null, changes: string|null}> */
    private function registrosDaAnalise(AnaliseDeInteligencia $analise): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            'SELECT action, tenant_id, actor_user_id, actor_email, changes FROM audit_log WHERE entity_class = :classe AND entity_id = :id AND action IN (:baixar, :imprime) ORDER BY id',
            [
                'classe' => AnaliseDeInteligencia::class,
                'id' => (string) $analise->getId(),
                'baixar' => RegistrarExportacaoDaAnaliseUseCase::ACAO_DOWNLOAD,
                'imprime' => RegistrarExportacaoDaAnaliseUseCase::ACAO_IMPRESSAO,
            ],
        );
    }

    /** @return array{User, Tenant, Pasta, AnaliseDeInteligencia} */
    private function cenario(KernelBrowser $client): array
    {
        [$user, $tenant] = $this->criarAdmin();
        $user->setFullName('Ana Lima');
        $this->em()->flush();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor);
        $this->concluirAnalise($analise, self::SIGILOSO . ' resumo.', [], null, self::SIGILOSO . ' texto.');
        $this->logarComTenant($client, $user, $tenant);

        return [$user, $tenant, $pasta, $analise];
    }

    #[TestDox('download grava 1 linha ia_baixar (tenant, autor, pasta, agente) sem texto do modelo; impressão grava 1 linha ia_imprime')]
    public function testDownloadEImpressaoGravam(): void
    {
        $client = $this->cliente();
        [$user, $tenant, $pasta, $analise] = $this->cenario($client);

        $this->registrar($client, $pasta, $analise, 'download');

        self::assertResponseStatusCodeSame(201);
        $linhas = $this->registrosDaAnalise($analise);
        self::assertCount(1, $linhas, 'download → 1 linha');
        self::assertSame('ia_baixar', $linhas[0]['action']);
        self::assertSame($tenant->getId(), (int) $linhas[0]['tenant_id']);
        self::assertSame($user->getId(), (int) $linhas[0]['actor_user_id']);
        self::assertSame($user->getEmail(), $linhas[0]['actor_email']);
        self::assertSame(['pasta_id' => (int) $pasta->getId(), 'agente' => 'gestor'], json_decode((string) $linhas[0]['changes'], true));
        self::assertStringNotContainsString(self::SIGILOSO, (string) $linhas[0]['changes'], 'o texto do modelo não vai para o audit_log');

        $this->registrar($client, $pasta, $analise, 'impressao');

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['ia_baixar', 'ia_imprime'], array_column($this->registrosDaAnalise($analise), 'action'));
    }

    #[TestDox('pasta riscada (lápide): o registro é liberado como o .zip — baixar a análise deixa a linha')]
    public function testLapideRegistra(): void
    {
        $client = $this->cliente();
        [$user, , $pasta, $analise] = $this->cenario($client);
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->registrar($client, $pasta, $analise, 'download');

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['ia_baixar'], array_column($this->registrosDaAnalise($analise), 'action'));
    }

    #[TestDox('o GET do .txt é puro: baixar o texto (com ou sem query) não grava linha de exportação')]
    public function testGetDoTxtNaoGrava(): void
    {
        $client = $this->cliente();
        [, , $pasta, $analise] = $this->cenario($client);
        $url = "/pasta/{$pasta->getId()}/ia/analises/{$analise->getId()}.txt";

        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $client->request('GET', $url . '?para=download');
        self::assertResponseIsSuccessful();
        $client->request('GET', $url . '?para=impressao');
        self::assertResponseIsSuccessful();

        self::assertSame([], $this->registrosDaAnalise($analise));
    }

    #[TestDox('ação fora de download|impressao (copia, vazia, ausente) → 422 e nenhuma linha')]
    public function testAcaoInvalida(): void
    {
        $client = $this->cliente();
        [, , $pasta, $analise] = $this->cenario($client);

        foreach (['copia', '', 'ia_baixar', null] as $acao) {
            $this->registrar($client, $pasta, $analise, $acao);
            self::assertResponseStatusCodeSame(422, 'ação ' . var_export($acao, true));
        }

        self::assertSame([], $this->registrosDaAnalise($analise));
    }

    #[TestDox('sem token, token de outra análise ou o token das ações comuns → 403 e nenhuma linha')]
    public function testSemCsrf(): void
    {
        $client = $this->cliente();
        [, , $pasta, $analise] = $this->cenario($client);

        foreach (['', 'TOKEN_inteligencia_analise_registro_999999999', 'TOKEN_inteligencia_analise_' . $analise->getId()] as $token) {
            $this->registrar($client, $pasta, $analise, 'download', $token);
            self::assertResponseStatusCodeSame(403);
        }

        self::assertSame([], $this->registrosDaAnalise($analise));
    }

    #[TestDox('análise ainda sem resultado → 404 e nenhuma linha')]
    public function testAnaliseNaoConcluida(): void
    {
        $client = $this->cliente();
        [$user, $tenant, $pasta] = $this->cenario($client);
        $pendente = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Prazos);

        $this->registrar($client, $pasta, $pendente, 'download');

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->registrosDaAnalise($pendente));
    }

    #[TestDox('outro escritório: pela pasta dele ou pela minha pasta com o id da análise dele → 404 e nenhuma linha')]
    public function testOutroEscritorio(): void
    {
        $client = $this->cliente();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        [$pastaA] = $this->criarPastaComPublicacao($tenantA);
        [$pastaB] = $this->criarPastaComPublicacao($tenantB);
        $analiseB = $this->criarAnaliseDoAgentePendente($tenantB, $userB, $pastaB);
        $this->concluirAnalise($analiseB, 'Resumo B.', [], null, 'Texto B.');
        $this->logarComTenant($client, $userA, $tenantA);
        $token = 'TOKEN_inteligencia_analise_registro_' . $analiseB->getId();

        foreach (['download', 'impressao'] as $acao) {
            $this->registrar($client, $pastaB, $analiseB, $acao, $token);
            self::assertResponseStatusCodeSame(404, 'pasta de outro escritório');
            $client->request(
                'POST',
                "/pasta/{$pastaA->getId()}/ia/analises/{$analiseB->getId()}/registro",
                ['_token' => $token, 'acao' => $acao],
                [],
                ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
            );
            self::assertResponseStatusCodeSame(404, 'análise de outro escritório pela própria pasta');
        }

        self::assertSame([], $this->registrosDaAnalise($analiseB));
    }

    #[TestDox('colega com o módulo da IA mas sem ver a pasta → 403 e nenhuma linha; com ResourceAccess de leitura, o mesmo POST grava')]
    public function testQuemNaoVeAPasta(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnaliseDoAgentePendente($tenant, $admin, $pasta, Agente::Gestor);
        $this->concluirAnalise($analise, 'Resumo.', [], null, 'Texto.');
        $colega = $this->criarUsuarioComPermissoes($tenant, ['modules.inteligencia.view', 'modules.pastas.view'], 'sem_acesso');
        $this->logarComTenant($client, $colega, $tenant);

        $this->registrar($client, $pasta, $analise, 'download');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->registrosDaAnalise($analise));

        // Prova do guarda: só o acesso à pasta muda, e o mesmo POST passa.
        $acesso = new ResourceAccess();
        $acesso->setUser($colega);
        $acesso->setTenant($tenant);
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId((int) $pasta->getId());
        $acesso->setCanView(true);
        $this->em()->persist($acesso);
        $this->em()->flush();

        $this->registrar($client, $pasta, $analise, 'download');
        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $this->registrosDaAnalise($analise));
    }

    #[TestDox('cartão do agente: a concluída leva url e token do registro (o que o JS usa); a pendente, não')]
    public function testCartaoLevaUrlEToken(): void
    {
        $client = $this->cliente();
        [$user, $tenant, $pasta, $analise] = $this->cenario($client);
        $pendente = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Prazos);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/agentes/gestor/analises");
        self::assertResponseIsSuccessful();
        $cartao = $crawler->filter('article[data-ia-analise="' . $analise->getId() . '"]');
        self::assertSame(1, $cartao->count());
        self::assertSame("/pasta/{$pasta->getId()}/ia/analises/{$analise->getId()}/registro", $cartao->attr('data-ia-registro-url'));
        // O gerenciador de CSRF randomiza o valor na tela; basta que o token exista.
        self::assertNotSame('', (string) $cartao->attr('data-ia-registro-token'));

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/agentes/prazos/analises");
        self::assertResponseIsSuccessful();
        $cartaoPendente = $crawler->filter('article[data-ia-analise="' . $pendente->getId() . '"]');
        self::assertSame(1, $cartaoPendente->count());
        self::assertNull($cartaoPendente->attr('data-ia-registro-url'), 'sem resultado não há o que baixar nem imprimir');
        self::assertNull($cartaoPendente->attr('data-ia-registro-token'));
    }

    #[TestDox('drawer: download e impressão entram na lista como "Download: …" e "Impressão: …" e somam no N')]
    public function testDrawerMostraOsRegistros(): void
    {
        $client = $this->cliente();
        [, , $pasta, $analise] = $this->cenario($client);

        $this->registrar($client, $pasta, $analise, 'download');
        self::assertResponseStatusCodeSame(201);
        $this->registrar($client, $pasta, $analise, 'impressao');
        self::assertResponseStatusCodeSame(201);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/auditoria");

        self::assertResponseIsSuccessful();
        $rodape = $crawler->filter(self::RODAPE);
        self::assertSame('Registro de auditoria (3)', trim($rodape->filter('.ia-audit-botao > .ia-audit-rotulo')->text()), '1 análise + 1 download + 1 impressão');
        self::assertSame(3, $rodape->filter('.ia-audit-lista > .ia-audit-linha')->count());

        $exportacoes = [];
        $rodape->filter('.ia-audit-lista > .ia-audit-linha[data-ia-audit-exportacao]')->each(static function (Crawler $l) use (&$exportacoes): void {
            $exportacoes[(string) $l->attr('data-ia-audit-acao')] = [$l->attr('data-ia-audit-exportacao'), $l->filter('.ia-audit-txt')->text()];
        });
        // Mesma ordem só dentro do segundo (a coluna não guarda fração): aqui vale o conjunto.
        ksort($exportacoes);
        self::assertSame(['download', 'impressao'], array_keys($exportacoes));
        self::assertSame((string) $analise->getId(), $exportacoes['download'][0]);
        self::assertSame('Download: Agente Gestor', $exportacoes['download'][1]);
        self::assertSame((string) $analise->getId(), $exportacoes['impressao'][0]);
        self::assertSame('Impressão: Agente Gestor', $exportacoes['impressao'][1]);
        self::assertSame(1, $rodape->filter('.ia-audit-linha[data-ia-audit-analise="' . $analise->getId() . '"]')->count(), 'a linha da análise continua');
        self::assertStringNotContainsString(self::SIGILOSO, (string) $client->getResponse()->getContent());
    }

    #[TestDox('drawer: linha de outro escritório (mesmo id de análise), de pasta irmã ou de outra ação não aparece nem soma no N')]
    public function testDrawerIsolamento(): void
    {
        $client = $this->cliente();
        [$user, $tenant, $pasta, $analise] = $this->cenario($client);
        [, $tenantB] = $this->criarAdmin();
        [$irma] = $this->criarPastaComPublicacao($tenant, '07099999999999999999');
        $analiseDaIrma = $this->criarAnaliseDoAgentePendente($tenant, $user, $irma, Agente::Gestor);
        $this->concluirAnalise($analiseDaIrma, 'Resumo.', [], null, 'Texto.');

        $this->registrar($client, $pasta, $analise, 'download');
        self::assertResponseStatusCodeSame(201);

        // Linha forjada com o tenant de OUTRO escritório apontando para a análise DAQUI.
        $this->gravarLinha($tenantB, $analise, RegistrarExportacaoDaAnaliseUseCase::ACAO_IMPRESSAO);
        // Linha deste escritório, mas da análise da pasta irmã.
        $this->gravarLinha($tenant, $analiseDaIrma, RegistrarExportacaoDaAnaliseUseCase::ACAO_DOWNLOAD);
        // Linha deste escritório e desta análise, mas de outra ação (não é download nem impressão).
        $this->gravarLinha($tenant, $analise, 'zip');

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/auditoria");

        self::assertResponseIsSuccessful();
        self::assertSame('Registro de auditoria (2)', trim($crawler->filter('.ia-audit-rotulo')->text()), '1 análise + 1 download desta pasta');
        $acoes = $crawler->filter('.ia-audit-linha[data-ia-audit-exportacao]')->each(static fn (Crawler $l): ?string => $l->attr('data-ia-audit-acao'));
        self::assertSame(['download'], $acoes);
        self::assertSame(0, $crawler->filter('[data-ia-audit-exportacao="' . $analiseDaIrma->getId() . '"]')->count());
    }

    private function gravarLinha(Tenant $tenant, AnaliseDeInteligencia $analise, string $acao): void
    {
        $log = (new AuditLog())
            ->setAction($acao)
            ->setEntityClass(AnaliseDeInteligencia::class)
            ->setEntityId((string) $analise->getId())
            ->setTenantId($tenant->getId())
            ->setChanges(['pasta_id' => $analise->getAlvoId(), 'agente' => 'gestor']);
        $this->em()->persist($log);
        $this->em()->flush();
    }
}
