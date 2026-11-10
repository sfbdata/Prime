<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Selo;

use App\AssinaturaEletronica\Service\Selo\ClasseDaFalhaNoSelo;
use App\AssinaturaEletronica\Service\Selo\FalhaNoSelo;
use App\AssinaturaEletronica\Service\Selo\HttpSeladorDePdf;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * O cliente de `POST /v1/selar` (spec assinatura-documentos-etapa-8, seção 2) com o `MockHttpClient`: nenhuma chamada
 * real. Cada linha da tabela 2.1 da etapa 6 vira um caso, com a classe que o {@see \App\AssinaturaEletronica\Service\Selo\SeloDoPdfFinal}
 * usa para decidir entre tentar de novo e guardar sem o selo.
 */
#[CoversClass(HttpSeladorDePdf::class)]
#[CoversClass(FalhaNoSelo::class)]
final class HttpSeladorDePdfTest extends TestCase
{
    private const URL = 'http://validador_pdf:8080';
    private const TOKEN = 'token-inventado-do-teste-0123456789abcdef';
    private const SEGREDO = 'CONTEUDO-SIGILOSO-DO-CONTRATO';
    private const PDF = "%PDF-1.7\n1 0 obj << /Producer (BlueJus) >> endobj\n(" . self::SEGREDO . ")\n%%EOF\n";
    private const ACRESCIMO = "\n4 0 obj << /FT /Sig /T (SeloBlueJus) >> endobj\n%%EOF\n";

    private LogDoSelo $log;
    private int $pedidos = 0;
    /** @var array{metodo?: string, url?: string, opcoes?: array<string, mixed>} */
    private array $visto = [];

    protected function setUp(): void
    {
        $this->log = new LogDoSelo();
    }

    #[TestDox('200 com application/pdf: devolve o selado, num POST com o token, o tipo do corpo, o PDF inteiro e 45 s, sem redirecionamento')]
    public function testSelado(): void
    {
        $selado = self::PDF . self::ACRESCIMO;

        self::assertSame($selado, $this->selador(self::pdf($selado), self::URL . '/')->selar(self::PDF));

        self::assertSame(1, $this->pedidos);
        self::assertSame('POST', $this->visto['metodo'] ?? null);
        self::assertSame('http://validador_pdf:8080/v1/selar', $this->visto['url'] ?? null, 'a barra do fim da URL não dobra');
        $opcoes = $this->visto['opcoes'] ?? [];
        self::assertSame(['Authorization: Bearer ' . self::TOKEN], $opcoes['normalized_headers']['authorization'] ?? null);
        self::assertSame(['Content-Type: application/pdf'], $opcoes['normalized_headers']['content-type'] ?? null);
        self::assertSame(self::PDF, $opcoes['body'] ?? null);
        self::assertSame(45.0, $opcoes['timeout'] ?? null);
        self::assertSame(45.0, $opcoes['max_duration'] ?? null);
        self::assertSame(0, $opcoes['max_redirects'] ?? null);
        self::assertSame([], $this->log->registros);
    }

    #[TestDox('URL e token com espaços nas pontas, inclusive o avanço de página (\\x0C): limpos como na etapa 6, e o pedido sai')]
    public function testEspacosDasPontas(): void
    {
        $selado = self::PDF . self::ACRESCIMO;
        $selador = new HttpSeladorDePdf($this->http(self::pdf($selado)), $this->log, "\x0C " . self::URL . "/\n", "\x0C" . self::TOKEN . "\x0C\t");

        self::assertSame($selado, $selador->selar(self::PDF));
        self::assertSame('http://validador_pdf:8080/v1/selar', $this->visto['url'] ?? null);
        self::assertSame(['Authorization: Bearer ' . self::TOKEN], $this->visto['opcoes']['normalized_headers']['authorization'] ?? null);
    }

    #[TestDox('200 com o tipo acompanhado de parâmetro (application/pdf; charset) também vale')]
    public function testTipoComParametro(): void
    {
        $selado = self::PDF . self::ACRESCIMO;
        $resposta = new MockResponse($selado, ['http_code' => 200, 'response_headers' => ['content-type' => 'Application/PDF; qs=1']]);

        self::assertSame($selado, $this->selador($resposta)->selar(self::PDF));
    }

