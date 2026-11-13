const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function pagina(fetch) {
    const atributos = new Map([['data-vc-erro', 'Falha no envio']]);
    const botao = {disabled: false};
    const resposta = {textContent: '', className: ''};
    let submeter;
    const form = {
        action: '/votar/token/pauta/1/comprovante/email',
        parentElement: {querySelector: () => resposta},
        querySelector: () => botao,
        addEventListener: (tipo, executar) => { submeter = executar; },
        getAttribute: chave => atributos.get(chave),
        setAttribute: (chave, valor) => atributos.set(chave, valor),
        hasAttribute: chave => atributos.has(chave),
        removeAttribute: chave => atributos.delete(chave)
    };
    const document = {
        querySelectorAll: seletor => seletor === '[data-vc-comprovante-email]' ? [form] : [],
        body: {getAttribute: () => '', classList: {contains: () => false}},
        activeElement: {closest: () => null}
    };
    const contexto = {document, window: {}, fetch, FormData: class {}, setTimeout: () => {}, setInterval: () => {}};
    vm.runInNewContext(readFileSync(path.join(__dirname, '../../app/public/js/voto-cedula.js'), 'utf8'), contexto);
    return {submeter: () => submeter({preventDefault() {}}), botao, resposta, atributos};
}

const concluir = () => new Promise(resolve => setImmediate(resolve));

test('clique repetido envia um pedido e libera o botão após a resposta', async () => {
    let resolver;
    let pedidos = 0;
    const tela = pagina(() => { ++pedidos; return new Promise(resolve => { resolver = resolve; }); });
    tela.submeter();
    tela.submeter();
    assert.equal(pedidos, 1);
    assert.equal(tela.botao.disabled, true);
    resolver({json: async () => ({ok: true, mensagem: 'Enviado'})});
    await concluir();
    assert.equal(tela.resposta.textContent, 'Enviado');
    assert.equal(tela.botao.disabled, false);
    assert.equal(tela.atributos.has('aria-busy'), false);
});

test('falha de conexão e recusa de cota permitem corrigir e tentar novamente', async () => {
    let pedidos = 0;
    const tela = pagina(() => {
        if (++pedidos === 1) { return Promise.reject(new Error('Rede')); }
        return Promise.resolve({json: async () => ({ok: false, mensagem: 'Limite de 3 envios atingido'})});
    });
    tela.submeter();
    await concluir();
    assert.equal(tela.resposta.textContent, 'Falha no envio');
    assert.equal(tela.botao.disabled, false);
    tela.submeter();
    await concluir();
    assert.equal(tela.resposta.textContent, 'Limite de 3 envios atingido');
    assert.match(tela.resposta.className, /vcMsgVermelho/);
});
