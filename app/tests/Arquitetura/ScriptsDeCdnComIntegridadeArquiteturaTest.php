<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Auditoria I-xss 5.08: script de CDN sem `integrity` roda o que o CDN servir, na origem do BlueJus.
 *
 * - A base (todas as telas) e o Contribua servem as bibliotecas de public/js/vendor, por asset().
 * - Em app/templates, nenhum `<script src="https://...">` NOVO sem `integrity`. Os que já existiam fora deste lote
 *   estão em TOLERADOS: a lista só encolhe (corrigiu um, tire-o daqui).
 */
#[CoversNothing]
final class ScriptsDeCdnComIntegridadeArquiteturaTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../templates';
    private const PUBLICO = __DIR__ . '/../../public';

    /** arquivo => quantos `<script src` externos sem integrity ele tinha em 05/11/2026 */
    private const TOLERADOS = [
        'cobranca/objeto/show.html.twig' => 1,
        'kanban/board.html.twig' => 2,
        'layout_peticionar.html.twig' => 3,
        'pasta/show.html.twig' => 1,
        'profile/index.html.twig' => 1,
        'tenant/sedes.html.twig' => 1,
    ];

    /** As bibliotecas que saíram do CDN, na versão exata que estava em uso. */
    private const LOCAIS = [
        'base.html.twig' => [
            'js/vendor/jquery/jquery.min.js',
            'js/vendor/bootstrap/bootstrap.bundle.min.js',
            'js/vendor/overlayscrollbars/overlayscrollbars.browser.es6.min.js',
            'js/vendor/select2/select2.min.js',
            'js/vendor/admin-lte/adminlte.min.js',
        ],
        'contribua/index.html.twig' => ['js/vendor/qrcode-generator/qrcode.js'],
    ];

    #[TestDox('Base e Contribua: nenhum script externo; as bibliotecas vêm de public/js/vendor por asset()')]
    public function testBaseEContribuaSemScriptExterno(): void
    {
        foreach (self::LOCAIS as $template => $arquivos) {
            $twig = (string) file_get_contents(self::TEMPLATES . '/' . $template);
            self::assertSame([], self::scriptsExternos($twig), $template . ' carrega script de fora');
            foreach ($arquivos as $arquivo) {
                self::assertStringContainsString('<script src="{{ asset(\'' . $arquivo . '\') }}"', $twig, $template);
                self::assertFileExists(self::PUBLICO . '/' . $arquivo);
                self::assertFileExists(\dirname(self::PUBLICO . '/' . $arquivo) . '/VERSION', $arquivo . ' sem VERSION (origem e hash)');
            }
        }
    }

    #[TestDox('VERSION de cada biblioteca local confere com o sha384 do arquivo')]
    public function testHashDoVersionConfere(): void
    {
        foreach (array_merge(...array_values(self::LOCAIS)) as $arquivo) {
            $caminho = self::PUBLICO . '/' . $arquivo;
            $hash = base64_encode(hash_file('sha384', $caminho, true) ?: '');
            self::assertStringContainsString($hash, (string) file_get_contents(\dirname($caminho) . '/VERSION'), $arquivo);
        }
    }

    #[TestDox('Nenhum <script src="https://..."> novo sem integrity em app/templates (a lista de tolerados só encolhe)')]
    public function testNenhumScriptExternoNovoSemIntegridade(): void
    {
        $encontrados = [];
        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::TEMPLATES, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterador as $arquivo) {
            if (!$arquivo->isFile() || !str_ends_with($arquivo->getFilename(), '.twig')) {
                continue;
            }
            $semIntegridade = array_filter(self::scriptsExternos((string) file_get_contents($arquivo->getPathname())), static fn (string $tag): bool => !preg_match('/\sintegrity="sha(256|384|512)-/', $tag));
            if ($semIntegridade !== []) {
                $encontrados[substr($arquivo->getPathname(), \strlen(self::TEMPLATES) + 1)] = \count($semIntegridade);
            }
        }
        ksort($encontrados);

        self::assertSame(self::TOLERADOS, $encontrados, 'script de CDN sem integrity: sirva de public/js/vendor ou ponha integrity="sha384-..." e crossorigin="anonymous"');
    }

    /** @return list<string> as tags `<script ...>` com src absoluto (http, https ou //) */
    private static function scriptsExternos(string $twig): array
    {
        preg_match_all('#<script\b[^>]*\ssrc\s*=\s*["\']?(?:https?:)?//[^>]*>#i', $twig, $m);

        return $m[0];
    }
}
