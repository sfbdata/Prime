<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * PMC-044 — depois de criar a meta pelo modal da pasta, o usuário fica na pasta: aba Metas, filtro
 * "Abertas", meta nova realçada (desenho, `salvar` do modal). O POST é XHR e devolve `destino`
 * (`/pasta/<id>?meta=<meta>#tarefas`, provado no CriarTarefaControllerTest); aqui se lê o JS como
 * folha: o modal segue o destino (e não mais `/tarefas/<id>`) e o pasta-metas.js realça a linha
 * pelo mesmo caminho do "Abrir meta existente". O efeito na tela é do smoke do dono.
 */
final class PastaMetaNovaRealceJsTest extends TestCase
{
    private const JS_METAS = __DIR__ . '/../../../public/js/pasta-metas.js';
    private const SHOW     = __DIR__ . '/../../../templates/pasta/show.html.twig';

    private function bloco(): string
    {
        $js     = (string) file_get_contents(self::JS_METAS);
        $inicio = strpos($js, 'function realceDaMetaNova()');
        self::assertNotFalse($inicio, 'o realce existe');

        return substr($js, $inicio);
    }

    #[TestDox('o modal da pasta segue o destino do servidor, não a tela /tarefas/<id>')]
    public function testModalSegueODestino(): void
    {
        $show = (string) file_get_contents(self::SHOW);
        self::assertStringContainsString('window.location.href = res.data.destino;', $show);
        self::assertStringNotContainsString("'/tarefas/' + res.data.id", $show);
    }

    #[TestDox('o realce roda no carregamento, lê ?meta=<número>, acha a linha pelo data-meta-id e usa destacarNaLista (aba Metas + "Abertas")')]
    public function testRealcaALinha(): void
    {
        $js = (string) file_get_contents(self::JS_METAS);
        self::assertMatchesRegularExpression('/function iniciar\(\) \{[^}]*realceDaMetaNova\(\);/', $js);

        $bloco = $this->bloco();
        self::assertStringContainsString("params.get('meta')", $bloco);
        self::assertStringContainsString('/^\\d+$/.test(id)', $bloco, 'só id numérico vira seletor');
        self::assertStringContainsString(".ps-meta[data-meta-id=\"' + id + '\"]", $bloco);
        self::assertStringContainsString('if (linha) { destacarNaLista(linha); }', $bloco);

        // destacarNaLista é quem põe o filtro "Abertas" e mostra a aba.
        $destacar = substr($js, (int) strpos($js, 'function destacarNaLista('), 600);
        self::assertStringContainsString("aplicarFiltro(cartao, 'abertas')", $destacar);
        self::assertStringContainsString("getElementById('tarefas-tab')", $destacar);
    }

    #[TestDox('o parâmetro sai da URL (F5 não realça de novo), mantendo o fragmento da aba')]
    public function testLimpaOParametro(): void
    {
        $bloco = $this->bloco();
        self::assertStringContainsString("params.delete('meta');", $bloco);
        self::assertStringContainsString('window.history.replaceState(null, \'\', window.location.pathname + (busca ? \'?\' + busca : \'\') + window.location.hash);', $bloco);
    }
}
