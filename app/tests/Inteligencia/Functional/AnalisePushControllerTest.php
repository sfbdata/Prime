<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Entity\Audit\AuditLog;
use App\Inteligencia\Controller\AnalisePushController;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Message\ProcessarAnaliseDeInteligencia;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A BlueJus IA dentro da pasta, pela HTTP. O que mais importa: (a) sem provedor é 409 honesto e
 * nenhuma linha; (b) com provedor a cadeia enfileira UMA mensagem e grava `pendente`; (c) outro
 * escritório e outra pasta respondem 404, nunca 403; (d) a resposta do modelo sai ESCAPADA.
 */
#[CoversClass(AnalisePushController::class)]
final class AnalisePushControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private bool $csrfInstalado = false;

    /** Token CSRF previsível — mesma receita do `PastaChecklistModeloControllerTest`. */
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

    private function csrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        // O estado do ProvedorFalso, do limitador e do transport em memória precisa sobreviver
        // entre requisições: sem isto o KernelBrowser recria o container a cada request.
        $client->disableReboot();
        $this->instalarCsrfStorage();

        return $client;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function solicitar(KernelBrowser $client, int $pastaId, bool $comToken = true): void
    {
        $client->request(
            'POST',
            "/pasta/{$pastaId}/ia/push/analises",
            $comToken ? ['_token' => $this->csrf('inteligencia_push_' . $pastaId)] : [],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );
    }

    private function linhasDaPasta(int $pastaId): int
    {
        return $this->contarNoBanco('SELECT count(*) FROM inteligencia_analise WHERE alvo_id = :id', ['id' => $pastaId]);
    }

    // =========================================================================
    // Guardas
    // =========================================================================

    #[TestDox('POST sem token CSRF → 403 e nenhuma linha')]
    public function testSemCsrf(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitar($client, (int) $pasta->getId(), comToken: false);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('csrf', $this->json($client)['motivo']);
        self::assertSame(0, $this->linhasDaPasta((int) $pasta->getId()));
    }

    #[TestDox('usuário que vê a pasta mas não tem modules.inteligencia.view → 403')]
    public function testSemPermissaoDoModulo(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $admin);
        $user = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitar($client, (int) $pasta->getId());

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->linhasDaPasta((int) $pasta->getId()));
    }

    #[TestDox('pasta de OUTRO escritório → 404 (nunca 403)')]
    public function testPastaDeOutroTenant(): void
    {
        $client = $this->cliente();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        [$pastaB] = $this->criarPastaComPublicacao($tenantB);
        $this->ligarIaNoTenant($tenantA, $userA);
        $this->ligarIaNoTenant($tenantB, $userB);
        $this->logarComTenant($client, $userA, $tenantA);

        $this->solicitar($client, (int) $pastaB->getId());
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', "/pasta/{$pastaB->getId()}/ia/push/analises");
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('análise de outro escritório — e de outra pasta do MESMO escritório — por id → 404; a própria → 200')]
    public function testAnaliseDeOutroTenantOuPastaPorId(): void
    {
        $client = $this->cliente();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        [$pastaA1] = $this->criarPastaComPublicacao($tenantA);
        [$pastaA2] = $this->criarPastaComPublicacao($tenantA, '07099999999999999999');
        [$pastaB] = $this->criarPastaComPublicacao($tenantB);
        $analiseB = $this->criarAnalisePendente($tenantB, $userB, $pastaB);
        $analiseA2 = $this->criarAnalisePendente($tenantA, $userA, $pastaA2);
        $this->logarComTenant($client, $userA, $tenantA);

        // outro escritório
        $client->request('GET', "/pasta/{$pastaA1->getId()}/ia/analises/{$analiseB->getId()}");
        self::assertResponseStatusCodeSame(404);

        // mesmo escritório, pasta irmã: o id existe, mas não é desta pasta
        $client->request('GET', "/pasta/{$pastaA1->getId()}/ia/analises/{$analiseA2->getId()}");
        self::assertResponseStatusCodeSame(404);

        // o mesmo id, pela pasta certa — senão os 404 acima passariam por engano
        $client->request('GET', "/pasta/{$pastaA2->getId()}/ia/analises/{$analiseA2->getId()}");
        self::assertResponseStatusCodeSame(200);
        self::assertSame('pendente', $this->json($client)['status']);

        // e as ações por id também recusam a pasta irmã
        $client->request('POST', "/pasta/{$pastaA1->getId()}/ia/analises/{$analiseA2->getId()}/excluir", [
            '_token' => $this->csrf('inteligencia_analise_' . $analiseA2->getId()),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(404);
    }

    // =========================================================================
    // Disponibilidade
    // =========================================================================

    #[TestDox('provedor NÃO configurado → 409 nao_configurada_na_plataforma e zero linhas')]
    public function testProvedorNaoConfigurado(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->provedorFalso()->desconfigurar();
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitar($client, (int) $pasta->getId());

        self::assertResponseStatusCodeSame(409);
        $json = $this->json($client);
        self::assertSame('nao_configurada_na_plataforma', $json['motivo']);
        self::assertSame('IA não configurada nesta instalação', $json['mensagem']);
        self::assertSame(0, $this->linhasDaPasta((int) $pasta->getId()));
        self::assertCount(0, $this->transporteAsync()->getSent());
    }

    #[TestDox('escritório com a IA desligada → 409 desligada_no_escritorio')]
    public function testDesligadaNoEscritorio(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitar($client, (int) $pasta->getId());

        self::assertResponseStatusCodeSame(409);
        self::assertSame('desligada_no_escritorio', $this->json($client)['motivo']);
    }

    #[TestDox('limite diário atingido → 429 limite_atingido')]
    public function testLimiteDiario(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user, limiteDiario: 0);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitar($client, (int) $pasta->getId());

        self::assertResponseStatusCodeSame(429);
        self::assertSame('limite_atingido', $this->json($client)['motivo']);
        self::assertSame(0, $this->linhasDaPasta((int) $pasta->getId()));
    }

    #[TestDox('pasta sem movimentação → 409 sem_movimentacoes, nada persistido')]
    public function testSemMovimentacoes(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitar($client, (int) $pasta->getId());

        self::assertResponseStatusCodeSame(409);
        self::assertSame('sem_movimentacoes', $this->json($client)['motivo']);
        self::assertSame(0, $this->linhasDaPasta((int) $pasta->getId()));
    }

    // =========================================================================
    // Caminho feliz
    // =========================================================================

    #[TestDox('com provedor e IA ligada → 202, linha pendente e UMA mensagem no transport async')]
    public function testSolicitaComSucesso(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitar($client, (int) $pasta->getId());

        self::assertResponseStatusCodeSame(202);
        $json = $this->json($client);
        self::assertSame('pendente', $json['status']);
        self::assertTrue($json['emAndamento']);
        self::assertSame(1, $json['totalMovimentacoes']);

        $enviadas = $this->transporteAsync()->getSent();
        self::assertCount(1, $enviadas);
        $mensagem = $enviadas[0]->getMessage();
        self::assertInstanceOf(ProcessarAnaliseDeInteligencia::class, $mensagem);
        self::assertSame($json['id'], $mensagem->analiseId);
        self::assertSame($tenant->getId(), $mensagem->tenantId);

        self::assertSame(1, $this->contarNoBanco(
            "SELECT count(*) FROM inteligencia_analise WHERE id = :id AND status = 'pendente' AND tenant_id = :tenant AND solicitante_id = :user",
            ['id' => $json['id'], 'tenant' => $tenant->getId(), 'user' => $user->getId()],
        ));
        self::assertFalse($this->provedorFalso()->foiChamado(), 'a request não chama o provedor — isso é do worker');
    }

    #[TestDox('segundo pedido com análise pendente → 202 com a MESMA análise e nenhuma mensagem nova')]
    public function testIdempotenciaDoPedido(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitar($client, (int) $pasta->getId());
        $primeira = $this->json($client)['id'];
        self::assertCount(1, $this->transporteAsync()->getSent(), 'o primeiro pedido enfileira');

        // O services_resetter zera o transport em memória no boot da request seguinte: o que
        // `getSent()` mostra depois da 2ª request é SÓ o que a 2ª request enfileirou.
        $this->solicitar($client, (int) $pasta->getId());

        self::assertResponseStatusCodeSame(202);
        $json = $this->json($client);
        self::assertSame($primeira, $json['id']);
        self::assertNotNull($json['aviso']);
        self::assertCount(0, $this->transporteAsync()->getSent(), 'o segundo pedido NÃO enfileira mensagem nova');
        self::assertSame(1, $this->linhasDaPasta((int) $pasta->getId()));
    }

    #[TestDox('cota do limitador estourada → 429 muitas_solicitacoes antes do UseCase: nada persistido nem enfileirado')]
    public function testLimitadorEstourado(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        // O 429 real não é observável por HTTP em teste: `cache.rate_limiter` é ArrayAdapter e o
        // services_resetter o zera a cada request. O dublê (padrão do repo) simula a cota gasta.
        static::getContainer()->set('limiter.inteligencia_solicitar', new RateLimiterFactoryEspiao(aceita: false));
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitar($client, (int) $pasta->getId());

        self::assertResponseStatusCodeSame(429);
        self::assertSame('muitas_solicitacoes', $this->json($client)['motivo']);
        self::assertSame(0, $this->linhasDaPasta((int) $pasta->getId()));
        self::assertCount(0, $this->transporteAsync()->getSent());
    }

    #[TestDox('o limitador é por USUÁRIO e conta toda tentativa autorizada (inclusive a idempotente), mas não a que cai no CSRF')]
    public function testLimitadorContaPorUsuario(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $espiao = new RateLimiterFactoryEspiao(aceita: true);
        static::getContainer()->set('limiter.inteligencia_solicitar', $espiao);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitar($client, (int) $pasta->getId(), comToken: false);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $espiao->chavesConsumidas, 'pedido sem CSRF não gasta a cota de ninguém');

        $this->solicitar($client, (int) $pasta->getId());
        self::assertResponseStatusCodeSame(202);
        $this->solicitar($client, (int) $pasta->getId());
        self::assertResponseStatusCodeSame(202, 'a segunda devolve a pendente (idempotente)…');

        self::assertSame(
            [(string) $user->getId(), (string) $user->getId()],
            $espiao->chavesConsumidas,
            '…mas conta como tentativa: a chave é o id do usuário, não o IP, e cada POST autorizado consome',
        );
    }

    // =========================================================================
    // Lista, status e ações
    // =========================================================================

    #[TestDox('GET lista mostra o cartão com o selo e a resposta do modelo ESCAPADA')]
    public function testListaMostraCartaoEscapado(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta, ['pub:1']);
        $this->concluirAnalise($analise, 'Sentença <b>publicada</b>.', [['tipo' => 'prazo', 'texto' => 'Apelação em 15 dias.']], 'Dra. Ana');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/push/analises");

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Gerada por inteligência artificial', $html);
        self::assertStringContainsString('não é ato oficial do processo', $html);
        self::assertStringContainsString('Sentença &lt;b&gt;publicada&lt;/b&gt;.', $html, 'a resposta do modelo tem de sair escapada');
        self::assertStringNotContainsString('<b>publicada</b>', $html);
        self::assertSame(1, $crawler->filter('.ps-ia-lista > .ps-ia-cartao')->count());
        self::assertSame(1, $crawler->filter('.ps-ia-cartao .ps-ia-ponto--prazo')->count());
        self::assertStringContainsString('Dra. Ana', $html);
    }

    #[TestDox('status: falhou traz a mensagem amigável; o motivo técnico só para quem administra a IA')]
    public function testStatusDeFalha(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnalisePendente($tenant, $admin, $pasta);
        $analise->falhar('falha transitória: timeout do provedor');
        $this->em()->flush();

        // quem usa a IA mas não a administra
        $comum = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.inteligencia.view']);
        $this->logarComTenant($client, $comum, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/ia/analises/{$analise->getId()}");
        self::assertResponseIsSuccessful();
        $json = $this->json($client);
        self::assertSame('falhou', $json['status']);
        self::assertTrue($json['terminal']);
        self::assertSame('Não foi possível gerar a análise agora. Tente novamente.', $json['mensagem']);
        self::assertNull($json['motivoTecnico']);

        // o admin (bypass) vê o motivo técnico
        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/ia/analises/{$analise->getId()}");
        self::assertSame('falha transitória: timeout do provedor', $this->json($client)['motivoTecnico']);
    }

    #[TestDox('marcar como lida (XHR) → JSON lida=true e lida_em gravado')]
    public function testMarcarLida(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $this->concluirAnalise($analise);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/ia/analises/{$analise->getId()}/lida", [
            '_token' => $this->csrf('inteligencia_analise_' . $analise->getId()),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json($client)['lida']);
        self::assertSame(1, $this->contarNoBanco(
            'SELECT count(*) FROM inteligencia_analise WHERE id = :id AND lida_em IS NOT NULL AND lida_por_id = :user',
            ['id' => $analise->getId(), 'user' => $user->getId()],
        ));
    }

    #[TestDox('alternar interna (form comum) → redireciona para a pasta #push e grava o cadeado')]
    public function testAlternarInternaPorFormulario(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $this->concluirAnalise($analise);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/ia/analises/{$analise->getId()}/interna", [
            '_token' => $this->csrf('inteligencia_analise_' . $analise->getId()),
        ]);

        self::assertResponseRedirects("/pasta/{$pasta->getId()}#push");
        self::assertSame(1, $this->contarNoBanco(
            'SELECT count(*) FROM inteligencia_analise WHERE id = :id AND interna_do_escritorio = false',
            ['id' => $analise->getId()],
        ));
    }

    #[TestDox('excluir é soft: some da lista, continua no banco e deixa rastro no audit_log')]
    public function testExcluirSoft(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $this->concluirAnalise($analise);
        $analiseId = (int) $analise->getId();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/ia/analises/{$analiseId}/excluir", [
            '_token' => $this->csrf('inteligencia_analise_' . $analiseId),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json($client)['excluida']);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/push/analises");
        self::assertSame(0, $crawler->filter('.ps-ia-cartao')->count(), 'excluída não aparece na lista');

        self::assertSame(1, $this->contarNoBanco(
            'SELECT count(*) FROM inteligencia_analise WHERE id = :id AND excluida_em IS NOT NULL AND excluida_por_id = :user',
            ['id' => $analiseId, 'user' => $user->getId()],
        ), 'a linha continua no banco, marcada');

        $client->request('GET', "/pasta/{$pasta->getId()}/ia/analises/{$analiseId}");
        self::assertResponseStatusCodeSame(404, 'excluída responde como inexistente');

        $rastros = array_filter(
            $this->em()->getRepository(AuditLog::class)->findBy(['entityClass' => AnaliseDeInteligencia::class, 'action' => 'update']),
            static fn (AuditLog $log): bool => $log->getEntityId() === (string) $analiseId
                && str_contains((string) json_encode($log->getChanges()), 'excluidaEm'),
        );
        self::assertCount(1, $rastros, 'a exclusão suave tem de deixar exatamente um update com excluidaEm no audit_log');
    }
}
