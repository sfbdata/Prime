<?php

declare(strict_types=1);

namespace App\Tests\Conector\Functional;

use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Ferramenta\QuemSouEuFerramenta;
use App\Conector\Mcp\ConectorDesligadoMiddleware;
use App\Conector\Mcp\RegistroDaChamadaMiddleware;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Conector\Service\ValidadorDoTokenDoConector;
use App\Conector\UseCase\AlterarConectorDoEscritorioUseCase;
use App\Conector\UseCase\AlterarLiberacaoDoConectorPeloMasterUseCase;
use App\Conector\UseCase\TrocarRenovacaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Conector\Support\CenarioDaAutorizacaoTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Desligar (administrador) ou bloquear (Master) só suspende o conector (spec mcp-remoto-oauth 12.24; decisão do
 * Coordenador de 16/11/2026), de ponta a ponta pelo HTTP, com o token de verdade: suspenso, o `/mcp` responde 403 sem
 * `WWW-Authenticate` e sem despachar a ferramenta (nada lido, nada gravado); a renovação no `/oauth/token` continua
 * girando, sem derrubar a concessão; religar volta a responder 200 com a mesma cadeia de tokens, sem reconectar.
 */
#[CoversClass(ConectorDesligadoMiddleware::class)]
#[CoversClass(ValidadorDoTokenDoConector::class)]
#[CoversClass(TrocarRenovacaoUseCase::class)]
#[CoversClass(AlterarConectorDoEscritorioUseCase::class)]
#[CoversClass(AlterarLiberacaoDoConectorPeloMasterUseCase::class)]
final class SuspensaoDoConectorTest extends JusPrimeWebTestCase
{
    use CenarioDaAutorizacaoTrait;

    private const FORM = 'autorizar_conexao';
    private const VERSAO = '2025-06-18';

