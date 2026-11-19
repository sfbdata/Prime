<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * O TEXTO de um documento da pasta (peça do editor, DOCX, DOC, ODT, RTF, TXT, CSV, XML ou KML), lido pelo
 * {@see \App\Inteligencia\Contexto\LeitorDeDocumentosDaPasta::textos()} (spec mcp-remoto-oauth, 6.5). Imutável.
 *
 * O `tipo` é o tipo gravado no upload, já normalizado, e o conteúdo conferiu com ele (o extrator recusa o arquivo de
 * outro formato). O `texto` vem como o arquivo tem, sem neutralizar nem mascarar: quem manda o texto para fora decide a
 * proteção. `cortado` diz que o texto passou do teto do extrator e veio só o começo.
 *
 * NUNCA é persistido nem logado.
 */
final readonly class TextoDeDocumento
{
    public function __construct(
        public int $documentoId,
        public string $nome,
        public string $tipo,
        public string $texto,
        public bool $cortado,
        public int $tamanhoDoArquivo,
    ) {
        if ($texto === '') {
            throw new \InvalidArgumentException('Texto vazio.');
        }
    }
}
