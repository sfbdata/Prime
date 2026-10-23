/* BlueJus · Bloco DESPACHO da meta (desenho 04B de 22/10, L59-66, L143-145, L159-161; spec minhas-metas-esteira-2210,
   C10 a C15). Template: templates/tarefa/_despacho_formulario.html.twig e a janela #modalCertificarDespacho do show.
   - Natureza: seleção única; clicar de novo desmarca; com o campo vazio, o chip escreve o começo do texto.
   - Prazo: "N dias úteis" recontado a cada troca de data pela lista de dias não úteis que o servidor manda
     (data-nao-uteis, regra do escritório com feriados); fora da lista, conta só o fim de semana.
   - Contador "N / 5.000" (N sem separador de milhar, como no desenho L64), nome do anexo, Ctrl+Enter (Cmd+Enter no Mac) = Despachar e os botões de ação.
   - Certificar cumprimento: o modal recebe o endereço e o token do despacho clicado e começa com "Certifico que ".
   - @menção (C3): o pasta-mencoes.js liga a lista ao campo; ao enviar, cada "@Nome" escolhido vira o token
     `@[Nome](user:ID)` (PastaMencoes.paraEnvio), que o servidor confere.
   - Extrato (L57, L150): abre a folha em janela nova; se o navegador bloquear, avisa "Permita pop-ups para gerar o
     extrato.". O aviso (toast) do desenho (L176) também mostra o "Lembrete enviado no Chat I.A." que volta na URL.
   - Aprendizado (Fase 4, L61 e L151-156): com o campo vazio, as frases que a pessoa mais usa nesta etapa, da natureza
     marcada (a lista por natureza vem em data-sugestoes); o clique põe o texto inteiro no campo e marca a natureza
     dele. Digitou, as sugestões somem; apagou tudo, voltam. */