    private KernelBrowser $client;
    private string $verificador;
    private int $tenantId;
    private int $pessoaId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->verificador = bin2hex(random_bytes(32));
    }

    /** @return iterable<string, array{string}> */
    public static function suspensoes(): iterable
    {
        yield 'desligar pelo administrador' => ['administrador'];
        yield 'bloquear pelo Master' => ['master'];
    }

    #[DataProvider('suspensoes')]
    #[TestDox('ciclo ($quem): conectado responde 200; suspenso, 403 sem desafio e sem despacho; renovar dá certo; religar responde 200 sem reconectar')]
    public function testCicloDaSuspensao(string $quem): void
    {
        $arquivo = static::getContainer()->getParameter('kernel.logs_dir') . '/mcp_test' . (string) getenv('TESTE_PROCESSO') . '.log';
        @unlink($arquivo);
        $par = $this->conectado();
        $this->exigirQuemSouEu($this->chamarQuemSouEu($par['access_token'], 1), 1);
        $consentimentoId = (int) $this->consentimentoDoToken($par['access_token'])->getId();
        $concessaoId = $this->concessaoDoToken($par['access_token']);

        $this->suspender($quem, false);
        $this->zerarUltimoUso($consentimentoId);

        $this->exigirSuspenso($this->chamarQuemSouEu($par['access_token'], 2), 2);
        self::assertNull($this->ultimoUso($consentimentoId), 'suspenso: nada gravado (nem o último uso da conexão)');

        $renovado = $this->exigirPar($this->renovar($par['refresh_token']));
        self::assertNull($this->revogadaEm($concessaoId), 'a renovação com o conector suspenso não derruba a concessão');
        $this->exigirSuspenso($this->chamarQuemSouEu($renovado['access_token'], 3), 3);
        self::assertNull($this->ultimoUso($consentimentoId));

        $this->suspender($quem, true);

        $this->exigirQuemSouEu($this->chamarQuemSouEu($renovado['access_token'], 4), 4);
        self::assertNotNull($this->ultimoUso($consentimentoId), 'religado, a chamada volta a gravar o último uso');
        self::assertSame($concessaoId, $this->concessaoDoToken($renovado['access_token']), 'a mesma concessão, sem reconectar');

        $linhas = $this->linhasDoLog($arquivo);
        self::assertCount(4, $linhas);
        foreach ([1, 2] as $suspensa) {
            $campos = $linhas[$suspensa];
            self::assertSame(403, $campos['status'] ?? null);
            self::assertSame(AcessoDoConectorRecusado::MOTIVO_LIBERACAO, $campos['motivo'] ?? null);
            self::assertArrayNotHasKey('ferramenta', $campos, 'a ferramenta não foi despachada');
            self::assertArrayNotHasKey('resultado', $campos);
            self::assertArrayNotHasKey('pessoa', $campos, 'o portão não abriu a requisição');
        }
        self::assertSame(QuemSouEuFerramenta::NOME, $linhas[3]['ferramenta'] ?? null);
    }

    private function suspender(string $quem, bool $religar): void
    {
        $tenant = $this->em()->find(Tenant::class, $this->tenantId) ?? throw new \LogicException('sem escritório');
        if ($quem === 'master') {
            static::getContainer()->get(AlterarLiberacaoDoConectorPeloMasterUseCase::class)
                ->executar($this->tenantId, $religar, $this->pessoa(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']));

            return;
        }

        $admin = static::getContainer()->get(AlterarConectorDoEscritorioUseCase::class);
        if ($religar) {
            $admin->ligar(true, $this->em()->find(User::class, $this->pessoaId) ?? throw new \LogicException('sem pessoa'), $tenant);

            return;
        }
        $admin->desligar($tenant);
    }

    /** O 403 do conector suspenso: sem desafio (o app não renova nem reconecta), erro JSON-RPC fixo, sem `result`. */
    private function exigirSuspenso(Response $resposta, int $id): void
    {
        self::assertSame(403, $resposta->getStatusCode(), (string) $resposta->getContent());
        self::assertFalse($resposta->headers->has('WWW-Authenticate'), 'sem invalid_token nem insufficient_scope');
        self::assertFalse($resposta->isRedirection());
        self::assertSame([], $resposta->headers->getCookies());
        $corpo = json_decode((string) $resposta->getContent(), true);
        self::assertIsArray($corpo, (string) $resposta->getContent());
        self::assertSame($id, $corpo['id'] ?? null);
        self::assertArrayNotHasKey('result', $corpo, 'nada foi executado');
        self::assertSame(ConectorDesligadoMiddleware::CODIGO, $corpo['error']['code'] ?? null);
        self::assertSame('Conector desligado neste escritório.', $corpo['error']['message'] ?? null);
        self::assertStringNotContainsString('Pessoa do Conector', (string) $resposta->getContent(), 'nenhum dado lido');
    }

    private function exigirQuemSouEu(Response $resposta, int $id): void
    {
        self::assertSame(200, $resposta->getStatusCode(), mb_substr((string) $resposta->getContent(), 0, 600));
        $corpo = json_decode((string) $resposta->getContent(), true);
        self::assertIsArray($corpo);
        self::assertSame($id, $corpo['id'] ?? null);
        self::assertFalse($corpo['result']['isError'] ?? null, (string) $resposta->getContent());
        self::assertSame($this->tenantId, $corpo['result']['structuredContent']['escritorio']['id'] ?? null);
    }

    /** @return array{access_token: string, refresh_token: string} */
    private function conectado(): array
    {
        $this->clienteValido();
        $tenant = $this->escritorio();
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $tenant);
        $this->liberarConector($tenant);
        $this->logarComTenant($this->client, $pessoa, $tenant);
        $this->tenantId = (int) $tenant->getId();
        $this->pessoaId = (int) $pessoa->getId();

        return $this->exigirPar($this->trocarCodigo($this->autorizar($this->tenantId)));
    }

    private static function desafioDe(string $verificador): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verificador, true)), '+/', '-_'), '=');
    }

    private function autorizar(int $tenantId): string
    {
        $tela = $this->client->request('GET', '/oauth/authorize?' . http_build_query($this->pedidoValido([
            'state' => 'estado-' . bin2hex(random_bytes(8)),
            'code_challenge' => self::desafioDe($this->verificador),
            'code_challenge_method' => 'S256',
            'resource' => ServidorDeAutorizacao::RESOURCE,
        ])));
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/oauth/authorize', [self::FORM => [
            'pedido' => (string) $tela->filter('input[name="' . self::FORM . '[pedido]"]')->attr('value'),
            '_token' => (string) $tela->filter('input[name="' . self::FORM . '[_token]"]')->attr('value'),
            'tenantId' => (string) $tenantId,
            'autorizar' => '',
        ]]);
        self::assertResponseStatusCodeSame(302);
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $parametros);
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
    private function exigirPar(Response $resposta): array
    {
        self::assertSame(200, $resposta->getStatusCode(), (string) $resposta->getContent());
        $par = json_decode((string) $resposta->getContent(), true);
        self::assertIsString($par['access_token'] ?? null);
        self::assertIsString($par['refresh_token'] ?? null);

        return ['access_token' => $par['access_token'], 'refresh_token' => $par['refresh_token']];
    }

    private function chamarQuemSouEu(string $token, int $id): Response
    {
        $mensagem = ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => QuemSouEuFerramenta::NOME, 'arguments' => new \stdClass()]];
        $this->client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => self::VERSAO,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], content: json_encode($mensagem, \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    private function consentimentoDoToken(string $token): ConsentimentoDoConector
    {
        $id = $this->em()->getConnection()->fetchOne(
            'SELECT c.consentimento_id FROM conector_ia_token t JOIN conector_ia_concessao c ON c.id = t.concessao_id WHERE t.identificador_hash = ?',
            [hash('sha256', $token)],
        );
        self::assertNotFalse($id);

        return $this->em()->find(ConsentimentoDoConector::class, (int) $id) ?? throw new \LogicException('sem consentimento');
    }

    private function concessaoDoToken(string $token): int
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT concessao_id FROM conector_ia_token WHERE identificador_hash = ?', [hash('sha256', $token)]);
        self::assertNotFalse($id);

        return (int) $id;
    }

    private function revogadaEm(int $concessaoId): mixed
    {
        return $this->em()->getConnection()->fetchOne('SELECT revogada_em FROM conector_ia_concessao WHERE id = ?', [$concessaoId]);
    }

    private function zerarUltimoUso(int $consentimentoId): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE conector_ia_consentimento SET ultimo_uso_em = NULL WHERE id = ?', [$consentimentoId]);
    }

    private function ultimoUso(int $consentimentoId): mixed
    {
        return $this->em()->getConnection()->fetchOne('SELECT ultimo_uso_em FROM conector_ia_consentimento WHERE id = ?', [$consentimentoId]);
    }

    /** @return list<array<string, mixed>> o contexto JSON de cada linha do `/mcp` no log */
    private function linhasDoLog(string $arquivo): array
    {
        $linhas = [];
        foreach (explode("\n", (string) @file_get_contents($arquivo)) as $linha) {
            if (preg_match('/' . preg_quote(RegistroDaChamadaMiddleware::MENSAGEM, '/') . ' (\{.*\}) \[\]\s*$/', $linha, $partes) === 1) {
                $contexto = json_decode($partes[1], true);
                self::assertIsArray($contexto, $linha);
                $linhas[] = $contexto;
            }
        }

        return $linhas;
    }
}
