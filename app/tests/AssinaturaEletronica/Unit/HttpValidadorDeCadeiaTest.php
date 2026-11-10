<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Service\Cadeia\CadeiaIndisponivel;
use App\AssinaturaEletronica\Service\Cadeia\HttpValidadorDeCadeia;
use App\AssinaturaEletronica\Service\Cadeia\ResultadoDaCadeia;
use App\Tests\AssinaturaEletronica\Support\CadeiaDeExemplo;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * O cliente do validador de cadeia (etapa 6, bloco 6B): o pedido do protocolo `v1` e as classes de resposta da seção
 * 2.1 (resultado, definitivo, passageiro, cliente ou configuração), com o `MockHttpClient`: nenhuma chamada real.
 */
#[CoversClass(HttpValidadorDeCadeia::class)]
#[Group('assinatura')]
final class HttpValidadorDeCadeiaTest extends TestCase
{
    private const URL = 'http://validador_pdf:8080';
    private const TOKEN = 'token-de-teste-do-validador';
    private const PDF = "%PDF-1.7\nconteudo do pdf assinado\n%%EOF";

    private TestHandler $log;

    protected function setUp(): void
    {
        $this->log = new TestHandler();
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function configuracoes(): iterable
    {
        yield 'URL e token' => [self::URL, self::TOKEN, true];
        yield 'as duas vazias' => ['', '', false];
        yield 'sem token' => [self::URL, '', false];
        yield 'token só com espaços (trim)' => [self::URL, "  \t ", false];
        yield 'token só com avanço de página (\x0C)' => [self::URL, "\x0C \x0C", false];
        yield 'token e URL com avanço de página nas pontas' => ["\x0C" . self::URL . "\x0C", "\x0C" . self::TOKEN . "\x0C\n", true];
        yield 'URL só com espaços' => ['   ', self::TOKEN, false];
        yield 'sem URL' => ['', self::TOKEN, false];
    }

    #[DataProvider('configuracoes')]
    #[TestDox('configurado() só com URL e token, sem os espaços das pontas: $_dataName')]
    public function testConfigurado(string $url, string $token, bool $esperado): void
    {
        self::assertSame($esperado, $this->validador(new MockHttpClient(), $url, $token)->configurado());
    }

    #[TestDox('não configurado: conferir() lança CadeiaIndisponivel sem pedido nenhum')]
    public function testNaoConfiguradoNaoChama(): void
    {
        $http = new MockHttpClient(static fn () => self::fail('Não devia chamar o validador.'));

        $this->expectException(CadeiaIndisponivel::class);
        $this->validador($http, self::URL, ' ')->conferir(self::PDF, 'icp');
    }

    #[TestDox('o pedido: POST /v1/conferir?ancora=..., Bearer com o token sem espaços, application/pdf e os bytes do PDF; 200 vira o resultado')]
    public function testPedidoDoProtocolo(): void
    {
        $pedido = [];
        $http = new MockHttpClient(static function (string $metodo, string $url, array $opcoes) use (&$pedido): MockResponse {
            $pedido = ['metodo' => $metodo, 'url' => $url, 'cabecalhos' => $opcoes['headers'], 'corpo' => $opcoes['body']];

            return self::json(200, CadeiaDeExemplo::corpo([CadeiaDeExemplo::item()]));
        });

        $resultado = $this->validador($http, ' ' . self::URL . '/ ', ' ' . self::TOKEN . "\n")->conferir(self::PDF, 'qualquer');

        self::assertSame('POST', $pedido['metodo']);
        self::assertSame(self::URL . '/v1/conferir?ancora=qualquer', $pedido['url']);
        self::assertContains('Authorization: Bearer ' . self::TOKEN, $pedido['cabecalhos']);
        self::assertContains('Content-Type: application/pdf', $pedido['cabecalhos']);
        self::assertSame(self::PDF, $pedido['corpo']);
        self::assertNull($resultado->erro);
        self::assertCount(1, $resultado->assinaturas);
        self::assertSame('Signature1', $resultado->assinaturas[0]->campo);
        self::assertSame(CadeiaDeExemplo::corpo([CadeiaDeExemplo::item()]), $resultado->paraJson(), 'o JSON volta igual (vai para a coluna cadeia)');
    }

    #[TestDox('o token sem o \x0C das pontas vai no Bearer')]
    public function testTokenSemAvancoDePagina(): void
    {
        $cabecalhos = [];
        $http = new MockHttpClient(static function (string $metodo, string $url, array $opcoes) use (&$cabecalhos): MockResponse {
            $cabecalhos = $opcoes['headers'];

            return self::json(200, CadeiaDeExemplo::corpo([]));
        });

        $this->validador($http, self::URL, "\x0C" . self::TOKEN . "\x0C")->conferir(self::PDF, 'icp');

        self::assertContains('Authorization: Bearer ' . self::TOKEN, $cabecalhos);
    }

    #[TestDox('URL que o cliente HTTP recusa (sem http://): CadeiaIndisponivel com erro no log (configuração), não aviso de passageiro')]
    public function testUrlSemEsquema(): void
    {
        try {
            // Sem a base padrão do MockHttpClient (https://example.com): a URL relativa falha como no cliente real.
            $this->validador(new MockHttpClient(self::json(200, CadeiaDeExemplo::corpo([])), null), 'validador_pdf')->conferir(self::PDF, 'icp');
            self::fail('Devia lançar CadeiaIndisponivel.');
        } catch (CadeiaIndisponivel) {
        }

        self::assertTrue($this->log->hasErrorRecords());
        self::assertFalse($this->log->hasWarningRecords());
    }

    #[TestDox('âncora fora de icp, govbr e qualquer: InvalidArgumentException, sem pedido')]
    public function testAncoraInvalida(): void
    {
        $http = new MockHttpClient(static fn () => self::fail('Não devia chamar o validador.'));

        $this->expectException(\InvalidArgumentException::class);
        $this->validador($http)->conferir(self::PDF, 'ICP');
    }

    #[TestDox('PDF acima de 20 MB: resultado definitivo pdf_grande_demais, sem ocupar o validador')]
    public function testPdfGrandeDemaisNaoEnvia(): void
    {
        $http = new MockHttpClient(static fn () => self::fail('Não devia chamar o validador.'));

        $resultado = $this->validador($http)->conferir(str_repeat('x', HttpValidadorDeCadeia::TETO_DO_PDF + 1), 'icp');

        self::assertSame('pdf_grande_demais', $resultado->erro);
        self::assertSame([], $resultado->assinaturas);
    }

    /** @return iterable<string, array{int, string}> */
    public static function definitivos(): iterable
    {
        yield '400 pdf_ilegivel' => [400, 'pdf_ilegivel'];
        yield '413 pdf_grande_demais' => [413, 'pdf_grande_demais'];
    }

    #[DataProvider('definitivos')]
    #[TestDox('definitivo para o PDF ($_dataName): volta como resultado com o erro, sem erro no log')]
    public function testDefinitivo(int $situacao, string $erro): void
    {
        $resultado = $this->validador(new MockHttpClient(self::json($situacao, ['v' => 1, 'erro' => $erro])))->conferir(self::PDF, 'icp');

        self::assertSame($erro, $resultado->erro);
        self::assertSame($erro === ResultadoDaCadeia::ERRO_PDF_ILEGIVEL, $resultado->ilegivel());
        self::assertFalse($this->log->hasRecordThatPasses(static fn (): bool => true, Level::Error));
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function passageiros(): iterable
    {
        yield '500 erro_interno' => [self::json(500, ['v' => 1, 'erro' => 'erro_interno'])];
        yield '503 ocupado' => [self::json(503, ['v' => 1, 'erro' => 'ocupado'], ['Retry-After: 10'])];
        yield '503 tempo_esgotado' => [self::json(503, ['v' => 1, 'erro' => 'tempo_esgotado'])];
        yield '503 ancoras_indisponiveis' => [self::json(503, ['v' => 1, 'erro' => 'ancoras_indisponiveis'], ['Retry-After: 60'])];
        yield 'fora do ar (falha de transporte)' => [new MockResponse('', ['error' => 'Não foi possível conectar.'])];
    }

    #[DataProvider('passageiros')]
    #[TestDox('passageiro ($_dataName): CadeiaIndisponivel com aviso no log, sem erro')]
    public function testPassageiro(MockResponse $resposta): void
    {
        try {
            $this->validador(new MockHttpClient($resposta))->conferir(self::PDF, 'govbr');
            self::fail('Devia lançar CadeiaIndisponivel.');
        } catch (CadeiaIndisponivel) {
        }

        self::assertTrue($this->log->hasWarningRecords());
        self::assertFalse($this->log->hasErrorRecords());
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function doNossoLado(): iterable
    {
        yield '401 nao_autorizado' => [self::json(401, ['v' => 1, 'erro' => 'nao_autorizado'], ['WWW-Authenticate: Bearer'])];
        yield '404 nao_encontrado' => [self::json(404, ['v' => 1, 'erro' => 'nao_encontrado'])];
        yield '405 metodo_nao_permitido' => [self::json(405, ['v' => 1, 'erro' => 'metodo_nao_permitido'])];
        yield '411 tamanho_ausente' => [self::json(411, ['v' => 1, 'erro' => 'tamanho_ausente'])];
        yield '400 parametro_invalido' => [self::json(400, ['v' => 1, 'erro' => 'parametro_invalido'])];
        yield '431 em HTML da biblioteca' => [new MockResponse('<html>Request Header Fields Too Large</html>', ['http_code' => 431, 'response_headers' => ['Content-Type: text/html']])];
        yield '400 pdf_ilegivel em HTML' => [new MockResponse('{"v":1,"erro":"pdf_ilegivel"}', ['http_code' => 400, 'response_headers' => ['Content-Type: text/html']])];
        yield 'redirecionamento' => [new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: http://outro/']])];
        yield '422 nao_selavel (só do selo)' => [self::json(422, ['v' => 1, 'erro' => 'nao_selavel'])];
        yield '503 com código de outra situação' => [self::json(503, ['v' => 1, 'erro' => 'pdf_ilegivel'])];
        yield '200 com versão desconhecida' => [self::json(200, ['v' => 2, 'assinaturas' => []])];
        yield '200 com erro' => [self::json(200, ['v' => 1, 'erro' => 'pdf_ilegivel'])];
        yield '200 que não é JSON' => [new MockResponse('nao e json', ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']])];
        yield '200 em HTML' => [new MockResponse((string) json_encode(CadeiaDeExemplo::corpo([])), ['http_code' => 200, 'response_headers' => ['Content-Type: text/html']])];
        yield '200 com item fora do protocolo' => [self::json(200, CadeiaDeExemplo::corpo([CadeiaDeExemplo::item(['revogacao' => 'talvez'])]))];
    }

    #[DataProvider('doNossoLado')]
    #[TestDox('cliente, configuração ou fora do protocolo ($_dataName): CadeiaIndisponivel com erro no log')]
    public function testDoNossoLado(MockResponse $resposta): void
    {
        try {
            $this->validador(new MockHttpClient($resposta))->conferir(self::PDF, 'icp');
            self::fail('Devia lançar CadeiaIndisponivel.');
        } catch (CadeiaIndisponivel) {
        }

        self::assertTrue($this->log->hasErrorRecords());
    }

    #[TestDox('o log nunca leva o token, o PDF nem o corpo da resposta: só rota, âncora, situação, erro, tamanho e tempo')]
    public function testLogSemSegredoNemConteudo(): void
    {
        $respostas = [
            self::json(200, CadeiaDeExemplo::corpo([CadeiaDeExemplo::item()])),
            self::json(401, ['v' => 1, 'erro' => 'nao_autorizado']),
            self::json(503, ['v' => 1, 'erro' => 'ocupado']),
        ];
        $validador = $this->validador(new MockHttpClient($respostas));
        foreach ($respostas as $_) {
            try {
                $validador->conferir(self::PDF, 'icp');
            } catch (CadeiaIndisponivel) {
            }
        }

        self::assertCount(3, $this->log->getRecords());
        foreach ($this->log->getRecords() as $registro) {
            $texto = $registro->message . json_encode($registro->context);
            self::assertStringNotContainsString(self::TOKEN, $texto);
            self::assertStringNotContainsString('conteudo do pdf', $texto);
            self::assertStringNotContainsString('FULANA', $texto);
            self::assertSame(['rota', 'ancora', 'tamanho'], \array_slice(array_keys($registro->context), 0, 3));
        }
    }

    private function validador(MockHttpClient $http, string $url = self::URL, string $token = self::TOKEN): HttpValidadorDeCadeia
    {
        return new HttpValidadorDeCadeia($http, new Logger('teste', [$this->log]), $url, $token);
    }

    /**
     * @param array<string, mixed> $corpo
     * @param list<string>         $cabecalhos
     */
    private static function json(int $situacao, array $corpo, array $cabecalhos = []): MockResponse
    {
        return new MockResponse((string) json_encode($corpo), [
            'http_code' => $situacao,
            'response_headers' => array_merge(['Content-Type: application/json'], $cabecalhos),
        ]);
    }
}
