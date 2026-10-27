<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\EspelhoRemoto;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\EspelhoRemoto\FalhaNoEspelhoRemoto;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use App\Shared\EspelhoRemoto\R2\ClienteR2;
use App\Shared\EspelhoRemoto\R2\EspelhoRemotoR2;
use App\Tests\Shared\Doubles\S3Simulado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use AsyncAws\Core\AbstractApi;
use AsyncAws\Core\Credentials\Credentials;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * O que só o adaptador R2 tem de provar, além do contrato: o que vai pelo fio.
 *
 * Tudo contra o {@see S3Simulado} — sem rede, sem credencial real. O que isto NÃO prova (a
 * assinatura criptograficamente aceita pelo R2, o R2 aceitar `x-amz-checksum-sha256`) é da P2.4.
 */
#[CoversClass(EspelhoRemotoR2::class)]
#[CoversClass(ClienteR2::class)]
final class EspelhoRemotoR2Test extends TestCase
{
    private const SEGREDO = 'SEGREDO-DE-TESTE-QUE-NUNCA-PODE-VAZAR';

    private S3Simulado $s3;
    private EspelhoRemotoR2 $espelho;

    protected function setUp(): void
    {
        $this->s3      = new S3Simulado();
        $this->espelho = new EspelhoRemotoR2(ClienteR2::criar($this->s3->endpoint, $this->s3->chaveDeAcesso, self::SEGREDO, $this->s3->cliente()), $this->s3->bucket);
    }

    /**
     * Os 326 nomes fora do padrão da P1 (fixture anonimizado, mesma forma — ver
     * `LayoutDeChaveRemotaTest`) atravessam o cliente S3 de verdade: a chave que CHEGA ao destino,
     * decodificada da URL, é byte a byte a do layout; o conteúdo volta intacto; e o HEAD acha o
     * objeto pela mesma chave.
     */
    #[TestDox('os 326 legados da P1 chegam ao destino com a chave byte a byte do layout — Unicode, espaço nas bordas e ／ inclusos')]
    public function testLegadosDaP1PeloFio(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/legados-p1-anonimizados.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(326, $fixture['nomes']);

        foreach ($fixture['nomes'] as $i => $nome) {
            $chave    = new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, $nome);
            $conteudo = 'legado #' . $i;
            $this->enviar($chave, $conteudo);

            $esperada = LayoutDeChaveRemota::chaveDe($chave);
            self::assertSame($conteudo, $this->s3->conteudoDe($esperada), sprintf('#%d %s', $i, json_encode($nome, \JSON_UNESCAPED_UNICODE)));
            self::assertTrue($this->espelho->existe($chave));
        }

