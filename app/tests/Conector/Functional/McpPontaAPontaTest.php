<?php

declare(strict_types=1);

namespace App\Tests\Conector\Functional;

use App\Conector\Controller\McpController;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Ferramenta\AtualizarClienteFerramenta;
use App\Conector\Ferramenta\AtualizarPastaFerramenta;
use App\Conector\Ferramenta\BuscarPastasFerramenta;
use App\Conector\Ferramenta\CatalogoFechadoDoConector;
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Conector\Ferramenta\DocumentosEPecasFerramenta;
use App\Conector\Ferramenta\FinanceiroDaPastaFerramenta;
use App\Conector\Ferramenta\GravarNaAgendaFerramenta;
use App\Conector\Ferramenta\LerAgendaFerramenta;
use App\Conector\Ferramenta\LerClientesFerramenta;
use App\Conector\Ferramenta\LerDocumentosEModelosFerramenta;
use App\Conector\Ferramenta\LerPastaFerramenta;
use App\Conector\Ferramenta\LerProcessosFerramenta;
use App\Conector\Ferramenta\MudarAndamentoFerramenta;
use App\Conector\Ferramenta\PrazosEMetasDaPastaFerramenta;
use App\Conector\Ferramenta\ProcessosDaPastaFerramenta;
use App\Conector\Ferramenta\QuemSouEuFerramenta;
use App\Conector\Ferramenta\RegistrarNaPastaFerramenta;
use App\Conector\Mcp\RegistroDaChamadaMiddleware;
use App\Conector\Mcp\ServidorMcpDoConector;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Conector\Service\ValidadorDoTokenDoConector;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\Conector\Support\CenarioDaAutorizacaoTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * O `/mcp` de ponta a ponta com o token de verdade (spec mcp-remoto-oauth 3, 3.5, 5.1, 9, 12.1, 12.18, 12.19 e PR 5 da
 * 13.1): a pessoa logada autoriza pelo `/oauth/authorize` com PKCE S256 e `resource`, o app troca o código no
 * `/oauth/token` e usa o `access_token` emitido no `/mcp`. Nada de dublê: o firewall, o validador, o portão, o limite,
 * o SDK e o log `mcp` são os do container. Um único navegador, com o debug ligado (o cliente padrão).
 *
 * Sem ferramenta de gravação registrada no PR 5 (só a `quem_sou_eu`), o step-up com o token de verdade fica para o PR
 * que registrar a primeira; o 403 `insufficient_scope` pela política do catálogo já está no {@see McpControllerTest}.
 */
#[CoversClass(McpController::class)]
#[CoversClass(ServidorMcpDoConector::class)]
#[CoversClass(ValidadorDoTokenDoConector::class)]
#[CoversClass(RegistroDaChamadaMiddleware::class)]
#[CoversClass(QuemSouEuFerramenta::class)]
final class McpPontaAPontaTest extends JusPrimeWebTestCase
{
    /** Ferramentas registradas até este PR, em ordem alfabética. Cada PR que entrega uma ferramenta a acrescenta aqui. */
    private const REGISTRADAS = [
        AtualizarClienteFerramenta::NOME,
        AtualizarPastaFerramenta::NOME,
        BuscarPastasFerramenta::NOME,
        ConfirmarAcaoFerramenta::NOME,
        DocumentosEPecasFerramenta::NOME,
        FinanceiroDaPastaFerramenta::NOME,
        GravarNaAgendaFerramenta::NOME,
        LerAgendaFerramenta::NOME,
        LerClientesFerramenta::NOME,
        LerDocumentosEModelosFerramenta::NOME,
        LerPastaFerramenta::NOME,
        LerProcessosFerramenta::NOME,
        MudarAndamentoFerramenta::NOME,
        PrazosEMetasDaPastaFerramenta::NOME,
        ProcessosDaPastaFerramenta::NOME,
        QuemSouEuFerramenta::NOME,
        RegistrarNaPastaFerramenta::NOME,
    ];

    use CenarioDaAutorizacaoTrait;

    private const FORM = 'autorizar_conexao';
    private const ERA_DO_INITIALIZE = '2025-06-18';
    private const SEM_ESTADO = '2026-07-28';

