<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os modelos editáveis (pedido M5.8 + M5.12; spec comercial-e-emails.md §8.2 e §13), lidos no fonte do JS (estilo do
 * ComercialRascunhoJsTest): "Salvar modelo" também no padrão, "Voltar ao padrão" só no editado, com confirmação, e o
 * seletor "Modelo" de cada data, sem texto solto no script.
 */
#[CoversNothing]
final class ComercialModelosEditaveisJsTest extends TestCase
{
    private const CENTRAL = __DIR__ . '/../../../public/js/master/comercial-campanha.js';
    private const DATAS = __DIR__ . '/../../../public/js/master/comercial-datas.js';

    #[TestDox('"Salvar modelo" no padrão vai pelo código dele; o próprio, pelo id; já não há a recusa "padrão não se altera"')]
    public function testSalvarPeloPadrao(): void
    {
        $js = (string) file_get_contents(self::CENTRAL);
        $salvar = substr($js, (int) strpos($js, 'function salvar()'), 700);

        self::assertStringContainsString("encodeURIComponent(m.proprio ? m.id : m.chave)", $salvar);
        self::assertStringNotContainsString('modelo_padrao', $js);
        self::assertStringNotContainsString('if (!m.proprio)', $salvar);
    }

    #[TestDox('"Voltar ao padrão": só no padrão editado, pede confirmação, POST na rota da chave e recarrega a grade com ele escolhido')]
    public function testVoltarAoPadrao(): void
    {
        $js = (string) file_get_contents(self::CENTRAL);
        $voltar = substr($js, (int) strpos($js, 'function voltarAoPadrao()'), 800);

        self::assertStringContainsString("x.hidden = !m.editado;", $js);
        self::assertStringContainsString('if (m.proprio || !m.editado) return;', $voltar);
        self::assertStringContainsString("texto: BJ_T.t('comercial.js.confirmar_voltar_padrao', { nome: m.nome })", $voltar);
        self::assertStringNotContainsString('window.confirm(', $voltar);
        self::assertStringContainsString("postar(ROTAS.urlPadrao.replace('xchavex', encodeURIComponent(m.chave)))", $voltar);
        self::assertStringContainsString('carregar(m.chave)', $voltar);
        self::assertStringContainsString("if (e.target.closest('[data-mc-cp-voltar-padrao]')) { voltarAoPadrao(); return; }", $js);
        self::assertStringContainsString('[data-mc-cp-salvar-como], [data-mc-cp-voltar-padrao], [data-mc-cp-excluir]', $js, 'bloqueado na edição de campanha, como os outros');
    }

    #[TestDox('A tela das datas tem o seletor "Modelo" com "Padrão da data" (vazio), os padrões e os próprios; o POST leva o modelo')]
    public function testSeletorDeModeloDaData(): void
    {
        $js = (string) file_get_contents(self::DATAS);

        self::assertStringContainsString("data-mc-dt-modelo>' + opcoesDeModelo(d) + '</select>'", $js);
        self::assertStringContainsString("op('', BJ_T.t('comercial.js.datas_modelo_padrao'))", $js);
        self::assertStringContainsString('modelos = r.modelos || [];', $js);
        self::assertStringContainsString("alterar(el.dataset.mcDtChave, { modelo: sel.value || null });", $js);
        self::assertStringContainsString("'[data-mc-dt-publico], [data-mc-dt-modelo]'", $js);
    }

    #[TestDox('Os textos novos estão no catálogo pt_BR e en, sem travessão, e nenhum texto visível fica solto nos scripts')]
    public function testTextos(): void
    {
        $js = CatalogoPtBr::carregar()['comercial']['js'];
        $tela = CatalogoPtBr::carregar()['comercial']['campanha'];

        self::assertSame('Voltar ao padrão', $tela['voltar_padrao']);
        self::assertSame(['Modelo', 'Padrão da data'], [$js['datas_modelo'], $js['datas_modelo_padrao']]);
        foreach (['confirmar_voltar_padrao', 'modelo_voltou_padrao', 'datas_modelo_de', 'datas_modelo_grupo_padroes', 'datas_modelo_grupo_proprios'] as $chave) {
            self::assertArrayHasKey($chave, $js);
        }
        $en = (string) file_get_contents(__DIR__ . '/../../../translations/comercial/messages+intl-icu.en.yaml');
        foreach (['voltar_padrao:', 'confirmar_voltar_padrao:', 'modelo_voltou_padrao:', 'datas_modelo:', 'datas_modelo_padrao:'] as $chave) {
            self::assertStringContainsString($chave, $en);
        }
        $todos = (string) json_encode([$js, $tela], JSON_UNESCAPED_UNICODE);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $todos);
    }
}
