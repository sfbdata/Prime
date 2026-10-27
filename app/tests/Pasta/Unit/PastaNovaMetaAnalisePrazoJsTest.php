<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Análise do prazo da "Nova meta": o JS é lido como folha (sem navegador no PHPUnit). Prova-se a chamada à rota com o
 * mesmo CSRF, o contrato de envio da meta, a regra do dc de só trocar o prazo se ele era a sugestão anterior e que
 * nenhum texto novo (JS, template, catálogo) diz "I.A.". O efeito visual é do smoke do dono.
 */
final class PastaNovaMetaAnalisePrazoJsTest extends TestCase
{
    private const JS      = __DIR__ . '/../../../public/js/pasta-meta-analise-prazo.js';
    private const SHOW    = __DIR__ . '/../../../templates/pasta/show.html.twig';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    #[TestDox('consulta a rota com o _token do formulário, debounce de 300 ms e os campos do contrato')]
    public function testConsulta(): void
    {
        $js = $this->js();
        self::assertStringContainsString("form.querySelector('input[name=\"_token\"]')", $js);
        self::assertStringContainsString('}, 300);', $js);
        foreach (['titulo', 'descricao', 'data_publicacao', 'data_ciencia', 'prazo_juridico', 'analise_confirmada'] as $campo) {
            self::assertStringContainsString("d.append('" . $campo . "'", $js, $campo);
        }
        self::assertStringContainsString('res.analise ? res.analise : null', $js, 'null esconde o bloco');
    }

    #[TestDox('envio da meta: analise_prazo só com análise, origem, jurídico e confirmação; 422 abre o Conferir')]
    public function testContratoDoEnvio(): void
    {
        $js = $this->js();
        self::assertStringContainsString("if (!analise) { return; }\n            dados.append('analise_prazo', '1');", $js);
        self::assertStringContainsString("dados.append('prazo_juridico'", $js);
        self::assertStringContainsString('abrirParaConferir', $js);

        $show = (string) file_get_contents(self::SHOW);
        self::assertStringContainsString('window.PastaMetaAnalisePrazo.acrescentar(data)', $show);
        self::assertStringContainsString('res.status === 422', $show);
        self::assertStringContainsString('<script src="/js/pasta-meta-analise-prazo.js"></script>', $show);
        self::assertGreaterThan(strpos($show, '/js/pasta-metas.js'), strpos($show, '/js/pasta-meta-analise-prazo.js'));
    }

    #[TestDox('só troca o prazo da meta se ele ainda era a sugestão anterior; o chip preenche #tarefaPrazo')]
    public function testRegraDoPrazoSugerido(): void
    {
        $js = $this->js();
        self::assertStringContainsString('campoPrazo.value === sugestaoAnterior', $js);
        self::assertStringContainsString('campoPrazo.value = analise.prazoInterno;', $js);
        self::assertStringContainsString("getElementById('tarefaPrazo')", $js);
    }

    #[TestDox('textos do JS e do catálogo sem "I.A." e com os literais do desenho')]
    public function testTextos(): void
    {
        $catalogo = CatalogoPtBr::carregar();
        $scripts  = $catalogo['pasta_scripts']['js']['analise_prazo'];
        self::assertIsArray($scripts);
        self::assertSame('Sugerido {data}', $scripts['chip']);
        self::assertSame('Informado pelo usuário', $scripts['estado_informado']);
        self::assertSame('Confirmado', $scripts['estado_confirmado']);
        self::assertSame('Necessita conferência', $scripts['estado_conferencia']);
        self::assertSame('Sem prazo legal identificado', $scripts['estado_sem_prazo']);
        self::assertSame('Origem do prazo informada', $scripts['origem_informada']);
        self::assertSame('Ocultar origem do prazo', $scripts['origem_ocultar']);
        self::assertSame('+ Informar origem do prazo', $scripts['origem_informar']);
        self::assertSame('Prazo sugerido: {data}. {porque}', $scripts['chip_dica']);
        self::assertStringStartsWith('Pela origem informada, a análise calcularia {data}.', $scripts['alt_informado']);
        self::assertSame('Prazo jurídico', $scripts['tip_jur_titulo']);
        self::assertSame('Dias úteis até lá', $scripts['tip_dias_titulo']);
        self::assertSame('Folga', $scripts['tip_folga_titulo']);

        foreach ($scripts as $chave => $texto) {
            self::assertStringNotContainsString('I.A', (string) $texto, 'catálogo: ' . $chave);
            self::assertStringNotContainsString('—', (string) $texto, 'travessão: ' . $chave);
            self::assertStringNotContainsString('–', (string) $texto, 'travessão: ' . $chave);
        }
        $meta = $catalogo['pasta_show']['meta'];
        foreach ($meta as $chave => $texto) {
            if (str_starts_with((string) $chave, 'analise_')) {
                self::assertStringNotContainsString('I.A', (string) $texto, 'catálogo: ' . $chave);
                self::assertStringNotContainsString('—', (string) $texto, 'travessão: ' . $chave);
            }
        }
        self::assertSame('Análise atualizada', $meta['analise_atualizada']);

        $js = $this->js();
        self::assertStringNotContainsString('I.A', $js);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $js);
        self::assertStringContainsString("var PREFIXO = 'pasta_scripts.js.analise_prazo.';", $js);
        self::assertStringContainsString('BJ_T.t(PREFIXO + chave', $js);
    }
}
