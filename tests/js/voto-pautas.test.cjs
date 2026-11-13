// Interações das pautas sem navegador: executa o script real com um DOM mínimo.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const caminho = require('node:path');

function ambiente() {
    const eventos = {};
    const janela = {};
    const campos = {};
    const atributos = { 'data-voto-pauta-form': 'existente' };
    const numeros = [campo('opcao_numero[]', '01'), campo('opcao_numero[]', '09')];
    const form = {
        hidden: false, action: '/pautas/1/editar',
        get elements() { return [...Object.values(campos), ...numeros]; },
        getAttribute: nome => atributos[nome] ?? null,
        setAttribute: (nome, valor) => { atributos[nome] = valor; },
        querySelector: seletor => campos[(seletor.match(/name="([^"]+)"/) || [])[1]] || null,
        querySelectorAll: () => numeros,
        closest: () => form,
    };
    function campo(nome, valor, opcoes) {
        return {
            name: nome, value: valor, defaultValue: valor, tagName: opcoes ? 'SELECT' : 'INPUT',
            options: opcoes && opcoes.map(v => ({ value: v, defaultSelected: v === valor })),
            maxLength: 2, closest: () => form,
            hasAttribute: atributo => atributo === ({ digitos: 'data-voto-digitos', regra_pronta: 'data-voto-regra-pronta', criterio: 'data-voto-regra', base: 'data-voto-regra' }[nome]),
        };
    }
    campos.titulo = campo('titulo', 'Conselho');
    campos.regra_pronta = campo('regra_pronta', '', ['', 'simples', 'absoluta', 'dois', 'unanime']);
    campos.criterio = campo('criterio', 'mais', ['mais', 'absoluta', 'dois', 'unanime']);
    campos.base = campo('base', 'validos', ['validos', 'presentes', 'aptos']);
    campos.digitos = campo('digitos', '2', ['1', '2', '3', '4', '5']);
    campos.vagas = campo('vagas', '1');
    campos.quorum_primeira = campo('quorum_primeira', '50');
    campos.quorum_segunda = campo('quorum_segunda', '0');
    const tela = { getAttribute: () => '', querySelector: () => ({ textContent: '', hidden: true }) };
    const document = {
        readyState: 'loading',
        addEventListener: (nome, fn) => { (eventos[nome] ||= []).push(fn); },
        querySelector: seletor => seletor === '[data-voto-pautas]' ? tela : null,
        querySelectorAll: seletor => seletor === 'form[data-voto-pauta-form]' ? [form] : [],
    };
    let responder;
    let enviado;
    const window = {
        addEventListener: (nome, fn) => { janela[nome] = fn; },
        sessionStorage: { getItem: () => null, removeItem() {} },
        setTimeout: () => 1, clearTimeout() {}, location: { href: '' },
        fetch: (url, opcoes) => { enviado = opcoes.body; return new Promise(resolve => { responder = resolve; }); },
    };
    class FormData {
        constructor(formulario) { this.valores = Object.fromEntries(formulario.elements.map(c => [c.name, c.value])); }
    }
    // Expõe funções privadas só na cópia em memória do teste, sem mudar o arquivo publicado.
    const codigo = readFileSync(caminho.resolve(__dirname, '../../app/public/js/voto-assembleia.js'), 'utf8')
        .replace('    function iniciar() {', '    globalThis.pauta = { retrato, alterada, gravar, proximoNumero, numerarOpcoes, limitarConfiguracao };\n    function iniciar() {');
    const contexto = vm.createContext({ window, document, FormData });
    vm.runInContext(codigo, contexto);
    return {
        campos, form, numeros, pauta: contexto.pauta,
        iniciar: () => eventos.DOMContentLoaded[0](),
        sair: () => {
            const link = { target: '', hasAttribute: () => false, getAttribute: () => '/outra', href: '/outra' };
            eventos.click[0]({ target: { closest: () => link }, button: 0, preventDefault() {} });
        },
        destino: () => window.location.href,
        mudar: nome => eventos.change[0]({ target: campos[nome] }),
        aviso: () => { let avisou = false; janela.beforeunload({ preventDefault() { avisou = true; } }); return avisou; },
        concluir: () => responder({ status: 200, json: async () => ({ ok: true, mensagem: 'Pauta salva.' }) }),
        enviado: () => enviado.valores,
    };
}

test('alteração somente no select conta como pendente e dispara aviso ao sair', () => {
    const a = ambiente(); a.iniciar();
    assert.equal(a.aviso(), false);
    a.campos.base.value = 'aptos'; a.mudar('base');
    assert.equal(a.pauta.alterada(a.form), true);
    assert.equal(a.aviso(), true);
});

test('restauração do navegador é comparada com defaultSelected e não vira dado salvo', () => {
    const a = ambiente();
    a.campos.digitos.value = '4'; a.iniciar();
    assert.equal(a.numeros[0].maxLength, 4);
    assert.equal(a.pauta.alterada(a.form), true);
    a.campos.digitos.value = '2';
    assert.equal(a.pauta.alterada(a.form), false);
    a.campos.base.options.forEach(o => { o.defaultSelected = false; });
    assert.match(a.pauta.retrato(a.form, true), /\["base","validos"\]/);
});

test('presets aplicam as quatro regras e Personalizada preserva os campos', () => {
    const a = ambiente(); a.iniciar();
    const esperado = { simples: ['mais', 'validos', '50', '0'], absoluta: ['absoluta', 'aptos', '0', ''], dois: ['dois', 'aptos', '0', ''], unanime: ['unanime', 'aptos', '0', ''] };
    for (const [chave, valores] of Object.entries(esperado)) {
        a.campos.regra_pronta.value = chave; a.mudar('regra_pronta');
        assert.deepEqual(['criterio', 'base', 'quorum_primeira', 'quorum_segunda'].map(n => a.campos[n].value), valores);
    }
    const antes = a.pauta.retrato(a.form, false);
    a.campos.regra_pronta.value = ''; a.mudar('regra_pronta');
    a.campos.regra_pronta.value = 'unanime';
    assert.equal(a.pauta.retrato(a.form, false), antes);
    a.campos.quorum_segunda.value = '99'; a.mudar('base');
    assert.equal(a.campos.regra_pronta.value, 'unanime', 'reconhecimento ignora segunda convocação');
    a.campos.base.value = 'presentes'; a.mudar('base');
    assert.equal(a.campos.regra_pronta.value, '');
});

test('dígitos não renumeram; Numerar segue a ordem e nova candidatura usa maior mais um', () => {
    const a = ambiente(); a.iniciar();
    assert.equal(a.pauta.proximoNumero(a.form), '10');
    a.campos.digitos.value = '1'; a.mudar('digitos');
    assert.equal(a.numeros[1].value, '09');
    assert.equal(a.numeros[1].maxLength, 1);
    assert.equal(a.pauta.proximoNumero(a.form), '');
    a.campos.digitos.value = '3'; a.mudar('digitos');
    a.pauta.numerarOpcoes(a.form);
    assert.deepEqual(a.numeros.map(n => n.value), ['001', '002']);
    assert.equal(a.pauta.proximoNumero(a.form), '003');
});

test('snapshot salvo no fetch contém selects enviados e preserva edição durante a resposta', async () => {
    const a = ambiente(); a.iniciar();
    a.campos.base.value = 'aptos';
    const salvando = a.pauta.gravar(a.form);
    assert.equal(a.enviado().base, 'aptos');
    a.campos.base.value = 'presentes';
    a.concluir(); await salvando;
    assert.equal(a.pauta.alterada(a.form), true);
    assert.equal(a.aviso(), true);
    a.campos.base.value = 'aptos';
    assert.equal(a.pauta.alterada(a.form), false);
});

test('sair pela tela salva alteração apenas de select antes de navegar', async () => {
    const a = ambiente(); a.iniciar();
    a.campos.base.value = 'aptos'; a.mudar('base'); a.sair();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(a.enviado().base, 'aptos');
    assert.equal(a.destino(), '');
    a.concluir();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(a.destino(), '/outra');
});

test('limites seguem o desenho: vagas de 1 a 9, quórum até 100 e segunda vazia', () => {
    const a = ambiente(); a.iniciar();
    for (const [nome, tipo, entrada, esperado] of [
        ['vagas', 'vagas', '', '1'], ['vagas', 'vagas', '22', '9'],
        ['quorum_primeira', 'primeira', '', '0'], ['quorum_primeira', 'primeira', '150', '100'],
        ['quorum_segunda', 'segunda', '', ''], ['quorum_segunda', 'segunda', 'a80', '80'],
    ]) {
        const campo = a.campos[nome]; campo.value = entrada; campo.getAttribute = () => tipo;
        a.pauta.limitarConfiguracao(campo);
        assert.equal(campo.value, esperado);
    }
});
