<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Confere o HTML de uma página contra a Content-Security-Policy da resposta, do jeito que o navegador faria
 * (auditoria I-xss 5.04): todo `<script src>`, `<link rel=stylesheet>`, `<img>`, `<iframe>`, `<form action>`,
 * `<base>` e `<object>` tem de caber na diretiva certa; `<script>` inline só com o nonce da política (ou com
 * `'unsafe-inline'` sem nonce nem hash); handler `on*=""` e URL `javascript:` só com `'unsafe-inline'`;
 * `<style>` e `style=""` só com `'unsafe-inline'` em style-src.
 *
 * Bloco de dados (`<script type="application/json">`) o navegador não executa: fica de fora.
 * Não executa JS: o que o script faz depois (fetch, iframe criado na hora) é conferido por grep e pelo smoke do dono.
 */
final class PaginaContraACsp
{
    private const TIPOS_QUE_NAO_EXECUTAM = ['application/json', 'application/ld+json', 'text/template', 'text/html'];

    /** @var array<string, list<string>> */
    private array $diretivas = [];

    /** @var list<string> */
    private array $violacoes = [];

    private function __construct(string $csp, private readonly string $origem)
    {
        foreach (explode(';', $csp) as $parte) {
            $fontes = preg_split('/\s+/', trim($parte)) ?: [];
            $nome = strtolower((string) array_shift($fontes));
            if ($nome !== '') {
                $this->diretivas[$nome] = array_values(array_filter($fontes, static fn (string $f): bool => $f !== ''));
            }
        }
    }

    /**
     * @param string $origem esquema e host da página, ex. http://localhost
     *
     * @return list<string> o que o navegador recusaria (vazio = a página cabe na política)
     */
    public static function violacoes(string $html, string $csp, string $origem = 'http://localhost'): array
    {
        $conferencia = new self($csp, $origem);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, \LIBXML_NOERROR | \LIBXML_NOWARNING | \LIBXML_NONET);
        foreach ($dom->getElementsByTagName('*') as $elemento) {
            $conferencia->conferirElemento($elemento);
        }

