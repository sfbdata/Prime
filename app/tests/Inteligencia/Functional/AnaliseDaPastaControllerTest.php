<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Inteligencia\Controller\AnaliseDaPastaController;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\Comando;
use App\Inteligencia\Message\ProcessarAnaliseDeInteligencia;
use App\Inteligencia\UseCase\ListarAnalisesDosComandosUseCase;
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
 * ESCAPADA nos fragmentos. Desde a F1 do drawer (spec inteligencia-drawer-da-pasta), o painel
 * mostra os comandos e relatórios, e as rotas `/ia/comandos/{comando}/analises` têm as mesmas
 * guardas, com a idempotência POR comando; as rotas por agente continuam funcionando.
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
        $this->liberarIaSemLigarNoTenant($tenant, $user);
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

    #[TestDox('limitador só gasta ao criar: com análise do comando em andamento, o clique volta 202 com ela mesmo com o limitador recusando')]
    public function testLimitadorNaoGastaSemCriar(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $pendente = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, comando: Comando::IdentificarRiscos);
        static::getContainer()->set('limiter.inteligencia_solicitar', new RateLimiterFactoryEspiao(aceita: false));
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitarComando($client, (int) $pasta->getId(), 'identificar_riscos');

        self::assertResponseStatusCodeSame(202);
        self::assertSame($pendente->getId(), $this->json($client)['id']);
    }

    #[TestDox('rota por agente não se prende à análise de um comando do mesmo agente')]
    public function testRotaDeAgenteIgnoraLinhaDeComando(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $doComando = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, comando: Comando::IdentificarRiscos);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitar($client, (int) $pasta->getId(), 'gestor');

        self::assertResponseStatusCodeSame(202);
        self::assertNotSame($doComando->getId(), $this->json($client)['id'], 'cria a do agente, não devolve a do comando');
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

    #[TestDox('GET painel: abas Análise (10 comandos) e Relatórios (5); a análise antiga do agente aparece no comando dele, com o nome do agente, o texto completo e a resposta ESCAPADA')]
    public function testPainel(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $gestor = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor);
        $this->concluirAnalise($gestor, 'Pasta <b>parada</b>.', [['tipo' => 'providencia', 'texto' => 'Protocolar réplica.']], 'Dra. Ana', "CONCLUSÃO\n• Pasta <i>parada</i>.\n\nNecessita de conferência do advogado.");
        $prazos = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Prazos);
        $riscos = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, comando: Comando::IdentificarRiscos);
        $this->concluirAnalise($riscos, 'Riscos identificados.');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/agentes");

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.ia-agente')->count(), 'os cartões de agente deram lugar aos comandos');
        self::assertSame(10, $crawler->filter('.ia-agentes > [data-ia-aba-painel="analise"] > .ia-comando')->count());
        self::assertSame(5, $crawler->filter('.ia-agentes > [data-ia-aba-painel="relatorios"] > .ia-comando')->count());
        self::assertSame('1', $crawler->filter('.ia-agentes')->attr('data-ia-carregado'));
        self::assertSame('1', $crawler->filter('.ia-agentes')->attr('data-ia-em-andamento'));

        $cartaoGestor = $crawler->filter('.ia-comando[data-ia-comando="analisar_pasta"] > .ia-ag-lista > .ia-cartao[data-ia-analise="' . $gestor->getId() . '"]');
        self::assertSame(1, $cartaoGestor->count(), 'a análise antiga do Gestor fica em "Analisar esta pasta" (doAgenteLegado)');
        self::assertSame('✦ Agente Gestor', trim($cartaoGestor->filter('.ia-selo')->text()));
        self::assertStringContainsString('não é ato oficial do processo', $cartaoGestor->filter('.ia-aviso')->text());
        self::assertSame(1, $cartaoGestor->filter('.ia-pontos > .ia-ponto--providencia')->count());
        self::assertSame(1, $cartaoGestor->filter('details.ia-completa > pre.ia-texto')->count());
        self::assertSame(0, $crawler->filter('.ia-comando[data-ia-comando="verificar_prazos"] .ia-cartao[data-ia-analise="' . $gestor->getId() . '"]')->count());
        self::assertSame(1, $crawler->filter('.ia-comando[data-ia-comando="verificar_prazos"] .ia-cartao[data-ia-status="pendente"] .ia-andamento')->count());
        self::assertStringContainsString('Analisando…', $crawler->filter('.ia-comando[data-ia-comando="verificar_prazos"] .ia-cta')->text());
        self::assertNotNull($crawler->filter('.ia-comando[data-ia-comando="verificar_prazos"] .ia-cta')->attr('disabled'));
        self::assertStringContainsString('Gerar nova análise', $crawler->filter('.ia-comando[data-ia-comando="analisar_pasta"] .ia-cta')->text());
        self::assertStringContainsString('Gerar análise', $crawler->filter('.ia-comando[data-ia-comando="analise_juridica"] .ia-cta')->text());
        self::assertStringContainsString('Gerar relatório da pasta', $crawler->filter('.ia-comando[data-ia-comando="relatorio_pasta"] .ia-cta')->text());

        // A análise do comando fica SÓ no comando, com o rótulo dele no selo.
        $cartaoRiscos = $crawler->filter('.ia-comando[data-ia-comando="identificar_riscos"] > .ia-ag-lista > .ia-cartao[data-ia-analise="' . $riscos->getId() . '"]');
        self::assertSame(1, $cartaoRiscos->count());
        self::assertSame('✦ Identificar riscos', trim($cartaoRiscos->filter('.ia-selo')->text()));
        self::assertSame(0, $crawler->filter('.ia-comando[data-ia-comando="analisar_pasta"] .ia-cartao[data-ia-analise="' . $riscos->getId() . '"]')->count());

        // Escolha inicial: o comando com análise em andamento aparece; o botão dele pulsa na grade.
        $botaoPrazos = $crawler->filter('.ia-comandos > button[data-ia-escolher="verificar_prazos"]');
        self::assertSame('true', $botaoPrazos->attr('aria-pressed'));
        self::assertStringContainsString('is-andamento', (string) $botaoPrazos->attr('class'));
        self::assertNull($crawler->filter('.ia-comando[data-ia-comando="verificar_prazos"]')->attr('hidden'));
        self::assertNotNull($crawler->filter('.ia-comando[data-ia-comando="analisar_pasta"]')->attr('hidden'));

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Pasta &lt;b&gt;parada&lt;/b&gt;.', $html, 'a resposta do modelo tem de sair escapada');
        self::assertStringContainsString('&lt;i&gt;parada&lt;/i&gt;', $html);
        self::assertStringNotContainsString('<b>parada</b>', $html);
        self::assertStringNotContainsString('<i>parada</i>', $html);
        self::assertStringContainsString('Dra. Ana', $html);
        self::assertSame(1, $cartaoGestor->filter('.ia-res-acoes > [data-ia-criar-meta]')->count());
        self::assertSame(1, $cartaoGestor->filter('form[data-ia-acao="interna"]')->count());
        self::assertSame($prazos->getId(), (int) $crawler->filter('.ia-comando[data-ia-comando="verificar_prazos"] .ia-cartao')->attr('data-ia-analise'));
    }

    #[TestDox('M3: o painel traz as N mais recentes de CADA comando; um comando com muitas análises não esconde os outros')]
    public function testPainelLimitaPorComando(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $limite = ListarAnalisesDosComandosUseCase::LIMITE_POR_COMANDO;
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
        self::assertSame($limite, $crawler->filter('.ia-comando[data-ia-comando="analisar_pasta"] .ia-cartao')->count());
        self::assertSame(1, $crawler->filter('.ia-comando[data-ia-comando="verificar_prazos"] .ia-cartao')->count(), 'Prazos aparece mesmo com o Gestor lotado');
        self::assertSame((string) $dePrazos->getId(), $crawler->filter('.ia-comando[data-ia-comando="verificar_prazos"] .ia-cartao')->attr('data-ia-analise'));
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
        self::assertSame(16, $crawler->filter('.ia-comando')->count(), '10 comandos, 5 relatórios e o Índice da aba Documentos');
        self::assertSame(0, $crawler->filter('.ia-cartao')->count());
        self::assertStringNotContainsString('Resumo do outro escritório.', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Gerar análise', $crawler->filter('.ia-comando[data-ia-comando="analisar_pasta"] .ia-cta')->text(), 'nem conta como análise desta pasta');
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

    // =========================================================================
    // Comandos do drawer (spec inteligencia-drawer-da-pasta, F1/F2)
    // =========================================================================

    private function solicitarComando(KernelBrowser $client, int $pastaId, string $comando, bool $comToken = true): void
    {
        $client->request(
            'POST',
            "/pasta/{$pastaId}/ia/comandos/{$comando}/analises",
            $comToken ? ['_token' => $this->csrf($pastaId)] : [],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );
    }

    private function linhasDoComando(int $pastaId, string $comando): int
    {
        return $this->contarNoBanco(
            "SELECT count(*) FROM inteligencia_analise WHERE alvo_id = :id AND tipo = 'analise_pasta' AND comando = :comando",
            ['id' => $pastaId, 'comando' => $comando],
        );
    }

    #[TestDox('comando: 202, linha pendente com o comando e o agente dele, UMA mensagem no async')]
    public function testComandoSolicitaComSucesso(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitarComando($client, (int) $pasta->getId(), 'identificar_riscos');

        self::assertResponseStatusCodeSame(202);
        $json = $this->json($client);
        self::assertSame('pendente', $json['status']);
        self::assertSame('gestor', $json['agente']);
        self::assertSame('identificar_riscos', $json['comando']);
        self::assertCount(1, $this->transporteAsync()->getSent());
        self::assertSame(1, $this->contarNoBanco(
            "SELECT count(*) FROM inteligencia_analise WHERE id = :id AND status = 'pendente' AND tipo = 'analise_pasta' AND agente = 'gestor' AND comando = 'identificar_riscos' AND tenant_id = :tenant AND solicitante_id = :user AND contexto_resumo->>'comando' = 'identificar_riscos'",
            ['id' => $json['id'], 'tenant' => $tenant->getId(), 'user' => $user->getId()],
        ));
        self::assertFalse($this->provedorFalso()->foiChamado());
    }

    #[TestDox('comando: relatório pelo mesmo caminho (Relatório de prazos pelo Agente Prazos)')]
    public function testRelatorioSolicitaComSucesso(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitarComando($client, (int) $pasta->getId(), 'relatorio_prazos');

        self::assertResponseStatusCodeSame(202);
        self::assertSame('prazos', $this->json($client)['agente']);
        self::assertSame(1, $this->linhasDoComando((int) $pasta->getId(), 'relatorio_prazos'));
    }

    #[TestDox('comando inexistente (inclusive o "Relatório do cliente", D12) → 404 no POST e no GET, nada persistido')]
    public function testComandoInexistente(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        foreach (['relatorio_cliente', 'gestor', 'chefe'] as $comando) {
            $this->solicitarComando($client, (int) $pasta->getId(), $comando);
            self::assertResponseStatusCodeSame(404, $comando);

            $client->request('GET', "/pasta/{$pasta->getId()}/ia/comandos/{$comando}/analises");
            self::assertResponseStatusCodeSame(404, $comando);
        }
        self::assertSame(0, $this->linhasDaPasta((int) $pasta->getId()));
        self::assertCount(0, $this->transporteAsync()->getSent());
    }

    #[TestDox('comando sem token CSRF → 403 e nenhuma linha')]
    public function testComandoSemCsrf(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitarComando($client, (int) $pasta->getId(), 'analisar_pasta', comToken: false);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('csrf', $this->json($client)['motivo']);
        self::assertSame(0, $this->linhasDaPasta((int) $pasta->getId()));
    }

    #[TestDox('comando: quem vê a pasta mas não tem modules.inteligencia.view → 403 no POST e no GET')]
    public function testComandoSemPermissao(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $admin);
        $user = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitarComando($client, (int) $pasta->getId(), 'analisar_pasta');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', "/pasta/{$pasta->getId()}/ia/comandos/analisar_pasta/analises");
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->linhasDaPasta((int) $pasta->getId()));
    }

    #[TestDox('comando em pasta de OUTRO escritório → 404 (nunca 403) no POST e no GET')]
    public function testComandoPastaDeOutroTenant(): void
    {
        $client = $this->cliente();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        [$pastaB] = $this->criarPastaComPublicacao($tenantB);
        $this->ligarIaNoTenant($tenantA, $userA);
        $this->ligarIaNoTenant($tenantB, $userB);
        $this->logarComTenant($client, $userA, $tenantA);

        $this->solicitarComando($client, (int) $pastaB->getId(), 'analisar_pasta');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', "/pasta/{$pastaB->getId()}/ia/comandos/analisar_pasta/analises");
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->linhasDaPasta((int) $pastaB->getId()));
    }

    #[TestDox('comando: pasta sem dados para o agente do comando → 409 sem_dados, nada persistido')]
    public function testComandoSemDados(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitarComando($client, (int) $pasta->getId(), 'analisar_pasta');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('sem_dados', $this->json($client)['motivo']);
        self::assertSame(0, $this->linhasDaPasta((int) $pasta->getId()));
    }

    #[TestDox('idempotência POR comando: o 2º pedido do mesmo comando devolve o mesmo (202, aviso com o rótulo); outro comando do mesmo agente abre outra')]
    public function testIdempotenciaPorComando(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitarComando($client, (int) $pasta->getId(), 'identificar_riscos');
        $primeira = $this->json($client)['id'];

        $this->solicitarComando($client, (int) $pasta->getId(), 'identificar_riscos');
        self::assertResponseStatusCodeSame(202);
        self::assertSame($primeira, $this->json($client)['id']);
        self::assertSame('Já existe uma análise "Identificar riscos" em andamento.', $this->json($client)['aviso']);
        self::assertCount(0, $this->transporteAsync()->getSent(), 'o segundo pedido do mesmo comando NÃO enfileira');

        // Mesmo agente (Gestor), outro comando: não trava.
        $this->solicitarComando($client, (int) $pasta->getId(), 'analisar_pasta');
        self::assertResponseStatusCodeSame(202);
        self::assertNotSame($primeira, $this->json($client)['id']);
        self::assertSame('analisar_pasta', $this->json($client)['comando']);
        self::assertCount(1, $this->transporteAsync()->getSent());

        self::assertSame(1, $this->linhasDoComando((int) $pasta->getId(), 'identificar_riscos'));
        self::assertSame(1, $this->linhasDoComando((int) $pasta->getId(), 'analisar_pasta'));
    }

    #[TestDox('as rotas por agente continuam funcionando ao lado das de comando (linha sem comando)')]
    public function testRotaPorAgenteContinua(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitar($client, (int) $pasta->getId(), 'prazos');

        self::assertResponseStatusCodeSame(202);
        self::assertNull($this->json($client)['comando']);
        self::assertSame(1, $this->contarNoBanco(
            "SELECT count(*) FROM inteligencia_analise WHERE id = :id AND agente = 'prazos' AND comando IS NULL",
            ['id' => $this->json($client)['id']],
        ));
    }

    #[TestDox('GET lista de UM comando: as do comando e as antigas do agente legado; nem as de outro comando, nem as excluídas, nem as de outro escritório')]
    public function testListaDoComando(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $antiga = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor);
        $this->concluirAnalise($antiga, 'Antiga do gestor.');
        $doComando = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, comando: Comando::AnalisarPasta);
        $this->concluirAnalise($doComando, 'Do comando.');
        $deOutroComando = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, comando: Comando::IdentificarRiscos);
        $this->concluirAnalise($deOutroComando, 'De outro comando.');
        $excluida = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, comando: Comando::AnalisarPasta);
        $this->concluirAnalise($excluida, 'Excluída.');
        $excluida->excluir($user);
        $alheia = $this->criarAnaliseDoAgentePendente($tenantB, $userB, $pasta, comando: Comando::AnalisarPasta);
        $this->concluirAnalise($alheia, 'Do outro escritório.');
        $this->em()->flush();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/comandos/analisar_pasta/analises");

        self::assertResponseIsSuccessful();
        $lista = $crawler->filter('.ia-ag-lista');
        self::assertSame('analisar_pasta', $lista->attr('data-ia-lista'));
        self::assertSame("/pasta/{$pasta->getId()}/ia/comandos/analisar_pasta/analises", $lista->attr('data-ia-url'));
        self::assertSame('1', $lista->attr('data-ia-tem-concluida'));
        self::assertSame(
            [(string) $doComando->getId(), (string) $antiga->getId()],
            $crawler->filter('.ia-ag-lista > .ia-cartao')->each(static fn ($c): ?string => $c->attr('data-ia-analise')),
        );
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('De outro comando.', $html);
        self::assertStringNotContainsString('Excluída.', $html);
        self::assertStringNotContainsString('Do outro escritório.', $html);
    }

    // =========================================================================
    // Índice inteligente da aba Documentos (spec inteligencia-drawer-da-pasta, F4)
    // =========================================================================

    /** @param list<int|string> $documentos */
    private function solicitarIndice(KernelBrowser $client, int $pastaId, array $documentos): void
    {
        $client->request(
            'POST',
            "/pasta/{$pastaId}/ia/comandos/indice_documentos/analises",
            ['_token' => $this->csrf($pastaId), 'documentos' => $documentos],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );
    }

    #[TestDox('Índice: 202 com a seleção; a linha é do Documental, com o comando e os ids ORDENADOS em contexto_resumo.documentosSelecionados')]
    public function testIndiceComSelecao(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $a = $this->criarDocumento($tenant, $pasta, 'Procuracao');
        $b = $this->criarDocumento($tenant, $pasta, 'Peticao inicial');
        $this->criarDocumento($tenant, $pasta, 'Nao marcado');
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitarIndice($client, (int) $pasta->getId(), [(string) $b->getId(), (string) $a->getId(), (string) $b->getId()]);

        self::assertResponseStatusCodeSame(202);
        $json = $this->json($client);
        self::assertSame('indice_documentos', $json['comando']);
        self::assertSame('documental', $json['agente']);
        $resumo = json_decode((string) $this->em()->getConnection()->fetchOne('SELECT contexto_resumo FROM inteligencia_analise WHERE id = :id', ['id' => $json['id']]), true);
        self::assertSame([(int) $a->getId(), (int) $b->getId()], $resumo['documentosSelecionados']);
        self::assertSame(2, $resumo['secoes']['documentos'], 'só os marcados entram na seção');
        self::assertCount(1, $this->transporteAsync()->getSent());
    }

    #[TestDox('Índice: "nada novo" vale POR seleção: a mesma seleção depois de concluída volta 200; outra seleção abre outra análise (202)')]
    public function testIndiceNadaNovoPorSelecao(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $a = $this->criarDocumento($tenant, $pasta, 'Procuracao');
        $b = $this->criarDocumento($tenant, $pasta, 'Peticao inicial');
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitarIndice($client, (int) $pasta->getId(), [(int) $a->getId()]);
        self::assertResponseStatusCodeSame(202);
        $primeira = $this->em()->getRepository(\App\Inteligencia\Entity\AnaliseDeInteligencia::class)->find($this->json($client)['id']);
        self::assertNotNull($primeira);
        $this->concluirAnalise($primeira, 'Índice de um documento.');

        $this->solicitarIndice($client, (int) $pasta->getId(), [(int) $a->getId()]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($primeira->getId(), $this->json($client)['id']);
        self::assertSame('Nada novo desde a última análise "Índice inteligente".', $this->json($client)['aviso']);

        $this->solicitarIndice($client, (int) $pasta->getId(), [(int) $a->getId(), (int) $b->getId()]);
        self::assertResponseStatusCodeSame(202);
        self::assertNotSame($primeira->getId(), $this->json($client)['id']);
        self::assertSame(2, $this->linhasDoComando((int) $pasta->getId(), 'indice_documentos'));
    }

    #[TestDox('Índice sem documento marcado → 422 selecao_invalida, nada persistido, e o limitador NÃO é gasto (recusando, seria 429)')]
    public function testIndiceSemSelecao(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->criarDocumento($tenant, $pasta, 'Procuracao');
        static::getContainer()->set('limiter.inteligencia_solicitar', new RateLimiterFactoryEspiao(aceita: false));
        $this->logarComTenant($client, $user, $tenant);

        $this->solicitarIndice($client, (int) $pasta->getId(), []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('selecao_invalida', $this->json($client)['motivo']);
        self::assertSame('Marque ao menos um documento para montar o índice.', $this->json($client)['mensagem']);
        self::assertSame(0, $this->linhasDaPasta((int) $pasta->getId()));
        self::assertCount(0, $this->transporteAsync()->getSent());
    }

    #[TestDox('Índice com id de documento de OUTRA pasta do escritório, de OUTRO escritório ou que não é número → 422, nada persistido')]
    public function testIndiceComIdForaDaPasta(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [, $tenantB] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        [$outraPasta] = $this->criarPastaComPublicacao($tenant, '07099995520258070001');
        [$pastaB] = $this->criarPastaComPublicacao($tenantB);
        $this->ligarIaNoTenant($tenant, $user);
        $meu = $this->criarDocumento($tenant, $pasta, 'Procuracao');
        $daOutraPasta = $this->criarDocumento($tenant, $outraPasta, 'Da outra pasta');
        $deOutroEscritorio = $this->criarDocumento($tenantB, $pastaB, 'Do outro escritorio');
        $this->logarComTenant($client, $user, $tenant);

        foreach ([(int) $daOutraPasta->getId(), (int) $deOutroEscritorio->getId(), 'abc'] as $intruso) {
            $this->solicitarIndice($client, (int) $pasta->getId(), [(int) $meu->getId(), $intruso]);

            self::assertResponseStatusCodeSame(422, 'id ' . $intruso);
            self::assertSame('selecao_invalida', $this->json($client)['motivo']);
            self::assertStringNotContainsString((string) $intruso, $this->json($client)['mensagem'], 'não confirma que o id existe');
        }
        self::assertSame(0, $this->linhasDaPasta((int) $pasta->getId()));
        self::assertCount(0, $this->transporteAsync()->getSent());
    }

    #[TestDox('Índice de quem tem a aba Documentos da pasta oculta → 422 (a lista dele é vazia) e o painel não traz a aba')]
    public function testIndiceComAbaDocumentosOculta(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $admin);
        $documento = $this->criarDocumento($tenant, $pasta, 'Procuracao');
        $comum = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.inteligencia.view']);
        $this->ocultarAbaParaTodos($pasta, \App\Pasta\Entity\AbaDaPasta::Documentos, $admin);
        $this->logarComTenant($client, $comum, $tenant);

        $this->solicitarIndice($client, (int) $pasta->getId(), [(int) $documento->getId()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->linhasDaPasta((int) $pasta->getId()));

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/agentes");
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('[data-ia-aba-painel="documentos"]')->count());
        self::assertSame(0, $crawler->filter('[data-ia-doc]')->count());
        self::assertStringNotContainsString('procuracao.pdf', (string) $client->getResponse()->getContent());
    }

    #[TestDox('nos outros comandos `documentos[]` é ignorado: nem valida, nem recorta, nem entra no resumo')]
    public function testOutroComandoIgnoraDocumentos(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/ia/comandos/identificar_riscos/analises",
            ['_token' => $this->csrf((int) $pasta->getId()), 'documentos' => ['999999']],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        self::assertResponseStatusCodeSame(202);
        self::assertSame(0, $this->contarNoBanco(
            "SELECT count(*) FROM inteligencia_analise WHERE id = :id AND contexto_resumo->'documentosSelecionados' IS NOT NULL",
            ['id' => $this->json($client)['id']],
        ));
    }
}
