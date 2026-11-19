<?php

declare(strict_types=1);

namespace App\Conector\DTO;

/**
 * Um arquivo que a ferramenta devolve inteiro ao app de I.A. (`docs/specs/mcp-remoto-oauth.md`, 6.5, e decisão 24 da
 * seção 14): a imagem vai como conteúdo de imagem do MCP e o PDF como recurso embutido. O arquivo vai num bloco à parte
 * do `content`, fora do teto de 50 mil caracteres por campo; o `structuredContent` leva só os metadados, que a
 * ferramenta põe em {@see RespostaDaFerramenta::$dados}.
 *
 * Os bytes nunca vão ao log `mcp`: lá entra só o {@see $sha256} (regra 9 e `ler_documentos_e_modelos`).
 */
final class ArquivoNaResposta
{
    public const IMAGENS = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
    public const PDF = 'application/pdf';

    public readonly string $sha256;

    /**
     * @param string $conteudo os bytes do arquivo, já conferidos pelo leitor (tipo pelo conteúdo, tamanho)
     * @param string $uri      o endereço do recurso embutido (ex.: `bluejus://pasta/12/documento/34`), sem nome de arquivo
     */
    public function __construct(
        public readonly string $conteudo,
        public readonly string $tipo,
        public readonly string $uri,
    ) {
        if ($tipo !== self::PDF && !\in_array($tipo, self::IMAGENS, true)) {
            throw new \InvalidArgumentException('Tipo de arquivo que o conector não devolve: ' . $tipo);
        }
        $this->sha256 = hash('sha256', $conteudo);
    }

    public function ehImagem(): bool
    {
        return \in_array($this->tipo, self::IMAGENS, true);
    }
}
