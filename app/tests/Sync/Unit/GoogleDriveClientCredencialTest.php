<?php

declare(strict_types=1);

namespace App\Tests\Sync\Unit;

use App\Sync\Exception\DownloadDoDriveFalhouException;
use App\Sync\Exception\TokenDoDriveRecusadoException;
use App\Sync\Service\GoogleDriveClient;
use App\Tests\Sync\Support\DriveHttpFalso;
use Google\Client as GoogleClient;
use GuzzleHttp\Exception\ConnectException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * DT-8 — sem credencial, nada sai para a API; e a renovação não perde o refresh token.
 *
 * Aqui o cliente HTTP usa a configuração PADRÃO do Google (`http_errors` desligado), que é a da
 * produção: é nela que o `refreshToken()` recusado volta calado em vez de lançar.
 */
#[CoversClass(GoogleDriveClient::class)]
#[CoversClass(TokenDoDriveRecusadoException::class)]
final class GoogleDriveClientCredencialTest extends TestCase
{
    private const REFRESH = 'refresh-do-escritorio';
    private const SEGREDO = 'segredo-do-app';

    private string $dir;
    private DriveHttpFalso $http;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dt8-credencial-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        $this->http = new DriveHttpFalso();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $arquivo) {
            unlink($arquivo);
        }
        rmdir($this->dir);
    }

    private function drive(): GoogleDriveClient
    {
        return GoogleDriveClient::comClienteHttp(
            googleDriveCredentials: null,
            googleDriveOauthClientId: 'id-do-app',
            googleDriveOauthClientSecret: self::SEGREDO,
            googleDriveOauthRefreshToken: self::REFRESH,
            clienteHttp: $this->http->clienteHttp(),
        );
    }

    private function destino(): string
    {
        $destino = tempnam($this->dir, 'destino-');
        self::assertIsString($destino);

        return $destino;
    }

    private function googleDe(GoogleDriveClient $drive): GoogleClient
    {
        $google = (new \ReflectionProperty(GoogleDriveClient::class, 'client'))->getValue($drive);
        self::assertInstanceOf(GoogleClient::class, $google);

        return $google;
    }

    /**
     * Simula a passagem de 2h: o token vence e o cache do middleware do Google também. Sem limpar o
     * cache, o middleware devolveria o token guardado — o que no tempo real não aconteceria.
     */
    private function vencerToken(GoogleClient $google): void
    {
        $token = $google->getAccessToken();
        self::assertIsArray($token);
        $token['created'] = time() - 7200;
        $google->setAccessToken($token);
        $google->getCache()->clear();
    }

    /** @param \Closure(): mixed $operacao */
    private function recusa(\Closure $operacao): TokenDoDriveRecusadoException
    {
        $erro = null;
        try {
            $operacao();
        } catch (TokenDoDriveRecusadoException $e) {
            $erro = $e;
        }
        self::assertNotNull($erro, 'a operação seguiu sem access token');

        return $erro;
    }

    /** @param \Closure(): mixed $operacao */
    private function falhaDoDownload(\Closure $operacao): DownloadDoDriveFalhouException
    {
        $erro = null;
        try {
            $operacao();
        } catch (DownloadDoDriveFalhouException $e) {
            $erro = $e;
        }
        self::assertNotNull($erro, 'a falha não virou DownloadDoDriveFalhouException');

        return $erro;
    }

    private function recusarRenovacaoCom(int $status, string $corpo): void
    {
        $this->http->rota('POST', DriveHttpFalso::HOST_TOKEN . '/token', DriveHttpFalso::responder(
            $status,
            $corpo,
            ['Content-Type' => 'application/json'],
        ));
    }

    #[TestDox('renovação recusada (invalid_grant) falha ANTES do GET e manda reconectar')]
    public function testRenovacaoRecusadaFalhaAntesDoGet(): void
    {
        $this->http->tokenRecusado()->download('ARQ-1', DriveHttpFalso::responder(200, 'nao deveria chegar aqui'));
        $destino = $this->destino();

        $erro = $this->recusa(fn () => $this->drive()->baixarArquivo('ARQ-1', $destino));

        self::assertSame([], $this->http->requisicoesDaApi(), 'uma chamada saiu para a API sem credencial');
        self::assertStringContainsString('invalid_grant', $erro->getMessage());
        self::assertStringContainsString('Reconecte o Drive do escritório', $erro->getMessage());
        self::assertFileDoesNotExist($destino);
    }

    #[TestDox('renovação que volta 200 sem access_token também é recusa')]
    public function testRenovacaoSemAccessTokenEhRecusa(): void
    {
        $this->recusarRenovacaoCom(200, '{"token_type":"Bearer","expires_in":3599}');
        $this->http->download('ARQ-1', DriveHttpFalso::responder(200, 'nao deveria chegar aqui'));

        $this->recusa(fn () => $this->drive()->baixarArquivo('ARQ-1', $this->destino()));

        self::assertSame([], $this->http->requisicoesDaApi());
    }

    #[TestDox('renovação que devolve access_token vazio também é recusa')]
    public function testRenovacaoComAccessTokenVazioEhRecusa(): void
    {
        $this->recusarRenovacaoCom(200, '{"access_token":"","expires_in":3599}');
        $this->http->download('ARQ-1', DriveHttpFalso::responder(200, 'nao deveria chegar aqui'));

        $this->recusa(fn () => $this->drive()->baixarArquivo('ARQ-1', $this->destino()));

        self::assertSame([], $this->http->requisicoesDaApi());
    }

    #[TestDox('recusa que não é invalid_grant não manda reconectar; invalid_client aponta a configuração do app')]
    public function testDicaDaRecusaDependeDoErro(): void
    {
        $this->recusarRenovacaoCom(500, '{"error":"internal_failure","error_description":"Backend Error"}');
        $erro = $this->recusa(fn () => $this->drive()->baixarArquivo('ARQ-1', $this->destino()));
        self::assertStringContainsString('internal_failure', $erro->getMessage());
        self::assertStringNotContainsString('Reconecte', $erro->getMessage());

        $this->recusarRenovacaoCom(401, '{"error":"invalid_client","error_description":"Unauthorized"}');
        $erro = $this->recusa(fn () => $this->drive()->baixarArquivo('ARQ-1', $this->destino()));
        self::assertStringContainsString('GOOGLE_DRIVE_OAUTH_CLIENT_ID', $erro->getMessage());
        self::assertStringNotContainsString('Reconecte', $erro->getMessage());
    }

    #[TestDox('a mensagem da recusa sai numa linha, curta e sem credencial, mesmo se o Google as ecoar')]
    public function testMensagemDaRecusaEhLimpa(): void
    {
        $this->recusarRenovacaoCom(400, (string) json_encode([
            'error'             => 'invalid_grant',
            'error_description' => sprintf("refresh %s\nsecret %s\t", self::REFRESH, self::SEGREDO) . str_repeat('y', 1000),
        ]));

        $erro = $this->recusa(fn () => $this->drive()->baixarArquivo('ARQ-1', $this->destino()));

        self::assertStringNotContainsString(self::REFRESH, $erro->getMessage());
        self::assertStringNotContainsString(self::SEGREDO, $erro->getMessage());
        self::assertStringNotContainsString("\n", $erro->getMessage());
        self::assertStringNotContainsString("\t", $erro->getMessage());
        self::assertStringContainsString('invalid_grant: refresh *** secret *** y', $erro->getMessage());
        self::assertLessThan(400, mb_strlen($erro->getMessage()));
    }

    #[TestDox('erro que não tem forma de identificador não entra como código da recusa')]
    public function testErroDaRecusaQueNaoEhIdentificadorNaoEntra(): void
    {
        $this->recusarRenovacaoCom(400, (string) json_encode(['error' => "invalid_grant\n<b>", 'error_description' => 'Bad Request']));

        $erro = $this->recusa(fn () => $this->drive()->baixarArquivo('ARQ-1', $this->destino()));

        self::assertStringNotContainsString('<b>', $erro->getMessage());
        self::assertStringNotContainsString('Reconecte', $erro->getMessage());
        self::assertStringContainsString('Bad Request', $erro->getMessage());
    }

    #[TestDox('falha de rede na renovação vira falha do download, sem GET e sem destino')]
    public function testFalhaDeRedeNaRenovacaoViraFalhaDoDownload(): void
    {
        $this->http
            ->rota('POST', DriveHttpFalso::HOST_TOKEN . '/token', static fn (RequestInterface $r): \Throwable => new ConnectException('cURL error 7: Failed to connect', $r))
            ->download('ARQ-1', DriveHttpFalso::responder(200, 'nao deveria chegar aqui'));
        $destino = $this->destino();

        $erro = null;
        try {
            $this->drive()->baixarArquivo('ARQ-1', $destino);
        } catch (DownloadDoDriveFalhouException $e) {
            $erro = $e;
        }

        self::assertNotNull($erro, 'a falha de rede na renovação não virou falha do download');
        self::assertNull($erro->status);
        self::assertStringContainsString('ConnectException', $erro->getMessage());
        self::assertSame([], $this->http->requisicoesDaApi());
        self::assertFileDoesNotExist($destino);
    }

    #[TestDox('falha passageira na renovação não fica guardada: a mesma instância tenta de novo e baixa')]
    public function testFalhaPassageiraNaRenovacaoNaoFicaGuardada(): void
    {
        $this->http
            ->rota('POST', DriveHttpFalso::HOST_TOKEN . '/token', static fn (RequestInterface $r): \Throwable => new ConnectException('cURL error 7: Failed to connect', $r))
            ->download('ARQ-1', DriveHttpFalso::responder(200, 'conteudo'));
        $drive = $this->drive();
        $this->falhaDoDownload(fn () => $drive->baixarArquivo('ARQ-1', $this->destino()));

        $this->recusarRenovacaoCom(502, '<html>Bad Gateway</html>');
        $this->falhaDoDownload(fn () => $drive->baixarArquivo('ARQ-1', $this->destino()));

        $this->http->tokenAceito();
        $destino = $this->destino();
        $drive->baixarArquivo('ARQ-1', $destino);

        self::assertSame('conteudo', file_get_contents($destino));
        self::assertSame(3, $this->http->tokensPedidos());
    }

    #[TestDox('resposta ilegível da renovação vira falha do download, sem GET')]
    public function testRespostaIlegivelDaRenovacaoViraFalhaDoDownload(): void
    {
        $this->http
            ->rota('POST', DriveHttpFalso::HOST_TOKEN . '/token', DriveHttpFalso::responder(502, '<html>Bad Gateway</html>', ['Content-Type' => 'text/html']))
            ->download('ARQ-1', DriveHttpFalso::responder(200, 'nao deveria chegar aqui'));

        $destino = $this->destino();

        $erro = $this->falhaDoDownload(fn () => $this->drive()->baixarArquivo('ARQ-1', $destino));

        self::assertNull($erro->status);
        self::assertStringContainsString('(Exception): Invalid JSON response', $erro->getMessage());
        self::assertSame([], $this->http->requisicoesDaApi());
        self::assertFileDoesNotExist($destino);
    }

    #[TestDox('renovação com JSON que não é objeto (TypeError na biblioteca) também vira falha do download')]
    public function testRenovacaoComJsonQueNaoEhObjetoViraFalhaDoDownload(): void
    {
        $this->recusarRenovacaoCom(200, '"ok"');
        $this->http->download('ARQ-1', DriveHttpFalso::responder(200, 'nao deveria chegar aqui'));
        $destino = $this->destino();

        $erro = $this->falhaDoDownload(fn () => $this->drive()->baixarArquivo('ARQ-1', $destino));

        self::assertStringContainsString('(TypeError)', $erro->getMessage());
        // Sem a exceção encadeada, o Error diz onde aconteceu — aqui, na biblioteca do Google.
        self::assertMatchesRegularExpression('/ em OAuth2\.php:\d+$/', $erro->getMessage());
        self::assertSame([], $this->http->requisicoesDaApi());
        self::assertFileDoesNotExist($destino);
    }

    /** @return iterable<string, array{string}> */
    public static function renovacoesSemValidade(): iterable
    {
        yield 'sem expires_in' => ['{"access_token":"token-sem-validade","token_type":"Bearer"}'];
        yield 'expires_in dentro da margem de 30 s' => ['{"access_token":"token-sem-validade","expires_in":10}'];
    }

    /**
     * Um token que o Google\Client já considera vencido faria o `authorize()` renovar de novo pelo
     * middleware, sem a nossa checagem — e um GET poderia sair sem Authorization.
     */
    #[DataProvider('renovacoesSemValidade')]
    #[TestDox('renovação que devolve token já vencido ($_dataName) é recusa, sem GET')]
    public function testRenovacaoComTokenJaVencidoEhRecusa(string $corpo): void
    {
        $this->recusarRenovacaoCom(200, $corpo);
        $this->http->download('ARQ-1', DriveHttpFalso::responder(200, 'nao deveria chegar aqui'));

        $erro = $this->recusa(fn () => $this->drive()->baixarArquivo('ARQ-1', $this->destino()));

        self::assertStringContainsString('validade', $erro->getMessage());
        self::assertStringNotContainsString('não devolveu access token ao', $erro->getMessage(), 'a mensagem nega o token que veio');
        self::assertStringNotContainsString('token-sem-validade', $erro->getMessage());
        self::assertSame([], $this->http->requisicoesDaApi());
        self::assertSame(1, $this->http->tokensPedidos(), 'o middleware do Google renovou por fora da checagem');
    }

    #[TestDox('sem credencial, NENHUMA operação chama a API, e a recusa não é pedida de novo na mesma instância')]
    public function testSemCredencialNenhumaOperacaoChamaAApi(): void
    {
        $this->http->tokenRecusado();
        $drive  = $this->drive();
        $origem = $this->destino();

        $operacoes = [
            'baixarArquivo'   => fn () => $drive->baixarArquivo('ARQ-1', $this->destino()),
            'listarArquivos'  => fn () => $drive->listarArquivos('PASTA-1'),
            'listarSubpastas' => fn () => $drive->listarSubpastas('PASTA-1'),
            'criarPasta'      => fn () => $drive->criarPasta('NOVA', 'PASTA-1'),
            'renomearPasta'   => fn () => $drive->renomearPasta('PASTA-1', 'NOVO NOME'),
            'enviarArquivo'   => fn () => $drive->enviarArquivo('PASTA-1', 'a.pdf', $origem, 'application/pdf'),
        ];
        foreach ($operacoes as $nome => $operacao) {
            $recusou = false;
            try {
                $operacao();
            } catch (TokenDoDriveRecusadoException) {
                $recusou = true;
            }
            self::assertTrue($recusou, sprintf('%s não recusou a falta de credencial', $nome));
        }

        self::assertSame([], $this->http->requisicoesDaApi(), 'uma chamada saiu para a API sem credencial');
        self::assertSame(1, $this->http->tokensPedidos(), 'a mesma instância pediu de novo um token já recusado');

        // Outra instância (a próxima rodada, a próxima mensagem) tenta de novo.
        $this->recusa(fn () => $this->drive()->listarArquivos('PASTA-1'));
        self::assertSame(2, $this->http->tokensPedidos());
    }

    #[TestDox('com a renovação aceita, todo GET leva Authorization')]
    public function testComRenovacaoAceitaTodoGetLevaAuthorization(): void
    {
        $this->http->tokenAceito()
            ->download('ARQ-1', DriveHttpFalso::responder(200, 'um'))
            ->download('ARQ-2', DriveHttpFalso::responder(200, 'dois'));
        $drive = $this->drive();

        $drive->baixarArquivo('ARQ-1', $this->destino());
        $drive->baixarArquivo('ARQ-2', $this->destino());

        $requisicoes = $this->http->requisicoesDaApi();
        self::assertCount(2, $requisicoes);
        foreach ($requisicoes as $requisicao) {
            self::assertSame('Bearer token-1', $requisicao->getHeaderLine('Authorization'));
        }
        self::assertSame(1, $this->http->tokensPedidos());
    }

    #[TestDox('token vencido é renovado pelo próprio client antes do download')]
    public function testTokenVencidoEhRenovadoAntesDoDownload(): void
    {
        $this->http->tokenAceito()->download('ARQ-1', DriveHttpFalso::responder(200, 'conteudo'));
        $drive = $this->drive();
        $drive->baixarArquivo('ARQ-1', $this->destino());

        $google = $this->googleDe($drive);
        $this->vencerToken($google);
        $drive->baixarArquivo('ARQ-1', $this->destino());

        self::assertSame(2, $this->http->tokensPedidos());
        $requisicoes = $this->http->requisicoesDaApi();
        self::assertSame('Bearer token-2', end($requisicoes)->getHeaderLine('Authorization'));
        self::assertSame(self::REFRESH, $google->getRefreshToken());
    }

    #[TestDox('renovação recusada no meio da rodada: o download falha sem GET, e a recusa fica na instância')]
    public function testRenovacaoRecusadaNoMeioDaRodadaFalhaSemGet(): void
    {
        $this->http->tokenAceito()->download('ARQ-1', DriveHttpFalso::responder(200, 'conteudo'));
        $drive = $this->drive();
        $drive->baixarArquivo('ARQ-1', $this->destino());
        $this->vencerToken($this->googleDe($drive));
        $this->http->tokenRecusado();
        $destino = $this->destino();

        $erro = $this->recusa(fn () => $drive->baixarArquivo('ARQ-1', $destino));

        self::assertStringContainsString('invalid_grant', $erro->getMessage());
        self::assertCount(1, $this->http->requisicoesDaApi(), 'um GET saiu com o token vencido ou sem credencial');
        self::assertFileDoesNotExist($destino);

        $this->recusa(fn () => $drive->baixarArquivo('ARQ-1', $this->destino()));
        self::assertSame(2, $this->http->tokensPedidos(), 'a mesma instância pediu de novo um token já recusado');
        self::assertCount(1, $this->http->requisicoesDaApi());
    }

    #[TestDox('renovação sem access_token no meio da rodada: nenhum GET sai sem Authorization')]
    public function testRenovacaoSemAccessTokenNoMeioDaRodadaFalhaSemGet(): void
    {
        $this->http->tokenAceito()->download('ARQ-1', DriveHttpFalso::responder(200, 'conteudo'));
        $drive = $this->drive();
        $drive->baixarArquivo('ARQ-1', $this->destino());
        $this->vencerToken($this->googleDe($drive));
        $this->recusarRenovacaoCom(200, '{"token_type":"Bearer","expires_in":3599}');

        $this->recusa(fn () => $drive->baixarArquivo('ARQ-1', $this->destino()));

        foreach ($this->http->requisicoesDaApi() as $requisicao) {
            self::assertSame('Bearer token-1', $requisicao->getHeaderLine('Authorization'), 'um GET saiu sem credencial válida');
        }
        self::assertCount(1, $this->http->requisicoesDaApi());
    }

    #[TestDox('a renovação feita pelo middleware (listagens, envios) preserva o refresh token: a 2ª expiração renova de novo')]
    public function testRenovacaoPeloMiddlewarePreservaORefreshToken(): void
    {
        $this->http->tokenAceito()->rota('GET', DriveHttpFalso::HOST_API . '/drive/v3/files', DriveHttpFalso::responder(
            200,
            '{"files":[]}',
            ['Content-Type' => 'application/json'],
        ));
        $drive = $this->drive();

        $drive->listarArquivos('PASTA-1');
        $google = $this->googleDe($drive);
        self::assertSame(self::REFRESH, $google->getRefreshToken());

        // 1ª expiração: o middleware renova (token-2) e o token regravado precisa manter o refresh.
        $this->vencerToken($google);
        $drive->listarArquivos('PASTA-1');
        self::assertSame('token-2', $google->getAccessToken()['access_token'] ?? null);
        self::assertSame(self::REFRESH, $google->getRefreshToken(), 'a renovação pelo middleware perdeu o refresh token');

        // 2ª expiração: sem o refresh token, o Google mandaria o token-2 vencido sem renovar.
        $this->vencerToken($google);
        $drive->listarArquivos('PASTA-1');

        self::assertSame(3, $this->http->tokensPedidos(), 'a segunda expiração não pediu token novo');
        $requisicoes = $this->http->requisicoesDaApi();
        self::assertSame('Bearer token-3', end($requisicoes)->getHeaderLine('Authorization'));
        self::assertSame(self::REFRESH, $google->getRefreshToken());
    }
}
