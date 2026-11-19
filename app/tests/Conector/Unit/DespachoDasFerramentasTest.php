<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\DTO\ArquivoNaResposta;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\CatalogoFechadoDoConector;
use App\Conector\Ferramenta\DespachoDasFerramentas;
use App\Conector\Service\RequisicaoDoConector;
use App\Tests\Conector\Support\DublesDoMcpTrait;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O despacho único das ferramentas (spec mcp-remoto-oauth 6.2 regras 1, 7, 8, 9 e 10; 6.3 item 1; 12.5, 12.14 e
 * 12.29): só o catálogo fechado, título, anotações e `_meta` do catálogo no `tools/list`, sem `outputSchema`, a
 * requisição aberta exigida, campo fora do esquema recusado, `content` igual ao `structuredContent`, teto de 50 mil
 * caracteres e `ErroDaFerramenta` como `isError`.
 */
#[CoversClass(DespachoDasFerramentas::class)]
final class DespachoDasFerramentasTest extends TestCase
{
    use DublesDoMcpTrait;

    #[TestDox('nome fora do catálogo derruba a montagem (6.3, item 1)')]
    public function testForaDoCatalogoRecusa(): void
    {
        $despacho = new DespachoDasFerramentas([self::ferramenta('consultar_sql', new RespostaDaFerramenta([]))], new RequisicaoDoConector());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('consultar_sql');
        $despacho->registradas();
    }

    #[TestDox('o mesmo nome duas vezes derruba a montagem')]
    public function testRepetidaRecusa(): void
    {
        $uma = self::ferramenta('quem_sou_eu', new RespostaDaFerramenta([]));
        $despacho = new DespachoDasFerramentas([$uma, self::ferramenta('quem_sou_eu', new RespostaDaFerramenta([]))], new RequisicaoDoConector());

        $this->expectException(\LogicException::class);
        $despacho->registradas();
    }

    #[TestDox('12.14 e 12.5: no tools/list, título, as quatro dicas e o _meta com securitySchemes vêm do catálogo; sem outputSchema; a gravação aparece para o token só de leitura')]
    public function testToolsListPeloCatalogo(): void
    {
        $ferramentas = [
            self::ferramenta('ler_processos', new RespostaDaFerramenta([])),
            self::ferramenta('atualizar_cliente', new RespostaDaFerramenta([])),
            self::ferramenta('confirmar_acao', new RespostaDaFerramenta([])),
        ];
        $resposta = $this->servidor(['mcp:leitura'], $ferramentas, new RequisicaoDoConector())->responder(self::post('tools/list'));

        $lista = self::corpo($resposta)['result']['tools'];
        self::assertSame(['ler_processos', 'atualizar_cliente', 'confirmar_acao'], array_column($lista, 'name'));
        foreach ($lista as $tool) {
            $nome = $tool['name'];
            self::assertSame(CatalogoFechadoDoConector::TITULOS[$nome], $tool['title'], $nome);
            self::assertSame(CatalogoFechadoDoConector::anotacoes($nome)->jsonSerialize(), $tool['annotations'], $nome);
            self::assertSame(CatalogoFechadoDoConector::meta($nome), $tool['_meta'], $nome);
            self::assertSame([['type' => 'oauth2', 'scopes' => CatalogoFechadoDoConector::escoposExigidos($nome)]], $tool['_meta']['securitySchemes'], $nome);
            self::assertArrayNotHasKey('outputSchema', $tool, $nome);
            self::assertFalse($tool['inputSchema']['additionalProperties'], $nome);
        }
        self::assertTrue($lista[0]['annotations']['readOnlyHint']);
        self::assertTrue($lista[0]['annotations']['openWorldHint']);
        self::assertTrue($lista[1]['annotations']['destructiveHint']);
        self::assertTrue($lista[2]['_meta']['anthropic/requiresUserInteraction']);
    }

    #[TestDox('sem a requisição aberta pelo portão, executar lança e a ferramenta não roda')]
    public function testExigeRequisicaoAberta(): void
    {
        $ferramenta = self::ferramenta('quem_sou_eu', new RespostaDaFerramenta(['a' => 1]));
        $despacho = new DespachoDasFerramentas([$ferramenta], new RequisicaoDoConector());

        try {
            $despacho->executar($ferramenta, []);
            self::fail('sem portão não pode executar');
        } catch (\LogicException) {
            self::assertSame([], $ferramenta->recebidos);
        }
    }

