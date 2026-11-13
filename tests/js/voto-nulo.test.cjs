const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const { readFileSync } = require('node:fs');
const path = require('node:path');

function ambiente(vagas = 1, digitos = 1) {
    function elemento() {
        return { children: [], textContent: '', className: '', appendChild(e) { this.children.push(e); }, setAttribute() {}, addEventListener() {}, querySelector: () => null, querySelectorAll: () => [] };
    }
    const raiz = elemento();
    const pauta = { id: 5, titulo: 'Conselho', vagas, digitos, opcoes: [
        { id: 10, numero: 1, rotulo: 'Ana' }, { id: 11, numero: 2, rotulo: 'Bia' }, { id: 12, numero: 0, rotulo: 'Abstenção', abstencao: true },
    ] };
    const dados = { pautas: [pauta], simulacao: [pauta], csrf: 'csrf-ficticio', urlVotar: '/pauta/__PAUTA__', textos: { numero_repetido: 'Você já escolheu este número.', numero_invalido: 'NÚMERO ERRADO', voto_nulo: 'VOTO NULO', fala_invalido: 'Número errado, voto nulo. Confirme ou corrija.' } };
    const document = {
        querySelector: seletor => seletor === '[data-vc-cabine]' ? raiz : { getAttribute: () => JSON.stringify(dados) },
        querySelectorAll: () => [], createElement: elemento, createTextNode: texto => ({ textContent: texto }),
    };
    let envio;
    const contexto = vm.createContext({ window: { matchMedia: () => ({ matches: true }) }, document, URLSearchParams,
        sessionStorage: { removeItem() {} }, fetch: async (url, opcoes) => { envio = { url, corpo: opcoes.body }; return { ok: true, json: async () => ({ ok: true, comprovante: 'BJV-TESTE' }) }; },
    });
    let codigo = readFileSync(path.resolve(__dirname, '../../app/public/js/voto-cabine.js'), 'utf8');
    codigo = codigo.replace("    botoes.branco.addEventListener", `    som = false; desenhar = function () {}; gravar = function (votos) { globalThis.gravados = votos; };
    c = { fase: 'urna', sim: false, i: 0, dig: '', branco: false, escolhas: [], votos: [], ver: false };
    globalThis.cabine = { digito, confirma, corrige, branco, telaVotando, falaAtual, enviarUm, estado: function () { return c; } }; return;
    botoes.branco.addEventListener`);
    vm.runInContext(codigo, contexto);
    return { cabine: contexto.cabine, votos: () => JSON.parse(JSON.stringify(contexto.gravados)), envio: () => envio };
}
function textos(no) { return [no.textContent || '', ...(no.children || []).map(textos)].join(' '); }

test('número inexistente mostra os dois textos do desenho e permite confirmar', () => {
    const a = ambiente(); a.cabine.digito('9');
    assert.match(textos(a.cabine.telaVotando()), /NÚMERO ERRADO VOTO NULO/);
    assert.equal(a.cabine.falaAtual(), 'Número errado, voto nulo. Confirme ou corrija.');
    a.cabine.confirma();
    assert.deepEqual(a.votos(), [{ pauta: 5, opcoes: [], nulos: ['9'] }]);
});

test('nome válido e nulo ocupam duas escolhas na mesma participação', () => {
    const a = ambiente(2); a.cabine.digito('1'); a.cabine.confirma();
    a.cabine.digito('9'); a.cabine.confirma();
    assert.deepEqual(a.votos(), [{ pauta: 5, opcoes: [10], nulos: ['9'] }]);
});

test('dois nulos distintos valem e repetir o mesmo número não avança', () => {
    const a = ambiente(2); a.cabine.digito('9'); a.cabine.confirma();
    a.cabine.digito('9'); a.cabine.confirma();
    assert.equal(a.cabine.estado().escolhas.length, 1);
    assert.equal(a.cabine.falaAtual(), 'Você já escolheu este número.');
    assert.match(textos(a.cabine.telaVotando()), /Você já escolheu este número/);
    assert.equal(a.cabine.estado().fase, 'urna');
    a.cabine.corrige(); a.cabine.digito('8'); a.cabine.confirma();
    assert.deepEqual(a.votos()[0].nulos, ['9', '8']);
});

test('CORRIGE desfaz nulo sem número digitado; BRANCO continua exclusivo', () => {
    const a = ambiente(3); a.cabine.digito('9'); a.cabine.confirma();
    a.cabine.branco(); assert.equal(a.cabine.estado().branco, false);
    a.cabine.corrige(); assert.equal(a.cabine.estado().escolhas.length, 0);
    a.cabine.branco(); assert.equal(a.cabine.estado().branco, true);
    a.cabine.confirma(); assert.deepEqual(a.votos()[0], { pauta: 5, opcoes: [12], nulos: [] });
});

test('número incompleto não vira nulo; zeros à esquerda chegam no POST', async () => {
    const a = ambiente(1, 3); a.cabine.digito('0'); a.cabine.confirma();
    assert.equal(a.cabine.estado().fase, 'urna');
    a.cabine.digito('0'); a.cabine.digito('9'); a.cabine.confirma();
    assert.deepEqual(a.votos()[0].nulos, ['009']);
    assert.equal(await a.cabine.enviarUm(a.votos()[0]), 'BJV-TESTE');
    assert.equal(a.envio().corpo.get('numero_nulo[]'), '009');
    assert.deepEqual(a.envio().corpo.getAll('opcao[]'), []);
});
