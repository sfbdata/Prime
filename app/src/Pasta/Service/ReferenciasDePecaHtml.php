<?php

declare(strict_types=1);

namespace App\Pasta\Service;

/**
 * Único lugar que conhece o formato das URLs de arquivo embutidas no HTML de uma peça.
 *
 * Existe por causa de uma regra que a E1 institui:
 *
 *   > "Arquivo sem linha própria no banco" NÃO significa "arquivo órfão".
 *
 * As imagens enviadas pelo editor (TinyMCE) são gravadas por `UploadImagemEditorUseCase`, que
 * devolve o nome e **não persiste nada**: a única referência a elas é a URL dentro do HTML da
 * peça. Uma rotina de limpeza que decidisse por `{arquivos no disco} − {linhas no banco}`
 * apagaria imagem viva. A auditoria E0 encontrou exatamente esse caso em produção.
 *
 * O formato tem duas variantes, porque o TinyMCE grava a URL como ABSOLUTA
 * (`/uploads/pastas/<hex>.png`) ou RELATIVA (`../../uploads/pastas/<hex>.png`, default de
 * `convert_urls`). Desde o isolamento por tenant (M5, commit 2b176cb7) as imagens novas moram em
 * `pastas/<tenantId>/`; as anteriores continuam no diretório plano. As três formas são
 * reconhecidas aqui.
 */
final class ReferenciasDePecaHtml
{
    /**
     * Consome o prefixo inteiro (`./`, `../`, `/`) antes de `uploads/pastas/`. É o mesmo padrão
     * que `ExportarPecaTextoUseCase` já usava para apontar as imagens ao disco no export — a E1
     * apenas o trouxe para cá, sem alterar o comportamento.
     */
    private const PADRAO_PREFIXO = '#(?:\.{1,2}/)*/?uploads/pastas/#';

    /** Mesmo prefixo, capturando o `<tenantId>/` opcional e o nome do arquivo. */
    private const PADRAO_REFERENCIA = '#(?:\.{1,2}/)*/?uploads/pastas/(?:(\d+)/)?([A-Za-z0-9][A-Za-z0-9._-]*)#';

    /**
     * Nomes de arquivo referenciados pelo HTML, sem diretório e sem repetição.
     *
     * Devolve o NOME porque é essa a unidade comparável com o que existe em disco e com
     * `pasta_documento.caminho_arquivo` — as duas pontas de qualquer decisão sobre órfão.
     *
     * @return string[]
     */
    public function extrair(string $html): array
    {
        if ($html === '') {
            return [];
        }

        if (preg_match_all(self::PADRAO_REFERENCIA, $html, $encontros, \PREG_SET_ORDER) === false) {
            return [];
        }

        $nomes = [];
        foreach ($encontros as $encontro) {
            $nomes[$encontro[2]] = true;
        }

        return array_keys($nomes);
    }

    /**
     * Troca o prefixo das URLs pelo caminho em disco informado, preservando o nome do arquivo.
     *
     * `preg_replace_callback` com callback fixo (e não `preg_replace`) evita que `$` e `\` do
     * caminho sejam interpretados como referência de grupo no valor de substituição.
     */
    public function reescreverPrefixo(string $html, string $prefixoDisco): string
    {
        return (string) preg_replace_callback(
            self::PADRAO_PREFIXO,
            static fn (): string => $prefixoDisco,
            $html,
        );
    }
}
