/* Voto: janela "Ajustar a foto" da candidatura (pedido B2 do Farlei, 19/11). Template
   templates/voto/assembleia/_janela_ajustar_foto.html.twig, CSS public/css/voto-janela.css (.vModFoto/.vFotoArea).
   Mesmo modelo do editor da foto do cliente da pasta (pasta-foto-cliente.js): arrastar para enquadrar, barra ou roda do
   mouse para aproximar (1 a 3), girar 90°, e o recorte feito no navegador. Aqui a moldura é 3x4 (240x320 na tela) e o
   JPEG sai com 360x480, qualidade 0,88, fundo branco. A foto sempre cobre a moldura inteira (sem faixa vazia).

   API: window.VotoFotoAjuste.abrir(arquivo, terminar)
     terminar(blob)      "Usar esta foto": o JPEG recortado;
     terminar(null)      "Cancelar", Esc ou clique fora: a escolha é desfeita;
     terminar(undefined) janela ausente, navegador sem canvas ou imagem ilegível: quem chama fica com o original.
   Quem valida tipo e tamanho continua sendo o servidor (FotosDasCandidaturas). Nada de HTML montado aqui. */
(function () {
    'use strict';

    var W = 240, H = 320;       // moldura na tela (3x4)
    var SAIDA_W = 360, SAIDA_H = 480;
    var QUALIDADE = 0.88;
    var ZOOM_MAX = 3;

    var janela = null, area, imagem, zoom, aplicar;
    var estado = null;          // { url, w, h, zoom, x, y, rot, terminar, volta }

    function preparar() {
        if (janela) {
            return true;
        }
        janela = document.querySelector('[data-voto-foto-ajuste]');
        if (!janela || !window.URL || !document.createElement('canvas').getContext) {
            janela = null;
            return false;
        }
        // `position: fixed`: no body, nenhum ancestral com transform a desloca.
        document.body.appendChild(janela);
        area = janela.querySelector('[data-voto-foto-ajuste-area]');
        imagem = janela.querySelector('[data-voto-foto-ajuste-img]');
        zoom = janela.querySelector('[data-voto-foto-ajuste-zoom]');
        aplicar = janela.querySelector('[data-voto-foto-ajuste-aplicar]');
        ligar();
        return true;
    }

    /* Lados da imagem depois do giro e a escala que faz a imagem cobrir a moldura no zoom 1. */
    function medidas() {
        var deitada = estado.rot % 180 !== 0;
        var ew = deitada ? estado.h : estado.w, eh = deitada ? estado.w : estado.h;
        var base = Math.max(W / ew, H / eh) * estado.zoom;
        return { dw: estado.w * base, dh: estado.h * base, ew: ew * base, eh: eh * base };
    }

    /* A foto nunca deixa faixa vazia na moldura: o deslocamento fica dentro da sobra de cada lado. */
    function limitar(d) {
        var mx = Math.max(0, (d.ew - W) / 2), my = Math.max(0, (d.eh - H) / 2);
        estado.x = Math.max(-mx, Math.min(mx, estado.x));
        estado.y = Math.max(-my, Math.min(my, estado.y));
    }

    function pintar() {
        var d = medidas();
        limitar(d);
        imagem.style.width = d.dw + 'px';
        imagem.style.height = d.dh + 'px';
        imagem.style.transform = 'translate(-50%,-50%) translate(' + estado.x + 'px,' + estado.y + 'px) rotate(' + estado.rot + 'deg)';
        zoom.value = String(estado.zoom);
    }

    function fechar(resultado) {
        if (!estado) {
            return;
        }
        var e = estado;
        estado = null;
        janela.hidden = true;
        imagem.removeAttribute('src');
        window.URL.revokeObjectURL(e.url);
        if (e.volta && e.volta.focus) {
            e.volta.focus();
        }
        e.terminar(resultado);
    }

    function recortar() {
        var e = estado, d = medidas(), f = SAIDA_W / W;
        var tela = document.createElement('canvas');
        tela.width = SAIDA_W;
        tela.height = SAIDA_H;
        var g = tela.getContext('2d');
        g.fillStyle = '#fff';
        g.fillRect(0, 0, SAIDA_W, SAIDA_H);
        g.translate(SAIDA_W / 2 + e.x * f, SAIDA_H / 2 + e.y * f);
        g.rotate(e.rot * Math.PI / 180);
        g.drawImage(imagem, -d.dw * f / 2, -d.dh * f / 2, d.dw * f, d.dh * f);
        aplicar.disabled = true;
        try {
            tela.toBlob(function (blob) {
                aplicar.disabled = false;
                fechar(blob || undefined);
            }, 'image/jpeg', QUALIDADE);
        } catch (erro) {
            aplicar.disabled = false;
            fechar(undefined);
        }
    }

    function arrastar(ev) {
        if (!estado) {
            return;
        }
        ev.preventDefault();
        var x0 = ev.clientX, y0 = ev.clientY, bx = estado.x, by = estado.y;
        function mover(m) {
            if (!estado) {
                return;
            }
            estado.x = bx + m.clientX - x0;
            estado.y = by + m.clientY - y0;
            pintar();
        }
        function soltar() {
            window.removeEventListener('pointermove', mover);
            window.removeEventListener('pointerup', soltar);
            window.removeEventListener('pointercancel', soltar);
        }
        window.addEventListener('pointermove', mover);
        window.addEventListener('pointerup', soltar);
        window.addEventListener('pointercancel', soltar);
    }

    function ligar() {
        area.addEventListener('pointerdown', arrastar);
        area.addEventListener('wheel', function (ev) {
            if (!estado) {
                return;
            }
            ev.preventDefault();
            estado.zoom = Math.max(1, Math.min(ZOOM_MAX, estado.zoom - ev.deltaY * 0.0015));
            pintar();
        }, { passive: false });
        zoom.addEventListener('input', function () {
            if (estado) {
                estado.zoom = +zoom.value;
                pintar();
            }
        });
        janela.querySelector('[data-voto-foto-ajuste-girar]').addEventListener('click', function () {
            if (!estado) {
                return;
            }
            estado.rot = (estado.rot + 90) % 360;
            estado.x = 0;
            estado.y = 0;
            pintar();
        });
        janela.querySelector('[data-voto-foto-ajuste-cancelar]').addEventListener('click', function () { fechar(null); });
        aplicar.addEventListener('click', function () {
            if (estado) {
                recortar();
            }
        });
        // Clique no fundo escurecido (fora da janela) desfaz, como as outras janelas do Voto.
        janela.addEventListener('click', function (ev) {
            if (ev.target === janela) {
                fechar(null);
            }
        });
        document.addEventListener('keydown', function (ev) {
            if (!estado) {
                return;
            }
            if (ev.key === 'Escape') {
                ev.preventDefault();
                fechar(null);
                return;
            }
            // Teclado: as setas movem a foto quando o foco não está na barra de zoom.
            var passo = { ArrowLeft: [-6, 0], ArrowRight: [6, 0], ArrowUp: [0, -6], ArrowDown: [0, 6] }[ev.key];
            if (passo && ev.target !== zoom) {
                ev.preventDefault();
                estado.x += passo[0];
                estado.y += passo[1];
                pintar();
            }
        });
    }

    function abrir(arquivo, terminar) {
        if (!arquivo || !/^image\//.test(arquivo.type) || !preparar()) {
            terminar(undefined);
            return;
        }
        if (estado) {
            fechar(null);
        }
        var url = window.URL.createObjectURL(arquivo);
        var volta = document.activeElement;
        imagem.onload = function () {
            imagem.onload = null;
            imagem.onerror = null;
            estado = { url: url, w: imagem.naturalWidth, h: imagem.naturalHeight, zoom: 1, x: 0, y: 0, rot: 0, terminar: terminar, volta: volta };
            janela.hidden = false;
            aplicar.disabled = false;
            pintar();
            aplicar.focus();
        };
        imagem.onerror = function () {
            imagem.onload = null;
            imagem.onerror = null;
            imagem.removeAttribute('src');
            window.URL.revokeObjectURL(url);
            terminar(undefined);
        };
        imagem.src = url;
    }

    window.VotoFotoAjuste = { abrir: abrir };
})();
