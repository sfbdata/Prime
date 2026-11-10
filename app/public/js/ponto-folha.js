/**
 * Barra da Folha de Ponto (E16 do desenho 16; docs/specs/ponto-desenho-0811.md, P22, P33, P87 a P89; decisões 2, 10,
 * 11, 12, 15 e 19), na /ponto e na ficha do gestor: as duas carregam este arquivo pelo ponto/_folha_table.html.twig.
 *
 * - "Baixar PDF" (`baixarPdf`, L717 a L730): o aviso azul "Gerando a folha em PDF...", o PDF pedido ao servidor (cada
 *   pedido é uma emissão nova, com código próprio: decisão 2) e salvo com o nome que a resposta dá; depois, o aviso
 *   verde com o código lido do cabeçalho `X-Codigo-Folha` (sem o segredo não há código) ou o vermelho na falha. Um
 *   pedido por vez, como o desenho.
 * - "Ver PDF" (P89, fica; decisão 12): a janela do desenho com o PDF (`inline=1`) num quadro. O "Baixar" de dentro
 *   salva o MESMO arquivo, sem nova emissão (decisão 2), fecha a janela e avisa como o "Baixar PDF".
 * - "Baixar XLSX" (P88, fica): a resposta vem como anexo, então ir até ela baixa sem sair da tela.
 * - "Assinar folha" (P33, só na /ponto): abre a janela do aceite na moldura das janelas do Ponto (`window.PontoAbas`,
 *   js/ponto-abas.js: Esc, foco preso, linha de erro que some ao editar) em "Concordo" e com a ressalva vazia (L816).
 *   "Discordo" sem texto não envia: "Descreva a ressalva." na linha de erro (L809). O resto é o envio normal.
 *
 * O aviso flutuante é o da E10 (`.pe-sf-aviso`, L266 e L468): some em 3,4 s, um por vez. Tudo por delegação no
 * documento: depois da batida a /ponto troca o cartão da folha, e os botões novos continuam funcionando.
 */
