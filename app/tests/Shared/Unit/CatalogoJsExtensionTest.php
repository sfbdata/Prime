<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Traducao\CatalogoJsExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

#[CoversClass(CatalogoJsExtension::class)]
final class CatalogoJsExtensionTest extends TestCase
{
    #[TestDox('Só as chaves dos prefixos pedidos, no idioma da requisição, com o português no que faltar')]
    public function testFiltraPorPrefixoComFallback(): void
    {
        $extensao = new CatalogoJsExtension($this->tradutor(), self::pilha('en'));

        self::assertSame(
            ['menu.usuario.js.sair' => 'Sign out', 'menu.usuario.js.salvo' => 'Salvo, {nome}.'],
            $extensao->mensagens(['menu.usuario.js']),
        );
    }

    #[TestDox('Sem requisição vale o português; o script é JSON que não fecha a tag')]
    public function testScriptSemRequisicao(): void
    {
        $html = (new CatalogoJsExtension($this->tradutor(), new RequestStack()))->catalogoJs('perfil.js');

        self::assertSame('<script type="application/json" data-bj-t>{"perfil.js.aviso":"\u003C/script\u003E fim"}</script>', $html);
        self::assertSame('<script type="application/json" data-bj-t>{}</script>', (new CatalogoJsExtension($this->tradutor(), new RequestStack()))->catalogoJs('nada'));
    }

    private function tradutor(): Translator
    {
        $t = new Translator('pt_BR');
        $t->setFallbackLocales(['pt_BR']);
        $t->addLoader('array', new ArrayLoader());
        $t->addResource('array', [
            'menu.usuario.js.sair' => 'Sair', 'menu.usuario.js.salvo' => 'Salvo, {nome}.', 'menu.usuario.titulo' => 'Menu',
            'menu.usuario.jsx' => 'outro', 'perfil.js.aviso' => '</script> fim',
        ], 'pt_BR', 'messages+intl-icu');
        $t->addResource('array', ['menu.usuario.js.sair' => 'Sign out'], 'en', 'messages+intl-icu');

        return $t;
    }

    private static function pilha(string $locale): RequestStack
    {
        $pilha = new RequestStack();
        $requisicao = new Request();
        $requisicao->setLocale($locale);
        $pilha->push($requisicao);

        return $pilha;
    }
}
