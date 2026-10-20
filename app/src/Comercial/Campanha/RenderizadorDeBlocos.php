<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * As linhas `<tr>` do corpo de uma campanha (spec `comercial-e-emails.md` §8.3), fiéis ao desenho 05: os 7 blocos de
 * dc L.488-494, a linha espaçadora de 14 px de L.499 e o modo simples de L.484-485 e L.499-500, com os estilos inline
 * 1:1. O resultado entra no layout `linhas` do {@see \App\Comercial\Service\MontadorDeEmail}.
 *
 * Texto sempre escapado como o `e()` do dc (`& < > "`), `\n` vira `<br>` onde o dc troca. `{nome}` fica literal só onde
 * o dc o troca (título, texto e colunas; corpo do simples): quem monta troca pelo primeiro nome escapado. Nos outros
 * lugares a chave sai como `&#123;nome}`, que aparece igual na tela e o montador não alcança (G13).
 *
 * Link do botão: as chaves do dc viram as URLs do sistema; `validar` (sem token por destinatário numa campanha) vira
 * `app`; outro valor só com `http(s)://`, senão o botão sai sem link. Imagem só com `https://` (R16).
 */
final class RenderizadorDeBlocos
{
    public const TIPOS = ['titulo', 'texto', 'imagem', 'botao', 'noticias', 'colunas', 'divisor'];

    /** As páginas de outras frentes (vagas da a7, Contribua da f6) entram pelo caminho: a rota não existe nesta base. */
    private const CAMINHOS = ['vagas' => '/vagas-juridicas', 'contribua' => '/contribua#sug', 'espaco' => '/contribua#espaco'];
    private const ROTAS = ['app' => 'app_login', 'cadastro' => 'auth_cadastro'];
    private const FONTE = 'Arial,Helvetica,sans-serif';

    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    /** @param list<array{t: string, a?: string, b?: string}> $blocos */
    public function blocos(array $blocos): string
    {
        return implode('', array_map($this->bloco(...), $blocos)) . '<tr><td style="height:14px;"></td></tr>';
    }

    /** O modo simples (dc L.484-485, L.499-500): parágrafos, itens com o visto e o botão. @param list<string> $itens */
    public function simples(string $corpo, array $itens, string $cta, string $link): string
    {
        $paragrafos = implode('', array_map(
            static fn (string $p): string => '<p style="margin:0 0 14px;font:15px/1.6 ' . self::FONTE . ';color:#34505f;">' . self::quebras(self::e($p)) . '</p>',
            preg_split('/\n\s*\n/', $corpo) ?: [],
        ));
        $linhas = implode('', array_map(
            static fn (string $t): string => '<tr><td style="padding:6px 0;font:14px/1.5 ' . self::FONTE . ';color:#243845;"><span style="color:#0a6fa0;font-weight:bold;">&#10003;</span>&nbsp; ' . self::fixo($t) . '</td></tr>',
            $itens,
        ));
        $tabela = $linhas === '' ? '' : '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:4px 0 18px;">' . $linhas . '</table>';
        $botao = $cta === '' ? '' : '<tr><td align="center" style="padding:6px 30px 30px;"><a' . $this->href($link) . ' style="display:inline-block;background:#0a6fa0;color:#ffffff;font:bold 15px ' . self::FONTE . ';text-decoration:none;padding:14px 26px;border-radius:4px;">' . self::fixo($cta) . '</a></td></tr>';

        return '<tr><td style="padding:28px 30px 8px;">' . $paragrafos . $tabela . '</td></tr>' . $botao;
    }

    /** A URL de uma chave do dc ou de um endereço http(s); null quando não serve (o botão sai sem link). */
    public function link(string $valor): ?string
    {
        $chave = $valor === 'validar' ? 'app' : $valor;
        if (isset(self::ROTAS[$chave])) {
            return $this->urls->generate(self::ROTAS[$chave], [], UrlGeneratorInterface::ABSOLUTE_URL);
        }
        if (isset(self::CAMINHOS[$chave])) {
            return $this->base() . self::CAMINHOS[$chave];
        }

        return preg_match('{^https?://[^\s<>"]+$}i', $valor) === 1 ? $valor : null;
    }