    #[TestDox('campo fora do esquema: isError com a mensagem fixa, sem chamar a ferramenta')]
    public function testCampoForaDoEsquema(): void
    {
        $requisicao = self::aberta();
        $ferramenta = self::ferramenta('ler_agenda', new RespostaDaFerramenta(['a' => 1]));

        $resultado = (new DespachoDasFerramentas([$ferramenta], $requisicao))->executar($ferramenta, ['cpf' => '1', 'intruso' => 'x']);

        self::assertTrue($resultado->isError);
        self::assertSame(ErroDaFerramenta::ENTRADA_INVALIDA, self::texto($resultado->content[0]));
        self::assertSame([], $ferramenta->recebidos);
        self::assertSame('recusado', $requisicao->anotacao('resultado'));
        self::assertSame(ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA, $requisicao->anotacao('recusa'));
    }

    #[TestDox('12.29: o content é o JSON do structuredContent; contagens, ids e ferramenta vão às anotações; a página seguinte entra no dado')]
    public function testContentIgualAoStructured(): void
    {
        $requisicao = self::aberta();
        $ferramenta = self::ferramenta('ler_agenda', new RespostaDaFerramenta(['itens' => [['id' => 3, 'nome' => 'Ação / Réu']]], 2, ['em_segredo' => 1], ['compromisso' => [3]]));

        $resultado = (new DespachoDasFerramentas([$ferramenta], $requisicao))->executar($ferramenta, ['cpf' => 'x']);

        self::assertFalse($resultado->isError);
        $esperado = ['itens' => [['id' => 3, 'nome' => 'Ação / Réu']], 'proxima_pagina' => 2];
        self::assertSame($esperado, $resultado->structuredContent);
        self::assertCount(1, $resultado->content);
        self::assertSame($esperado, json_decode(self::texto($resultado->content[0]), true));
        self::assertSame(json_encode($esperado, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), self::texto($resultado->content[0]));
        self::assertSame(
            ['ferramenta' => 'ler_agenda', 'resultado' => 'ok', 'contagens' => ['em_segredo' => 1], 'ids' => ['compromisso' => [3]]],
            $requisicao->anotacoes(),
        );
    }

    #[TestDox('a página seguinte pode ser um cursor em texto: sai como veio, nos dois campos')]
    public function testProximaPaginaEmTexto(): void
    {
        $ferramenta = self::ferramenta('ler_agenda', new RespostaDaFerramenta(['itens' => []], 'c:2026-11-16:40'));

        $resultado = (new DespachoDasFerramentas([$ferramenta], self::aberta()))->executar($ferramenta, []);

        self::assertFalse($resultado->isError);
        self::assertSame(['itens' => [], 'proxima_pagina' => 'c:2026-11-16:40'], $resultado->structuredContent);
        self::assertSame('{"itens":[],"proxima_pagina":"c:2026-11-16:40"}', self::texto($resultado->content[0]));
    }

    #[TestDox('dado vazio sai como objeto JSON nos dois campos')]
    public function testDadoVazioEhObjeto(): void
    {
        $ferramenta = self::ferramenta('ler_agenda', new RespostaDaFerramenta([]));
        $resultado = (new DespachoDasFerramentas([$ferramenta], self::aberta()))->executar($ferramenta, []);

        self::assertSame('{}', self::texto($resultado->content[0]));
        self::assertSame('{}', json_encode($resultado->structuredContent));
    }

    #[TestDox('12.29: passou de 50 mil caracteres, isError com a mensagem fixa; exatamente no teto, passa')]
    public function testTeto(): void
    {
        // {"t":"..."} = 8 caracteres além do texto; "é" conta como um caractere, não como dois bytes.
        $noTeto = self::ferramenta('ler_agenda', new RespostaDaFerramenta(['t' => str_repeat('é', DespachoDasFerramentas::TETO_DE_CARACTERES - 8)]));
        $acima = self::ferramenta('ler_agenda', new RespostaDaFerramenta(['t' => str_repeat('é', DespachoDasFerramentas::TETO_DE_CARACTERES - 7)]));

        $passa = (new DespachoDasFerramentas([$noTeto], self::aberta()))->executar($noTeto, []);
        self::assertFalse($passa->isError);
        self::assertSame(DespachoDasFerramentas::TETO_DE_CARACTERES, mb_strlen(self::texto($passa->content[0])));

        $requisicao = self::aberta();
        $recusa = (new DespachoDasFerramentas([$acima], $requisicao))->executar($acima, []);
        self::assertTrue($recusa->isError);
        self::assertSame(DespachoDasFerramentas::GRANDE_DEMAIS, self::texto($recusa->content[0]));
        self::assertFalse($recusa->hasStructuredContent());
        self::assertSame(DespachoDasFerramentas::MOTIVO_GRANDE_DEMAIS, $requisicao->anotacao('recusa'));
    }

