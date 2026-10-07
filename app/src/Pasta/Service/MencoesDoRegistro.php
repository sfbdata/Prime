<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Shared\Service\SanitizadorTextoRico;

/**
 * As @menções do Registro da pasta (item 20b): o formato gravado, a leitura dos ids, a troca do
 * rótulo pelo nome verdadeiro e a exibição como destaque.
 *
 * FORMATO GRAVADO — um token TEXTUAL dentro do conteúdo:
 *
 *     @[Nome da Pessoa](user:42)
 *
 * É texto, não marcação: atravessa o sanitizador `textoRico` sem nenhuma tag ou atributo novo
 * (D-EDITOR é decisão do Samuel; o sanitizador não foi tocado). Duas observações do formato:
 *  - o HtmlSanitizer codifica `@` como `&#64;` no texto (StringSanitizer::REPLACEMENTS), então
 *    no conteúdo HTML o token aparece como `&#64;[Nome](user:42)`. O padrão aceita as duas formas;
 *  - o rótulo não aceita `[ ] < >`: o token nunca atravessa uma tag nem se aninha. Por isso o nome
 *    que vai no rótulo passa por {@see rotulo()} (que tira também `( )`).
 *
 * QUEM GARANTE O QUÊ:
 *  - {@see reescrever()} roda no envio (UseCase), DEPOIS do sanitizador: token com id válido
 *    (colega ativo do escritório, decidido por quem chama) tem o rótulo trocado pelo nome que está
 *    no banco — o rótulo digitado não vale; token com id inexistente ou de outro escritório perde a
 *    marcação e vira `@rótulo` em texto comum. Assim nunca se grava nome de outro escritório.
 *  - {@see exibir()} roda na tela: sanitiza (como `texto_rico`) e só ENTÃO troca cada token pelo
 *    destaque. O rótulo é decodificado e escapado de novo, e o id é só dígitos — o destaque é
 *    marcação nossa, montada aqui, nunca vinda do usuário. A troca é feita só nos trechos de TEXTO
 *    (entre tags), nunca dentro de um atributo.
 */
final class MencoesDoRegistro
{
    /** `@` cru (texto puro) ou `&#64;` (HTML que passou pelo sanitizador). */
    public const PADRAO = '/(?:@|&#64;)\[([^\[\]<>]{1,120})\]\(user:(\d{1,10})\)/u';

    /** Teto de pessoas lidas por registro — o resto dos tokens fica como texto comum. */
    public const MAXIMO_POR_REGISTRO = 20;

    public function __construct(
        private readonly SanitizadorTextoRico $sanitizador,
    ) {
    }

    /**
     * Ids mencionados, na ordem em que aparecem, sem repetição (até {@see MAXIMO_POR_REGISTRO}).
     *
     * @return list<int>
     */
    public function extrairIds(?string $conteudo): array
    {
        if ($conteudo === null || preg_match_all(self::PADRAO, $conteudo, $achados) === false) {
            return [];
        }

        $ids = [];
        foreach ($achados[2] as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
            if (count($ids) >= self::MAXIMO_POR_REGISTRO) {
                break;
            }
        }

        return $ids;
    }

    /**
     * Grava a forma canônica: id em `$nomesValidos` → `@[nome do banco](user:id)`; qualquer outro
     * id → `@rótulo` sem marcação. Recebe conteúdo JÁ sanitizado.
     *
     * @param array<int, string> $nomesValidos id => nome completo (só colegas do escritório)
     */
    public function reescrever(string $conteudo, array $nomesValidos): string
    {
        // Sem `<` o conteúdo é texto puro e é exibido escapado; com `<`, é HTML e o nome precisa
        // entrar escapado. O rótulo nunca traz `<`, então reescrever não muda o tipo do conteúdo.
        $ehHtml = str_contains($conteudo, '<');

        return $this->emTrechosDeTexto($conteudo, static function (array $m) use ($nomesValidos, $ehHtml): string {
            $id = (int) $m[2];
            if (!array_key_exists($id, $nomesValidos)) {
                return '@' . $m[1];
            }

            $rotulo = self::rotulo($nomesValidos[$id]);

            return '@[' . ($ehHtml ? htmlspecialchars($rotulo, \ENT_QUOTES | \ENT_HTML5, 'UTF-8') : $rotulo) . '](user:' . $id . ')';
        });
    }

    /**
     * HTML seguro para a tela: o mesmo do `texto_rico` com cada token trocado pelo destaque.
     */
    public function exibir(?string $conteudo): string
    {
        $seguro = $this->sanitizador->paraExibicao($conteudo) ?? '';

        return $this->emTrechosDeTexto($seguro, static function (array $m): string {
            $rotulo = html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

            return '<span class="ps-mencao" data-user-id="' . (int) $m[2] . '">@'
                . htmlspecialchars($rotulo, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')
                . '</span>';
        });
    }

    /** Para textos sem marcação (sino): o token vira só `@rótulo`. */
    public function paraTextoPlano(string $texto): string
    {
        return (string) preg_replace_callback(self::PADRAO, static fn (array $m): string => '@' . $m[1], $texto);
    }

    /**
     * O conteúdo (HTML do editor ou texto puro) como o sino mostra: fim de bloco e <br> viram
     * espaço (senão "linha um</p><p>linha dois" grudaria), entidades decodificadas, cada menção
     * como "@Nome" e os brancos colapsados.
     */
    public function textoDoSino(?string $html): string
    {
        $html  = preg_replace('#<br\s*/?>|</(p|li|h[1-6]|blockquote|pre)>#i', ' ', (string) $html) ?? '';
        $texto = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $texto = $this->paraTextoPlano($texto);

        return trim(preg_replace('/[\s\p{Z}]+/u', ' ', $texto) ?? '');
    }

    /** O nome como rótulo do token: sem `[ ] ( ) < >` nem controles, brancos colapsados. */
    public static function rotulo(string $nome): string
    {
        $limpo = (string) preg_replace('/[\[\]()<>\p{Cc}]+/u', ' ', $nome);
        $limpo = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $limpo));

        return $limpo === '' ? 'colega' : mb_substr($limpo, 0, 120);
    }

    /**
     * Aplica a troca só no TEXTO, nunca dentro de uma tag. O HTML que chega aqui saiu do
     * sanitizador (atributos com `<`/`>` codificados), então separar por `<…>` é confiável.
     *
     * @param callable(array<int, string>): string $troca
     */
    private function emTrechosDeTexto(string $html, callable $troca): string
    {
        $partes = preg_split('/(<[^>]*>)/', $html, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if ($partes === false) {
            return $html;
        }

        foreach ($partes as $i => $parte) {
            if ($parte === '' || $parte[0] === '<') {
                continue;
            }
            $partes[$i] = (string) preg_replace_callback(self::PADRAO, $troca, $parte);
        }

        return implode('', $partes);
    }
}