    /** @param array{t: string, a?: string, b?: string} $b */
    private function bloco(array $b): string
    {
        $a = (string) ($b['a'] ?? '');
        $segundo = (string) ($b['b'] ?? '');

        return match ($b['t']) {
            'titulo' => '<tr><td style="padding:22px 30px 4px;font:bold 20px/1.3 ' . self::FONTE . ';color:#0b3550;">' . self::e($a) . '</td></tr>',
            'texto' => '<tr><td style="padding:8px 30px;font:15px/1.6 ' . self::FONTE . ';color:#34505f;">' . self::quebras(self::e($a)) . '</td></tr>',
            'imagem' => self::imagem($a, $segundo),
            'botao' => '<tr><td align="center" style="padding:14px 30px 20px;"><a' . $this->href($segundo) . ' style="display:inline-block;background:#0a6fa0;color:#fff;font:bold 15px ' . self::FONTE . ';text-decoration:none;padding:13px 24px;border-radius:4px;">' . self::fixo($a === '' ? 'Saiba mais' : $a) . '</a></td></tr>',
            'divisor' => '<tr><td style="padding:10px 30px;"><div style="height:1px;background:#e6edf2;"></div></td></tr>',
            'noticias' => self::noticias($a),
            'colunas' => self::colunas($a, $segundo),
            default => '',
        };
    }

    /** dc L.490: sem endereço, o bloco some; aqui também some o endereço que não é `https://`. */
    private static function imagem(string $src, string $alt): string
    {
        if (preg_match('{^https://[^\s<>"]+$}i', $src) !== 1) {
            return '';
        }

        return '<tr><td style="padding:12px 30px;"><img src="' . self::fixo($src) . '" alt="' . self::fixo($alt) . '" width="540" style="display:block;width:100%;max-width:540px;height:auto;border-radius:6px;border:0;"></td></tr>';
    }

    /** dc L.493: uma notícia por linha, "Título|Resumo". */
    private static function noticias(string $texto): string
    {
        $cartoes = '';
        foreach (array_filter(explode("\n", $texto), static fn (string $l): bool => $l !== '') as $linha) {
            $partes = explode('|', $linha);
            $resumo = $partes[1] ?? '';
            $cartoes .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 10px;border:1px solid #e6edf2;border-radius:6px;"><tr><td style="padding:12px 14px;"><div style="font:bold 14.5px ' . self::FONTE . ';color:#0b3550;">' . self::fixo($partes[0]) . '</div>'
                . ($resumo !== '' ? '<div style="font:13.5px/1.5 ' . self::FONTE . ';color:#5b7383;margin-top:3px;">' . self::fixo($resumo) . '</div>' : '')
                . '</td></tr></table>';
        }

        return '<tr><td style="padding:8px 30px;">' . $cartoes . '</td></tr>';
    }

    /** dc L.494. */
    private static function colunas(string $esquerda, string $direita): string
    {
        $coluna = static fn (string $lado, string $texto): string => '<td width="50%" valign="top" style="padding-' . $lado . ':8px;font:14px/1.55 ' . self::FONTE . ';color:#34505f;">' . self::quebras(self::e($texto)) . '</td>';

        return '<tr><td style="padding:8px 30px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $coluna('right', $esquerda) . $coluna('left', $direita) . '</tr></table></td></tr>';
    }

    private function href(string $link): string
    {
        $url = $this->link($link);

        return $url === null ? '' : ' href="' . self::fixo($url) . '"';
    }

    /** O `e()` do dc: `& < > "`. */
    private static function e(string $texto): string
    {
        return htmlspecialchars($texto, ENT_COMPAT | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** Escapado e sem troca de `{nome}` (o dc não troca ali): a chave sai como `&#123;nome}`. */
    private static function fixo(string $texto): string
    {
        return str_replace('{', '&#123;', self::e($texto));
    }

    private static function quebras(string $html): string
    {
        return str_replace("\n", '<br>', $html);
    }

    /** Esquema e host da aplicação (no worker, do `DEFAULT_URI`). */
    private function base(): string
    {
        $absoluto = $this->urls->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $caminho = $this->urls->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_PATH);

        return substr($absoluto, 0, \strlen($absoluto) - \strlen($caminho));
    }
}