    #[TestDox('ErroDaFerramenta vira isError com a mensagem fixa e o motivo vai às anotações')]
    public function testErroDaFerramenta(): void
    {
        $requisicao = self::aberta();
        $ferramenta = self::ferramenta('ler_agenda', ErroDaFerramenta::naoEncontrado());

        $resultado = (new DespachoDasFerramentas([$ferramenta], $requisicao))->executar($ferramenta, []);

        self::assertTrue($resultado->isError);
        self::assertSame(ErroDaFerramenta::NAO_ENCONTRADO, self::texto($resultado->content[0]));
        self::assertSame(['ferramenta' => 'ler_agenda', 'resultado' => 'recusado', 'recusa' => 'nao_encontrado'], $requisicao->anotacoes());
    }

    #[TestDox('exceção que não é ErroDaFerramenta segue para o SDK, mas antes anota resultado=erro para a linha do log mcp')]
    public function testFalhaInesperadaAnotaErro(): void
    {
        $requisicao = self::aberta();
        $falha = new \RuntimeException('banco fora do ar');
        $ferramenta = self::ferramenta('ler_agenda', $falha);

        try {
            (new DespachoDasFerramentas([$ferramenta], $requisicao))->executar($ferramenta, []);
            self::fail('a falha inesperada não pode virar resultado');
        } catch (\RuntimeException $lancada) {
            self::assertSame($falha, $lancada);
        }

        self::assertSame(['ferramenta' => 'ler_agenda', 'resultado' => 'erro'], $requisicao->anotacoes());
    }

    #[TestDox('pelo SDK: tools/call devolve o mesmo dado em content e structuredContent; o erro da ferramenta é um 200 com isError')]
    public function testPeloSdk(): void
    {
        $ok = self::ferramenta('ler_agenda', new RespostaDaFerramenta(['n' => 1]));
        $resposta = $this->servidor(['mcp:leitura'], [$ok], new RequisicaoDoConector())
            ->responder(self::post('tools/call', ['name' => 'ler_agenda', 'arguments' => new \stdClass()]));
        $resultado = self::corpo($resposta)['result'];

        self::assertSame(['n' => 1], $resultado['structuredContent']);
        self::assertSame([['type' => 'text', 'text' => '{"n":1}']], $resultado['content']);
        self::assertFalse($resultado['isError']);

        $erro = self::ferramenta('ler_agenda', ErroDaFerramenta::naoEncontrado());
        $resposta = $this->servidor(['mcp:leitura'], [$erro], new RequisicaoDoConector())
            ->responder(self::post('tools/call', ['name' => 'ler_agenda', 'arguments' => new \stdClass()]));

        self::assertSame(200, $resposta->getStatusCode());
        self::assertTrue(self::corpo($resposta)['result']['isError']);
        self::assertSame(ErroDaFerramenta::NAO_ENCONTRADO, self::corpo($resposta)['result']['content'][0]['text']);
    }

    #[TestDox('arquivo na resposta (decisão 24): a imagem vira ImageContent em base64 num bloco à parte, depois do JSON dos metadados')]
    public function testImagemVaiComoImageContent(): void
    {
        $png = "\x89PNG\r\n\x1A\nimagem";
        $arquivo = new ArquivoNaResposta($png, 'image/png', 'bluejus://pasta/1/documento/2');
        $ferramenta = self::ferramenta('ler_documentos_e_modelos', new RespostaDaFerramenta(['id' => 2, 'tipo' => 'image/png'], arquivo: $arquivo));

        $resultado = (new DespachoDasFerramentas([$ferramenta], self::aberta()))->executar($ferramenta, []);

        self::assertFalse($resultado->isError);
        self::assertCount(2, $resultado->content);
        self::assertSame('{"id":2,"tipo":"image/png"}', self::texto($resultado->content[0]));
        self::assertInstanceOf(ImageContent::class, $resultado->content[1]);
        self::assertSame(base64_encode($png), $resultado->content[1]->data);
        self::assertSame('image/png', $resultado->content[1]->mimeType);
        self::assertSame(['id' => 2, 'tipo' => 'image/png'], $resultado->structuredContent, 'o structuredContent leva só os metadados');
    }

