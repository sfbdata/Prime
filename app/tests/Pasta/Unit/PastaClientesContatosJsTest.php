<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O salvar de contato da janela "Detalhes do cliente" (`ctGravar` em `public/js/pasta-clientes.js`)
 * lido como FOLHA: não há harness de navegador no PHPUnit, então o que se prova aqui é que a
 * resposta só é aceita quando é a janela — sessão expirada (redirect ao login) não vira "salvo".
 */
final class PastaClientesContatosJsTest extends TestCase
{
    private const JS       = __DIR__ . '/../../../public/js/pasta-clientes.js';
    private const TEMPLATE = __DIR__ . '/../../../templates/cliente/_resumo.html.twig';

    private function ctGravar(): string
    {
        $js     = (string) file_get_contents(self::JS);
        $inicio = strpos($js, 'function ctGravar(valor)');
        self::assertNotFalse($inicio, 'ctGravar existe');
        $fim = strpos($js, '/* ── Delegacao', $inicio);
        self::assertNotFalse($fim);

        return substr($js, $inicio, $fim - $inicio);
    }

    #[TestDox('resposta redirecionada (login) é erro de sessão, conferido ANTES de ler o 200 como janela')]
    public function testRedirectEhSessaoExpirada(): void
    {
        $bloco = $this->ctGravar();

        $redirect = strpos($bloco, 'if (r.redirected) { return { erro: CT_SESSAO_EXPIRADA }; }');
        self::assertNotFalse($redirect);
        $ok = strpos($bloco, 'if (r.ok)');
        self::assertNotFalse($ok);
        self::assertLessThan($ok, $redirect);
        self::assertStringContainsString(
            "var CT_SESSAO_EXPIRADA = 'Sua sessão expirou; recarregue a página';",
            (string) file_get_contents(self::JS),
        );
    }

    #[TestDox('só troca a janela se o HTML recebido tem a marca .ps-cli-janela; senão avisa e não troca')]
    public function testExigeAMarcaDaJanelaAntesDeTrocar(): void
    {
        $bloco = $this->ctGravar();

        $marca = strpos($bloco, "!nova.classList.contains('ps-cli-janela')");
        self::assertNotFalse($marca);
        $troca = strpos($bloco, 'janela.replaceWith(nova)');
        self::assertNotFalse($troca);
        self::assertLessThan($troca, $marca);

        // No ramo da marca ausente: avisa a sessão expirada e sai antes de trocar.
        $ramo = substr($bloco, $marca, $troca - $marca);
        self::assertStringContainsString('avisar(CT_SESSAO_EXPIRADA)', $ramo);
        self::assertStringContainsString('return;', $ramo);

        $salvo = strpos($bloco, "avisar(valor === '' ? 'Contato removido' : 'Contato salvo')");
        self::assertNotFalse($salvo);
        self::assertLessThan($salvo, $troca, '"Contato salvo" só depois da troca');
    }

    #[TestDox('a marca que o JS exige é a raiz do fragmento servido (cliente/_resumo.html.twig)')]
    public function testMarcaEhARaizDoFragmento(): void
    {
        $twig           = (string) file_get_contents(self::TEMPLATE);
        $semComentarios = trim((string) preg_replace('/\{#.*?#\}/s', '', $twig));
        $semSet         = trim((string) preg_replace('/^\{%\s*set\b.*?%\}/s', '', $semComentarios));

        self::assertStringStartsWith('<div class="ps-cli-janela"', $semSet);
    }
}
