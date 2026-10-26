<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Planos e limites no painel (spec perfis-1.2-planos-e-limites P1 a P6, P9 e P10; desenho 05 - Perfis 1.2,
 * linhas 194-210, 397-410, 678, 688 e 726-735), lidos como FOLHA, no padrão de PainelMenuDaContaJsTest:
 * sem navegador no PHPUnit, prova-se o contrato do public/js/master/painel.js com o desenho, o template e o CSS.
 * Desde a P2b do idioma (spec idioma-do-sistema), o texto mora no catálogo: o teste confere a chave no JS e o
 * português no catálogo (textoNoCatalogo).
 */
#[CoversNothing]
final class PainelPlanosELimitesJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';

    private static function js(): string
    {
        return (string) file_get_contents(self::RAIZ . 'public/js/master/painel.js');
    }

    /** Texto em português de uma chave do catálogo. */
    private static function catalogo(string $chave): string
    {
        $no = CatalogoPtBr::carregar();
        foreach (explode('.', $chave) as $parte) {
            self::assertIsArray($no, $chave);
            self::assertArrayHasKey($parte, $no, $chave);
            $no = $no[$parte];
        }
        self::assertIsString($no, $chave);

        return $no;
    }

    /** O painel.js usa a chave e o catálogo tem o texto do desenho. */
    private static function textoNoCatalogo(string $js, string $chave, string $texto): void
    {
        self::assertStringContainsString("T('" . $chave . "'", $js, $chave);
        self::assertSame($texto, self::catalogo($chave), $chave);
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
        self::assertStringContainsString("const nomeDoPlano = p => (p.codigo === 'free' ? T('master.js.planos.plano_free', { nome: p.nome }) : p.nome);", $js);
        self::assertSame('Plano {nome}', self::catalogo('master.js.planos.plano_free'));
        self::assertStringContainsString("'<span class=\"mp-plano-tag\">' + T('master.js.planos.padrao') + '</span>'", $js);
        self::assertSame('Padrão: toda conta nova começa aqui', self::catalogo('master.js.planos.padrao'));
        self::assertStringContainsString("'<span> ' + T('master.js.planos.por_mes') + '</span></span>'", $js);
        self::assertSame('/ mês', self::catalogo('master.js.planos.por_mes'));
        self::assertStringContainsString("p.limiteUsuarios === null ? T('master.js.planos.usuarios_sem_limite') : T('master.js.planos.usuarios', { n: num(p.limiteUsuarios) })", $js);
        self::assertSame('usuários sem limite', self::catalogo('master.js.planos.usuarios_sem_limite'));
        self::assertSame('{n} usuários', self::catalogo('master.js.planos.usuarios'));
        self::assertStringContainsString("T('master.js.planos.contas', { n: num(p.escritorios), acima: num(acima) }) + '</span>'", $js);
        self::assertSame('{n} conta(s) · {acima} perto ou acima da franquia', self::catalogo('master.js.planos.contas'));
        self::assertStringContainsString("const COR_PLANO = { free: '#c08a12', essencial: '#0a6fa0', profissional: '#1e7b45', avancado: '#7a3e8f' };", $js);
        self::textoNoCatalogo($js, 'master.js.planos.nota', 'Toda conta nova entra no Plano Free (10 GB). A partir de 90% de uso o usuário primário recebe aviso no sistema e por e-mail recomendando ampliar o espaço; em 100% novos envios ficam bloqueados, mas consultar e baixar arquivos continua liberado. Mudanças de plano com impacto comercial exigem confirmação e ficam registradas na auditoria.');

        $css = self::css();
        self::assertStringContainsString('.mp-planos { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 14px; }', $css);
        self::assertStringContainsString('.mp-plano { padding: 18px 20px; display: flex; flex-direction: column; gap: 8px; border-top: 3px solid var(--cor); }', $css);
        self::assertStringContainsString('.mp-plano-tag { height: 20px; padding: 0 7px; border-radius: 3px; background: #fff4d6; color: #7a5200; font-size: 11px; font-weight: bold;', $css);
    }

    #[TestDox('P4: modal de limite com os quadros, rótulos, placeholder e mensagens do desenho; botão desabilitado #9fb3c0')]
    public function testModalDeLimite(): void
    {
        $js = self::js();

        foreach (["[T('master.js.limite.em_uso'), gb(c.bytes)]", "[T('master.js.limite.limite_atual'),", "[T('master.js.limite.livre'),", "[T('master.js.limite.usuarios'), num(c.usuarios)]", "[T('master.js.limite.vagas'),", "T('master.js.limite.novo_arm') : T('master.js.limite.novo_usu')", "campo(T('master.js.limite.justificativa')", "placeholder=\"' + esc(T('master.js.limite.justificativa_dica')) + '\"", "T('master.js.limite.confirmar')", "arm ? T('master.js.menu.lim_arm') : T('master.js.menu.lim_usu')"] as $trecho) {
            self::assertStringContainsString($trecho, $js, $trecho);
        }
        foreach (['em_uso' => 'EM USO', 'limite_atual' => 'LIMITE ATUAL', 'livre' => 'LIVRE', 'usuarios' => 'USUÁRIOS', 'vagas' => 'VAGAS', 'novo_arm' => 'NOVO LIMITE (GB)', 'novo_usu' => 'NOVO LIMITE DE USUÁRIOS', 'justificativa' => 'JUSTIFICATIVA', 'justificativa_dica' => 'Obrigatória, fica na auditoria', 'confirmar' => 'Confirmar alteração'] as $chave => $texto) {
            self::assertSame($texto, self::catalogo('master.js.limite.' . $chave), $chave);
        }
        self::assertSame('Editar limite de armazenamento', self::catalogo('master.js.menu.lim_arm'));
        self::assertSame('Definir limite de usuários', self::catalogo('master.js.menu.lim_usu'));
        self::assertStringContainsString("T('master.js.limite.msg_abaixo_arm', { gb: gb(m.c.bytes) })", $js);
        self::assertSame('O novo limite é menor que o volume em uso ({gb}). Reduzir não apaga arquivos, por isso não é permitido abaixo do uso.', self::catalogo('master.js.limite.msg_abaixo_arm'));
        self::textoNoCatalogo($js, 'master.js.limite.msg_abaixo_usu', 'Menor que os usuários já cadastrados.');
        self::assertStringContainsString("msg = T(arm ? 'master.js.limite.msg_diferenca_arm' : 'master.js.limite.msg_diferenca_usu', { d: (d >= 0 ? '+' : '') + d });", $js);
        self::assertSame('Diferença: {d} GB. A alteração fica registrada na auditoria.', self::catalogo('master.js.limite.msg_diferenca_arm'));
        self::assertSame('Diferença: {d} usuário(s). A alteração fica registrada na auditoria.', self::catalogo('master.js.limite.msg_diferenca_usu'));
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

        self::assertStringContainsString("const FAIXA = { livre: [T('master.js.faixa.livre'), '#1e7b45'], atencao: [T('master.js.faixa.atencao'), '#c08a12'], alerta: [T('master.js.faixa.alerta'), '#c0392b'], critico: [T('master.js.faixa.critico'), '#a3232b'], bloqueio: [T('master.js.faixa.bloqueio'), '#7a1b22'] };", $js);
        self::assertStringContainsString("const CHIPS = [['atencao', T('master.js.faixa.atencao'), '#8a5a12', '#fbf1dc'], ['alerta', T('master.js.faixa.alerta'), '#a3232b', '#fbe7e7'], ['critico', T('master.js.faixa.critico'), '#7a1b22', '#f6dcdc'], ['bloqueio', T('master.js.faixa.chip_bloqueio'), '#fff', '#7a1b22']];", $js);
        foreach (['livre' => 'Normal', 'atencao' => 'Atenção', 'alerta' => 'Alerta', 'critico' => 'Crítico', 'bloqueio' => 'Limite atingido', 'chip_bloqueio' => 'Bloqueio'] as $chave => $texto) {
            self::assertSame($texto, self::catalogo('master.js.faixa.' . $chave), $chave);
        }
        self::textoNoCatalogo($js, 'master.js.arm.aviso_bloqueio', 'Antes de ligar, confira os escritórios acima do plano e defina sem limite ou uma cota própria.');
        self::assertStringContainsString("T('master.js.arm.faixas_titulo') + ' <span>' + T('master.js.arm.configuraveis') + '</span></b>'", $js);
        self::assertSame('Faixas de alerta de consumo', self::catalogo('master.js.arm.faixas_titulo'));
        self::assertSame('(configuráveis)', self::catalogo('master.js.arm.configuraveis'));
        self::assertStringContainsString("<span>' + T('master.js.arm.a_partir', { faixa: rot }) + '</span>'", $js, 'texto contínuo do chip (desenho, linha 197)');
        self::assertSame('{faixa} a partir de', self::catalogo('master.js.arm.a_partir'));
        self::textoNoCatalogo($js, 'master.js.arm.faixas_nota', 'No limite (100%), novos uploads acima da capacidade ficam bloqueados. Consulta, download e exportação continuam liberados. Nenhum documento é apagado automaticamente.');
        self::assertStringContainsString('data-lim-arm="\' + c.id + \'">\' + T(\'master.js.arm.editar_limite\') + \'</button>', $js);
        self::assertSame('Editar limite', self::catalogo('master.js.arm.editar_limite'));
        self::assertStringContainsString("<span>' + esc(T('master.js.contas.arm_livre', { faixa: rotulo, livre: gb(livreDe(c)) })) + '</span>", $js);
        self::assertSame('{faixa} · livre {livre}', self::catalogo('master.js.contas.arm_livre'));
        self::assertStringContainsString("(mes < 1 ? T('master.js.contas.prev_menos') : T(n === 1 ? 'master.js.contas.prev_mes' : 'master.js.contas.prev_meses', { n }))", $js);
        self::assertSame(['menos de 1 mês', '≈ {n} mês', '≈ {n} meses'], [self::catalogo('master.js.contas.prev_menos'), self::catalogo('master.js.contas.prev_mes'), self::catalogo('master.js.contas.prev_meses')]);
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

        // Os textos do KPI estão no catálogo desde a P2a do idioma (spec idioma-do-sistema).
        self::assertStringContainsString("desc: T('master.js.visao.kpi.arm_desc', { cota: num(a.cotaGb), livre: gb(a.bytesLivres) }) + sem, cor: (FAIXA[a.faixa] || FAIXA.livre)[1], pct: a.percentual + '%'", $js);
        self::assertStringContainsString("a.contasSemLimite > 0 ? ' · ' + T('master.js.visao.kpi.arm_sem_limite', { gb: gb(a.bytesSemLimite), n: num(a.contasSemLimite) }) : ''", $js, 'decisão L do orquestrador');
        $kpi = CatalogoPtBr::carregar()['master']['js']['visao']['kpi'];
        self::assertSame('de {cota} GB contratados · livre {livre}', $kpi['arm_desc']);
        self::assertSame('+ {gb} em {n} conta(s) sem limite', $kpi['arm_sem_limite']);
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
        self::assertStringContainsString("modal.erro = erro || T('master.js.erro_acao');", $js);
        self::assertSame('Não foi possível concluir a ação. Recarregue a página.', self::catalogo('master.js.erro_acao'));
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