    private KernelBrowser $client;
    private string $verificador;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->verificador = bin2hex(random_bytes(32));
    }

    #[TestDox('2025-06-18: autorização inteira pelo HTTP; initialize, tools/list e quem_sou_eu com o access_token real, sem Set-Cookie, sem 302 e sem cache público')]
    public function testEraDoInitializeComOTokenReal(): void
    {
        [$tenant, $par] = $this->conectado();

        $inicio = $this->mcp($par['access_token'], 'initialize', [
            'protocolVersion' => self::ERA_DO_INITIALIZE,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'teste-ponta-a-ponta', 'version' => '1.0'],
        ], self::ERA_DO_INITIALIZE, 1);
        $resultado = $this->resultado($inicio, 1);
        self::assertSame(self::ERA_DO_INITIALIZE, $resultado['protocolVersion']);
        self::assertSame(ServidorMcpDoConector::NOME, $resultado['serverInfo']['name']);
        self::assertArrayHasKey('tools', $resultado['capabilities']);
        self::assertFalse($inicio->headers->has('Mcp-Session-Id'), 'sem sessão MCP (3.1)');

        $this->exigirFerramentasRegistradas($this->resultado($this->mcp($par['access_token'], 'tools/list', [], self::ERA_DO_INITIALIZE, 2), 2));

        $this->exigirQuemSouEu(
            $this->resultado($this->chamarQuemSouEu($par['access_token'], self::ERA_DO_INITIALIZE, 3), 3),
            $tenant,
            [ServidorDeAutorizacao::ESCOPO_LEITURA, ServidorDeAutorizacao::ESCOPO_ESCRITA],
        );
    }

    #[TestDox('2026-07-28 (sem initialize): server/discover, tools/list e quem_sou_eu com o access_token real, sem Set-Cookie, sem 302 e sem cache público')]
    public function testEraSemEstadoComOTokenReal(): void
    {
        [$tenant, $par] = $this->conectado(escrita: false);

        $descoberta = $this->resultado($this->mcp($par['access_token'], 'server/discover', [], self::SEM_ESTADO, 1), 1);
        // O server/discover só existe na era sem estado e lista as revisões dela; a 2025-06-18 segue pelo initialize.
        self::assertContains(self::SEM_ESTADO, $descoberta['supportedVersions']);

        $this->exigirFerramentasRegistradas($this->resultado($this->mcp($par['access_token'], 'tools/list', [], self::SEM_ESTADO, 2), 2));

        $this->exigirQuemSouEu(
            $this->resultado($this->chamarQuemSouEu($par['access_token'], self::SEM_ESTADO, 3), 3),
            $tenant,
            [ServidorDeAutorizacao::ESCOPO_LEITURA],
        );
    }

    /**
     * 12.1 e 12.19, no mesmo kernel (`disableReboot`): o cookie da sessão do navegador no A vai junto em toda requisição
     * do app, e nem a troca do código nem o `/mcp` mudam o escritório da sessão.
     */
    /**
     * 6.4: a `quem_sou_eu` diz "quais ferramentas registradas a pessoa pode usar", e ela própria é uma delas (a tabela
     * {@see \App\Conector\Ferramenta\TabelaDeAbaEChave} a põe sem módulo exigido). O teste de integração monta a
     * ferramenta à mão com ela na lista; o serviço do container, não.
     */
    #[TestDox('6.4: com o serviço do container, a quem_sou_eu se lista entre as ferramentas que a pessoa pode usar')]
    public function testQuemSouEuSeListaComOServicoDoContainer(): void
    {
        [, $par] = $this->conectado(escrita: false);

        $dados = $this->resultado($this->chamarQuemSouEu($par['access_token'], self::ERA_DO_INITIALIZE, 1), 1)['structuredContent'];

        // A pessoa do cenário não tem módulo: sobram as que não pedem nenhum (a ler_pasta segue a tela da pasta, decisão
        // 10 do PR 6, a própria quem_sou_eu e a confirmar_acao, que confere a ação confirmada ao executar), esta não
        // liberada na conexão só de leitura.
        self::assertSame([
            ['nome' => ConfirmarAcaoFerramenta::NOME, 'titulo' => CatalogoFechadoDoConector::TITULOS[ConfirmarAcaoFerramenta::NOME], 'liberada_nesta_conexao' => false],
            ['nome' => LerPastaFerramenta::NOME, 'titulo' => CatalogoFechadoDoConector::TITULOS[LerPastaFerramenta::NOME], 'liberada_nesta_conexao' => true],
            ['nome' => QuemSouEuFerramenta::NOME, 'titulo' => CatalogoFechadoDoConector::TITULOS[QuemSouEuFerramenta::NOME], 'liberada_nesta_conexao' => true],
        ], $dados['ferramentas']);
    }

    #[TestDox('12.1 e 12.19: sessão no A, autoriza o B, cookie junto; quem_sou_eu mostra só o B e a tela seguinte do navegador continua no A')]
    public function testEscritorioDoTokenNaoMisturaComODaSessao(): void
    {
        $this->client->disableReboot();
        $this->clienteValido();
        $a = $this->escritorio('Escritório A');
        $b = $this->escritorio('Escritório B');
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $a, permissoes: ['modules.bi.view']);
        $this->vinculo($pessoa, $b);
        $this->liberarConector($a);
        $this->liberarConector($b);
        $idA = (int) $a->getId();
        $idB = (int) $b->getId();
        $nomeA = (string) $a->getName();
        $this->logarComTenant($this->client, $pessoa, $a);

        $par = $this->exigirPar($this->trocarCodigo($this->autorizar($idB)));
        self::assertNotSame([], $this->client->getCookieJar()->all(), 'o cookie da sessão do navegador continua no pote');

        $resposta = $this->chamarQuemSouEu($par['access_token'], self::ERA_DO_INITIALIZE, 7);
        $dados = $this->resultado($resposta, 7)['structuredContent'];
        self::assertSame($idB, $dados['escritorio']['id']);
        self::assertStringNotContainsString($nomeA, (string) $resposta->getContent(), 'nada do escritório da sessão na resposta');

        $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getStatusCode());
        self::assertSame($idA, $this->client->getRequest()->getSession()->get('current_tenant_id'), 'a sessão continua no A');
        self::assertSame($nomeA, trim($this->client->getCrawler()->filter('.navtop-escritorio-nome')->text()));
    }

    #[TestDox('9.2 e 12.6: renovado pelo /oauth/token, o token novo responde; revogado o consentimento, o pedido seguinte dá 401 invalid_token e a renovação, invalid_grant')]
    public function testRevogarOConsentimentoDerrubaOToken(): void
    {
        [, $par] = $this->conectado();
        $this->resultado($this->mcp($par['access_token'], 'tools/list', [], self::ERA_DO_INITIALIZE, 1), 1);

        $renovado = $this->exigirPar($this->renovar($par['refresh_token']));
        self::assertNotSame($par['access_token'], $renovado['access_token']);
        $this->resultado($this->mcp($renovado['access_token'], 'tools/list', [], self::ERA_DO_INITIALIZE, 2), 2);

        $consentimento = $this->consentimentoDoToken($renovado['access_token']);
        $consentimento->revogar(new \DateTimeImmutable());
        $this->em()->flush();

        $recusa = $this->mcp($renovado['access_token'], 'tools/list', [], self::ERA_DO_INITIALIZE, 3);
        self::assertSame(401, $recusa->getStatusCode(), (string) $recusa->getContent());
        $desafio = (string) $recusa->headers->get('WWW-Authenticate');
        self::assertStringStartsWith('Bearer ', $desafio);
        self::assertStringContainsString('error="invalid_token"', $desafio);
        self::assertStringContainsString('resource_metadata="https://bluejus.com.br/.well-known/oauth-protected-resource/mcp"', $desafio);
        self::assertSemSessaoNemCache($recusa);

        self::assertSame(401, $this->mcp($par['access_token'], 'tools/list', [], self::ERA_DO_INITIALIZE, 4)->getStatusCode(), 'o token anterior da cadeia também cai');
        $this->exigirInvalidGrant($this->renovar($renovado['refresh_token']));
    }

    #[TestDox('6.2.9 e 12.15: uma linha no log mcp por requisição do /mcp, com os ids da chamada e sem o token (nem o hash dele)')]
    public function testUmaLinhaDeLogPorRequisicao(): void
    {
        $arquivo = static::getContainer()->getParameter('kernel.logs_dir') . '/mcp_test' . (string) getenv('TESTE_PROCESSO') . '.log';
        @unlink($arquivo);
        [$tenant, $par, $pessoaId] = $this->conectado();

        $this->resultado($this->mcp($par['access_token'], 'tools/list', [], self::ERA_DO_INITIALIZE, 1), 1);
        $this->resultado($this->chamarQuemSouEu($par['access_token'], self::ERA_DO_INITIALIZE, 2), 2);
        $this->resultado($this->chamarQuemSouEu($par['access_token'], self::SEM_ESTADO, 3), 3);

        $conteudo = (string) @file_get_contents($arquivo);
        $linhas = array_values(array_filter(
            explode("\n", $conteudo),
            static fn (string $linha): bool => str_contains($linha, RegistroDaChamadaMiddleware::MENSAGEM),
        ));
        self::assertCount(3, $linhas, $conteudo);

        $consentimento = $this->consentimentoDoToken($par['access_token']);
        $concessaoId = $this->concessaoDoToken($par['access_token']);
        foreach ($linhas as $linha) {
            $campos = self::contextoDaLinha($linha);
            self::assertSame(200, $campos['status'] ?? null, $linha);
            self::assertSame($pessoaId, $campos['pessoa'] ?? null, $linha);
            self::assertSame($tenant->getId(), $campos['escritorio'] ?? null, $linha);
            self::assertSame($concessaoId, $campos['concessao'] ?? null, $linha);
            self::assertSame($consentimento->getId(), $campos['consentimento'] ?? null, $linha);
            self::assertSame('claude.ai', $campos['cliente'] ?? null, $linha);
        }
        self::assertSame(QuemSouEuFerramenta::NOME, self::contextoDaLinha($linhas[1])['ferramenta'] ?? null);
        // O método JSON-RPC e a versão do protocolo: distinguem no log a lista da chamada da ferramenta.
        self::assertSame(['tools/list', 'tools/call', 'tools/call'], array_map(static fn (string $l): ?string => self::contextoDaLinha($l)['metodo'] ?? null, $linhas));
        self::assertSame([self::ERA_DO_INITIALIZE, self::ERA_DO_INITIALIZE, self::SEM_ESTADO], array_map(static fn (string $l): ?string => self::contextoDaLinha($l)['versao'] ?? null, $linhas));

        foreach ([$par['access_token'], $par['refresh_token'], hash('sha256', $par['access_token'])] as $segredo) {
            self::assertStringNotContainsString($segredo, $conteudo, 'o token nunca vai ao log mcp');
        }
    }

    #[TestDox('ler_documentos_e_modelos ler: o /mcp devolve o bloco de imagem com o arquivo, e o log mcp leva só o sha256, sem nome nem bytes')]
    public function testLerDocumentoPeloMcpNaoVazaNoLog(): void
    {
        $disco = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $this->client->disableReboot();
        $arquivoDeLog = static::getContainer()->getParameter('kernel.logs_dir') . '/mcp_test' . (string) getenv('TESTE_PROCESSO') . '.log';
        @unlink($arquivoDeLog);
        [$conectado, $par] = $this->conectado(permissoes: ['modules.pastas.view', 'resources.pasta.view']);
        $tenant = $this->em()->find(Tenant::class, $conectado->getId());
        self::assertNotNull($tenant);
        $png = "\x89PNG\r\n\x1A\n" . 'bytes-da-imagem-ponta-a-ponta';
        $nome = 'rg-sigiloso-do-cliente.png';
        $pasta = (new Pasta())->setNup('E2E-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $documento = (new PastaDocumento())->setTenant($tenant)->setPasta($pasta)->setTitulo($nome)->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo(uniqid('doc', true) . '.bin')->setNomeOriginal($nome)->setMimeType('image/png')->setTamanhoBytes(\strlen($png))->setPaginas(null);
        $this->em()->persist($documento);
        $this->em()->flush();
        $disco->memoria->semear(ChavesDePasta::documento($documento), $png);

        $argumentos = ['acao' => 'ler', 'pasta_id' => $pasta->getId(), 'documento_id' => $documento->getId()];
        $resultado = $this->resultado($this->mcp($par['access_token'], 'tools/call', ['name' => LerDocumentosEModelosFerramenta::NOME, 'arguments' => $argumentos], self::ERA_DO_INITIALIZE, 1, LerDocumentosEModelosFerramenta::NOME), 1);

        self::assertFalse($resultado['isError'] ?? null, json_encode($resultado, \JSON_THROW_ON_ERROR));
        $imagens = array_values(array_filter($resultado['content'], static fn (array $bloco): bool => ($bloco['type'] ?? null) === 'image'));
        self::assertCount(1, $imagens, 'a resposta tem o bloco de imagem');
        self::assertSame('image/png', $imagens[0]['mimeType']);
        self::assertSame(base64_encode($png), $imagens[0]['data']);

        $conteudo = (string) @file_get_contents($arquivoDeLog);
        $linhas = array_values(array_filter(explode("\n", $conteudo), static fn (string $l): bool => str_contains($l, RegistroDaChamadaMiddleware::MENSAGEM)));
        self::assertCount(1, $linhas, $conteudo);
        self::assertSame(hash('sha256', $png), self::contextoDaLinha($linhas[0])['arquivo_sha256'] ?? null);
        foreach ([$nome, 'rg-sigiloso', 'bytes-da-imagem', base64_encode($png), $par['access_token']] as $proibido) {
            self::assertStringNotContainsString($proibido, $conteudo, 'o log mcp só leva o sha256 do arquivo');
        }
    }

    /**
     * Pessoa logada com o escritório na sessão; autoriza o mesmo escritório e troca o código.
     *
     * @param list<string> $permissoes os códigos de permissão do perfil da pessoa
     *
     * @return array{Tenant, array{access_token: string, refresh_token: string}, int} o escritório, o par e o id da pessoa
     */
    private function conectado(bool $escrita = true, array $permissoes = []): array
    {
        $this->clienteValido();
        $tenant = $this->escritorio();
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $tenant, permissoes: $permissoes);
        $this->liberarConector($tenant);
        $this->logarComTenant($this->client, $pessoa, $tenant);

        $par = $this->exigirPar($this->trocarCodigo($this->autorizar((int) $tenant->getId(), $escrita)), $escrita);

        return [$tenant, $par, (int) $pessoa->getId()];
    }

    /** O `code_challenge` S256 (RFC 7636 4.2): SHA-256 do verificador em base64url, sem preenchimento. */
    private static function desafioDe(string $verificador): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verificador, true)), '+/', '-_'), '=');
    }

    /** Abre a tela com o pedido do app, autoriza o escritório e devolve o `code` do retorno ao app. */
    private function autorizar(int $tenantId, bool $escrita = true): string
    {
        $estado = 'estado-' . bin2hex(random_bytes(8));
        $tela = $this->client->request('GET', '/oauth/authorize?' . http_build_query($this->pedidoValido([
            'state' => $estado,
            'code_challenge' => self::desafioDe($this->verificador),
            'code_challenge_method' => 'S256',
            'resource' => ServidorDeAutorizacao::RESOURCE,
        ])));
        self::assertResponseIsSuccessful();

        $campos = [
            'pedido' => (string) $tela->filter('input[name="' . self::FORM . '[pedido]"]')->attr('value'),
            '_token' => (string) $tela->filter('input[name="' . self::FORM . '[_token]"]')->attr('value'),
            'tenantId' => (string) $tenantId,
            'autorizar' => '',
        ];
        if ($escrita) {
            $campos['escrita'] = '1';
        }
        $this->client->request('POST', '/oauth/authorize', [self::FORM => $campos]);

        self::assertResponseStatusCodeSame(302);
        $local = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::REDIRECT . '?', $local);
        parse_str((string) parse_url($local, \PHP_URL_QUERY), $parametros);
        self::assertSame($estado, $parametros['state'] ?? null);
        self::assertSame(ServidorDeAutorizacao::ISSUER, $parametros['iss'] ?? null);
        self::assertIsString($parametros['code'] ?? null);

        return $parametros['code'];
    }

    /** @param array<string, string> $campos */
    private function postarToken(array $campos): Response
    {
        $this->client->request('POST', '/oauth/token', $campos, [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);

        return $this->client->getResponse();
    }

    private function trocarCodigo(string $codigo): Response
    {
        return $this->postarToken([
            'grant_type' => 'authorization_code',
            'code' => $codigo,
            'redirect_uri' => self::REDIRECT,
            'client_id' => self::CLIENT_ID,
            'code_verifier' => $this->verificador,
            'resource' => ServidorDeAutorizacao::RESOURCE,
        ]);
    }

    private function renovar(string $renovacao): Response
    {
        return $this->postarToken(['grant_type' => 'refresh_token', 'refresh_token' => $renovacao, 'client_id' => self::CLIENT_ID]);
    }

    /** @return array{access_token: string, refresh_token: string} */
    private function exigirPar(Response $resposta, bool $escrita = true): array
    {
        self::assertSame(200, $resposta->getStatusCode(), (string) $resposta->getContent());
        $par = json_decode((string) $resposta->getContent(), true);
        self::assertIsArray($par);
        self::assertIsString($par['access_token'] ?? null);
        self::assertIsString($par['refresh_token'] ?? null);
        self::assertSame('Bearer', $par['token_type'] ?? null);
        self::assertSame($escrita ? 'mcp:leitura mcp:escrita offline_access' : 'mcp:leitura offline_access', $par['scope'] ?? null);

        return ['access_token' => $par['access_token'], 'refresh_token' => $par['refresh_token']];
    }

    private function exigirInvalidGrant(Response $resposta): void
    {
        self::assertSame(400, $resposta->getStatusCode(), (string) $resposta->getContent());
        self::assertSame('invalid_grant', json_decode((string) $resposta->getContent(), true)['error'] ?? null);
    }

    /**
     * Um POST JSON-RPC ao `/mcp` com o bearer. Na era do `initialize`, só o cabeçalho da versão; na 2026-07-28, a versão
     * e as capacidades no `_meta` e o método (e o nome da ferramenta) nos cabeçalhos, como o SDK exige.
     *
     * @param array<string, mixed> $params
     */
    private function mcp(string $token, string $metodo, array $params, string $versao, int $id, ?string $nome = null): Response
    {
        $cabecalhos = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => $versao,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];
        if ($versao === self::SEM_ESTADO) {
            $params['_meta'] = [
                'io.modelcontextprotocol/protocolVersion' => self::SEM_ESTADO,
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ];
            $cabecalhos['HTTP_MCP_METHOD'] = $metodo;
            if ($nome !== null) {
                $cabecalhos['HTTP_MCP_NAME'] = $nome;
            }
        }
        $mensagem = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $metodo];
        if ($params !== []) {
            $mensagem['params'] = $params;
        }
        $this->client->request('POST', '/mcp', server: $cabecalhos, content: json_encode($mensagem, \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    private function chamarQuemSouEu(string $token, string $versao, int $id): Response
    {
        return $this->mcp($token, 'tools/call', ['name' => QuemSouEuFerramenta::NOME, 'arguments' => new \stdClass()], $versao, $id, QuemSouEuFerramenta::NOME);
    }

    /**
     * A resposta 200 do `/mcp`, sem sessão nem cache, com o `result` do JSON-RPC do id pedido.
     *
     * @return array<string, mixed>
     */
    private function resultado(Response $resposta, int $id): array
    {
        self::assertSame(200, $resposta->getStatusCode(), mb_substr((string) $resposta->getContent(), 0, 600));
        self::assertSemSessaoNemCache($resposta);
        $corpo = json_decode((string) $resposta->getContent(), true);
        self::assertIsArray($corpo, (string) $resposta->getContent());
        self::assertSame($id, $corpo['id'] ?? null);
        self::assertArrayNotHasKey('error', $corpo, (string) $resposta->getContent());
        self::assertIsArray($corpo['result'] ?? null);

        return $corpo['result'];
    }

    /** 12.18: nunca 302, nenhum cookie e, se houver `Cache-Control`, nada de cache compartilhado. */
    private static function assertSemSessaoNemCache(Response $resposta): void
    {
        self::assertFalse($resposta->isRedirection(), 'o /mcp nunca redireciona');
        self::assertSame([], $resposta->headers->getCookies());
        self::assertFalse($resposta->headers->has('Set-Cookie'));
        if ($resposta->headers->has('Cache-Control')) {
            $cache = (string) $resposta->headers->get('Cache-Control');
            self::assertStringNotContainsString('public', $cache, $cache);
            self::assertMatchesRegularExpression('/no-store|no-cache|private/', $cache, $cache);
        }
    }

    /** @param array<string, mixed> $resultado */
    private function exigirFerramentasRegistradas(array $resultado): void
    {
        self::assertIsArray($resultado['tools'] ?? null);
        $nomes = array_column($resultado['tools'], 'name');
        sort($nomes);
        self::assertSame(self::REGISTRADAS, $nomes, 'só as ferramentas já entregues estão registradas, e todas do catálogo fechado');

        foreach ($resultado['tools'] as $ferramenta) {
            $nome = $ferramenta['name'];
            self::assertSame(CatalogoFechadoDoConector::TITULOS[$nome], $ferramenta['title'] ?? null);
            self::assertSame(
                json_decode(json_encode(CatalogoFechadoDoConector::anotacoes($nome), \JSON_THROW_ON_ERROR), true),
                $ferramenta['annotations'] ?? null,
            );
            foreach (['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'] as $dica) {
                self::assertIsBool($ferramenta['annotations'][$dica] ?? null, $nome . ': ' . $dica . ' com valor explícito');
            }
            self::assertSame(CatalogoFechadoDoConector::meta($nome)['securitySchemes'], $ferramenta['_meta']['securitySchemes'] ?? null);
        }
    }

    /**
     * @param array<string, mixed> $resultado
     * @param list<string>         $escoposDoMcp os escopos do recurso que o token tem
     */
    private function exigirQuemSouEu(array $resultado, Tenant $tenant, array $escoposDoMcp): void
    {
        self::assertFalse($resultado['isError'] ?? null);
        $dados = $resultado['structuredContent'] ?? null;
        self::assertIsArray($dados);
        self::assertIsInt($dados['pessoa']['id'] ?? null);
        self::assertSame('Pessoa do Conector', $dados['pessoa']['nome'] ?? null);
        self::assertSame($tenant->getId(), $dados['escritorio']['id'] ?? null);
        self::assertSame($tenant->getName(), $dados['escritorio']['nome'] ?? null);
        self::assertSame($escoposDoMcp, array_values(array_intersect($dados['escopos'] ?? [], ServidorDeAutorizacao::ESCOPOS_DO_RECURSO)));
        self::assertIsString($dados['conexao_vale_ate'] ?? null);
        $valeAte = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $dados['conexao_vale_ate']);
        self::assertInstanceOf(\DateTimeImmutable::class, $valeAte);
        self::assertGreaterThan(time(), $valeAte->getTimestamp());
        self::assertLessThanOrEqual(time() + 7 * 86400 + 60, $valeAte->getTimestamp(), 'nunca além dos 7 dias da autorização');
        self::assertIsArray($dados['ferramentas'] ?? null);
        self::assertSame(json_decode((string) $resultado['content'][0]['text'], true), $dados, 'content igual ao structuredContent');
    }

    private function consentimentoDoToken(string $token): ConsentimentoDoConector
    {
        $id = $this->em()->getConnection()->fetchOne(
            'SELECT c.consentimento_id FROM conector_ia_token t JOIN conector_ia_concessao c ON c.id = t.concessao_id WHERE t.identificador_hash = ?',
            [hash('sha256', $token)],
        );
        self::assertNotFalse($id, 'o token está no banco pelo hash');
        $consentimento = $this->em()->find(ConsentimentoDoConector::class, (int) $id);
        self::assertNotNull($consentimento);

        return $consentimento;
    }

    private function concessaoDoToken(string $token): int
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT concessao_id FROM conector_ia_token WHERE identificador_hash = ?', [hash('sha256', $token)]);
        self::assertNotFalse($id);

        return (int) $id;
    }

    /** @return array<string, mixed> o contexto JSON da linha do Monolog */
    private static function contextoDaLinha(string $linha): array
    {
        self::assertSame(1, preg_match('/' . preg_quote(RegistroDaChamadaMiddleware::MENSAGEM, '/') . ' (\{.*\}) \[\]\s*$/', $linha, $partes), $linha);
        $contexto = json_decode($partes[1], true);
        self::assertIsArray($contexto, $linha);

        return $contexto;
    }
}
