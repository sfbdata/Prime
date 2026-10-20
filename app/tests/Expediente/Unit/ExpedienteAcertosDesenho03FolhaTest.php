<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Acertos finais do Expediente contra o desenho 03 VS 18.10.26 (conferência de 19/10): as dicas do cabeçalho e da
 * paginação, o clique na linha com um menu aberto e a Nova Pasta que fica na lista. Lê o texto dos arquivos; o
 * comportamento na tela é conferido no navegador. O menu de responsável tem teste próprio (PastaRespMenuDesenhoTelaTest).
 */
final class ExpedienteAcertosDesenho03FolhaTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../';

    private static function ler(string $arquivo): string
    {
        return (string) file_get_contents(self::DIR . $arquivo);
    }

    #[TestDox('Cabeçalho: as dicas "Menu", "Notificações" e "Tela cheia" do desenho (dc L.59, 65, 66)')]
    public function testDicasDoCabecalho(): void
    {
        $base = self::ler('templates/base.html.twig');
        self::assertStringContainsString('aria-label="Abrir/fechar menu" data-tip="Menu"', $base);
        self::assertStringContainsString('aria-label="Notificações" data-tip="Notificações"', $base);
        self::assertStringContainsString('aria-label="Tela cheia" data-tip="Tela cheia"', $base);
    }

    #[TestDox('Paginação: as dicas do desenho (dc `pags`), com "Página N de M"')]
    public function testDicasDaPaginacao(): void
    {
        $pag = self::ler('templates/expediente/_paginacao.html.twig');
        foreach (['Primeira página', 'Página anterior', 'Próxima página', 'Última página'] as $dica) {
            self::assertStringContainsString('data-tip="' . $dica . '"', $pag);
        }
        self::assertStringContainsString('data-tip="Página {{ p }} de {{ total }}"', $pag);
    }

    #[TestDox('Com um menu aberto (filtro, responsável ou contexto), o clique na linha só fecha o menu e não há dica (dc `abrir` e `_ov`)')]
    public function testCliqueNaLinhaComMenuAberto(): void
    {
        $js = self::ler('public/js/expediente-lista.js');
        self::assertStringContainsString("menuAbertoNoToque = !!(menu || document.querySelector('.exp-pop, #pastaRespMenu:not([hidden])'));", $js);
        self::assertStringContainsString('}, true);' . "\n" . '    window.ExpMenu.abertoNoToque = function () { return menuAbertoNoToque; };', $js, 'a pergunta é feita na captura do mousedown');
        self::assertStringContainsString("if (window.ExpMenu.abertoNoToque()) { return; }", $js, 'nem a janela de sigilo abre');
        self::assertStringContainsString("document.querySelector('.exp-pop, #pastaRespMenu:not([hidden])')) { fecharDica(); return; }", $js);

        $index = self::ler('templates/expediente/index.html.twig');
        self::assertStringContainsString('if (window.ExpMenu && window.ExpMenu.abertoNoToque && window.ExpMenu.abertoNoToque()) return;', $index);
    }

    #[TestDox('Nova Pasta no Expediente: fica na lista (NUP decrescente, página 1) com o aviso "Pasta N aberta para CLIENTE"; fora dele, abre a pasta')]
    public function testNovaPastaFicaNaLista(): void
    {
        $modal = self::ler('templates/_partials/modal_nova_pasta.html.twig');
        self::assertStringContainsString("{% if desenho11 %} data-fica-na-lista=\"1\"{% endif %}", $modal);
        self::assertStringContainsString("document.dispatchEvent(new CustomEvent('exp:pasta-criada', { detail: { nup: json.nup, cliente: json.cliente || '' } }));", $modal);
        self::assertStringContainsString('window.location = json.redirect;', $modal, 'a lista da Pasta segue abrindo a pasta criada');

        $index = self::ler('templates/expediente/index.html.twig');
        self::assertStringContainsString("document.addEventListener('exp:pasta-criada', function (e) {", $index);
        self::assertStringContainsString("if (ord) ord.value = 'nup';", $index);
        self::assertStringContainsString("if (dir) dir.value = 'desc';", $index);
        self::assertStringContainsString("avisarOuAlertar('Pasta ' + e.detail.nup + ' aberta para ' + e.detail.cliente);", $index);
    }
}
