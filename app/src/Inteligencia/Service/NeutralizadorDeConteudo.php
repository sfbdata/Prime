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
 * direção bidirecional (os usados para esconder instrução) saem também.
 *
 * Idempotente: aplicar duas vezes dá o mesmo resultado — o montador e o prompt podem ambos chamar.
 */
final class NeutralizadorDeConteudo
{
    /**
     * Nomes das tags que os prompts usam como fronteira de dado: os do Push (fatia 1) e os dos
     * agentes da pasta (fatia 2). Bloco novo no prompt = nome novo AQUI, senão o dado pode fechá-lo.
     */
    public const DELIMITADORES = [
        'movimentacoes', 'processo', 'equipe', 'analise_anterior',
        'pasta', 'processos_vinculados', 'clientes', 'metas', 'anotacoes', 'observacoes',
        'documentos', 'checklist', 'financeiro',
    ];

    /** Controle C0/C1 (menos \t \n \r, que viram espaço depois), zero-width, bidi e BOM. */
    private const CONTROLE = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}'
        . '\x{200B}-\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u';

    public static function neutralizar(string $texto): string
    {
        if ($texto === '') {
            return '';
        }

        // Sequência inválida derrubaria o preg_replace (devolve null = texto perdido): limpa antes.
        $texto = (string) mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
        $texto = (string) preg_replace(self::CONTROLE, '', $texto);
        $texto = (string) preg_replace(
            '/<\s*(\/?)\s*(' . implode('|', self::DELIMITADORES) . ')\b/iu',
            '[$1$2',
            $texto,
        );

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }
}
