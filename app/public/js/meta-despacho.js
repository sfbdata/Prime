/* BlueJus · Bloco DESPACHO da meta (desenho 04B de 22/10, L59-66, L143-145, L159-161; spec minhas-metas-esteira-2210,
   C10 a C15). Template: templates/tarefa/_despacho_formulario.html.twig e o modal #modalCertificarDespacho do show.
   - Natureza: seleção única; clicar de novo desmarca; com o campo vazio, o chip escreve o começo do texto.
   - Prazo: "N dias úteis" recontado a cada troca de data pela lista de dias não úteis que o servidor manda
     (data-nao-uteis, regra do escritório com feriados); fora da lista, conta só o fim de semana.
   - Contador "N / 5.000", nome do anexo, Ctrl+Enter (Cmd+Enter no Mac) = Despachar e os botões de ação.
   - Certificar cumprimento: o modal recebe o endereço e o token do despacho clicado e começa com "Certifico que ".
   - @menção (C3): o pasta-mencoes.js liga a lista ao campo; ao enviar, cada "@Nome" escolhido vira o token
     `@[Nome](user:ID)` (PastaMencoes.paraEnvio), que o servidor confere.
   - Extrato (L57, L150): abre a folha em janela nova; se o navegador bloquear, avisa "Permita pop-ups para gerar o
     extrato.". O aviso (toast) do desenho (L176) também mostra o "Lembrete enviado no Chat I.A." que volta na URL. */
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
      contador.textContent = BJ_T.t('metas.despacho.js.contador', { n: campo.value.length.toLocaleString('pt-BR') });
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
    modal.addEventListener('show.bs.modal', function (e) {
      var botao = e.relatedTarget;
      if (!botao) return;
      form.action = botao.dataset.url;
      form.querySelector('input[name="_token"]').value = botao.dataset.token;
      texto.value = texto.dataset.inicio || '';
    });
    modal.addEventListener('shown.bs.modal', function () {
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
    iniciarPrazo(form);
    iniciarContadorEAnexo(form, campo);
    iniciarEnvio(form, campo);
  });
})();
