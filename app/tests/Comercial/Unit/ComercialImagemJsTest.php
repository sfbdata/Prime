<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * F3i (dc 05 VS 21.10.26 L659; spec comercial-e-emails.md §12): o navegador reduz a imagem a 1080 px e a converte em
 * JPEG 0,85 num canvas, envia o arquivo por FormData (nunca um `data:` na mensagem) e guarda só o token no modelo.
 */
#[CoversNothing]
final class ComercialImagemJsTest extends TestCase
{
    private static function js(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/master/comercial-campanha.js');
    }

    #[TestDox('o canvas do dc: no máximo 1080 px, proporção mantida, JPEG 0,85 em Blob')]
    public function testReducaoComoODc(): void
    {
        $js = self::js();
        self::assertStringContainsString('const W = Math.min(1080, im.width), H = Math.round(im.height * W / im.width)', $js);
        self::assertStringContainsString("cv.getContext('2d').drawImage(im, 0, 0, W, H);", $js);
        self::assertStringContainsString("), 'image/jpeg', 0.85);", $js);
        self::assertStringContainsString('cv.toBlob(', $js);
        self::assertStringNotContainsString('toDataURL', $js, 'a imagem não vai como data: na mensagem');
    }

    #[TestDox('o envio é FormData com o CSRF no header, para a URL da raiz; o token volta ao modelo e vai no pedido só no simples')]
    public function testEnvioEToken(): void
    {
        $js = self::js();
        self::assertStringContainsString("dados.append('imagem', blob, 'imagem.jpg');", $js);
        self::assertStringContainsString("fetch(ROTAS.urlImagem, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': TOKEN }, body: dados })", $js);
        self::assertStringContainsString('.then(r => trocarImagem(r.token))', $js);
        self::assertStringContainsString("editar('imagem', token);", $js);
        self::assertStringContainsString("imagem: m.modo === 'simples' ? m.imagem || '' : '',", $js);
        self::assertStringContainsString("if (e.target.closest('[data-mc-cp-img-rm]')) { trocarImagem(''); return; }", $js);
        self::assertStringContainsString("e.target.closest('[data-mc-cp-img-arquivo]')", $js);
    }

    #[TestDox('"Adicionar imagem" vira "Trocar imagem" e o "Remover" aparece só com imagem; os textos vêm do catálogo')]
    public function testRotulos(): void
    {
        $js = self::js();
        self::assertStringContainsString("texto.textContent = tem ? BJ_T.t('comercial.js.imagem_trocar') : BJ_T.t('comercial.js.imagem_adicionar');", $js);
        self::assertStringContainsString('remover.hidden = !tem;', $js);
        self::assertStringContainsString("avisar(BJ_T.t('comercial.js.imagem_invalida'))", $js);
        self::assertStringContainsString("el('[data-mc-cp-html]').hidden = m.modo !== 'html';\n      desenharImagem();", $js, 'o editor redesenha os botões da imagem');
    }
}
