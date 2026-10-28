<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Inteligencia\Controller\ConversaDaPastaController;
use App\Inteligencia\Message\ProcessarAnaliseDeInteligencia;
use App\Inteligencia\UseCase\ListarConversaDaPastaUseCase;
use App\Inteligencia\UseCase\PerguntarSobreAPastaUseCase;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A aba "Perguntar" do drawer, pela HTTP (spec inteligencia-drawer-da-pasta, F3). O que mais importa:
 * (a) a pergunta vira UMA análise `pergunta_livre` pendente + a linha da pergunta + UMA mensagem;
 * (b) texto vazio ou longo é 422 sem gastar o limitador; (c) a segunda pergunta com uma em andamento
 * devolve a mesma, sem criar; (d) outra pasta/escritório é 404 e sem módulo é 403; (e) a conversa é
 * da pessoa: a de outra pessoa não aparece nem pela lista nem pelas rotas por id; (f) a resposta do
 * modelo sai ESCAPADA.
 */
#[CoversClass(ConversaDaPastaController::class)]
#[CoversClass(PerguntarSobreAPastaUseCase::class)]
#[CoversClass(ListarConversaDaPastaUseCase::class)]
final class ConversaDaPastaControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private bool $csrfInstalado = false;

    /** Token CSRF previsível, mesma receita do `AnaliseDaPastaControllerTest`. */
    private function instalarCsrfStorage(): void
    {
        if ($this->csrfInstalado) {
            return;
        }
        $this->csrfInstalado = true;

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

    private function perguntar(KernelBrowser $client, int $pastaId, string $texto = 'O que falta fazer?', bool $comToken = true): void
    {
        $dados = ['texto' => $texto];
        if ($comToken) {
            $dados['_token'] = 'TOKEN_inteligencia_agentes_' . $pastaId;
        }
        $client->request('POST', "/pasta/{$pastaId}/ia/conversa/perguntas", $dados, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    private function perguntasDaPasta(int $pastaId): int
    {
        return $this->contarNoBanco('SELECT count(*) FROM inteligencia_pergunta WHERE pasta_id = :id', ['id' => $pastaId]);
    }

    private function analisesDaPasta(int $pastaId): int
    {
        return $this->contarNoBanco('SELECT count(*) FROM inteligencia_analise WHERE alvo_id = :id', ['id' => $pastaId]);
    }

    /** @return array{0: \App\Entity\Auth\User, 1: \App\Entity\Tenant\Tenant, 2: \App\Pasta\Entity\Pasta} */
    private function cenario(KernelBrowser $client): array
    {
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        return [$user, $tenant, $pasta];
    }

    // =========================================================================
    // Pedido
    // =========================================================================

    #[TestDox('pergunta válida → 202, análise pergunta_livre pendente do Gestor com financeiro gravado, pergunta aparada gravada e UMA mensagem no async')]
    public function testPerguntaComSucesso(): void
    {
        $client = $this->cliente();
        [$user, $tenant, $pasta] = $this->cenario($client);

        $this->perguntar($client, (int) $pasta->getId(), "   Esse processo está parado?  \n");

        self::assertResponseStatusCodeSame(202);
        $json = $this->json($client);
        self::assertSame('pendente', $json['status']);
        self::assertTrue($json['emAndamento']);
        self::assertSame('gestor', $json['agente']);
        self::assertNull($json['comando']);
        self::assertIsInt($json['pergunta']);

        self::assertSame(1, $this->contarNoBanco(
            "SELECT count(*) FROM inteligencia_analise WHERE id = :id AND tipo = 'pergunta_livre' AND status = 'pendente' AND agente = 'gestor' AND comando IS NULL AND versao_do_prompt = 'pergunta-v1' AND tenant_id = :tenant AND solicitante_id = :user AND (contexto_resumo->>'financeiro')::boolean = true",
            ['id' => $json['id'], 'tenant' => $tenant->getId(), 'user' => $user->getId()],
        ));
        self::assertSame(1, $this->contarNoBanco(
            'SELECT count(*) FROM inteligencia_pergunta WHERE id = :id AND analise_id = :analise AND tenant_id = :tenant AND autor_id = :user AND pasta_id = :pasta AND texto = :texto',
            ['id' => $json['pergunta'], 'analise' => $json['id'], 'tenant' => $tenant->getId(), 'user' => $user->getId(), 'pasta' => $pasta->getId(), 'texto' => 'Esse processo está parado?'],
        ));

        $enviadas = $this->transporteAsync()->getSent();
        self::assertCount(1, $enviadas);
        $mensagem = $enviadas[0]->getMessage();
        self::assertInstanceOf(ProcessarAnaliseDeInteligencia::class, $mensagem);
        self::assertSame($json['id'], $mensagem->analiseId);
        self::assertSame($tenant->getId(), $mensagem->tenantId);
        self::assertFalse($this->provedorFalso()->foiChamado(), 'a request não chama o provedor: isso é do worker');
    }

    #[TestDox('pasta sem dado nenhum nas seções: a pergunta é aceita (202), ao contrário dos comandos (sem_dados)')]
    public function testPastaVaziaAceitaPergunta(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $this->perguntar($client, (int) $pasta->getId());

        self::assertResponseStatusCodeSame(202);
        self::assertSame(1, $this->perguntasDaPasta((int) $pasta->getId()));
    }

    #[TestDox('texto vazio (só espaços) ou com mais de 2000 caracteres → 422 pergunta_invalida, nada gravado e o limitador intocado')]
    public function testTextoInvalido(): void
    {
        $client = $this->cliente();
        $espiao = new RateLimiterFactoryEspiao();
        static::getContainer()->set('limiter.inteligencia_solicitar', $espiao);
        [, , $pasta] = $this->cenario($client);

        foreach (['   ', '', str_repeat('a', 2001)] as $texto) {
            $this->perguntar($client, (int) $pasta->getId(), $texto);

            self::assertResponseStatusCodeSame(422);
            $json = $this->json($client);
            self::assertSame('pergunta_invalida', $json['motivo']);
            self::assertNotEmpty($json['mensagem']);
        }

        self::assertSame(0, $this->analisesDaPasta((int) $pasta->getId()));
        self::assertSame(0, $this->perguntasDaPasta((int) $pasta->getId()));
        self::assertSame([], $espiao->chavesConsumidas, 'texto inválido não gasta o ritmo de ninguém');
        self::assertCount(0, $this->transporteAsync()->getSent());
    }

    #[TestDox('2000 caracteres (com acento) é o limite aceito')]
    public function testTextoNoLimite(): void
    {
        $client = $this->cliente();
        [, , $pasta] = $this->cenario($client);

        $this->perguntar($client, (int) $pasta->getId(), str_repeat('é', 2000));

        self::assertResponseStatusCodeSame(202);
    }

    #[TestDox('limitador estourado → 429 muitas_solicitacoes, nada gravado')]
    public function testLimitadorEstourado(): void
    {
        $client = $this->cliente();
        static::getContainer()->set('limiter.inteligencia_solicitar', new RateLimiterFactoryEspiao(aceita: false));
        [, , $pasta] = $this->cenario($client);

        $this->perguntar($client, (int) $pasta->getId());

        self::assertResponseStatusCodeSame(429);
        self::assertSame('muitas_solicitacoes', $this->json($client)['motivo']);
        self::assertSame(0, $this->perguntasDaPasta((int) $pasta->getId()));
        self::assertSame(0, $this->analisesDaPasta((int) $pasta->getId()));
    }

    #[TestDox('segunda pergunta com uma em andamento: 202 com a MESMA e aviso, sem criar e sem gastar o limitador (mesmo recusando)')]
    public function testSegundaPerguntaEmAndamentoNaoCria(): void
    {
        $client = $this->cliente();
        [$user, $tenant, $pasta] = $this->cenario($client);
        $pendente = $this->criarPergunta($tenant, $user, $pasta, 'Primeira pergunta');
        static::getContainer()->set('limiter.inteligencia_solicitar', new RateLimiterFactoryEspiao(aceita: false));

        $this->perguntar($client, (int) $pasta->getId(), 'Segunda pergunta');

        self::assertResponseStatusCodeSame(202);
        $json = $this->json($client);
        self::assertSame($pendente->getAnalise()->getId(), $json['id']);
        self::assertSame($pendente->getId(), $json['pergunta']);
        self::assertSame(PerguntarSobreAPastaUseCase::AVISO_EM_ANDAMENTO, $json['aviso']);
        self::assertSame(1, $this->perguntasDaPasta((int) $pasta->getId()));
        self::assertCount(0, $this->transporteAsync()->getSent());
    }

    #[TestDox('a pergunta em andamento de OUTRA pessoa na mesma pasta não trava a minha')]
    public function testEmAndamentoDeOutraPessoaNaoTrava(): void
    {
        $client = $this->cliente();
        [, $tenant, $pasta] = $this->cenario($client);
        $colega = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.inteligencia.view'], 'colega');
        $daColega = $this->criarPergunta($tenant, $colega, $pasta, 'Pergunta da colega');

        $this->perguntar($client, (int) $pasta->getId(), 'Minha pergunta');

        self::assertResponseStatusCodeSame(202);
        self::assertNotSame($daColega->getAnalise()->getId(), $this->json($client)['id']);
        self::assertSame(2, $this->perguntasDaPasta((int) $pasta->getId()));
    }

    #[TestDox('sem token CSRF → 403 csrf, nada gravado')]
    public function testSemCsrf(): void
    {
        $client = $this->cliente();
        [, , $pasta] = $this->cenario($client);

        $this->perguntar($client, (int) $pasta->getId(), comToken: false);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('csrf', $this->json($client)['motivo']);
        self::assertSame(0, $this->perguntasDaPasta((int) $pasta->getId()));
    }

    #[TestDox('sem modules.inteligencia.view → 403 no POST e na conversa')]
    public function testSemPermissaoDoModulo(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $admin);
        $user = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        $this->logarComTenant($client, $user, $tenant);

        $this->perguntar($client, (int) $pasta->getId());
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', "/pasta/{$pasta->getId()}/ia/conversa");
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->perguntasDaPasta((int) $pasta->getId()));
    }

    #[TestDox('pasta de OUTRO escritório → 404 (nunca 403) no POST e na conversa')]
    public function testPastaDeOutroTenant(): void
    {
        $client = $this->cliente();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        [$pastaB] = $this->criarPastaComPublicacao($tenantB);
        $this->ligarIaNoTenant($tenantA, $userA);
        $this->ligarIaNoTenant($tenantB, $userB);
        $this->criarPergunta($tenantB, $userB, $pastaB, 'Pergunta do escritório B');
        $this->logarComTenant($client, $userA, $tenantA);

        $this->perguntar($client, (int) $pastaB->getId());
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', "/pasta/{$pastaB->getId()}/ia/conversa");
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('Pergunta do escritório B', (string) $client->getResponse()->getContent());
        self::assertSame(1, $this->perguntasDaPasta((int) $pastaB->getId()));
    }

    #[TestDox('provedor não configurado → 409 nao_configurada_na_plataforma, nada gravado')]
    public function testProvedorNaoConfigurado(): void
    {
        $client = $this->cliente();
        [, , $pasta] = $this->cenario($client);
        $this->provedorFalso()->desconfigurar();

        $this->perguntar($client, (int) $pasta->getId());

        self::assertResponseStatusCodeSame(409);
        self::assertSame('nao_configurada_na_plataforma', $this->json($client)['motivo']);
        self::assertSame(0, $this->perguntasDaPasta((int) $pasta->getId()));
    }

    #[TestDox('processo em segredo de justiça → 409 contexto_bloqueado, nada gravado')]
    public function testSigilo(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->criarPastaComPublicacao($tenant);
        $processo->setNivelSigilo(1);
        $this->em()->flush();
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $this->perguntar($client, (int) $pasta->getId());

        self::assertResponseStatusCodeSame(409);
        self::assertSame('contexto_bloqueado', $this->json($client)['motivo']);
        self::assertSame(0, $this->perguntasDaPasta((int) $pasta->getId()));
    }

    // =========================================================================
    // Conversa
    // =========================================================================

    #[TestDox('conversa vazia: o texto do desenho e as 4 sugestões literais, sem cartão de carregando')]
    public function testConversaVazia(): void
    {
        $client = $this->cliente();
        [, , $pasta] = $this->cenario($client);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/conversa");

        self::assertResponseIsSuccessful();
        $raiz = $crawler->filter('.ia-conversa[data-ia-conversa]');
        self::assertSame('1', $raiz->attr('data-ia-carregado'));
        self::assertSame('0', $raiz->attr('data-ia-em-andamento'));
        self::assertSame(
            'Pergunte sobre esta pasta. A IA já conhece o cliente, o processo, os prazos, as metas, os registros e os documentos.',
            trim($crawler->filter('.ia-conversa > .ia-conversa-msgs > p.ia-conversa-vazia')->text()),
        );
        $sugestoes = $crawler->filter('.ia-conversa > .ia-conversa-msgs > .ia-conversa-sugestoes > button.ia-conversa-sugestao[data-ia-sugestao]');
        self::assertSame(
            ['Esse processo está parado?', 'O que falta fazer?', 'Veja se existe prazo', 'Existe algum risco?'],
            $sugestoes->each(static fn ($b): string => trim($b->text())),
        );
        self::assertSame(0, $crawler->filter('[data-ia-consultando]')->count());
    }

    #[TestDox('conversa: as minhas à direita, as respostas da IA escapadas, falha dita como falha e o cartão "Consultando" com a em andamento; a de outra pessoa NÃO aparece')]
    public function testConversaDaPessoa(): void
    {
        $client = $this->cliente();
        [$user, $tenant, $pasta] = $this->cenario($client);
        $colega = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.inteligencia.view'], 'colega');
        $daColega = $this->criarPergunta($tenant, $colega, $pasta, 'Pergunta secreta da colega');
        $this->concluirAnalise($daColega->getAnalise(), 'Resposta da colega.', [], null, 'Resposta secreta da colega.');

        $concluida = $this->criarPergunta($tenant, $user, $pasta, 'Existe algum <b>risco</b>?');
        $this->concluirAnalise($concluida->getAnalise(), 'Conclusão.', [], null, "CONCLUSÃO\n<script>alert(1)</script> risco baixo.");
        $falhou = $this->criarPergunta($tenant, $user, $pasta, 'Pergunta que falhou');
        $falhou->getAnalise()->falhar('falha definitiva: 401');
        $this->em()->flush();
        $this->criarPergunta($tenant, $user, $pasta, 'Pergunta em andamento');

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/conversa");

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertSame('1', $crawler->filter('.ia-conversa')->attr('data-ia-em-andamento'));
        $minhas = $crawler->filter('.ia-conversa > .ia-conversa-msgs > .ia-conversa-msg--eu');
        self::assertSame(['Existe algum <b>risco</b>?', 'Pergunta que falhou', 'Pergunta em andamento'], $minhas->each(static fn ($m): string => $m->text()));
        $daIa = $crawler->filter('.ia-conversa > .ia-conversa-msgs > .ia-conversa-msg--ia');
        self::assertSame(2, $daIa->count(), 'a em andamento ainda não tem resposta: nenhuma inventada');
        self::assertStringContainsString('<script>alert(1)</script> risco baixo.', $daIa->eq(0)->text());
        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'a resposta do modelo sai escapada');
        self::assertStringContainsString('&lt;b&gt;risco&lt;/b&gt;', $html);
        self::assertSame('Não foi possível responder.', $daIa->eq(1)->text());
        self::assertTrue($daIa->eq(1)->matches('.is-falha'));
        $carga = '.ia-conversa > .ia-conversa-carregando[data-ia-consultando]';
        self::assertSame('Consultando os dados desta pasta…', trim($crawler->filter($carga . ' > .ia-carga-topo > .ia-carga-textos > .ia-conversa-etapa')->text()));
        self::assertSame(1, $crawler->filter($carga . ' > .ia-carga-varre')->count());
        self::assertSame(['atual', 'futuro', 'futuro'], $crawler->filter($carga . ' > .ia-carga-passos > .ia-carga-passo')->each(static fn ($p): string => (string) $p->attr('data-estado')));
        self::assertSame(['Consultando os dados desta pasta', 'Lendo registros e documentos', 'Redigindo a resposta'], $crawler->filter($carga . ' > .ia-carga-passos > .ia-carga-passo')->each(static fn ($p): string => (string) $p->attr('data-rotulo')));
        self::assertCount(7, json_decode((string) $crawler->filter($carga)->attr('data-frases'), true));
        self::assertSame(1, $crawler->filter($carga . ' > .ia-carga-barra > div[data-ia-barra]')->count());
        self::assertSame(0, $crawler->filter('.ia-conversa-shim')->count(), 'o brilho cinza antigo saiu');
        self::assertSame(4, $crawler->filter('.ia-conversa-sugestao[disabled]')->count(), 'com pergunta em andamento as sugestões ficam desabilitadas');
        self::assertSame(0, $crawler->filter('.ia-conversa p.ia-conversa-vazia')->count());
        self::assertStringNotContainsString('secreta', $html, 'a conversa de outra pessoa nunca aparece');
    }

    #[TestDox('rotas por id: a análise da pergunta de outra pessoa responde 404 (status e .txt); a minha responde')]
    public function testRotasPorIdSoParaQuemPerguntou(): void
    {
        $client = $this->cliente();
        [$user, $tenant, $pasta] = $this->cenario($client);
        $colega = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.inteligencia.view'], 'colega');
        $daColega = $this->criarPergunta($tenant, $colega, $pasta, 'Pergunta da colega');
        $this->concluirAnalise($daColega->getAnalise(), 'Resposta.', [], null, 'Resposta da colega.');
        $minha = $this->criarPergunta($tenant, $user, $pasta, 'Minha pergunta');

        $client->request('GET', "/pasta/{$pasta->getId()}/ia/analises/{$daColega->getAnalise()->getId()}");
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', "/pasta/{$pasta->getId()}/ia/analises/{$daColega->getAnalise()->getId()}.txt");
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('Resposta da colega', (string) $client->getResponse()->getContent());

        $client->request('GET', "/pasta/{$pasta->getId()}/ia/analises/{$minha->getAnalise()->getId()}");
        self::assertResponseIsSuccessful();
        self::assertSame('pendente', $this->json($client)['status']);
    }

    #[TestDox('Registro de auditoria: a pergunta aparece como "Pergunta à IA", sem o texto da pergunta')]
    public function testAuditoriaSemTextoDaPergunta(): void
    {
        $client = $this->cliente();
        [$user, $tenant, $pasta] = $this->cenario($client);
        $colega = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.inteligencia.view'], 'colega');
        $this->criarPergunta($tenant, $colega, $pasta, 'Pergunta reservada da colega');

        $client->request('GET', "/pasta/{$pasta->getId()}/ia/auditoria");

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Pergunta à IA', $html);
        self::assertStringNotContainsString('Agente Gestor', $html, 'a pergunta não se passa por análise do Gestor');
        self::assertStringNotContainsString('reservada', $html);
    }

    #[TestDox('rotas por id de escrita: um colega não exclui, não marca lida nem interna a pergunta de outra pessoa (404, nada muda)')]
    public function testColegaNaoAgeNaPerguntaDeOutraPessoa(): void
    {
        $client = $this->cliente();
        [$user, $tenant, $pasta] = $this->cenario($client);
        $colega = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.inteligencia.view'], 'colega');
        $minha = $this->criarPergunta($tenant, $user, $pasta, 'Minha pergunta');
        $this->concluirAnalise($minha->getAnalise(), 'Resposta.', [], null, 'Resposta minha.');
        $analiseId = (int) $minha->getAnalise()->getId();
        $this->logarComTenant($client, $colega, $tenant);

        foreach (['excluir', 'lida', 'interna'] as $acao) {
            $client->request('POST', "/pasta/{$pasta->getId()}/ia/analises/{$analiseId}/{$acao}", [
                '_token' => 'TOKEN_inteligencia_analise_' . $analiseId,
            ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
            self::assertResponseStatusCodeSame(404, $acao);
        }

        self::assertSame(1, $this->contarNoBanco(
            'SELECT count(*) FROM inteligencia_analise WHERE id = :id AND excluida_em IS NULL AND lida_em IS NULL AND interna_do_escritorio = true',
            ['id' => $analiseId],
        ), 'nem excluída, nem lida, nem com o cadeado trocado');
    }

    #[TestDox('a conversa mostra só as últimas 20 perguntas da pessoa, em ordem cronológica')]
    public function testLimiteDaConversa(): void
    {
        $client = $this->cliente();
        [$user, $tenant, $pasta] = $this->cenario($client);
        for ($i = 1; $i <= ListarConversaDaPastaUseCase::LIMITE + 2; ++$i) {
            $p = $this->criarPergunta($tenant, $user, $pasta, 'Pergunta ' . $i);
            $this->concluirAnalise($p->getAnalise(), 'R' . $i, [], null, 'Resposta ' . $i);
        }

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/conversa");

        $minhas = $crawler->filter('.ia-conversa-msg--eu');
        self::assertSame(ListarConversaDaPastaUseCase::LIMITE, $minhas->count());
        self::assertSame('Pergunta 3', $minhas->first()->text());
        self::assertSame('Pergunta 22', $minhas->last()->text());
    }
}
