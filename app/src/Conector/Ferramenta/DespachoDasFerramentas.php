<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ArquivoNaResposta;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Mcp\RegistroDasFerramentas;
use App\Conector\Service\RequisicaoDoConector;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Server\Builder;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * O despacho único das ferramentas do conector (`docs/specs/mcp-remoto-oauth.md`, 6.2, regras 1, 7, 8, 9 e 10, e 6.3,
 * item 1). Registra no SDK cada {@see FerramentaDoConector} da tag e executa toda chamada:
 *  - só ferramenta do {@see CatalogoFechadoDoConector}; o `title`, as anotações e o `_meta` (com os `securitySchemes`)
 *    vêm do catálogo, nunca da ferramenta, e nenhuma declara `outputSchema`;
 *  - a chamada só roda com a requisição aberta pelo portão ({@see RequisicaoDoConector::atual()} lança sem ela);
 *  - campo fora do esquema de entrada é recusado antes da ferramenta;
 *  - a resposta leva o dado no `structuredContent` e o mesmo dado, em JSON, no `content`, com teto de
 *    {@see TETO_DE_CARACTERES} caracteres por campo; o arquivo da resposta ({@see ArquivoNaResposta}), quando há, vai num
 *    bloco à parte do `content` (imagem do MCP ou PDF como recurso embutido), fora do teto (6.5, decisão 24);
 *  - {@see ErroDaFerramenta} vira `isError` com a mensagem fixa;
 *  - ferramenta, resultado, contagens e ids vão às anotações da requisição, para o log `mcp`.
 */
final class DespachoDasFerramentas implements RegistroDasFerramentas
{
    /** O teto prático por campo (6.2, regra 7): o `content` e o `structuredContent` têm o mesmo tamanho. */
    public const TETO_DE_CARACTERES = 50_000;

    public const MOTIVO_GRANDE_DEMAIS = 'resposta_grande_demais';
    public const GRANDE_DEMAIS = 'A resposta passou do tamanho máximo. Peça uma parte menor ou a página seguinte.';

    private const JSON = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

    /** @param iterable<FerramentaDoConector> $ferramentas */
    public function __construct(
        #[AutowireIterator(FerramentaDoConector::TAG)]
        private readonly iterable $ferramentas,
        private readonly RequisicaoDoConector $requisicao,
    ) {
    }

    public function registrarNo(Builder $construtor): void
    {
        foreach ($this->registradas() as $ferramenta) {
            $construtor->add(self::definicao($ferramenta), $this->manipulador($ferramenta));
        }
    }

    /**
     * As ferramentas da tag, conferidas contra o catálogo: nome fora da lista fechada ou repetido derruba a montagem.
     *
     * @return list<FerramentaDoConector>
     *
     * @throws \LogicException
     */
    public function registradas(): array
    {
        $registradas = [];
        foreach ($this->ferramentas as $ferramenta) {
            $nome = $ferramenta->nome();
            if (!CatalogoFechadoDoConector::existe($nome)) {
                throw new \LogicException(sprintf('Ferramenta fora do catálogo do conector: %s.', $nome));
            }
            if (isset($registradas[$nome])) {
                throw new \LogicException(sprintf('Ferramenta do conector registrada duas vezes: %s.', $nome));
            }
            $registradas[$nome] = $ferramenta;
        }

        return array_values($registradas);
    }

    /**
     * Executa a chamada já recebida pelo SDK. Sem a requisição aberta pelo portão, lança (o SDK responde erro interno).
     * Exceção que não é {@see ErroDaFerramenta} segue para o SDK depois de anotar `resultado=erro`.
     *
     * @param array<string, mixed> $argumentos
     */
    public function executar(FerramentaDoConector $ferramenta, array $argumentos): CallToolResult
    {
        $chamada = $this->requisicao->atual();
        $this->requisicao->anotar('ferramenta', $ferramenta->nome());
        $argumentos = self::semObjetos($argumentos);

        try {
            self::exigirCamposDoEsquema($ferramenta, $argumentos);
            $resposta = $ferramenta->executar($argumentos, $chamada);
            $dados = self::dadosDe($resposta);
            $json = json_encode($dados, self::JSON);
            if (mb_strlen($json) > self::TETO_DE_CARACTERES) {
                throw new ErroDaFerramenta(self::MOTIVO_GRANDE_DEMAIS, self::GRANDE_DEMAIS);
            }
        } catch (ErroDaFerramenta $erro) {
            $this->requisicao->anotar('resultado', 'recusado');
            $this->requisicao->anotar('recusa', $erro->motivo);

            return CallToolResult::error([new TextContent($erro->getMessage())]);
        } catch (\Throwable $erro) {
            // Falha inesperada: a linha do log `mcp` diz que a chamada deu erro, e o SDK responde o erro interno.
            $this->requisicao->anotar('resultado', 'erro');

            throw $erro;
        }

        $this->anotarSucesso($resposta);

        $conteudo = [new TextContent($json)];
        if ($resposta->arquivo !== null) {
            $conteudo[] = self::blocoDoArquivo($resposta->arquivo);
        }

        return new CallToolResult($conteudo, false, $dados);
    }

