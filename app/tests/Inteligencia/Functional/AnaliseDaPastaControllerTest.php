<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Inteligencia\Controller\AnaliseDaPastaController;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Message\ProcessarAnaliseDeInteligencia;
use App\Inteligencia\UseCase\ListarAnalisesDosAgentesUseCase;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Os agentes da BlueJus IA na pasta, pela HTTP (spec inteligencia-agentes-da-pasta §4 e §6).
 * O que mais importa: (a) sem provedor é 409 honesto e nenhuma linha; (b) com provedor a cadeia
 * enfileira UMA mensagem e grava `pendente` com o `agente`; (c) outro escritório e agente
 * desconhecido respondem 404; (d) a idempotência é POR agente; (e) a resposta do modelo sai
 * ESCAPADA nos fragmentos.
 */
#[CoversClass(AnaliseDaPastaController::class)]
final class AnaliseDaPastaControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private bool $csrfInstalado = false;

    /** Token CSRF previsível — mesma receita do `AnalisePushControllerTest`. */
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

    private function csrf(int $pastaId): string
    {
        return 'TOKEN_inteligencia_agentes_' . $pastaId;
    }

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        return $client;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function solicitar(KernelBrowser $client, int $pastaId, string $agente = 'gestor', bool $comToken = true): void
    {
        $client->request(
            'POST',
            "/pasta/{$pastaId}/ia/agentes/{$agente}/analises",
            $comToken ? ['_token' => $this->csrf($pastaId)] : [],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );
    }

    private function linhasDaPasta(int $pastaId, ?string $agente = null): int
    {
        if ($agente === null) {
            return $this->contarNoBanco('SELECT count(*) FROM inteligencia_analise WHERE alvo_id = :id', ['id' => $pastaId]);
        }

        return $this->contarNoBanco(
            "SELECT count(*) FROM inteligencia_analise WHERE alvo_id = :id AND tipo = 'analise_pasta' AND agente = :agente",
            ['id' => $pastaId, 'agente' => $agente],
        );
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

    #[TestDox('usuário que vê a pasta mas não tem modules.inteligencia.view → 403 no POST e no painel')]
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

        $client->request('GET', "/pasta/{$pasta->getId()}/ia/agentes");
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('pasta de OUTRO escritório → 404 (nunca 403) no POST, no painel e na lista do agente')]
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

        $client->request('GET', "/pasta/{$pastaB->getId()}/ia/agentes");
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', "/pasta/{$pastaB->getId()}/ia/agentes/gestor/analises");
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->linhasDaPasta((int) $pastaB->getId()));
    }

    #[TestDox('agente desconhecido na URL → 404, nada persistido')]
    public function testAgenteDesconhecido(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitar($client, (int) $pasta->getId(), 'chefe');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', "/pasta/{$pasta->getId()}/ia/agentes/chefe/analises");
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->linhasDaPasta((int) $pasta->getId()));
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

    #[TestDox('cota do limitador estourada → 429 muitas_solicitacoes antes do UseCase (mesmo limitador do Push)')]
    public function testLimitadorEstourado(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        static::getContainer()->set('limiter.inteligencia_solicitar', new RateLimiterFactoryEspiao(aceita: false));
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitar($client, (int) $pasta->getId());

        self::assertResponseStatusCodeSame(429);
        self::assertSame('muitas_solicitacoes', $this->json($client)['motivo']);
        self::assertSame(0, $this->linhasDaPasta((int) $pasta->getId()));
    }

    #[TestDox('agente sem dado nenhum na pasta (Documental sem documentos) → 409 sem_dados, nada persistido')]
    public function testSemDadosParaOAgente(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitar($client, (int) $pasta->getId(), 'documental');

        self::assertResponseStatusCodeSame(409);
        $json = $this->json($client);
        self::assertSame('sem_dados', $json['motivo']);
        self::assertStringContainsString('este agente', $json['mensagem']);
        self::assertSame(0, $this->linhasDaPasta((int) $pasta->getId()));
    }

    #[TestDox('M1: pasta recém-criada (sem processo, cliente, meta ou dado financeiro) → 409 sem_dados para Gestor, Relatórios e Cliente')]
    public function testPastaVaziaEhSemDadosParaQuemLeFinanceiro(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        foreach (['gestor', 'relatorios', 'cliente'] as $agente) {
            $this->solicitar($client, (int) $pasta->getId(), $agente);

            self::assertResponseStatusCodeSame(409, $agente);
            self::assertSame('sem_dados', $this->json($client)['motivo'], $agente);
        }
        self::assertSame(0, $this->linhasDaPasta((int) $pasta->getId()));
    }

    #[TestDox('processo em segredo de justiça → 409 contexto_bloqueado, nada persistido')]
    public function testSigilo(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->criarPastaComPublicacao($tenant);
        $processo->setNivelSigilo(1);
        $this->em()->flush();
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitar($client, (int) $pasta->getId(), 'prazos');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('contexto_bloqueado', $this->json($client)['motivo']);
        self::assertSame(0, $this->linhasDaPasta((int) $pasta->getId()));
    }

    // =========================================================================
    // Caminho feliz e idempotência por agente
    // =========================================================================

    #[TestDox('com provedor e IA ligada → 202, linha pendente com agente e financeiro gravados, UMA mensagem no async')]
    public function testSolicitaComSucesso(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitar($client, (int) $pasta->getId(), 'gestor');

        self::assertResponseStatusCodeSame(202);
        $json = $this->json($client);
        self::assertSame('pendente', $json['status']);
        self::assertSame('gestor', $json['agente']);
        self::assertTrue($json['emAndamento']);

        $enviadas = $this->transporteAsync()->getSent();
        self::assertCount(1, $enviadas);
        $mensagem = $enviadas[0]->getMessage();
        self::assertInstanceOf(ProcessarAnaliseDeInteligencia::class, $mensagem);
        self::assertSame($json['id'], $mensagem->analiseId);
        self::assertSame($tenant->getId(), $mensagem->tenantId);

        self::assertSame(1, $this->contarNoBanco(
            "SELECT count(*) FROM inteligencia_analise WHERE id = :id AND status = 'pendente' AND tipo = 'analise_pasta' AND agente = 'gestor' AND versao_do_prompt = 'agente-v1' AND tenant_id = :tenant AND solicitante_id = :user AND (contexto_resumo->>'financeiro')::boolean = true",
            ['id' => $json['id'], 'tenant' => $tenant->getId(), 'user' => $user->getId()],
        ));
        self::assertFalse($this->provedorFalso()->foiChamado(), 'a request não chama o provedor — isso é do worker');
    }

    #[TestDox('idempotência POR agente: 2º pedido do Gestor devolve a mesma; pedido de Prazos abre outra')]
    public function testIdempotenciaPorAgente(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitar($client, (int) $pasta->getId(), 'gestor');
        $primeira = $this->json($client)['id'];

        $this->solicitar($client, (int) $pasta->getId(), 'gestor');
        self::assertResponseStatusCodeSame(202);
        self::assertSame($primeira, $this->json($client)['id']);
        self::assertNotNull($this->json($client)['aviso']);
        self::assertCount(0, $this->transporteAsync()->getSent(), 'o segundo pedido do mesmo agente NÃO enfileira');

        $this->solicitar($client, (int) $pasta->getId(), 'prazos');
        self::assertResponseStatusCodeSame(202);
        self::assertNotSame($primeira, $this->json($client)['id']);
        self::assertSame('prazos', $this->json($client)['agente']);
        self::assertCount(1, $this->transporteAsync()->getSent());

        self::assertSame(1, $this->linhasDaPasta((int) $pasta->getId(), 'gestor'));
        self::assertSame(1, $this->linhasDaPasta((int) $pasta->getId(), 'prazos'));
    }

    #[TestDox('uma análise pendente de agente NÃO trava o "Resumir com IA" do Push (tipos separados)')]
    public function testAgentePendenteNaoTravaOPush(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/ia/push/analises", [
            '_token' => 'TOKEN_inteligencia_push_' . $pasta->getId(),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(202);
        self::assertNull($this->json($client)['aviso'], 'abriu uma análise nova do Push, não devolveu a do agente');
        self::assertSame('resumo_push', $this->contarTipo((int) $this->json($client)['id']));
    }

    private function contarTipo(int $id): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT tipo FROM inteligencia_analise WHERE id = :id', ['id' => $id]);
    }

    // =========================================================================
    // Fragmentos
    // =========================================================================

    #[TestDox('GET painel: os sete agentes, cada um com a sua lista; o cartão traz o nome do agente, o texto completo e a resposta ESCAPADA')]
    public function testPainel(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $gestor = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor);
        $this->concluirAnalise($gestor, 'Pasta <b>parada</b>.', [['tipo' => 'providencia', 'texto' => 'Protocolar réplica.']], 'Dra. Ana', "CONCLUSÃO\n• Pasta <i>parada</i>.\n\nNecessita de conferência do advogado.");
        $prazos = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Prazos);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/agentes");

        self::assertResponseIsSuccessful();
        self::assertSame(7, $crawler->filter('.ia-agentes > .ia-agente')->count());
        self::assertSame('1', $crawler->filter('.ia-agentes')->attr('data-ia-carregado'));
        self::assertSame('1', $crawler->filter('.ia-agentes')->attr('data-ia-em-andamento'));

        $cartaoGestor = $crawler->filter('.ia-agente[data-ia-agente="gestor"] > .ia-ag-lista > .ia-cartao[data-ia-analise="' . $gestor->getId() . '"]');
        self::assertSame(1, $cartaoGestor->count(), 'a análise do Gestor fica na seção do Gestor');
        self::assertSame('✦ Agente Gestor', trim($cartaoGestor->filter('.ia-selo')->text()));
        self::assertStringContainsString('não é ato oficial do processo', $cartaoGestor->filter('.ia-aviso')->text());
        self::assertSame(1, $cartaoGestor->filter('.ia-pontos > .ia-ponto--providencia')->count());
        self::assertSame(1, $cartaoGestor->filter('details.ia-completa > pre.ia-texto')->count());
        self::assertSame(0, $crawler->filter('.ia-agente[data-ia-agente="prazos"] .ia-cartao[data-ia-analise="' . $gestor->getId() . '"]')->count());
        self::assertSame(1, $crawler->filter('.ia-agente[data-ia-agente="prazos"] .ia-cartao[data-ia-status="pendente"] .ia-andamento')->count());
        self::assertStringContainsString('Analisando…', $crawler->filter('.ia-agente[data-ia-agente="prazos"] .ia-cta')->text());
        self::assertNotNull($crawler->filter('.ia-agente[data-ia-agente="prazos"] .ia-cta')->attr('disabled'));
        self::assertStringContainsString('Gerar nova análise', $crawler->filter('.ia-agente[data-ia-agente="gestor"] .ia-cta')->text());
        self::assertStringContainsString('Gerar análise', $crawler->filter('.ia-agente[data-ia-agente="juridico"] .ia-cta')->text());

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Pasta &lt;b&gt;parada&lt;/b&gt;.', $html, 'a resposta do modelo tem de sair escapada');
        self::assertStringContainsString('&lt;i&gt;parada&lt;/i&gt;', $html);
        self::assertStringNotContainsString('<b>parada</b>', $html);
        self::assertStringNotContainsString('<i>parada</i>', $html);
        self::assertStringContainsString('Dra. Ana', $html);
        self::assertSame(1, $cartaoGestor->filter('.ia-menu [data-ia-criar-meta]')->count());
        self::assertSame(1, $cartaoGestor->filter('form[data-ia-acao="interna"]')->count());
        self::assertSame($prazos->getId(), (int) $crawler->filter('.ia-agente[data-ia-agente="prazos"] .ia-cartao')->attr('data-ia-analise'));
    }

    #[TestDox('M3: o painel traz as N mais recentes de CADA agente — um agente com muitas análises não esconde os outros')]
    public function testPainelLimitaPorAgente(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $limite = ListarAnalisesDosAgentesUseCase::LIMITE_POR_AGENTE;
        $doGestor = [];
        for ($i = 0; $i < $limite + 3; ++$i) {
            $a = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor);
            $this->concluirAnalise($a, 'Gestor ' . $i);
            $doGestor[] = $a;
        }
        $dePrazos = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Prazos);
        $this->concluirAnalise($dePrazos, 'Prazos.');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/agentes");

        self::assertResponseIsSuccessful();
        self::assertSame($limite, $crawler->filter('.ia-agente[data-ia-agente="gestor"] .ia-cartao')->count());
        self::assertSame(1, $crawler->filter('.ia-agente[data-ia-agente="prazos"] .ia-cartao')->count(), 'Prazos aparece mesmo com o Gestor lotado');
        self::assertSame((string) $dePrazos->getId(), $crawler->filter('.ia-agente[data-ia-agente="prazos"] .ia-cartao')->attr('data-ia-analise'));
        // As mais recentes ficam; a mais antiga do Gestor é a que sai.
        self::assertSame(0, $crawler->filter('.ia-cartao[data-ia-analise="' . $doGestor[0]->getId() . '"]')->count());
        self::assertSame(1, $crawler->filter('.ia-cartao[data-ia-analise="' . $doGestor[$limite + 2]->getId() . '"]')->count());
    }

    #[TestDox('M4: a lista do Push (inteligencia_push_listar) NÃO mostra análise de agente')]
    public function testListaDoPushNaoMostraAnaliseDeAgente(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $doAgente = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor);
        $this->concluirAnalise($doAgente, 'Resumo do Agente Gestor.');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/push/analises");

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.ps-ia-cartao')->count());
        self::assertSame('0', $crawler->filter('.ps-ia-lista')->attr('data-ia-tem-concluida'));
        self::assertStringNotContainsString('Resumo do Agente Gestor.', (string) $client->getResponse()->getContent());
        self::assertSame(1, $this->linhasDaPasta((int) $pasta->getId(), 'gestor'), 'a análise existe, só não é do Push');
    }

    #[TestDox('GET lista de UM agente só traz as dele, e a excluída some')]
    public function testListaDoAgente(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $gestor = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor);
        $this->concluirAnalise($gestor, 'Do gestor.');
        $excluida = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor);
        $this->concluirAnalise($excluida, 'Excluída.');
        $excluida->excluir($user);
        $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Cliente);
        $this->em()->flush();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/agentes/gestor/analises");

        self::assertResponseIsSuccessful();
        self::assertSame('gestor', $crawler->filter('.ia-ag-lista')->attr('data-ia-lista'));
        self::assertSame(1, $crawler->filter('.ia-ag-lista > .ia-cartao')->count());
        self::assertSame((string) $gestor->getId(), $crawler->filter('.ia-cartao')->attr('data-ia-analise'));
        self::assertSame('1', $crawler->filter('.ia-ag-lista')->attr('data-ia-tem-concluida'));
        self::assertStringNotContainsString('Excluída.', (string) $client->getResponse()->getContent());
    }

    #[TestDox('análise de agente de OUTRO escritório apontando para o id desta pasta não aparece no painel')]
    public function testPainelNaoVazaOutroTenant(): void
    {
        $client = $this->cliente();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        [$pastaA] = $this->criarPastaComPublicacao($tenantA);
        $this->ligarIaNoTenant($tenantA, $userA);
        // Recurso irmão: mesmo alvo e mesmo agente, só que do escritório B.
        $alheia = $this->criarAnaliseDoAgentePendente($tenantB, $userB, $pastaA, Agente::Gestor);
        $this->concluirAnalise($alheia, 'Resumo do outro escritório.');
        $this->logarComTenant($client, $userA, $tenantA);

        $crawler = $client->request('GET', "/pasta/{$pastaA->getId()}/ia/agentes");

        self::assertResponseIsSuccessful();
        self::assertSame(7, $crawler->filter('.ia-agente')->count());
        self::assertSame(0, $crawler->filter('.ia-cartao')->count());
        self::assertStringNotContainsString('Resumo do outro escritório.', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Gerar análise', $crawler->filter('.ia-agente[data-ia-agente="gestor"] .ia-cta')->text(), 'nem conta como análise desta pasta');
    }

    #[TestDox('status, marcar lida e excluir por id valem para a análise de agente (rotas da fatia 1)')]
    public function testAcoesPorIdDaFatia1(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Juridico);
        $this->concluirAnalise($analise, 'Tese.');
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', "/pasta/{$pasta->getId()}/ia/analises/{$analise->getId()}");
        self::assertResponseIsSuccessful();
        self::assertSame('concluida', $this->json($client)['status']);
        self::assertSame('juridico', $this->json($client)['agente']);

        $client->request('POST', "/pasta/{$pasta->getId()}/ia/analises/{$analise->getId()}/lida", [
            '_token' => 'TOKEN_inteligencia_analise_' . $analise->getId(),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json($client)['lida']);

        $client->request('POST', "/pasta/{$pasta->getId()}/ia/analises/{$analise->getId()}/excluir", [
            '_token' => 'TOKEN_inteligencia_analise_' . $analise->getId(),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/agentes/juridico/analises");
        self::assertSame(0, $crawler->filter('.ia-cartao')->count());
        self::assertSame(1, $this->linhasDaPasta((int) $pasta->getId(), 'juridico'), 'soft delete: a linha fica');
    }
}