    #[TestDox('503 selo_nao_configurado (só ele): nulo, como sem configuração')]
    public function testSeloNaoConfiguradoNoValidador(): void
    {
        self::assertNull($this->selador(self::json(503, 'selo_nao_configurado'))->selar(self::PDF));
        self::assertSame([], $this->log->registros);
    }

    /** @return iterable<string, array{MockResponse, string, ClasseDaFalhaNoSelo, int|null}> */
    public static function respostasDeFalha(): iterable
    {
        $passageira = ClasseDaFalhaNoSelo::Passageira;
        $configuracao = ClasseDaFalhaNoSelo::Configuracao;
        $definitiva = ClasseDaFalhaNoSelo::Definitiva;

        // Tabela 2.1 da etapa 6, as respostas {"v":1,"erro":...} da rota de selar.
        yield '400 pdf_ilegivel: definitiva' => [self::json(400, 'pdf_ilegivel'), 'pdf_ilegivel', $definitiva, 400];
        yield '400 parametro_invalido: cliente' => [self::json(400, 'parametro_invalido'), 'parametro_invalido', $configuracao, 400];
        yield '401 nao_autorizado: configuração' => [self::json(401, 'nao_autorizado'), 'nao_autorizado', $configuracao, 401];
        yield '404 nao_encontrado: cliente' => [self::json(404, 'nao_encontrado'), 'nao_encontrado', $configuracao, 404];
        yield '405 metodo_nao_permitido: cliente' => [self::json(405, 'metodo_nao_permitido'), 'metodo_nao_permitido', $configuracao, 405];
        yield '411 tamanho_ausente: cliente' => [self::json(411, 'tamanho_ausente'), 'tamanho_ausente', $configuracao, 411];
        yield '413 pdf_grande_demais: definitiva' => [self::json(413, 'pdf_grande_demais'), 'pdf_grande_demais', $definitiva, 413];
        yield '422 nao_selavel: definitiva' => [self::json(422, 'nao_selavel'), 'nao_selavel', $definitiva, 422];
        yield '500 erro_interno: passageira' => [self::json(500, 'erro_interno'), 'erro_interno', $passageira, 500];
        yield '502 carimbo_indisponivel: passageira' => [self::json(502, 'carimbo_indisponivel'), 'carimbo_indisponivel', $passageira, 502];
        yield '503 ocupado: passageira' => [self::json(503, 'ocupado'), 'ocupado', $passageira, 503];
        yield '503 tempo_esgotado: passageira' => [self::json(503, 'tempo_esgotado'), 'tempo_esgotado', $passageira, 503];
        yield '503 ancoras_indisponiveis: passageira' => [self::json(503, 'ancoras_indisponiveis'), 'ancoras_indisponiveis', $passageira, 503];
        yield '503 selo_certificado_invalido: classe própria' => [self::json(503, 'selo_certificado_invalido'), 'selo_certificado_invalido', ClasseDaFalhaNoSelo::CertificadoInvalido, 503];

        // Pedido HTTP malformado: a biblioteca padrão responde em HTML, antes do validador (erro de cliente).
        foreach ([400, 414, 431, 505] as $situacao) {
            yield $situacao . ' em HTML: cliente' => [self::html($situacao), 'http_' . $situacao, $configuracao, $situacao];
        }

        // Fora do protocolo: passageira (tenta de novo e, esgotadas as tentativas, guarda sem o selo).
        yield '502 em HTML (sem {"v":1})' => [self::html(502), HttpSeladorDePdf::FORA_DO_PROTOCOLO, $passageira, 502];
        yield '503 sem corpo' => [new MockResponse('', ['http_code' => 503]), HttpSeladorDePdf::FORA_DO_PROTOCOLO, $passageira, 503];
        yield '500 de outra versão do protocolo' => [new MockResponse('{"v":2,"erro":"erro_interno"}', ['http_code' => 500]), HttpSeladorDePdf::FORA_DO_PROTOCOLO, $passageira, 500];
        yield '503 com código desconhecido' => [self::json(503, 'codigo_novo'), HttpSeladorDePdf::FORA_DO_PROTOCOLO, $passageira, 503];
        yield '422 com código de outra situação' => [self::json(422, 'ocupado'), HttpSeladorDePdf::FORA_DO_PROTOCOLO, $passageira, 422];
        yield '200 em JSON, não PDF' => [new MockResponse('{"v":1}', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]), HttpSeladorDePdf::FORA_DO_PROTOCOLO, $passageira, 200];
        yield '302: passageira (que ele não é seguido prova o max_redirects 0 do testSelado)' => [new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'http://outro.test/']]), HttpSeladorDePdf::FORA_DO_PROTOCOLO, $passageira, 302];
        yield 'erro de transporte (validador fora do ar)' => [new MockResponse('', ['error' => 'Connection refused for "http://validador_pdf:8080/v1/selar".']), HttpSeladorDePdf::FALHA_DE_TRANSPORTE, $passageira, null];
    }

    #[DataProvider('respostasDeFalha')]
    #[TestDox('$_dataName')]
    public function testFalha(MockResponse $resposta, string $codigo, ClasseDaFalhaNoSelo $classe, ?int $situacao): void
    {
        $falha = $this->falhaDe($this->selador($resposta));

        self::assertSame($codigo, $falha->codigo);
        self::assertSame($classe, $falha->classe);
        self::assertSame($situacao, $falha->situacao);
        self::assertSame($classe !== ClasseDaFalhaNoSelo::Definitiva, $falha->tentaDeNovo());
        self::assertNull($falha->getPrevious(), 'a exceção do cliente HTTP não é encadeada');
        $this->assertNadaVazou($falha);
    }

    #[TestDox('tempo esgotado no cliente (45 s): passageira')]
    public function testTempoEsgotado(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new TimeoutException('Idle timeout reached for "http://validador_pdf:8080/v1/selar".');
        });

        $falha = $this->falhaDe(new HttpSeladorDePdf($http, $this->log, self::URL, self::TOKEN));

        self::assertSame(HttpSeladorDePdf::TEMPO_ESGOTADO_NO_CLIENTE, $falha->codigo);
        self::assertSame(ClasseDaFalhaNoSelo::Passageira, $falha->classe);
        self::assertNull($falha->getPrevious());
    }

    #[TestDox('PDF acima de 20 MB: definitiva, sem pedido; com 20 MB exatos, o pedido sai')]
    public function testTetoDe20Mb(): void
    {
        $pdf = self::PDF . str_repeat('0', HttpSeladorDePdf::PDF_MAXIMO_EM_BYTES - \strlen(self::PDF));
        $selador = $this->selador(self::json(503, 'ocupado'));

        $falha = $this->falhaDe($selador, $pdf . '0');
        self::assertSame(HttpSeladorDePdf::PDF_GRANDE_DEMAIS, $falha->codigo);
        self::assertSame(ClasseDaFalhaNoSelo::Definitiva, $falha->classe);
        self::assertSame(0, $this->pedidos, 'nenhum pedido acima do teto');

        self::assertSame('ocupado', $this->falhaDe($selador, $pdf)->codigo);
        self::assertSame(1, $this->pedidos);
    }

    /** @return iterable<string, array{string}> */
    public static function seladosSemOOriginal(): iterable
    {
        yield 'não começa com o original' => ["%PDF-1.7\noutro documento" . self::ACRESCIMO];
        yield 'o original sem acréscimo' => [self::PDF];
        yield 'o original cortado' => [substr(self::PDF, 0, -3)];
        yield 'vazio' => [''];
    }

    #[DataProvider('seladosSemOOriginal')]
    #[TestDox('200 com PDF que não é acréscimo do original ($_dataName): passageira e logger->error, sem o conteúdo')]
    public function testSeladoSemOOriginal(string $selado): void
    {
        $falha = $this->falhaDe($this->selador(self::pdf($selado)));

        self::assertSame(HttpSeladorDePdf::SEM_O_ORIGINAL, $falha->codigo);
        self::assertSame(ClasseDaFalhaNoSelo::Passageira, $falha->classe);
        self::assertSame(200, $falha->situacao);
        self::assertCount(1, $this->log->erros());
        self::assertSame(['original' => \strlen(self::PDF), 'selado' => \strlen($selado)], $this->log->registros[0]['contexto']);
        $this->assertNadaVazou($falha);
    }

    #[TestDox('200 que não é PDF (a URL aponta para outra rota): passageira e logger->error já na primeira vez, com o tipo')]
    public function testDuzentosSemPdf(): void
    {
        $resposta = new MockResponse('{"v":1}', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);

        $falha = $this->falhaDe($this->selador($resposta));

        self::assertSame(HttpSeladorDePdf::FORA_DO_PROTOCOLO, $falha->codigo);
        self::assertSame(ClasseDaFalhaNoSelo::Passageira, $falha->classe);
        self::assertCount(1, $this->log->erros());
        self::assertSame(['tipo' => 'application/json'], $this->log->registros[0]['contexto']);
        $this->assertNadaVazou($falha);
    }

    /** @return iterable<string, array{string, string}> */
    public static function configuracoesQueNaoServem(): iterable
    {
        yield 'token com espaço no meio' => [self::URL, 'token com espaco'];
        yield 'token com quebra de linha no meio' => [self::URL, "token\ninjetado"];
        yield 'token com acento' => [self::URL, 'tokén'];
        yield 'URL sem esquema' => ['validador_pdf:8080', self::TOKEN];
        yield 'URL de outro esquema' => ['ftp://validador_pdf', self::TOKEN];
    }

    #[DataProvider('configuracoesQueNaoServem')]
    #[TestDox('$_dataName: configuração, sem pedido e sem o valor do token na exceção')]
    public function testConfiguracaoQueNaoServe(string $url, string $token): void
    {
        $selador = new HttpSeladorDePdf($this->http(self::json(503, 'ocupado')), $this->log, $url, $token);

        $falha = $this->falhaDe($selador);

        self::assertSame(HttpSeladorDePdf::CONFIGURACAO_INVALIDA, $falha->codigo);
        self::assertSame(ClasseDaFalhaNoSelo::Configuracao, $falha->classe);
        self::assertSame(0, $this->pedidos);
        self::assertStringNotContainsString($token, $falha->getMessage());
    }

    private function selador(MockResponse $resposta, string $url = self::URL): HttpSeladorDePdf
    {
        return new HttpSeladorDePdf($this->http($resposta), $this->log, $url, self::TOKEN);
    }

    private function http(MockResponse $resposta): MockHttpClient
    {
        return new MockHttpClient(function (string $metodo, string $url, array $opcoes) use ($resposta): MockResponse {
            ++$this->pedidos;
            $this->visto = ['metodo' => $metodo, 'url' => $url, 'opcoes' => $opcoes];

            return $resposta;
        });
    }

    private function falhaDe(HttpSeladorDePdf $selador, string $pdf = self::PDF): FalhaNoSelo
    {
        try {
            $selador->selar($pdf);
        } catch (FalhaNoSelo $falha) {
            return $falha;
        }

        self::fail('Era para lançar FalhaNoSelo.');
    }

    /** O conteúdo do PDF e o token nunca vão para a exceção nem para o log. */
    private function assertNadaVazou(FalhaNoSelo $falha): void
    {
        foreach ([self::SEGREDO, self::TOKEN] as $proibido) {
            self::assertStringNotContainsString($proibido, $falha->getMessage());
            self::assertStringNotContainsString($proibido, $this->log->tudo());
        }
    }

    private static function json(int $situacao, string $erro): MockResponse
    {
        return new MockResponse(
            json_encode(['v' => 1, 'erro' => $erro], \JSON_THROW_ON_ERROR),
            ['http_code' => $situacao, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    private static function html(int $situacao): MockResponse
    {
        return new MockResponse(
            '<!DOCTYPE HTML><html><body><h1>Error response</h1><p>Error code: ' . $situacao . '</p></body></html>',
            ['http_code' => $situacao, 'response_headers' => ['content-type' => 'text/html;charset=utf-8']],
        );
    }

    private static function pdf(string $corpo): MockResponse
    {
        return new MockResponse($corpo, ['http_code' => 200, 'response_headers' => ['content-type' => 'application/pdf']]);
    }
}
