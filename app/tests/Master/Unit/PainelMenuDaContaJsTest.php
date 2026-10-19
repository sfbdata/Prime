<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Menu da conta e confirmação de suspender/reativar (spec perfis-1.2-contas C7 e C8; desenho 05 -
 * Perfis 1.2, linhas 624 e 735), lidos como FOLHA: não há navegador no PHPUnit, então o que se prova
 * é o contrato do public/js/master/painel.js com o desenho, com o template e com o masters.js.
 */
final class PainelMenuDaContaJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';

    private static function js(string $arquivo): string
    {
        return (string) file_get_contents(self::RAIZ . 'public/js/master/' . $arquivo);
    }

    private static function menuDaConta(): string
    {
        $js = self::js('painel.js');
        $inicio = strpos($js, 'const menuDaConta = c =>');
        self::assertNotFalse($inicio, 'o menu da conta existe');
        $fim = strpos($js, '</div>\';', $inicio);
        self::assertNotFalse($fim);

        return substr($js, $inicio, $fim - $inicio);
    }

    #[TestDox('itens na ordem do desenho: Estatísticas, Histórico e, por situação, Suspender OU Reativar; sem Excluir e sem limites (ainda)')]
    public function testItensDoMenu(): void
    {
        $menu = self::menuDaConta();

        $posicoes = array_map(static fn (string $rotulo): int|false => strpos($menu, $rotulo), ['Estatísticas de uso', 'Histórico de alterações', 'Reativar conta', 'Suspender conta']);
        self::assertNotContains(false, $posicoes);
        self::assertSame($posicoes, array_values(array_unique($posicoes)));
        self::assertLessThan($posicoes[2], $posicoes[1], 'Histórico antes de Suspender/Reativar');
        self::assertStringContainsString("c.situacao === 'Suspensa'", $menu, 'Reativar só para conta suspensa, Suspender para as demais');
        self::assertMatchesRegularExpression("/c\\.situacao === 'Suspensa'\\s*\\?\\s*'[^']*data-op=\"reativar\"/", $menu);
        foreach (['Excluir conta', 'Editar limite de armazenamento', 'Definir limite de usuários'] as $ausente) {
            self::assertStringNotContainsString($ausente, $menu, "{$ausente}: fora desta parte da spec");
        }
        self::assertStringContainsString('bi-pause-circle', $menu);
        self::assertStringContainsString('bi-play-circle', $menu);
    }

    #[TestDox('confirmação com os textos, ícones e cores exatos da linha 624 do desenho')]
    public function testTextosDaConfirmacao(): void
    {
        $js = self::js('painel.js');

        self::assertStringContainsString("suspender: c => ({ titulo: 'Suspender conta', icone: 'bi-pause-circle', cor: '#c08a12', botao: 'Suspender', texto: 'Os usuários de \"' + c.nome + '\" ficam sem acesso até a reativação. Nenhum dado é apagado.' })", $js);
        self::assertStringContainsString("reativar: c => ({ titulo: 'Reativar conta', icone: 'bi-play-circle', cor: '#1e7b45', botao: 'Reativar', texto: 'A conta \"' + c.nome + '\" volta a ter acesso normal.' })", $js);
    }

    #[TestDox('envia por POST com o token da conta e usa a confirmação do masters.js; as rotas vêm do template')]
    public function testContratoComServidorEMastersJs(): void
    {
        $js = self::js('painel.js');
        self::assertStringContainsString("corpo.append('_token', c.token || '')", $js);
        self::assertStringContainsString("method: 'POST'", $js);
        self::assertStringContainsString('URLS.contaSuspender', $js);
        self::assertStringContainsString('URLS.contaReativar', $js);
        self::assertStringContainsString('window.BJMasterConf.abrir(', $js);

        self::assertStringContainsString('window.BJMasterConf = { abrir: abrirConf, fechar: fecharConf };', self::js('masters.js'));
        self::assertStringContainsString('ok.disabled = false;', self::js('masters.js'), 'a confirmação destrava para a próxima ação sem recarregar');

        $twig = (string) file_get_contents(self::RAIZ . 'templates/master/painel.html.twig');
        self::assertStringContainsString("contaSuspender: path('master_conta_suspender', {id: 0})", $twig);
        self::assertStringContainsString("contaReativar: path('master_conta_reativar', {id: 0})", $twig);
    }

    #[TestDox('só a resposta JSON direta com a conta é sucesso: redirecionada, sem JSON ou sem conta é erro')]
    public function testRespostaSemContaEhErro(): void
    {
        $js = self::js('painel.js');
        $inicio = strpos($js, 'function enviarAcaoNaConta(');
        self::assertNotFalse($inicio);
        $envio = substr($js, $inicio, (int) strpos($js, 'function avisar(') - $inicio);

        self::assertStringContainsString('r.json().catch(() => null)', $envio, 'corpo que não é JSON vira nulo, não sucesso');
        self::assertStringContainsString("const json = !r.redirected && j !== null && typeof j === 'object';", $envio);
        self::assertStringContainsString('if (json && r.ok && j.conta) {', $envio, 'verde só com a conta no corpo');
        self::assertStringContainsString('avisar(false, json && !r.ok ? j.erro : null);', $envio);
        self::assertStringContainsString("'Não foi possível concluir a ação. Recarregue a página.'", $js);
    }

    #[TestDox('sem travessão no texto novo do menu e da confirmação')]
    public function testSemTravessao(): void
    {
        $js = self::js('painel.js');
        $inicio = strpos($js, 'const menuDaConta = c =>');
        $fim = strpos($js, 'function avisar(');
        self::assertNotFalse($inicio);
        self::assertNotFalse($fim);

        self::assertDoesNotMatchRegularExpression('/[—–]/u', substr($js, $inicio, $fim - $inicio));
    }
}
