<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

/**
 * Torna inerte, para o prompt, qualquer dado que não nasceu no código: texto de publicação (DJEN),
 * classe/assunto/órgão/tribunal (Datajud), NUP, nomes de pessoas, resposta anterior do modelo.
 *
 * O prompt delimita cada bloco de dado com uma tag própria ({@see DELIMITADORES}) e diz ao modelo que
 * o que está entre elas é dado, não instrução. A defesa só vale se o dado não puder FECHAR a tag e
 * abrir outra por conta própria — então toda ocorrência de `<nome>`/`</nome>` (com qualquer caixa e
 * espaço interno) vira `[nome>`/`[/nome>`, texto inerte. Caracteres de controle, zero-width e de
 * direção bidirecional (os usados para esconder instrução) saem também. Vale também para o que só
 * PARECE a tag (IA-014): formas de largura total, `&lt;`/`&#60;`, sinais parecidos com `<` e `/`
 * e espaços não ASCII no meio — o modelo lê "‹/movimentacoes>" como fechamento tanto quanto o original.
 *
 * Idempotente: aplicar duas vezes dá o mesmo resultado — o montador e o prompt podem ambos chamar.
 */
final class NeutralizadorDeConteudo
{
    /**
     * Nomes das tags que os prompts usam como fronteira de dado: os do Push (fatia 1), os dos agentes da pasta
     * (fatia 2), os da aba Perguntar do drawer (F3: `conversa_anterior`, `pergunta`) e os do chat da Central
     * (`pessoa`, `historico`, `pergunta`). Bloco novo no prompt = nome novo AQUI, senão o dado pode fechá-lo.
     */
    public const DELIMITADORES = [
        'movimentacoes', 'processo', 'equipe', 'analise_anterior',
        'pasta', 'processos_vinculados', 'clientes', 'metas', 'anotacoes', 'observacoes',
        'documentos', 'documentos_anexados', 'checklist', 'financeiro',
        'conversa_anterior', 'pergunta',
        'historico', 'pessoa',
        // Chat I.A em página inteira (`chat-livre-v1`): texto extraído de arquivo DOCX anexado.
        'anexo_texto',
    ];

    /** Controle C0/C1 (menos \t \n \r, que viram espaço depois), zero-width, bidi e BOM. */
    private const CONTROLE = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}'
        . '\x{200B}-\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u';

    /**
     * O que um leitor (o modelo) pode tomar por `<`: o próprio, as entidades HTML e os sinais
     * parecidos (‹ 〈 ⟨ ﹤ ˂ ᐸ ❬ ❮ ⧼). A forma de largura total (＜) já virou `<` antes.
     */
    private const MENOR_QUE = '(?:<|&lt;|&#0*60;|&#x0*3c;|[\x{2039}\x{2329}\x{27E8}\x{3008}\x{FE64}\x{02C2}\x{1438}\x{276C}\x{276E}\x{29FC}])';

    /** O que pode passar por `/`: o próprio, as entidades e os parecidos (⁄ ∕ ⧸). */
    private const BARRA = '(?:\/|&#0*47;|&#x0*2f;|&sol;|[\x{2044}\x{2215}\x{29F8}])';

    /** Espaço entre `<`, `/` e o nome — inclusive os não ASCII (NBSP, espaços finos, ideográfico). */
    private const ESPACO = '[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]*';

    public static function neutralizar(string $texto): string
    {
        if ($texto === '') {
            return '';
        }

        // Sequência inválida derrubaria o preg_replace (devolve null = texto perdido): limpa antes.
        $texto = (string) mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
        $texto = (string) preg_replace(self::CONTROLE, '', $texto);
        // Largura total (U+FF01–U+FF5E) vira ASCII: "＜／ｍｅｔａｓ＞" é "</metas>" para quem lê. Só
        // esse bloco, de propósito: uma normalização NFKC geral trocaria "nº" por "no" no texto jurídico.
        $texto = (string) preg_replace_callback(
            '/[\x{FF01}-\x{FF5E}]/u',
            static fn (array $m): string => chr(mb_ord($m[0], 'UTF-8') - 0xFEE0),
            $texto,
        );
        $texto = (string) preg_replace_callback(
            '/' . self::MENOR_QUE . self::ESPACO . '(' . self::BARRA . ')?' . self::ESPACO . '(' . implode('|', self::DELIMITADORES) . ')\b/iu',
            static fn (array $m): string => '[' . ($m[1] !== '' ? '/' : '') . $m[2],
            $texto,
        );

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }
}
