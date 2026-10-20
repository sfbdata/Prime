<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Planos e limites no painel (spec perfis-1.2-planos-e-limites P1 a P6, P9 e P10; desenho 05 - Perfis 1.2,
 * linhas 194-210, 397-410, 678, 688 e 726-735), lidos como FOLHA, no padrão de PainelMenuDaContaJsTest:
 * sem navegador no PHPUnit, prova-se o contrato do public/js/master/painel.js com o desenho, o template e o CSS.
 */
#[CoversNothing]
final class PainelPlanosELimitesJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';

    private static function js(): string
    {
        return (string) file_get_contents(self::RAIZ . 'public/js/master/painel.js');
    }

    private static function css(): string
    {
        return (string) file_get_contents(self::RAIZ . 'public/css/master.css');
    }

    #[TestDox('P1: cartões com os textos do desenho, "Plano Free" e o selo, preço " / mês", "usuários sem limite"; sai o estado vazio e o "Preços de exemplo"')]
    public function testCartoesDosPlanos(): void
    {
        $js = self::js();

        self::assertStringNotContainsString("planos: ['bi-sliders', 'Planos e limites'", $js, 'o estado vazio da D1 saiu');
        self::assertStringNotContainsString('Preços de exemplo', $js);
        self::assertStringContainsString("const nomeDoPlano = p => (p.codigo === 'free' ? 'Plano ' + p.nome : p.nome);", $js);
        self::assertStringContainsString("'<span class=\"mp-plano-tag\">Padrão: toda conta nova começa aqui</span>'", $js);
        self::assertStringContainsString("'<span> / mês</span></span>'", $js);
        self::assertStringContainsString("p.limiteUsuarios === null ? 'usuários sem limite' : num(p.limiteUsuarios) + ' usuários'", $js);
        self::assertStringContainsString("' conta(s) · ' + num(acima) + ' perto ou acima da franquia</span>'", $js);
        self::assertStringContainsString("const COR_PLANO = { free: '#c08a12', essencial: '#0a6fa0', profissional: '#1e7b45', avancado: '#7a3e8f' };", $js);
        self::assertStringContainsString('Toda conta nova entra no Plano Free (10 GB). A partir de 90% de uso o usuário primário recebe aviso no sistema e por e-mail recomendando ampliar o espaço; em 100% novos envios ficam bloqueados, mas consultar e baixar arquivos continua liberado. Mudanças de plano com impacto comercial exigem confirmação e ficam registradas na auditoria.', $js);

        $css = self::css();
        self::assertStringContainsString('.mp-planos { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 14px; }', $css);
        self::assertStringContainsString('.mp-plano { padding: 18px 20px; display: flex; flex-direction: column; gap: 8px; border-top: 3px solid var(--cor); }', $css);
        self::assertStringContainsString('.mp-plano-tag { height: 20px; padding: 0 7px; border-radius: 3px; background: #fff4d6; color: #7a5200; font-size: 11px; font-weight: bold;', $css);
    }

    #[TestDox('P4: modal de limite com os quadros, rótulos, placeholder e mensagens do desenho; botão desabilitado #9fb3c0')]
    public function testModalDeLimite(): void
    {
        $js = self::js();

        foreach (["['EM USO', gb(c.bytes)]", "['LIMITE ATUAL',", "['LIVRE',", "['USUÁRIOS', num(c.usuarios)]", "['VAGAS',", "'NOVO LIMITE (GB)'", "'NOVO LIMITE DE USUÁRIOS'", "campo('JUSTIFICATIVA'", 'placeholder="Obrigatória, fica na auditoria"', "'Confirmar alteração'", "'Editar limite de armazenamento' : 'Definir limite de usuários'"] as $trecho) {
            self::assertStringContainsString($trecho, $js, $trecho);
        }
        self::assertStringContainsString("'O novo limite é menor que o volume em uso (' + gb(m.c.bytes) + '). Reduzir não apaga arquivos, por isso não é permitido abaixo do uso.'", $js);
        self::assertStringContainsString("'Menor que os usuários já cadastrados.'", $js);
        self::assertStringContainsString("msg = 'Diferença: ' + (d >= 0 ? '+' : '') + d + unidade + fim;", $js);
        self::assertStringContainsString("const unidade = arm ? ' GB' : ' usuário(s)', fim = '. A alteração fica registrada na auditoria.';", $js);
        self::assertStringContainsString('const bloq = (!sem && (novo <= 0 || abaixo || !inteiro)) || !just.trim() || m.enviando;', $js, 'regra do desenho (linha 623): <= 0, abaixo do uso ou justificativa vazia');
        self::assertStringContainsString("const abaixo = !sem && (arm ? novo * GB < m.c.bytes : novo < m.c.usuarios);", $js);
        self::assertStringContainsString('data-lim-sem', $js, 'opção "sem limite" do contrato');
        self::assertStringContainsString("corpo.append('semLimite', e.sem ? '1' : '0');", $js);

        $css = self::css();
        self::assertStringContainsString('.mp .mp-lim-ok:disabled { background: #9fb3c0; cursor: not-allowed; }', $css);
        self::assertStringContainsString('.mp-lim { width: 100%; max-width: 480px;', $css);
        self::assertStringContainsString('.mp-lim-info { display: grid; grid-template-columns: repeat(3,1fr); gap: 8px; }', $css);
        self::assertStringContainsString('.mp-lim-campo > input { box-sizing: content-box; height: 38px; border: 1px solid var(--borda, #d6e0e7);', $css);
    }

    #[TestDox('P5/P6: ranking com "Editar limite" e faixa do RetratoDoEspaco; faixas com as cores do desenho, a chave do bloqueio e o aviso')]
    public function testArmazenamento(): void
    {
        $js = self::js();

        self::assertStringContainsString("const FAIXA = { livre: ['Normal', '#1e7b45'], atencao: ['Atenção', '#c08a12'], alerta: ['Alerta', '#c0392b'], critico: ['Crítico', '#a3232b'], bloqueio: ['Limite atingido', '#7a1b22'] };", $js);
        self::assertStringContainsString("const CHIPS = [['atencao', 'Atenção', '#8a5a12', '#fbf1dc'], ['alerta', 'Alerta', '#a3232b', '#fbe7e7'], ['critico', 'Crítico', '#7a1b22', '#f6dcdc'], ['bloqueio', 'Bloqueio', '#fff', '#7a1b22']];", $js);
        self::assertStringContainsString("const AVISO_BLOQUEIO = 'Antes de ligar, confira os escritórios acima do plano e defina sem limite ou uma cota própria.';", $js);
        self::assertStringContainsString('Faixas de alerta de consumo <span>(configuráveis)</span>', $js);
        self::assertStringContainsString("<span>' + rot + ' a partir de</span>'", $js, 'texto contínuo do chip (desenho, linha 197)');
        self::assertStringContainsString('No limite (100%), novos uploads acima da capacidade ficam bloqueados. Consulta, download e exportação continuam liberados. Nenhum documento é apagado automaticamente.', $js);
        self::assertStringContainsString('data-lim-arm="\' + c.id + \'">Editar limite</button>', $js);
        self::assertStringContainsString("<span>' + esc(rotulo) + ' · livre ' + esc(gb(livreDe(c))) + '</span>", $js);
        self::assertStringContainsString("(mes < 1 ? 'menos de 1 mês' : '≈ ' + n + (n === 1 ? ' mês' : ' meses'))", $js);
        self::assertStringNotContainsString("<span>sem plano</span>", $js);
        self::assertStringNotContainsString('sem cota definida', $js);

        $css = self::css();
        self::assertStringContainsString('.mp-faixa-chip { display: flex; align-items: center; gap: 8px; font-size: 13px; padding: 8px 12px; border-radius: 4px; background: var(--bg); color: var(--cor); font-weight: bold;', $css);
        self::assertStringContainsString('.mp .mp-rank-btn { height: 30px; border: none; border-radius: 4px; background: #e2e9ee; color: #243845; font-size: 12.5px; font-weight: bold; padding: 0 12px;', $css);
        self::assertStringContainsString('.mp .mp-rank-btn:hover { background: #0a6fa0; color: #fff; }', $css);
    }

    #[TestDox('P9/P10: KPI ARMAZENAMENTO com cota, livre e barra pela faixa; selo da auditoria desde a abertura')]
    public function testVisaoGeral(): void
    {
        $js = self::js();

        self::assertStringContainsString("desc: 'de ' + num(a.cotaGb) + ' GB contratados · livre ' + gb(a.bytesLivres) + sem, cor: (FAIXA[a.faixa] || FAIXA.livre)[1], pct: a.percentual + '%'", $js);
        self::assertStringContainsString("a.contasSemLimite > 0 ? ' · + ' + gb(a.bytesSemLimite) + ' em ' + num(a.contasSemLimite) + ' conta(s) sem limite' : ''", $js, 'decisão L do orquestrador');
        self::assertStringContainsString("return Object.assign(base, { n: gb(a.bytesUsados), desc:", $js, 'o número grande soma só as contas com limite');
        self::assertSame(3, substr_count($js, 'recarregarVisaoETrilha();'), 'limite, faixas e plano recarregam o KPI e o selo');
        self::assertStringContainsString(': S.nAud || 0;', $js, 'o selo fica com o último número enquanto recarrega');
        self::assertStringContainsString("D.visao ? D.visao.registrosDeAuditoria || 0", $js);
        self::assertStringContainsString("carregar('visao', URLS.visao);", $js);
    }

    #[TestDox('rotas do servidor vêm do template; POST com o token certo; recusa do servidor aparece no modal')]
    public function testContratoComOServidor(): void
    {
        $js = self::js();
        $twig = (string) file_get_contents(self::RAIZ . 'templates/master/painel.html.twig');

        foreach (["contaLimiteArm: path('master_conta_limite_armazenamento', {id: 0})", "contaLimiteUsu: path('master_conta_limite_usuarios', {id: 0})", "planos: path('master_dados_planos')", "planoAlterar: path('master_plano_alterar', {codigo: 'codigo'})", "faixas: path('master_faixas_configurar')", '<div id="mp-modal"></div>'] as $trecho) {
            self::assertStringContainsString($trecho, $twig, $trecho);
        }
        self::assertStringContainsString("corpo.append('_token', m.c.token || '');", $js, 'limite: token da conta');
        self::assertStringContainsString("['_token', m.p.token || '']", $js, 'plano: token do plano');
        self::assertStringContainsString("corpo.append('_token', D.planos.tokenFaixas || '');", $js);
        self::assertStringContainsString('if (json && r.ok && j.mensagem) return aoDarCerto(j);', $js);
        self::assertStringContainsString("modal.erro = erro || 'Não foi possível concluir a ação. Recarregue a página.';", $js);
    }

    #[TestDox('sem travessão no texto novo')]
    public function testSemTravessao(): void
    {
        $js = self::js();
        $inicio = strpos($js, '/* ================= ARMAZENAMENTO');
        $fim = strpos($js, '/* ================= SEGURANÇA E AUDITORIA');
        self::assertNotFalse($inicio);
        self::assertNotFalse($fim);

        self::assertDoesNotMatchRegularExpression('/[—–]/u', substr($js, $inicio, $fim - $inicio));
    }
}
