<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** O comercial-base.js: janela do painel (nunca confirm), CSRF, DOM por textContent e as mesmas chaves nos dois idiomas. */
#[CoversNothing]
final class ComercialBaseJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/master/comercial-base.js';
    private const PASTA = __DIR__ . '/../../../translations/comercial/';

    #[TestDox('Confirma pela janela do painel, envia o token CSRF, não usa innerHTML nem confirm/prompt nativos')]
    public function testContrato(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString('window.BJMasterConf.perguntar({', $js);
        self::assertStringNotContainsString('window.confirm(', $js);
        self::assertStringNotContainsString('window.prompt(', $js);
        self::assertStringNotContainsString('innerHTML', $js);
        self::assertStringContainsString("'X-CSRF-Token': TOKEN", $js);
        self::assertStringContainsString('BYTES_DO_CSV = 1048576', $js);
    }

    #[TestDox('Toda chave usada pelo JS e pelo Twig da Base existe no catálogo pt_BR e en, sem travessão')]
    public function testCatalogo(): void
    {
        $js = (string) file_get_contents(self::JS);
        $twig = (string) file_get_contents(__DIR__ . '/../../../templates/master/_comercial_base_de_contatos.html.twig');
        preg_match_all("/\\bt\\('([a-z_]+)'/", $js, $doJs);
        preg_match_all("/'(comercial\\.base\\.[a-z_]+)'\\|trans/", $twig, $doTwig);
        $pt = Yaml::parseFile(self::PASTA . 'messages+intl-icu.pt_BR.yaml')['comercial'];
        $en = Yaml::parseFile(self::PASTA . 'messages+intl-icu.en.yaml')['comercial'];

        foreach (array_unique($doJs[1]) as $chave) {
            self::assertArrayHasKey($chave, $pt['js'], $chave);
            self::assertArrayHasKey($chave, $en['js'], $chave);
        }
        foreach (array_unique($doTwig[1]) as $chave) {
            $folha = substr($chave, \strlen('comercial.base.'));
            self::assertArrayHasKey($folha, $pt['base'], $chave);
            self::assertArrayHasKey($folha, $en['base'], $chave);
        }
        self::assertSame(array_keys($pt['base']), array_keys($en['base']));
        $textos = json_encode([$pt['base'], $en['base'], $pt['campanha']['base'], $pt['js']['base_ok_importado']], \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('—', (string) $textos);
        self::assertStringNotContainsString('–', (string) $textos);
        self::assertSame('{novos} novos, {atualizados} atualizados, {existiam} já existiam, {invalidos} inválidos', CatalogoPtBr::carregar()['comercial']['js']['base_ok_importado']);
    }
}