        return $conferencia->violacoes;
    }

    private function conferirElemento(\DOMElement $el): void
    {
        $tag = strtolower($el->tagName);
        $this->conferirAtributosInline($el, $tag);
        match ($tag) {
            'script' => $this->conferirScript($el),
            'style' => $this->exigirInline('style-src', '<style> inline'),
            'link' => $this->conferirLink($el),
            'img' => $this->conferirUrl('img-src', $el->getAttribute('src'), '<img>'),
            'iframe', 'frame' => $this->conferirUrl('frame-src', $el->getAttribute('src'), '<iframe>'),
            'object', 'embed' => $this->conferirUrl('object-src', $el->getAttribute('data') . $el->getAttribute('src'), '<' . $tag . '>', true),
            'form' => $this->conferirUrl('form-action', $el->getAttribute('action'), '<form>', true),
            'base' => $this->conferirUrl('base-uri', $el->getAttribute('href'), '<base>', true),
            default => null,
        };
    }

    private function conferirAtributosInline(\DOMElement $el, string $tag): void
    {
        foreach ($el->attributes ?? [] as $atributo) {
            $nome = strtolower($atributo->nodeName);
            $valor = trim((string) $atributo->nodeValue);
            if (str_starts_with($nome, 'on')) {
                $this->exigirInlineSemNonce('script-src', sprintf('handler %s="" em <%s>', $nome, $tag));
            }
            if (\in_array($nome, ['href', 'src', 'action', 'formaction'], true) && preg_match('/^\s*javascript:/i', $valor) === 1) {
                $this->exigirInlineSemNonce('script-src', sprintf('URL javascript: em <%s %s>', $tag, $nome));
            }
            if ($nome === 'style') {
                $this->exigirInline('style-src', sprintf('style="" em <%s>', $tag));
            }
        }
    }

    private function conferirScript(\DOMElement $el): void
    {
        $tipo = strtolower(trim($el->getAttribute('type')));
        if (\in_array($tipo, self::TIPOS_QUE_NAO_EXECUTAM, true)) {
            return;
        }
        if ($el->hasAttribute('src')) {
            $this->conferirUrl('script-src', $el->getAttribute('src'), '<script src>');

            return;
        }
        $fontes = $this->fontes('script-src');
        $nonce = $el->getAttribute('nonce');
        if ($nonce !== '' && \in_array("'nonce-" . $nonce . "'", $fontes, true)) {
            return;
        }
        if ($this->hashConfere($fontes, (string) $el->textContent)) {
            return;
        }
        $this->exigirInlineSemNonce('script-src', '<script> inline sem nonce válido: ' . mb_substr(trim((string) $el->textContent), 0, 60));
    }

    private function conferirLink(\DOMElement $el): void
    {
        $rel = strtolower($el->getAttribute('rel'));
        if (str_contains($rel, 'stylesheet')) {
            $this->conferirUrl('style-src', $el->getAttribute('href'), '<link rel=stylesheet>');
        } elseif ($rel === 'manifest') {
            $this->conferirUrl('manifest-src', $el->getAttribute('href'), '<link rel=manifest>');
        }
    }

    /** @param list<string> $fontes */
    private function hashConfere(array $fontes, string $conteudo): bool
    {
        foreach (['sha256', 'sha384', 'sha512'] as $algoritmo) {
            if (\in_array("'" . $algoritmo . '-' . base64_encode(hash($algoritmo, $conteudo, true)) . "'", $fontes, true)) {
                return true;
            }
        }

        return false;
    }

    private function exigirInline(string $diretiva, string $oque): void
    {
        $fontes = $this->fontes($diretiva);
        if (!\in_array("'unsafe-inline'", $fontes, true)) {
            $this->violacoes[] = $diretiva . ' recusa ' . $oque;
        }
    }

    /** Com nonce ou hash na diretiva o navegador ignora o 'unsafe-inline' (CSP nível 2). */
    private function exigirInlineSemNonce(string $diretiva, string $oque): void
    {
        $fontes = $this->fontes($diretiva);
        $temNonceOuHash = array_filter($fontes, static fn (string $f): bool => preg_match("/^'(nonce|sha256|sha384|sha512)-/", $f) === 1) !== [];
        if (!\in_array("'unsafe-inline'", $fontes, true) || $temNonceOuHash) {
            $this->violacoes[] = $diretiva . ' recusa ' . $oque;
        }
    }

    private function conferirUrl(string $diretiva, string $url, string $oque, bool $semPadrao = false): void
    {
        $url = trim($url);
        if ($url === '' || $url === 'about:blank') {
            return;
        }
        $fontes = $this->fontes($diretiva, $semPadrao);
        if ($fontes !== null && !$this->urlCabe($fontes, $url)) {
            $this->violacoes[] = sprintf('%s recusa %s %s', $diretiva, $oque, $url);
        }
    }

    /** @param list<string> $fontes */
    private function urlCabe(array $fontes, string $url): bool
    {
        if (preg_match('#^(data|blob):#i', $url, $m) === 1) {
            return \in_array(strtolower($m[1]) . ':', $fontes, true);
        }
        $absoluta = str_starts_with($url, '//') ? 'https:' . $url : $url;
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $absoluta) !== 1) {
            $absoluta = $this->origem . (str_starts_with($url, '/') ? '' : '/') . $url;
        }
        foreach ($fontes as $fonte) {
            if ($this->fonteAceita($fonte, $absoluta)) {
                return true;
            }
        }

        return false;
    }

    private function fonteAceita(string $fonte, string $url): bool
    {
        if ($fonte === "'self'") {
            return str_starts_with($url, $this->origem . '/') || $url === $this->origem;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:$#i', $fonte) === 1) {
            return str_starts_with(strtolower($url), strtolower($fonte));
        }
        if (!str_contains($fonte, '://')) {
            return false;
        }
        $semCaminho = substr_count($fonte, '/') === 2;
        if ($semCaminho) {
            return str_starts_with($url, $fonte . '/') || $url === $fonte;
        }

        return str_ends_with($fonte, '/') ? str_starts_with($url, $fonte) : $url === $fonte;
    }

    /**
     * @return ($semPadrao is true ? list<string>|null : list<string>) a lista de fontes da diretiva, com a queda para
     *                                                                   default-src das diretivas de busca
     */
    private function fontes(string $diretiva, bool $semPadrao = false): ?array
    {
        if (isset($this->diretivas[$diretiva])) {
            return $this->diretivas[$diretiva];
        }
        if ($diretiva === 'frame-src' && isset($this->diretivas['child-src'])) {
            return $this->diretivas['child-src'];
        }
        if ($semPadrao) {
            return null;
        }

        return $this->diretivas['default-src'] ?? null;
    }
}