    /**
     * O SDK entrega objeto aninhado dos argumentos como `\stdClass` (`CallToolRequest`: "nested objects are \stdClass").
     * Antes, ele confere os argumentos contra o esquema e recusa (-32602) objeto em campo de texto, número ou lista de
     * números; mas um campo de objeto ou uma lista de objetos chegaria assim, e a ferramenta que espera array recusaria
     * o pedido. Aqui, na entrada e uma vez para todas, viram arrays, em profundidade. Um objeto vazio vira lista vazia.
     *
     * @param array<string, mixed> $argumentos
     *
     * @return array<string, mixed>
     */
    private static function semObjetos(array $argumentos): array
    {
        $converter = static function (mixed $valor) use (&$converter): mixed {
            if ($valor instanceof \stdClass) {
                $valor = get_object_vars($valor);
            }

            return \is_array($valor) ? array_map($converter, $valor) : $valor;
        };

        return $converter($argumentos);
    }

    private static function definicao(FerramentaDoConector $ferramenta): Tool
    {
        $nome = $ferramenta->nome();

        return new Tool(
            name: $nome,
            title: CatalogoFechadoDoConector::TITULOS[$nome],
            inputSchema: $ferramenta->esquemaDeEntrada(),
            description: $ferramenta->descricao(),
            annotations: CatalogoFechadoDoConector::anotacoes($nome),
            meta: CatalogoFechadoDoConector::meta($nome),
        );
    }

    private function manipulador(FerramentaDoConector $ferramenta): ToolHandlerInterface
    {
        $despacho = $this;

        return new class ($despacho, $ferramenta) implements ToolHandlerInterface {
            public function __construct(private readonly DespachoDasFerramentas $despacho, private readonly FerramentaDoConector $ferramenta)
            {
            }

            /** @param array<string, mixed> $arguments */
            public function execute(array $arguments, ClientGateway $gateway): CallToolResult
            {
                return $this->despacho->executar($this->ferramenta, $arguments);
            }
        };
    }

    /**
     * Só os campos que o esquema declara (6.2, regra 10): a ferramenta nunca recebe o que não pediu.
     *
     * @param array<string, mixed> $argumentos
     *
     * @throws ErroDaFerramenta
     */
    private static function exigirCamposDoEsquema(FerramentaDoConector $ferramenta, array $argumentos): void
    {
        $propriedades = $ferramenta->esquemaDeEntrada()['properties'] ?? [];
        $conhecidos = \is_array($propriedades) ? array_keys($propriedades) : [];
        if (array_diff(array_keys($argumentos), $conhecidos) !== []) {
            throw ErroDaFerramenta::entradaInvalida();
        }
    }

    /**
     * O `structuredContent`: os dados da ferramenta e, quando a lista foi cortada, a página seguinte (número ou cursor
     * em texto, como a ferramenta devolveu). Sempre um objeto JSON, mesmo vazio.
     *
     * @return array<string, mixed>|\stdClass
     */
    private static function dadosDe(RespostaDaFerramenta $resposta): array|\stdClass
    {
        $dados = $resposta->dados;
        if ($resposta->proximaPagina !== null) {
            $dados['proxima_pagina'] = $resposta->proximaPagina;
        }

        return $dados === [] ? new \stdClass() : $dados;
    }

    /** Imagem como conteúdo de imagem do MCP; PDF como recurso embutido, marcado experimental (6.5). */
    private static function blocoDoArquivo(ArquivoNaResposta $arquivo): ImageContent|EmbeddedResource
    {
        $base64 = base64_encode($arquivo->conteudo);
        if ($arquivo->ehImagem()) {
            return new ImageContent($base64, $arquivo->tipo);
        }

        return new EmbeddedResource(new BlobResourceContents($arquivo->uri, $arquivo->tipo, $base64, ['experimental' => true]));
    }

    private function anotarSucesso(RespostaDaFerramenta $resposta): void
    {
        $this->requisicao->anotar('resultado', 'ok');
        if ($resposta->arquivo !== null) {
            // Só o hash vai ao log, nunca o conteúdo nem o nome do arquivo (regra 9).
            $this->requisicao->anotar('arquivo_sha256', $resposta->arquivo->sha256);
        }
        if ($resposta->contagens !== []) {
            $this->requisicao->anotar('contagens', $resposta->contagens);
        }
        if ($resposta->ids !== []) {
            $this->requisicao->anotar('ids', $resposta->ids);
        }
    }
}
