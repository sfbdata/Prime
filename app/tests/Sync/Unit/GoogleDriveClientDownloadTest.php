<?php

declare(strict_types=1);

namespace App\Tests\Sync\Unit;

use App\Sync\Exception\DownloadDoDriveFalhouException;
use App\Sync\Service\GoogleDriveClient;
use App\Tests\Sync\Support\DriveHttpFalso;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * DT-8 — o download do Drive só vale quando a resposta final é 200 (spec `dt8-download-drive.md`).
 *
 * O cliente HTTP daqui tem `http_errors` LIGADO de propósito: se a checagem de status dependesse do
 * Guzzle, o 4xx/5xx chegaria como exceção de transporte, sem status — e os testes pegariam.
 */
#[CoversClass(GoogleDriveClient::class)]
#[CoversClass(DownloadDoDriveFalhouException::class)]
final class GoogleDriveClientDownloadTest extends TestCase
{
    private const REFRESH = 'refresh-do-escritorio';
    private const SEGREDO = 'segredo-do-app';
    private const PDF     = "%PDF-1.4\nconteudo-conhecido";

    private string $dir;
    private DriveHttpFalso $http;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dt8-download-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        $this->http = (new DriveHttpFalso())->tokenAceito();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $arquivo) {
            unlink($arquivo);
        }
        rmdir($this->dir);
    }

    private function drive(?bool $httpErrors = true): GoogleDriveClient
    {
        return GoogleDriveClient::comClienteHttp(
            googleDriveCredentials: null,
            googleDriveOauthClientId: 'id-do-app',
            googleDriveOauthClientSecret: self::SEGREDO,
            googleDriveOauthRefreshToken: self::REFRESH,
            clienteHttp: $this->http->clienteHttp($httpErrors),
        );
    }

    /** O chamador real cria o destino antes de baixar (`tempnam`); aqui também. */
    private function destino(): string
    {
        $destino = tempnam($this->dir, 'destino-');
        self::assertIsString($destino);

        return $destino;
    }

    private function baixarEsperandoFalha(GoogleDriveClient $drive, string $fileId, string $destino): DownloadDoDriveFalhouException
    {
        $erro = null;
        try {
            $drive->baixarArquivo($fileId, $destino);
        } catch (DownloadDoDriveFalhouException $e) {
            $erro = $e;
        }
        self::assertNotNull($erro, sprintf('o download de %s foi aceito como válido', $fileId));

        return $erro;
    }

    #[TestDox('200: grava os bytes recebidos, pede alt=media com Bearer')]
    public function testResposta200GravaOConteudoNoDestino(): void
    {
        $this->http->download('ARQ-1', DriveHttpFalso::responder(200, self::PDF, ['Content-Type' => 'application/pdf']));
        $destino = $this->destino();

        $this->drive()->baixarArquivo('ARQ-1', $destino);

        self::assertSame(self::PDF, file_get_contents($destino));
        $requisicoes = $this->http->requisicoesDaApi();
        self::assertCount(1, $requisicoes);
        self::assertSame('GET', $requisicoes[0]->getMethod());
        self::assertSame(
            'https://www.googleapis.com/drive/v3/files/ARQ-1?alt=media&supportsAllDrives=true',
            (string) $requisicoes[0]->getUri(),
        );
        self::assertSame('Bearer token-1', $requisicoes[0]->getHeaderLine('Authorization'));
    }

    #[TestDox('redirect seguido até 200: o destino fica só com o conteúdo final')]
    public function testRedirectAte200GravaSoOConteudoFinal(): void
    {
        $this->http
            // Corpo do 302 maior que o final: o destino tem de terminar só com o conteúdo final. Quem
            // trunca a cada salto é o transporte (no curl, o `LazyOpenStream('w+')` do Guzzle; aqui, o
            // dublê) — o teste prova que o client aceita a resposta final e só ela, não a truncagem.
            ->download('ARQ-MOVIDO', DriveHttpFalso::responder(302, '<HTML>Moved</HTML>' . str_repeat('#', 200), [
                'Location' => 'https://www.googleapis.com/drive/v3/files/ARQ-FINAL?alt=media',
            ]))
            ->download('ARQ-FINAL', DriveHttpFalso::responder(200, self::PDF));
        $destino = $this->destino();

        $this->drive()->baixarArquivo('ARQ-MOVIDO', $destino);

        self::assertSame(self::PDF, file_get_contents($destino));
        $requisicoes = $this->http->requisicoesDaApi();
        self::assertCount(2, $requisicoes);
        foreach ($requisicoes as $requisicao) {
            self::assertSame('Bearer token-1', $requisicao->getHeaderLine('Authorization'));
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function respostasQueNaoSao200(): iterable
    {
        yield '204 sem conteúdo' => [204, ''];
        yield '206 conteúdo parcial' => [206, substr(self::PDF, 0, 4)];
        yield '304 não modificado' => [304, ''];
        yield '401 credencial inválida' => [401, DriveHttpFalso::erroDoGoogle(401, 'authError', 'Invalid Credentials')];
        yield '403 cota de download' => [403, DriveHttpFalso::erroDoGoogle(403, 'downloadQuotaExceeded', 'The download quota for this file has been exceeded.')];
        yield '403 arquivo bloqueado' => [403, DriveHttpFalso::erroDoGoogle(403, 'cannotDownloadAbusiveFile', 'This file has been identified as malware or spam and cannot be downloaded.')];
        yield '404 inexistente' => [404, DriveHttpFalso::erroDoGoogle(404, 'notFound', 'File not found: ARQ-1.')];
        yield '429 limite de taxa' => [429, DriveHttpFalso::erroDoGoogle(429, 'rateLimitExceeded', 'Rate Limit Exceeded')];
        yield '500 erro interno' => [500, DriveHttpFalso::erroDoGoogle(500, 'internalError', 'Internal Error')];
        yield '502 página HTML' => [502, '<!DOCTYPE html><html lang=en><title>Error 502 (Server Error)!!1</title>'];
        yield '503 indisponível' => [503, DriveHttpFalso::erroDoGoogle(503, 'backendError', 'Backend Error')];
    }

    #[DataProvider('respostasQueNaoSao200')]
    #[TestDox('$_dataName: falha com status e fileId, e o destino não fica')]
    public function testRespostaQueNaoEh200FalhaERemoveODestino(int $status, string $corpo): void
    {
        $this->http->download('ARQ-1', DriveHttpFalso::responder($status, $corpo));
        $destino = $this->destino();

        $erro = $this->baixarEsperandoFalha($this->drive(), 'ARQ-1', $destino);

        self::assertSame($status, $erro->status, 'a falha não veio da nossa checagem de status');
        self::assertSame('ARQ-1', $erro->fileId);
        self::assertStringContainsString((string) $status, $erro->getMessage());
        self::assertStringContainsString('ARQ-1', $erro->getMessage());
        self::assertFileDoesNotExist($destino, 'o corpo da resposta inválida ficou no destino');
    }

    #[TestDox('com a configuração padrão do Google (http_errors desligado) o 403 também falha')]
    public function testConfiguracaoPadraoDoGoogleTambemFalha(): void
    {
        $this->http->download('ARQ-1', DriveHttpFalso::responder(
            403,
            DriveHttpFalso::erroDoGoogle(403, 'downloadQuotaExceeded', 'The download quota for this file has been exceeded.'),
        ));
        $destino = $this->destino();

        $erro = $this->baixarEsperandoFalha($this->drive(httpErrors: null), 'ARQ-1', $destino);

        self::assertSame(403, $erro->status);
        self::assertFileDoesNotExist($destino);
    }

    #[TestDox('o motivo do Google (reason e message) entra no diagnóstico')]
    public function testMotivoDoGoogleEntraNoDiagnostico(): void
    {
        $this->http->download('ARQ-1', DriveHttpFalso::responder(
            403,
            DriveHttpFalso::erroDoGoogle(403, 'downloadQuotaExceeded', 'The download quota for this file has been exceeded.'),
        ));

        $erro = $this->baixarEsperandoFalha($this->drive(), 'ARQ-1', $this->destino());

        self::assertSame('downloadQuotaExceeded: The download quota for this file has been exceeded.', $erro->motivo);
        self::assertStringContainsString('downloadQuotaExceeded', $erro->getMessage());
        self::assertStringContainsString('The download quota for this file has been exceeded.', $erro->getMessage());
    }

    /** @return iterable<string, array{string}> */
    public static function corposQueNaoSaoErroDoGoogle(): iterable
    {
        yield 'página HTML' => ['<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'];
        yield 'JSON sem o envelope error' => ['{"mensagem":"qualquer coisa"}'];
        yield 'error que não é objeto' => ['{"error":"invalid_request"}'];
        yield 'texto puro' => ['Service Unavailable'];
    }

    #[DataProvider('corposQueNaoSaoErroDoGoogle')]
    #[TestDox('corpo que não é o JSON de erro do Google não entra na mensagem')]
    public function testCorpoQueNaoEhErroDoGoogleNaoEntraNaMensagem(string $corpo): void
    {
        $this->http->download('ARQ-1', DriveHttpFalso::responder(502, $corpo));

        $erro = $this->baixarEsperandoFalha($this->drive(), 'ARQ-1', $this->destino());

        self::assertNull($erro->motivo);
        self::assertStringNotContainsString($corpo, $erro->getMessage());
        self::assertStringNotContainsString('<', $erro->getMessage());
    }

    #[TestDox('motivo longo ou com quebra de linha é limpo e truncado')]
    public function testMotivoEhLimpoETruncado(): void
    {
        $this->http->download('ARQ-1', DriveHttpFalso::responder(
            403,
            DriveHttpFalso::erroDoGoogle(403, 'forbidden', "linha 1\nlinha 2\t" . str_repeat('x', 1000)),
        ));

        $erro = $this->baixarEsperandoFalha($this->drive(), 'ARQ-1', $this->destino());

        self::assertIsString($erro->motivo);
        self::assertStringNotContainsString("\n", $erro->getMessage());
        self::assertStringNotContainsString("\t", $erro->getMessage());
        self::assertStringStartsWith('forbidden: linha 1 linha 2 x', $erro->motivo);
        self::assertStringEndsWith('x…', $erro->motivo);
        self::assertSame(201, mb_strlen($erro->motivo), 'o motivo não foi cortado em 200 caracteres');
    }

    #[TestDox('corpo de erro além do limite de leitura não é lido inteiro')]
    public function testCorpoDeErroAlemDoLimiteNaoEhLidoInteiro(): void
    {
        // O JSON do Google vem DEPOIS de 20 KB de espaços: lido até o limite, sobram só espaços.
        $this->http->download('ARQ-1', DriveHttpFalso::responder(
            403,
            str_repeat(' ', 20000) . DriveHttpFalso::erroDoGoogle(403, 'downloadQuotaExceeded', 'Quota'),
        ));

        $erro = $this->baixarEsperandoFalha($this->drive(), 'ARQ-1', $this->destino());

        self::assertSame(403, $erro->status);
        self::assertNull($erro->motivo, 'o corpo de erro foi lido além do limite');
    }

    #[TestDox('reason que não é identificador não entra no motivo')]
    public function testReasonQueNaoEhIdentificadorNaoEntra(): void
    {
        $this->http->download('ARQ-1', DriveHttpFalso::responder(
            403,
            DriveHttpFalso::erroDoGoogle(403, "cota estourada\n<b>", 'Quota exceeded'),
        ));

        $erro = $this->baixarEsperandoFalha($this->drive(), 'ARQ-1', $this->destino());

        self::assertSame('Quota exceeded', $erro->motivo);
    }

    #[TestDox('credenciais ecoadas no corpo do erro não aparecem na mensagem')]
    public function testMensagemNaoExpoeCredenciaisEcoadasNoCorpo(): void
    {
        $this->http->download('ARQ-1', DriveHttpFalso::responder(
            401,
            DriveHttpFalso::erroDoGoogle(
                401,
                self::REFRESH,
                sprintf('Bearer token-1 recusado; refresh %s; secret %s', self::REFRESH, self::SEGREDO),
            ),
        ));

        $erro = $this->baixarEsperandoFalha($this->drive(), 'ARQ-1', $this->destino());

        foreach (['token-1', self::REFRESH, self::SEGREDO] as $segredo) {
            self::assertStringNotContainsString($segredo, $erro->getMessage());
            self::assertStringNotContainsString($segredo, (string) $erro->motivo);
        }
        self::assertStringContainsString('401', $erro->getMessage());
    }

    /** @return iterable<string, array{string, \Closure(RequestInterface): \Throwable}> */
    public static function falhasDeTransporte(): iterable
    {
        // Guzzle 7.14: só os erros 6, 7, 28, 35 e 52 do cURL viram ConnectException; o resto é RequestException.
        yield 'tempo esgotado no meio do corpo' => [
            'ConnectException',
            static fn (RequestInterface $r): \Throwable => new ConnectException('cURL error 28: Operation timed out after 300000 milliseconds with 5 out of 27 bytes received', $r),
        ];
        yield 'conexão caiu no meio do corpo' => [
            'RequestException',
            static fn (RequestInterface $r): \Throwable => new RequestException('cURL error 56: Recv failure: Connection reset by peer', $r),
        ];
        yield 'corpo menor que o Content-Length' => [
            'RequestException',
            static fn (RequestInterface $r): \Throwable => new RequestException('cURL error 18: end of response with 900 bytes missing', $r),
        ];
    }

    /** @param \Closure(RequestInterface): \Throwable $falha */
    #[DataProvider('falhasDeTransporte')]
    #[TestDox('falha de transporte com parte do corpo gravada: falha e o destino parcial não fica')]
    public function testFalhaDeTransporteRemoveODestinoParcial(string $causa, \Closure $falha): void
    {
        $this->http->download('ARQ-1', static function (RequestInterface $r, array $opcoes) use ($falha): \Throwable {
            file_put_contents((string) $opcoes['sink'], substr(self::PDF, 0, 5));

            return $falha($r);
        });
        $destino = $this->destino();

        $erro = $this->baixarEsperandoFalha($this->drive(), 'ARQ-1', $destino);

        self::assertNull($erro->status);
        self::assertSame('ARQ-1', $erro->fileId);
        self::assertStringContainsString('ARQ-1', $erro->getMessage());
        self::assertStringContainsString('(' . $causa . ')', $erro->getMessage());
        self::assertStringContainsString('cURL error', $erro->getMessage());
        // A exceção do Guzzle carrega a requisição, com o Authorization: não pode seguir encadeada.
        self::assertNull($erro->getPrevious());
        self::assertFileDoesNotExist($destino, 'o conteúdo parcial ficou no destino');
    }

    #[TestDox('credencial ecoada na mensagem de transporte não aparece no diagnóstico')]
    public function testFalhaDeTransporteNaoExpoeCredenciais(): void
    {
        $this->http->download('ARQ-1', static fn (RequestInterface $r): \Throwable => new ConnectException(
            sprintf('falhou com Bearer token-1, refresh %s e secret %s', self::REFRESH, self::SEGREDO),
            $r,
        ));

        $erro = $this->baixarEsperandoFalha($this->drive(), 'ARQ-1', $this->destino());

        foreach (['token-1', self::REFRESH, self::SEGREDO] as $segredo) {
            self::assertStringNotContainsString($segredo, $erro->getMessage());
        }
    }

    #[TestDox('destino que não pode ser apagado é ao menos esvaziado')]
    public function testDestinoQueNaoPodeSerApagadoEhEsvaziado(): void
    {
        $this->http->download('ARQ-1', DriveHttpFalso::responder(
            403,
            DriveHttpFalso::erroDoGoogle(403, 'downloadQuotaExceeded', 'The download quota for this file has been exceeded.'),
        ));
        if (posix_geteuid() === 0) {
            self::markTestSkipped('como root o chmod do diretório não impede a remoção');
        }
        $destino = $this->destino();
        chmod($this->dir, 0500); // sem escrita no diretório: o unlink falha, o arquivo continua gravável
        try {
            $this->baixarEsperandoFalha($this->drive(), 'ARQ-1', $destino);
            clearstatcache(true, $destino);

            self::assertFileExists($destino, 'pré-condição: o diretório devia impedir a remoção');
            self::assertSame('', file_get_contents($destino), 'o corpo do erro ficou no destino');
        } finally {
            chmod($this->dir, 0700);
        }
    }

    #[TestDox('redirects demais: falha sem status e o destino não fica')]
    public function testRedirectsDemaisFalhamERemovemODestino(): void
    {
        $this->http->download('ARQ-LOOP', DriveHttpFalso::responder(302, '<HTML>loop</HTML>', [
            'Location' => 'https://www.googleapis.com/drive/v3/files/ARQ-LOOP?alt=media',
        ]));
        $destino = $this->destino();

        $erro = $this->baixarEsperandoFalha($this->drive(), 'ARQ-LOOP', $destino);

        self::assertNull($erro->status);
        self::assertStringContainsString('(TooManyRedirectsException)', $erro->getMessage());
        self::assertNull($erro->getPrevious());
        self::assertFileDoesNotExist($destino);
    }
}