    #[TestDox('arquivo na resposta (decisão 24): o PDF vira recurso embutido com BlobResourceContents, a uri e o meta experimental')]
    public function testPdfVaiComoRecursoEmbutido(): void
    {
        $pdf = "%PDF-1.7\nconteudo";
        $arquivo = new ArquivoNaResposta($pdf, 'application/pdf', 'bluejus://pasta/1/documento/2');
        $ferramenta = self::ferramenta('ler_documentos_e_modelos', new RespostaDaFerramenta(['id' => 2], arquivo: $arquivo));

        $resultado = (new DespachoDasFerramentas([$ferramenta], self::aberta()))->executar($ferramenta, []);

        self::assertCount(2, $resultado->content);
        $bloco = $resultado->content[1];
        self::assertInstanceOf(EmbeddedResource::class, $bloco);
        self::assertSame(
            ['blob' => base64_encode($pdf), 'uri' => 'bluejus://pasta/1/documento/2', 'mimeType' => 'application/pdf', '_meta' => ['experimental' => true]],
            $bloco->resource->jsonSerialize(),
        );
    }

    #[TestDox('arquivo na resposta (decisão 24): o JSON continua dentro do teto de 50 mil mesmo com um arquivo de 8 MB; só o JSON conta')]
    public function testArquivoGrandeNaoEntraNoTeto(): void
    {
        $grande = "%PDF-1.7\n" . str_repeat('A', 8 * 1024 * 1024);
        $arquivo = new ArquivoNaResposta($grande, 'application/pdf', 'bluejus://pasta/1/documento/2');
        $ferramenta = self::ferramenta('ler_documentos_e_modelos', new RespostaDaFerramenta(['id' => 2], arquivo: $arquivo));

        $resultado = (new DespachoDasFerramentas([$ferramenta], self::aberta()))->executar($ferramenta, []);

        self::assertFalse($resultado->isError);
        self::assertLessThan(DespachoDasFerramentas::TETO_DE_CARACTERES, mb_strlen(self::texto($resultado->content[0])));
        self::assertInstanceOf(EmbeddedResource::class, $resultado->content[1]);
    }

    #[TestDox('arquivo na resposta (regra 9): o log anota o sha256 do arquivo e nunca o conteúdo, a uri nem o base64')]
    public function testLogAnotaSoOHash(): void
    {
        $pdf = "%PDF-1.7\nconteudo secreto";
        $requisicao = self::aberta();
        $arquivo = new ArquivoNaResposta($pdf, 'application/pdf', 'bluejus://pasta/1/documento/2');
        $ferramenta = self::ferramenta('ler_documentos_e_modelos', new RespostaDaFerramenta(['id' => 2], contagens: ['bytes' => \strlen($pdf)], ids: ['documento' => [2]], arquivo: $arquivo));

        (new DespachoDasFerramentas([$ferramenta], $requisicao))->executar($ferramenta, []);

        self::assertSame(hash('sha256', $pdf), $requisicao->anotacao('arquivo_sha256'));
        $anotacoes = json_encode($requisicao->anotacoes(), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secreto', $anotacoes);
        self::assertStringNotContainsString(base64_encode($pdf), $anotacoes);
        self::assertStringNotContainsString('bluejus://', $anotacoes);
    }

    #[TestDox('sem arquivo, o content tem um bloco só e o log não leva arquivo_sha256')]
    public function testSemArquivoNaoAnotaHash(): void
    {
        $requisicao = self::aberta();
        $ferramenta = self::ferramenta('ler_agenda', new RespostaDaFerramenta(['a' => 1]));

        $resultado = (new DespachoDasFerramentas([$ferramenta], $requisicao))->executar($ferramenta, []);

        self::assertCount(1, $resultado->content);
        self::assertNull($requisicao->anotacao('arquivo_sha256'));
    }

    private static function aberta(): RequisicaoDoConector
    {
        $requisicao = new RequisicaoDoConector();
        $requisicao->abrir(self::chamada());

        return $requisicao;
    }

    private static function texto(mixed $conteudo): string
    {
        self::assertInstanceOf(TextContent::class, $conteudo);

        return $conteudo->text;
    }
}
