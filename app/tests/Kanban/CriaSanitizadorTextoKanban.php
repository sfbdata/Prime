<?php

declare(strict_types=1);

namespace App\Tests\Kanban;

use App\Kanban\Service\SanitizadorTextoKanban;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Monta um `SanitizadorTextoKanban` real para testes unitários (sem subir o contêiner).
 * A configuração espelha o sanitizador `kanban` de `config/packages/html_sanitizer.yaml`.
 */
trait CriaSanitizadorTextoKanban
{
    private function criarSanitizadorTextoKanban(): SanitizadorTextoKanban
    {
        $config = (new HtmlSanitizerConfig())
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('s')
            ->allowElement('h1')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('ol')
            ->allowElement('ul')
            ->allowElement('li', ['data-list'])
            ->allowElement('pre')
            ->allowElement('code')
            ->allowElement('a', ['href', 'title'])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            ->forceHttpsUrls(true);

        return new SanitizadorTextoKanban(new HtmlSanitizer($config));
    }
}