(function () {
  'use strict';

  var DIA_MS = 86400000;

  function emUtc(iso) {
    var p = String(iso || '').split('-');
    if (p.length !== 3) return null;
    var t = Date.UTC(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
    return isNaN(t) ? null : t;
  }

  function isoDe(t) {
    return new Date(t).toISOString().slice(0, 10);
  }

  function contarUteis(hoje, alvo, naoUteis) {
    var inicio = emUtc(hoje), fim = emUtc(alvo);
    if (inicio === null || fim === null) return null;
    var n = 0;
    for (var t = inicio + DIA_MS; t <= fim; t += DIA_MS) {
      var semana = new Date(t).getUTCDay();
      if (semana !== 0 && semana !== 6 && !naoUteis[isoDe(t)]) n++;
    }
    return n;
  }

  function textoDosUteis(n) {
    return n === 1 ? BJ_T.t('metas.despacho.js.um_dia_util') : BJ_T.t('metas.despacho.js.dias_uteis', { n: n });
  }

  function iniciarNatureza(form, campo) {
    var oculto = form.querySelector('#despachoNatureza');
    var chips = form.querySelectorAll('.despacho-chip');
    chips.forEach(function (chip) {
      chip.addEventListener('click', function () {
        var ligar = chip.getAttribute('aria-pressed') !== 'true';
        chips.forEach(function (c) { c.setAttribute('aria-pressed', 'false'); });
        chip.setAttribute('aria-pressed', ligar ? 'true' : 'false');
        oculto.value = ligar ? chip.dataset.natureza : '';
        if (ligar && campo.value.trim() === '') {
          campo.value = chip.dataset.inicio || '';
          campo.dispatchEvent(new Event('input'));
          campo.focus();
        }
      });
    });
  }

  /* As frases da natureza marcada (L152): a lista do servidor já vem filtrada por natureza; '' é sem natureza. */
  function iniciarSugestoes(form, campo) {
    var caixa = form.querySelector('.despacho-sugestoes');
    if (!caixa) return;
    var lista = caixa.querySelector('.despacho-sugestoes-lista');
    var oculto = form.querySelector('#despachoNatureza');
    var porNatureza = {};
    try { porNatureza = JSON.parse(caixa.dataset.sugestoes || '{}') || {}; } catch (e) { porNatureza = {}; }

    function frase(f) {
      var botao = document.createElement('button');
      botao.type = 'button';
      botao.className = 'despacho-sugestao';
      botao.dataset.texto = f.texto;
      botao.dataset.natureza = f.natureza || '';
      var texto = document.createElement('span');
      texto.className = 'despacho-sugestao-texto';
      texto.textContent = f.rotulo;
      var vezes = document.createElement('span');
      vezes.className = 'despacho-sugestao-vezes';
      vezes.textContent = f.vezes > 1 ? BJ_T.t('metas.despacho.js.vezes', { n: f.vezes }) : '';
      botao.appendChild(texto);
      botao.appendChild(vezes);
      return botao;
    }

    function desenhar() {
      var frases = porNatureza[oculto.value] || [];
      lista.textContent = '';
      frases.forEach(function (f) { lista.appendChild(frase(f)); });
      caixa.hidden = frases.length === 0 || campo.value.trim() !== '';
    }

    function marcarNatureza(valor) {
      form.querySelectorAll('.despacho-chip').forEach(function (c) {
        c.setAttribute('aria-pressed', c.dataset.natureza === valor ? 'true' : 'false');
      });
      oculto.value = valor;
    }

    lista.addEventListener('click', function (e) {
      var botao = e.target.closest('.despacho-sugestao');
      if (!botao) return;
      if (botao.dataset.natureza) marcarNatureza(botao.dataset.natureza);
      campo.value = botao.dataset.texto;
      campo.dispatchEvent(new Event('input'));
      campo.focus();
    });
    form.querySelectorAll('.despacho-chip').forEach(function (chip) {
      chip.addEventListener('click', desenhar);
    });
    campo.addEventListener('input', function () {
      caixa.hidden = (porNatureza[oculto.value] || []).length === 0 || campo.value.trim() !== '';
    });
  }

  function iniciarPrazo(form) {
    var data = form.querySelector('#despachoPrazo');
    var rotulo = form.querySelector('#despachoUteis');
    if (!data || !rotulo) return;
    var naoUteis = {};
    try {
      JSON.parse(form.dataset.naoUteis || '[]').forEach(function (d) { naoUteis[d] = true; });
    } catch (e) { /* lista inválida: conta só o fim de semana */ }

    function atualizar() {
      var n = data.value ? contarUteis(form.dataset.hoje, data.value, naoUteis) : null;
      rotulo.hidden = n === null;
      if (n !== null) rotulo.textContent = textoDosUteis(n);
    }
    data.addEventListener('change', atualizar);
    data.addEventListener('input', atualizar);
    data.addEventListener('click', function () {
      try { if (data.showPicker) data.showPicker(); } catch (e) { /* navegador sem showPicker */ }
    });
  }

  function iniciarContadorEAnexo(form, campo) {
    var contador = form.querySelector('#despachoContador');
    var anexo = form.querySelector('#despachoAnexo');
    var nome = form.querySelector('#despachoAnexoNome');
    function contar() {
      contador.textContent = BJ_T.t('metas.despacho.js.contador', { n: String(campo.value.length) });
    }
    campo.addEventListener('input', contar);
    contar();
    if (anexo && nome) {
      anexo.addEventListener('change', function () {
        var f = anexo.files && anexo.files[0];
        nome.textContent = f ? f.name : nome.dataset.vazio;
      });
    }
  }

  function iniciarEnvio(form, campo) {
    var acao = form.querySelector('#acaoMensagem');
    var anexo = form.querySelector('#despachoAnexo');
    function enviar(valor) {
      /* Despachar pede texto ou anexo; só com anexo, sem texto, vale (04B L145). */
      var temAnexo = !!(anexo && anexo.files && anexo.files.length);
      if (valor === '' && campo.value.trim() === '' && !temAnexo) return;
      if (window.PastaMencoes) campo.value = PastaMencoes.paraEnvio(campo, campo.value);
      acao.value = valor;
      form.submit();
    }
    form.querySelectorAll('.despacho-botao').forEach(function (botao) {
      botao.addEventListener('click', function () { enviar(botao.dataset.acao || ''); });
    });
    campo.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        enviar('');
      }
    });
  }

  function iniciarCertidao() {
    var modal = document.getElementById('modalCertificarDespacho');
    if (!modal) return;
    var form = modal.querySelector('form');
    var texto = modal.querySelector('#certidaoTexto');
    /* A janela é do meta-detalhe.js: 'md:abrir' traz o botão "Certificar cumprimento" que a abriu. */
    modal.addEventListener('md:abrir', function (e) {
      var botao = e.detail && e.detail.origem;
      if (!botao) return;
      form.action = botao.dataset.url;
      form.querySelector('input[name="_token"]').value = botao.dataset.token;
      texto.value = texto.dataset.inicio || '';
      texto.focus();
      texto.setSelectionRange(texto.value.length, texto.value.length);
    });
  }

  /* O toast do desenho (L176): embaixo, no centro, some em 3 segundos. */
  function avisar(texto) {
    var aviso = document.createElement('div');
    aviso.className = 'meta-toast';
    aviso.setAttribute('role', 'status');
    var icone = document.createElement('i');
    icone.className = 'bi bi-check-circle';
    aviso.appendChild(icone);
    aviso.appendChild(document.createTextNode(texto));
    document.body.appendChild(aviso);
    setTimeout(function () { aviso.remove(); }, 3000);
  }

  function iniciarAvisos() {
    document.querySelectorAll('[data-meta-toast]').forEach(function (aviso) {
      setTimeout(function () { aviso.remove(); }, 3000);
    });
    /* O aviso do Lembrar vem na URL (?lembrete=enviado): sai da barra para não voltar ao recarregar. */
    if (window.URL && window.history && history.replaceState && /[?&]lembrete=/.test(location.search)) {
      var url = new URL(location.href);
      url.searchParams.delete('lembrete');
      history.replaceState(null, '', url.pathname + url.search + url.hash);
    }
  }

  function iniciarExtrato() {
    document.querySelectorAll('a[data-extrato]').forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        /* Sem 'noopener' nas opções: com ele o window.open devolve null mesmo quando abre. */
        var janela = window.open(link.href, '_blank');
        if (!janela) {
          avisar(BJ_T.t('metas.detalhe.js.permita_popups'));
          return;
        }
        janela.opener = null;
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    iniciarCertidao();
    iniciarAvisos();
    iniciarExtrato();
    var form = document.querySelector('form[data-despacho]');
    if (!form) return;
    var campo = form.querySelector('#tarefaMensagemConteudo');
    iniciarNatureza(form, campo);
    iniciarSugestoes(form, campo);
    iniciarPrazo(form);
    iniciarContadorEAnexo(form, campo);
    iniciarEnvio(form, campo);
  });
})();