(function () {
    'use strict';

    if (window.PontoFolha) {
        return;
    }

    const AVISO_MS = 3400;
    // O PDF em imagem é desenhado na hora, no servidor; sem resposta até aqui, a falha aparece.
    const PRAZO_PDF_MS = 30000;
    const GERANDO = 'Gerando a folha em PDF...';
    const BAIXADA = 'Folha baixada em PDF.';
    const FALHOU = 'Não foi possível gerar o PDF.';
    const LIMITE = 'Muitos pedidos de folha em sequência. Aguarde um minuto e tente de novo.';
    const SEM_RESSALVA = 'Descreva a ressalva.';
    const NOME_PADRAO = 'folha_ponto.pdf';
    const FOCAVEIS = 'button:not([disabled]), iframe, [href]';

    let aviso = null;
    let avisoPrazo = null;
    let gerandoPdf = false;
    // Janela "Ver PDF": o número do pedido que vale (resposta de pedido anterior é descartada), quem a abriu, o PDF
    // recebido e o endereço dele no quadro.
    const ver = { pedido: 0, origem: null, pdf: null, endereco: '' };

    function avisar(texto, tom) {
        if (aviso) {
            aviso.remove();
            clearTimeout(avisoPrazo);
        }
        aviso = document.createElement('div');
        aviso.className = 'pe-sf-aviso' + (tom ? ' pe-sf-aviso--' + tom : '');
        aviso.setAttribute('role', 'status');
        aviso.textContent = texto;
        (document.querySelector('[data-folha-janelas]') || document.body).appendChild(aviso);
        avisoPrazo = setTimeout(function () {
            if (aviso) {
                aviso.remove();
                aviso = null;
            }
        }, AVISO_MS);
    }

    function textoDaBaixa(codigo) {
        return codigo ? 'Folha baixada em PDF. Código ' + codigo + '.' : BAIXADA;
    }

    function nomeDoArquivo(resposta) {
        const disposicao = resposta.headers.get('Content-Disposition') || '';
        const utf8 = disposicao.match(/filename\*=UTF-8''([^;]+)/i);
        if (utf8) {
            return decodeURIComponent(utf8[1]);
        }
        const simples = disposicao.match(/filename="?([^";]+)"?/i);

        return simples ? simples[1] : NOME_PADRAO;
    }

    function salvar(blob, nome) {
        const endereco = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = endereco;
        link.download = nome;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(endereco); }, 1000);
    }

    // O PDF da rota: `{ blob, nome, codigo }`, ou `{ erro }` com a frase do aviso. Resposta que não é PDF (a sessão
    // que caiu e voltou na tela de entrar, por exemplo) é falha, e não um arquivo salvo com o nome errado.
    async function pedirPdf(url) {
        const aborto = new AbortController();
        const prazo = setTimeout(function () { aborto.abort(); }, PRAZO_PDF_MS);
        try {
            const resposta = await fetch(url, { credentials: 'same-origin', signal: aborto.signal });
            if (resposta.status === 429) {
                return { erro: LIMITE };
            }
            if (!resposta.ok || !(resposta.headers.get('Content-Type') || '').includes('application/pdf')) {
                return { erro: FALHOU };
            }
            const blob = await resposta.blob();

            return { blob: blob, nome: nomeDoArquivo(resposta), codigo: (resposta.headers.get('X-Codigo-Folha') || '').trim() };
        } catch (erro) {
            return { erro: FALHOU };
        } finally {
            clearTimeout(prazo);
        }
    }

    // ---- Baixar PDF (L717 a L730) ----

    async function baixarPdf(botao) {
        if (gerandoPdf) {
            return;
        }
        gerandoPdf = true;
        avisar(GERANDO, 'info');
        const pdf = await pedirPdf(botao.dataset.folhaPdf);
        gerandoPdf = false;
        if (pdf.erro) {
            avisar(pdf.erro, 'erro');
            return;
        }
        salvar(pdf.blob, pdf.nome);
        avisar(textoDaBaixa(pdf.codigo));
    }

    // ---- Ver PDF (P89, decisão 12) ----

    function janelaVer() {
        return document.getElementById('ponto-janela-ver-pdf');
    }

    function erroDaJanela(fundo, texto) {
        const linha = fundo.querySelector('[data-folha-ver-erro]');
        linha.textContent = texto;
        linha.hidden = texto === '';
    }

    function soltarArquivo() {
        if (ver.endereco) {
            URL.revokeObjectURL(ver.endereco);
        }
        ver.endereco = '';
        ver.pdf = null;
    }

    function aguardando(fundo) {
        const corpo = fundo.querySelector('[data-folha-ver-corpo]');
        const texto = document.createElement('div');
        texto.className = 'pe-janela-texto';
        texto.textContent = GERANDO;
        corpo.classList.remove('pe-folha-ver-quadro');
        corpo.replaceChildren(texto);
        erroDaJanela(fundo, '');
        fundo.querySelector('[data-folha-ver-baixar]').disabled = true;
    }

    function mostrarPdf(fundo, pdf) {
        const corpo = fundo.querySelector('[data-folha-ver-corpo]');
        const quadro = document.createElement('iframe');
        ver.pdf = pdf;
        ver.endereco = URL.createObjectURL(pdf.blob);
        quadro.src = ver.endereco;
        quadro.title = fundo.querySelector('.pe-janela-titulo').textContent;
        corpo.classList.add('pe-folha-ver-quadro');
        corpo.replaceChildren(quadro);
        fundo.querySelector('[data-folha-ver-baixar]').disabled = false;
    }

    async function abrirVer(botao) {
        const fundo = janelaVer();
        if (!fundo) {
            return;
        }
        const esta = ++ver.pedido;
        soltarArquivo();
        ver.origem = botao;
        aguardando(fundo);
        fundo.hidden = false;
        fundo.querySelector('.pe-janela-fechar').focus();

        const pdf = await pedirPdf(botao.dataset.folhaVer);
        if (esta !== ver.pedido || fundo.hidden) {
            return;
        }
        if (pdf.erro) {
            fundo.querySelector('[data-folha-ver-corpo]').replaceChildren();
            erroDaJanela(fundo, pdf.erro);
            return;
        }
        mostrarPdf(fundo, pdf);
    }

    // Esc, o "x" e "Fechar" fecham; clicar fora não fecha (o desenho não fecha). A resposta que chega depois é descartada.
    function fecharVer() {
        const fundo = janelaVer();
        if (!fundo || fundo.hidden) {
            return;
        }
        ver.pedido++;
        fundo.hidden = true;
        fundo.querySelector('[data-folha-ver-corpo]').replaceChildren();
        soltarArquivo();
        if (ver.origem && document.contains(ver.origem)) {
            ver.origem.focus();
        }
        ver.origem = null;
    }

    // O mesmo arquivo do quadro, sem nova emissão (decisão 2).
    function baixarDaJanela() {
        const pdf = ver.pdf;
        if (!pdf) {
            return;
        }
        salvar(pdf.blob, pdf.nome);
        fecharVer();
        avisar(textoDaBaixa(pdf.codigo));
    }

    // O foco não sai da janela aberta com Tab (aria-modal).
    function prenderFoco(evento, fundo) {
        const focaveis = Array.from(fundo.querySelectorAll(FOCAVEIS));
        if (focaveis.length === 0) {
            return;
        }
        const primeiro = focaveis[0];
        const ultimo = focaveis[focaveis.length - 1];
        if (evento.shiftKey && document.activeElement === primeiro) {
            evento.preventDefault();
            ultimo.focus();
        } else if (!evento.shiftKey && document.activeElement === ultimo) {
            evento.preventDefault();
            primeiro.focus();
        }
    }

    // ---- Assinar folha (P33, L290 e L809) ----

    function abrirAceite(botao) {
        const moldura = window.PontoAbas;
        const fundo = moldura ? moldura.abrirJanela('ponto-janela-aceite', botao) : null;
        if (!fundo) {
            return;
        }
        const form = fundo.querySelector('form');
        delete fundo.dataset.ocupada;
        form.querySelector('[data-janela-enviar]').disabled = false;
        form.querySelector('[data-aceite-situacao]').value = 'concordo';
        form.querySelector('[data-aceite-ressalva]').value = '';
        form.querySelector('[data-aceite-situacao]').focus();
    }

    function conferirAceite(evento, form) {
        const fundo = form.closest('.pe-janela-fundo');
        const ressalva = form.querySelector('[data-aceite-ressalva]');
        if (form.querySelector('[data-aceite-situacao]').value === 'ressalva' && ressalva.value.trim() === '') {
            evento.preventDefault();
            window.PontoAbas.mostrarErro(fundo, SEM_RESSALVA);
            ressalva.focus();
            return;
        }
        form.querySelector('[data-janela-enviar]').disabled = true;
        fundo.dataset.ocupada = '1';
    }

    // ---- Delegação ----

    const ACOES_DO_CLIQUE = [
        ['[data-folha-pdf]', baixarPdf],
        ['[data-folha-ver]', abrirVer],
        ['[data-folha-ver-fechar]', fecharVer],
        ['[data-folha-ver-baixar]', baixarDaJanela],
        ['[data-folha-baixar]', function (el) { window.location.href = el.dataset.folhaBaixar; }],
        ['[data-folha-assinar]', abrirAceite],
    ];

    document.addEventListener('click', function (evento) {
        for (const [seletor, acao] of ACOES_DO_CLIQUE) {
            const el = evento.target.closest(seletor);
            if (el) {
                acao(el);
                return;
            }
        }
    });

    document.addEventListener('keydown', function (evento) {
        const fundo = janelaVer();
        if (!fundo || fundo.hidden) {
            return;
        }
        if (evento.key === 'Escape') {
            fecharVer();
        } else if (evento.key === 'Tab') {
            prenderFoco(evento, fundo);
        }
    });

    document.addEventListener('submit', function (evento) {
        if (evento.target.matches('[data-janela-form="aceite"]')) {
            conferirAceite(evento, evento.target);
        }
    });

    window.PontoFolha = { avisar: avisar };
})();
