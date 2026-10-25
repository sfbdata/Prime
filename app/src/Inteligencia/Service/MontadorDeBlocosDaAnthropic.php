<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Inteligencia\DTO\AnexoDeDocumento;

/**
 * O `content` da última mensagem do usuário na API de Mensagens da Anthropic quando o pedido traz anexos (PR F,
 * "Ler N documentos"). Sem anexos, o conteúdo continua a string de sempre.
 *
 * Forma dos blocos (Messages API, conferida na documentação em 24/10/2026; documento só em turno do usuário):
 *   PDF    → `{"type":"document","source":{"type":"base64","media_type":"application/pdf","data":"<base64>"}}`
 *   imagem → `{"type":"image","source":{"type":"base64","media_type":"image/png|jpeg|gif|webp","data":"<base64>"}}`
 * Inline, em base64: sem Files API (fora da retenção zero) e sem OCR nosso.
 *
 * Cada arquivo vem precedido de um bloco de texto "DOCUMENTO ANEXADO N", na ordem do bloco
 * `<documentos_anexados>` do prompt, e o texto do pedido vem por último (a recomendação da API é o arquivo
 * antes da pergunta). O nome do arquivo não vai em `title` nem em metadado: ele já está no texto, delimitado e
 * neutralizado.
 */
final class MontadorDeBlocosDaAnthropic
{
    /**
     * @param list<AnexoDeDocumento> $anexos
     *
     * @return string|list<array<string, mixed>>
     */
    public static function conteudo(string $texto, array $anexos): string|array
    {
        if ($anexos === []) {
            return $texto;
        }

        $blocos = [];
        foreach ($anexos as $i => $anexo) {
            $blocos[] = ['type' => 'text', 'text' => sprintf('DOCUMENTO ANEXADO %d', $i + 1)];
            $blocos[] = self::bloco($anexo);
        }
        $blocos[] = ['type' => 'text', 'text' => $texto];

        return $blocos;
    }

    /** @return array<string, mixed> */
    public static function bloco(AnexoDeDocumento $anexo): array
    {
        return [
            'type'   => $anexo->ehPdf() ? 'document' : 'image',
            'source' => [
                'type'       => 'base64',
                'media_type' => $anexo->mediaType,
                'data'       => $anexo->base64(),
            ],
        ];
    }
}
