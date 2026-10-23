<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Teste de FOLHA do agenda-nova.js (lê o JS, o CSS e o catálogo; sem navegador) para a janela que orienta a
 * conectar o Google Agenda (spec docs/specs/agenda-aviso-google.md). O comportamento na tela é do smoke do dono.
 */
#[CoversNothing]
final class AgendaAvisoDoGoogleJsTest extends TestCase
{
    private const APP = __DIR__ . '/../../..';
    private const JS = self::APP . '/public/js/agenda-nova.js';
    private const CSS = self::APP . '/public/css/agenda-nova.css';
    private const CATALOGO = self::APP . '/translations/messages+intl-icu.pt_BR.yaml';

    private function ler(string $caminho): string
    {
        $conteudo = file_get_contents($caminho);
        self::assertIsString($conteudo, "não leu {$caminho}");

        return $conteudo;
    }

    /** O corpo de `function $nome(…) {` até o fechamento no recuo de 4 espaços. */
    private function funcao(string $nome): string
    {
        $js = $this->ler(self::JS);
        $ini = strpos($js, '    function ' . $nome . '(');
        self::assertNotFalse($ini, "função {$nome} não encontrada");
        $fim = strpos($js, "\n    }", $ini);

        return substr($js, $ini, $fim - $ini);
    }

    #[TestDox('Só abre quando o servidor manda (data-google-aviso="1") e com a conexão liberada')]
    public function testSoAbreQuandoOServidorManda(): void
    {
        $aviso = $this->funcao('googleAviso');
        self::assertStringContainsString("if ('1' !== raiz.dataset.googleAviso || !url.googleConectar) { return; }", $aviso);
        self::assertStringContainsString('googleAvisoDispensar: raiz.dataset.urlGoogleAvisoDispensar', $this->ler(self::JS));
    }

    #[TestDox('É a janela de pergunta da Agenda (desenho l.125) no #agPerg, com X, caixa "Não mostrar" e os dois botões; nada de Bootstrap')]
    public function testComponenteDaAgenda(): void
    {
        $aviso = $this->funcao('googleAviso');
        self::assertStringContainsString("document.getElementById('agPerg').innerHTML", $aviso);
        foreach (['ag-perg-fundo', 'ag-perg-caixa', 'class="ag-perg"', 'ag-perg-cab', 'ag-perg-txt', 'ag-perg-rodape', 'ag-mbtn ag-perg-nao', 'ag-mbtn is-pri'] as $classe) {
            self::assertStringContainsString($classe, $aviso);
        }
        self::assertStringContainsString('role="dialog" aria-modal="true"', $aviso);
        self::assertStringContainsString('class="ag-modal-x" data-google-orientar="fechar"', $aviso, 'o X fecha como "Agora não"');
        self::assertStringContainsString('<input type="checkbox" id="agGoogleAvisoNao">', $aviso);
        self::assertStringContainsString('data-google-orientar="sincronizar"', $aviso);
        self::assertStringNotContainsString('bootstrap', strtolower($aviso));
        self::assertStringNotContainsString('.modal(', $aviso);
        self::assertStringContainsString('.ag-perg-cab-x {', $this->ler(self::CSS));
    }

    #[TestDox('Textos vêm do catálogo agenda.js.google.aviso, iguais ao pedido do dono e sem travessão')]
    public function testTextosDoCatalogo(): void
    {
        $aviso = $this->funcao('googleAviso');
        $catalogo = Yaml::parseFile(self::CATALOGO)['agenda']['js']['google']['aviso'];
        self::assertSame([
            'titulo' => 'Sincronize com o Google Agenda',
            'texto' => 'Conecte a sua conta Google para ver os compromissos do BlueJus no seu Google Agenda e receber os lembretes no celular. Use o botão Sincronizar com o Google Agenda, aqui na Agenda.',
            'nao_mostrar' => 'Não mostrar esta mensagem novamente',
            'agora_nao' => 'Agora não',
            'sincronizar' => 'Sincronizar agora',
        ], $catalogo);
        foreach (array_keys($catalogo) as $chave) {
            self::assertStringContainsString("BJ_T.t('agenda.js.google.aviso." . $chave . "')", $aviso);
        }
        self::assertDoesNotMatchRegularExpression('/[—–]/u', implode(' ', $catalogo));
    }

    #[TestDox('A dispensa só grava com a caixa marcada; "Sincronizar agora" chama o googleSincronizar() do botão #agGoogle, sem copiar a lógica')]
    public function testDispensaEFluxoDoBotao(): void
    {
        $fechar = $this->funcao('fecharAvisoDoGoogle');
        self::assertStringContainsString("const naoMostrar = !!document.querySelector('#agGoogleAvisoNao:checked');", $fechar);
        self::assertStringContainsString('if (naoMostrar) { postar(url.googleAvisoDispensar, {})', $fechar);
        self::assertStringContainsString('if (sincronizar) { googleSincronizar(); }', $fechar);
        self::assertStringNotContainsString('location.href', $fechar);

        $js = $this->ler(self::JS);
        self::assertStringContainsString("if (alvo('[data-google-orientar=\"fechar\"]')) { fecharAvisoDoGoogle(false); return; }", $js);
        self::assertStringContainsString("if (alvo('[data-google-orientar=\"sincronizar\"]')) { fecharAvisoDoGoogle(true); }", $js);
        self::assertStringContainsString("if (alvo('#agGoogle')) { googleSincronizar(); return; }", $js, 'o botão do painel segue o mesmo caminho');
    }

    #[TestDox('Abre ao carregar a Agenda, menos na entrada por link que já abre algo (#google= da volta do Google, #ev= do compromisso)')]
    public function testAbreAoCarregar(): void
    {
        $js = $this->ler(self::JS);
        self::assertMatchesRegularExpression(
            '/const abreAlgoPeloLink = \\/\\(\\?:google\\|ev\\)=\\/\\.test\\(location\\.hash\\);\\s*avisoDaVoltaDoGoogle\\(\\);\\s*if \\(!abreAlgoPeloLink\\) \\{ googleAviso\\(\\); \\}/',
            $js,
        );
        // O mesmo teste cobre os fragmentos que abrem algo: o do compromisso é o que o abrirDoEndereco() lê.
        self::assertStringContainsString("location.hash.match(/ev=([^&]+)/)", $js);
        self::assertStringContainsString("location.hash.match(/google=(", $js);
        foreach (['#ev=123' => false, '#google=cancelado' => false, '' => true, '#outra=1' => true] as $hash => $abre) {
            self::assertSame($abre, 1 !== preg_match('/(?:google|ev)=/', $hash), 'fragmento ' . $hash);
        }
    }
}
