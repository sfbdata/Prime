<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Service\SanitizadorTextoRico;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Monta um `SanitizadorTextoRico` real para testes unitários (que não sobem o contêiner).
 *
 * A configuração aqui espelha o sanitizador `textoRico` de `config/packages/html_sanitizer.yaml`.
 * Essa duplicação é consciente e tem guarda: o teste funcional
 * `SanitizadorTextoRicoContainerTest` confere que o serviço montado pelo contêiner se comporta
 * igual a este — se alguém mexer só no YAML, ele acusa a divergência.
 */
trait CriaSanitizadorTextoRico
{
    private function criarSanitizadorTextoRico(): SanitizadorTextoRico
    {
        $config = (new HtmlSanitizerConfig())
            ->allowElement('p', ['class'])
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('s')
            ->allowElement('h1', ['class'])
            ->allowElement('h2', ['class'])
            ->allowElement('h3', ['class'])
            ->allowElement('h4', ['class'])
            ->allowElement('h5', ['class'])
            ->allowElement('h6', ['class'])
            ->allowElement('ol')
            ->allowElement('ul')
            ->allowElement('li', ['class', 'data-list'])
            ->allowElement('blockquote', ['class'])
            ->allowElement('span', ['class'])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false);

        return new SanitizadorTextoRico(new HtmlSanitizer($config));
    }
}
