<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * O rascunho automático da Central de envios (pedido do Farlei M5.15, 23/11/2026), lido no fonte do
 * comercial-campanha.js como os outros testes de JS da Central: a mensagem em edição fica no navegador por modo e volta
 * ao recarregar; trocar de modelo, "Ver mensagem" e "Novo modelo" perguntam antes de descartar a edição.
 */
#[CoversNothing]
final class ComercialRascunhoJsTest extends TestCase
{
    private const CENTRAL = __DIR__ . '/../../../public/js/master/comercial-campanha.js';
    private const CATALOGOS = [
        __DIR__ . '/../../../translations/comercial/messages+intl-icu.pt_BR.yaml',
        __DIR__ . '/../../../translations/comercial/messages+intl-icu.en.yaml',
    ];

    #[TestDox('O rascunho é por modo, guarda só a mensagem (sem as listas de e-mails) e todo acesso ao armazenamento tem try/catch')]
    public function testSoAMensagemPorModo(): void
    {
        $js = $this->js();

        self::assertStringContainsString("const CHAVE_DO_RASCUNHO = 'bj-mc-rascunho-' + P;", $js);
        self::assertStringContainsString('JSON.stringify({ mk, ed, em: Date.now() })', $js);
        self::assertSame(1, substr_count($js, 'localStorage.setItem'), 'só a mensagem é gravada: csvTexto e individual não');
        foreach (['getItem', 'setItem', 'removeItem'] as $metodo) {
            self::assertMatchesRegularExpression('/try \{(?:(?!\} catch).)*window\.localStorage\.' . $metodo . '/s', $js, $metodo . ' dentro de try');
        }
    }

    #[TestDox('Nada grava antes do primeiro carregamento; o rascunho volta só nele e com aviso de quando foi salvo')]
    public function testRecuperaNoPrimeiroCarregamento(): void
    {
        $js = $this->js();

        self::assertStringContainsString('if (!rascunhoLido) return;', $js);
        self::assertStringContainsString('const recuperado = rascunhoLido ? null : lerRascunho();', $js);
        self::assertStringContainsString("avisar(BJ_T.t('comercial.js.rascunho_recuperado', { quando }));", $js);
    }

    #[TestDox('Trocar de modelo, "Novo modelo" e "Ver mensagem" perguntam antes de descartar a edição')]
    public function testConfirmaAntesDeSobrescrever(): void
    {
        $js = $this->js();

        self::assertStringContainsString("const podeDescartar = () => !ed || !sujo || window.confirm(BJ_T.t('comercial.js.rascunho_confirmar_troca'));", $js);
        self::assertStringContainsString("if (modelo) {\n        if (!podeDescartar()) return;", $js);
        self::assertStringContainsString("if (e.target.closest('[data-mc-cp-novo]')) { if (!podeDescartar()) return;", $js);
        self::assertStringContainsString("if (!podeDescartar()) { avisar(BJ_T.t('comercial.js.rascunho_mantido')); return; }", $js);
        self::assertStringContainsString('if (carregado) abrir(); else carregarPrimeira().then(abrir);', $js, 'carrega (o rascunho volta) antes de perguntar');
        self::assertStringContainsString("if (!podeDescartar()) { entrada.value = ''; return; }", $js, 'importar HTML também pergunta');
    }

    #[TestDox('Campanha confirmada apaga o rascunho dela e não pede mais confirmação para trocar')]
    public function testEnviadaApagaORascunho(): void
    {
        $js = $this->js();
        $fim = strpos($js, "document.dispatchEvent(new CustomEvent('mc:campanha-confirmada'))");
        self::assertNotFalse($fim);
        $sucesso = substr($js, $fim - 300, 300);

        self::assertStringContainsString("sujo = false;\n            gravarRascunho();", $sucesso);
        self::assertStringContainsString('if (ed && sujo) window.localStorage.setItem(', $js, 'sem nada a perder, gravar apaga');
    }

    #[TestDox('Uma carga só no começo, para a segunda não zerar o rascunho recuperado; pagehide grava o que falta')]
    public function testCargaUnicaEPagehide(): void
    {
        $js = $this->js();

        self::assertStringContainsString('if (!primeiraCarga) primeiraCarga = carregar().finally(() => { primeiraCarga = null; });', $js);
        self::assertStringNotContainsString(' carregar();', $js, 'os gatilhos usam carregarPrimeira()');
        self::assertStringContainsString("window.addEventListener('pagehide', () => { if (esperaDoRascunho) gravarRascunho(); });", $js);
    }

    #[TestDox('Os 3 textos do rascunho existem em pt_BR e en, sem travessão')]
    public function testTextos(): void
    {
        foreach (self::CATALOGOS as $arquivo) {
            $js = (array) ((array) ((array) Yaml::parseFile($arquivo))['comercial'])['js'];
            foreach (['rascunho_recuperado', 'rascunho_confirmar_troca', 'rascunho_mantido'] as $chave) {
                self::assertIsString($js[$chave] ?? null, basename($arquivo) . ': ' . $chave);
                self::assertDoesNotMatchRegularExpression('/[—–]/u', $js[$chave]);
            }
            self::assertStringContainsString('{quando}', $js['rascunho_recuperado']);
        }
    }

    private function js(): string
    {
        return (string) file_get_contents(self::CENTRAL);
    }
}
