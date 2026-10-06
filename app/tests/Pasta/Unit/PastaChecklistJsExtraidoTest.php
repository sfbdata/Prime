<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O JS do checklist saiu do inline da pasta/show para `public/js/pasta-checklist.js` (lote L3).
 * Confere as FOLHAS, não a tela: se alguém colar o bloco de volta no template, os dois rodariam
 * e cada clique marcaria o item duas vezes (o segundo desfaz o primeiro) — nenhum teste de HTML
 * veria isso.
 */
final class PastaChecklistJsExtraidoTest extends TestCase
{
    private const SHOW      = __DIR__ . '/../../../templates/pasta/show.html.twig';
    private const CHECKLIST = __DIR__ . '/../../../templates/pasta/_documentos_checklist.html.twig';
    private const JS        = __DIR__ . '/../../../public/js/pasta-checklist.js';
    private const CSS       = __DIR__ . '/../../../public/css/pasta-checklist.css';

    private function show(): string
    {
        return (string) file_get_contents(self::SHOW);
    }

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    #[TestDox('a pasta/show não tem mais o JS inline do checklist e carrega pasta-checklist.js depois do SortableJS')]
    public function testShowCarregaOArquivoEnaoOInline(): void
    {
        $twig = $this->show();

        self::assertStringNotContainsString("getElementById('checklistLista')", $twig, 'o JS inline do checklist voltou para a pasta/show');
        self::assertStringNotContainsString("getElementById('btnChecklistEditar')", $twig);
        self::assertStringNotContainsString("getElementById('checklistModelosPainel')", $twig);
        self::assertStringNotContainsString("csrf_token('checklist_pasta_' ~ pasta.id)|json_encode|raw", $twig, 'o token agora vai por data-* do #pexChecklist');

        self::assertSame(1, substr_count($twig, "asset('js/pasta-checklist.js')"), 'carregado uma vez');
        $sortable  = strpos($twig, 'sortablejs@');
        $checklist = strpos($twig, "asset('js/pasta-checklist.js')");
        self::assertNotFalse($sortable);
        self::assertGreaterThan($sortable, $checklist, 'o checklist usa o Sortable no modo de edição: tem de vir depois');
    }

    #[TestDox('a pasta/show não inclui mais o painel de sugeridos solto: ele é incluído pelo cartão do checklist')]
    public function testSugeridosSoPeloChecklist(): void
    {
        self::assertStringNotContainsString("include('pasta/_documentos_sugeridos.html.twig')", $this->show());
        self::assertStringContainsString("include('pasta/_documentos_sugeridos.html.twig')", (string) file_get_contents(self::CHECKLIST));
    }

    #[TestDox('o arquivo cobre todas as funções do bloco inline, pelos mesmos ids e endpoints')]
    public function testArquivoCobreAsFuncoes(): void
    {
        $js = $this->js();

        foreach ([
            'checklistLista', 'checklistBadge', 'checklistBarra', 'btnChecklistEditar', 'btnChecklistAdicionar',
            'checklistFormAdicionar', 'checklistNovoTitulo', 'btnChecklistSalvarNovo', 'checklistErroNovo',
            'checklistModelosPainel', 'btnChecklistModelos', 'checklistModelosLista', 'checklistModeloNome',
            'btnChecklistModeloSalvar', 'btnChecklistModelosFechar', 'checklistVazio',
        ] as $id) {
            self::assertStringContainsString("'" . $id . "'", $js, "#{$id} é contrato com o template");
        }

        foreach (['/toggle', '/editar', '/excluir', '/checklist/reordenar', '/aplicar', '/renomear'] as $trecho) {
            self::assertStringContainsString($trecho, $js);
        }
        self::assertStringContainsString('new window.Sortable(lista', $js, 'reordenar arrastando');
        self::assertStringContainsString("'dblclick'", $js, 'renomear inline');
        self::assertStringContainsString("confirm('Excluir este item do checklist?')", $js);
    }

    #[TestDox('nada de innerHTML, nada de localStorage/sessionStorage: título de item é dado do usuário e o estado é do servidor')]
    public function testSemInnerHtmlNemStorage(): void
    {
        $js = $this->js();

        self::assertStringNotContainsString('innerHTML', $js);
        self::assertStringNotContainsString('insertAdjacentHTML', $js);
        self::assertStringNotContainsString('localStorage', $js);
        self::assertStringNotContainsString('sessionStorage', $js);
    }

    #[TestDox('a folha do checklist é escopada em #pexChecklist e tem par no tema escuro')]
    public function testFolhaEscopadaComTemaEscuro(): void
    {
        $css = (string) file_get_contents(self::CSS);
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        self::assertStringContainsString('[data-bs-theme="dark"] #pexChecklist', $css);

        // Todo seletor de regra começa em #pexChecklist (ou no tema escuro dele): nada global.
        preg_match_all('/(^|})\s*([^{}@]+)\{/', $css, $m);
        foreach ($m[2] as $seletores) {
            foreach (explode(',', $seletores) as $seletor) {
                $seletor = trim($seletor);
                if ($seletor === '') {
                    continue;
                }
                self::assertMatchesRegularExpression('/^(\[data-bs-theme="dark"\] )?#pexChecklist\b/', $seletor, "regra fora do escopo: {$seletor}");
            }
        }
    }
}