        self::assertCount(326, $this->s3->chavesGravadas(), 'nenhuma chave colidiu nem foi reescrita em outra');
        foreach ($this->s3->requisicoes() as $requisicao) {
            self::assertNotNull($requisicao['chave']);
            self::assertStringNotContainsString("\u{201B}", $requisicao['chave'], 'o ‛ do rclone não pode aparecer');
            self::assertStringStartsWith('core/t/1/uploads/pastas/', $requisicao['chave']);
        }
    }

    #[TestDox('as nove categorias chegam ao destino nas chaves da tabela ratificada (inclusive tarefa e a foto global)')]
    public function testNoveCategoriasPeloFio(): void
    {
        $casos = [
            [CategoriaDeArquivo::PASTA_DOCUMENTO, EscopoDeArquivo::deTenant(3), 'core/t/3/uploads/pastas/n.pdf'],
            [CategoriaDeArquivo::PASTA_IMAGEM_EDITOR, EscopoDeArquivo::deTenant(3), 'core/t/3/uploads/pastas/3/n.pdf'],
            [CategoriaDeArquivo::CLIENTE_DOCUMENTO, EscopoDeArquivo::deTenant(3), 'core/t/3/uploads/clientes/n.pdf'],
            [CategoriaDeArquivo::CHAMADO_ANEXO, EscopoDeArquivo::deTenant(3), 'core/t/3/uploads/chamados/n.pdf'],
            [CategoriaDeArquivo::JUSTIFICATIVA_ANEXO, EscopoDeArquivo::deTenant(3), 'core/t/3/uploads/justificativas/n.pdf'],
            [CategoriaDeArquivo::COBRANCA_DOCUMENTO, EscopoDeArquivo::deTenant(3), 'core/t/3/uploads/cobrancas/3/n.pdf'],
            [CategoriaDeArquivo::KANBAN_ANEXO, EscopoDeArquivo::deTenant(3), 'core/t/3/uploads/kanban/n.pdf'],
            [CategoriaDeArquivo::TAREFA_ANEXO, EscopoDeArquivo::deTenant(3), 'core/t/3/uploads/tarefas/chat/n.pdf'],
            [CategoriaDeArquivo::FOTO_PERFIL, EscopoDeArquivo::global(), 'core/g/uploads/perfil/n.pdf'],
        ];

        foreach ($casos as [$categoria, $escopo, $esperada]) {
            $this->enviar(new ChaveDeArquivo($escopo, $categoria, 'n.pdf'), $categoria->value);
            self::assertSame($categoria->value, $this->s3->conteudoDe($esperada), $categoria->value);
        }
    }

    #[TestDox('só PUT e HEAD saem pelo fio — nenhuma exclusão, em estilo de caminho, para o endpoint e o bucket configurados')]
    public function testSoPutEHead(): void
    {
        $chave = new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 'a.pdf');
        $this->enviar($chave, 'a');
        $this->espelho->consultar($chave);
        $this->espelho->existe(new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 'nao-existe.pdf'));

        self::assertEqualsCanonicalizing(['PUT', 'HEAD'], $this->s3->metodosUsados());
        foreach ($this->s3->requisicoes() as $requisicao) {
            self::assertStringStartsWith($this->s3->endpoint . '/' . $this->s3->bucket . '/core/', $requisicao['url']);
        }
    }

    #[TestDox('o PUT leva x-amz-checksum-sha256 (o destino recusa corpo corrompido), o SHA como metadado e o MIME')]
    public function testCabecalhosDeIntegridade(): void
    {
        $this->enviar(new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 'i.pdf'), 'íntegro', 'application/pdf');

        $put = array_values(array_filter($this->s3->requisicoes(), static fn (array $r): bool => $r['metodo'] === 'PUT'))[0];
        self::assertSame(base64_encode(hash('sha256', 'íntegro', true)), $put['cabecalhos']['x-amz-checksum-sha256']);
        self::assertSame(hash('sha256', 'íntegro'), $put['cabecalhos']['x-amz-meta-sha256']);
        self::assertSame('application/pdf', $put['cabecalhos']['content-type']);
        self::assertSame((string) \strlen('íntegro'), $put['cabecalhos']['content-length']);
        self::assertArrayNotHasKey('content-encoding', $put['cabecalhos'], 'sem aws-chunked');
    }

    #[TestDox('SHA declarado que não bate com o stream: o ADAPTADOR recusa e nenhum PUT sai pelo fio (não depende do destino)')]
    public function testShaErradoNaoSaiPeloFio(): void
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'conteúdo real');
        rewind($stream);

        try {
            $this->espelho->enviar(new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 's.pdf'), $stream, \strlen('conteúdo real'), hash('sha256', 'outro'), 'application/pdf');
            self::fail('SHA errado tinha de falhar');
        } catch (FalhaNoEspelhoRemoto $e) {
            self::assertStringContainsString('nada foi enviado', $e->getMessage());
        }

        self::assertSame([], $this->s3->requisicoes(), 'a recusa é do adaptador, antes de qualquer requisição');
    }

    #[TestDox('tamanho declarado que não bate com o stream: recusa e nenhum PUT sai')]
    public function testTamanhoErradoNaoSaiPeloFio(): void
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'abc');
        rewind($stream);

        $this->expectException(FalhaNoEspelhoRemoto::class);
        try {
            $this->espelho->enviar(new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 't.pdf'), $stream, 4, hash('sha256', 'abc'), 'application/pdf');
        } finally {
            self::assertSame([], $this->s3->requisicoes());
        }
    }

    #[TestDox('o provedor de credencial é FIXO — a cadeia padrão (WebIdentity, ~/.aws, contêiner, IMDS) nunca é montada')]
    public function testProvedorDeCredencialFixo(): void
    {
        $cliente  = ClienteR2::criar($this->s3->endpoint, $this->s3->chaveDeAcesso, self::SEGREDO, $this->s3->cliente());
        $provedor = (new \ReflectionProperty(AbstractApi::class, 'credentialProvider'))->getValue($cliente);

        self::assertInstanceOf(Credentials::class, $provedor);
    }

    #[TestDox('com AWS_ROLE_ARN e token de WebIdentity no ambiente, nada sai além do endpoint do R2 (sem STS)')]
    public function testWebIdentityDoAmbienteNaoEhUsada(): void
    {
        $token = tempnam(sys_get_temp_dir(), 'token-');
        file_put_contents((string) $token, 'token-falso');
        $nomes = ['AWS_ROLE_ARN' => 'arn:aws:iam::123456789012:role/falsa', 'AWS_WEB_IDENTITY_TOKEN_FILE' => (string) $token, 'AWS_ROLE_SESSION_NAME' => 'teste', 'AWS_PROFILE' => 'perfil-falso'];
        $antes         = [];
        $antesNoServer = [];
        foreach ($nomes as $nome => $valor) {
            $antes[$nome]         = getenv($nome);
            $antesNoServer[$nome] = $_SERVER[$nome] ?? null;
            putenv($nome . '=' . $valor);
            $_SERVER[$nome] = $valor;
        }

        try {
            $s3      = new S3Simulado();
            $espelho = new EspelhoRemotoR2(ClienteR2::criar($s3->endpoint, $s3->chaveDeAcesso, self::SEGREDO, $s3->cliente()), $s3->bucket);
            $espelho->existe(new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 'w.pdf'));

            self::assertNotEmpty($s3->requisicoes());
            foreach ($s3->requisicoes() as $requisicao) {
                self::assertStringStartsWith($s3->endpoint . '/', $requisicao['url'], 'nenhuma requisição a outro host (STS, IMDS)');
            }
            self::assertStringContainsString('Credential=' . $s3->chaveDeAcesso . '/', $s3->requisicoes()[0]['cabecalhos']['authorization']);
        } finally {
            foreach ($antes as $nome => $valor) {
                putenv($valor === false ? $nome : $nome . '=' . $valor);
                if ($antesNoServer[$nome] === null) {
                    unset($_SERVER[$nome]);
                } else {
                    $_SERVER[$nome] = $antesNoServer[$nome];
                }
            }
            @unlink((string) $token);
        }
    }

    #[TestDox('o PUT conta como feito só depois que o HEAD confere: objeto que volta diferente é falha')]
    public function testHeadQueNaoConfereEhFalha(): void
    {
        $chave = new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 'h.pdf');
        $this->s3->falharAProxima(new MockResponse('', ['http_code' => 200]));
        $this->s3->falharAProxima(new MockResponse('', ['http_code' => 200, 'response_headers' => ['content-length' => '1', 'x-amz-meta-sha256' => hash('sha256', 'outro')]]));

        $this->expectException(FalhaNoEspelhoRemoto::class);
        $this->expectExceptionMessage('não confere');
        $this->enviar($chave, 'conteúdo');
    }

    #[TestDox('PUT e HEAD pedem Accept-Encoding: identity (a borda da Cloudflare não comprime o HEAD de texto)')]
    public function testPedeRespostaSemCompressao(): void
    {
        $this->enviar(new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 't.txt'), 'texto com mais de cinquenta bytes para a borda comprimir', 'text/plain');

        $metodos = [];
        foreach ($this->s3->requisicoes() as $requisicao) {
            self::assertSame('identity', $requisicao['cabecalhos']['accept-encoding'] ?? null, $requisicao['metodo']);
            $metodos[] = $requisicao['metodo'];
        }
        self::assertSame(['PUT', 'HEAD'], $metodos);
    }

    #[TestDox('HEAD sem Content-Length é falha de consulta, nunca "0 bytes": o objeto íntegro não vira divergente')]
    public function testHeadSemContentLengthEhFalhaDeConsulta(): void
    {
        $chave = new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 'gz.txt');
        $this->s3->falharAProxima(new MockResponse('', ['http_code' => 200, 'response_headers' => ['x-amz-meta-sha256' => hash('sha256', 'x')]]));

        $this->expectException(FalhaNoEspelhoRemoto::class);
        $this->expectExceptionMessage('HEAD sem Content-Length');
        $this->espelho->consultar($chave);
    }

    #[TestDox('recusa do destino no PUT (403) vira FalhaNoEspelhoRemoto com status e código S3 — sem segredo nem assinatura')]
    public function testRecusaSemVazarSegredo(): void
    {
        $this->s3->falharAProxima(new MockResponse('<?xml version="1.0"?><Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>', ['http_code' => 403, 'response_headers' => ['content-type' => 'application/xml']]));

        try {
            $this->enviar(new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 'r.pdf'), 'x');
            self::fail('403 tinha de falhar');
        } catch (FalhaNoEspelhoRemoto $e) {
            self::assertStringContainsString('HTTP 403', $e->getMessage());
            self::assertStringContainsString('AccessDenied', $e->getMessage());
            self::assertStringContainsString('core/t/1/uploads/pastas/r.pdf', $e->getMessage());
            self::assertStringNotContainsString(self::SEGREDO, $e->getMessage());
            self::assertStringNotContainsString('Signature=', $e->getMessage());
            self::assertStringNotContainsString($this->s3->chaveDeAcesso, $e->getMessage());
        }
    }

    #[TestDox('pane no HEAD (500) é falha, não "não existe" — ausência ≠ pane, como D10')]
    public function testPaneNoHeadNaoViraAusencia(): void
    {
        $this->s3->falharAProxima(new MockResponse('<?xml version="1.0"?><Error><Code>InternalError</Code></Error>', ['http_code' => 500]));

        $this->expectException(FalhaNoEspelhoRemoto::class);
        $this->espelho->existe(new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 'p.pdf'));
    }

    #[TestDox('falha de rede vira FalhaNoEspelhoRemoto')]
    public function testFalhaDeRede(): void
    {
        $this->s3->falharAProxima(new MockResponse('', ['error' => 'host inalcançável']));

        $this->expectException(FalhaNoEspelhoRemoto::class);
        $this->expectExceptionMessage('falha de rede');
        $this->espelho->consultar(new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 'n.pdf'));
    }

    #[TestDox('metadado sha256 malformado no destino é tratado como ausente — o objeto não "confere"')]
    public function testMetadadoMalformado(): void
    {
        $chave = new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 'm.pdf');
        $this->s3->semear('core/t/1/uploads/pastas/m.pdf', 'm', ['sha256' => 'nao-e-hex']);

        $meta = $this->espelho->consultar($chave);

        self::assertNotNull($meta);
        self::assertNull($meta->sha256);
        self::assertFalse($meta->confereCom(1, hash('sha256', 'm')));
    }

    #[TestDox('variáveis AWS_* do ambiente não mudam credencial nem região — o cliente usa só o que recebeu')]
    public function testAmbienteAwsNaoInterfere(): void
    {
        $antes = [getenv('AWS_ACCESS_KEY_ID'), getenv('AWS_SECRET_ACCESS_KEY'), getenv('AWS_REGION')];
        putenv('AWS_ACCESS_KEY_ID=CHAVE-DO-AMBIENTE');
        putenv('AWS_SECRET_ACCESS_KEY=segredo-do-ambiente');
        putenv('AWS_REGION=us-east-1');

        try {
            $s3      = new S3Simulado();
            $espelho = new EspelhoRemotoR2(ClienteR2::criar($s3->endpoint, $s3->chaveDeAcesso, self::SEGREDO, $s3->cliente()), $s3->bucket);
            $espelho->existe(new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 'e.pdf'));

            $autorizacao = $s3->requisicoes()[0]['cabecalhos']['authorization'];
            self::assertStringContainsString('Credential=' . $s3->chaveDeAcesso . '/', $autorizacao);
            self::assertStringContainsString('/auto/s3/aws4_request', $autorizacao);
        } finally {
            foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_REGION'] as $i => $nome) {
                putenv($antes[$i] === false ? $nome : $nome . '=' . $antes[$i]);
            }
        }
    }

    #[TestDox('cliente recusa endpoint sem https, com caminho, e credencial vazia; adaptador recusa bucket inválido')]
    public function testValidacoesDeConfiguracao(): void
    {
        foreach ([
            fn () => ClienteR2::criar('http://conta.r2.cloudflarestorage.com', 'k', 's'),
            fn () => ClienteR2::criar('https://conta.r2.cloudflarestorage.com/bucket', 'k', 's'),
            fn () => ClienteR2::criar('https://conta.r2.cloudflarestorage.com', ' ', 's'),
            fn () => ClienteR2::criar('https://conta.r2.cloudflarestorage.com', 'k', ''),
            fn () => new EspelhoRemotoR2(ClienteR2::criar('https://conta.r2.cloudflarestorage.com', 'k', 's'), 'Bucket_Invalido'),
        ] as $i => $chamada) {
            try {
                $chamada();
                self::fail('caso ' . $i . ' devia recusar');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function enviar(ChaveDeArquivo $chave, string $conteudo, string $mime = 'application/octet-stream'): void
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $conteudo);
        rewind($stream);

        $this->espelho->enviar($chave, $stream, \strlen($conteudo), hash('sha256', $conteudo), $mime);
    }
}
