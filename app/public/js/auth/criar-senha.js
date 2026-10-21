/*
 * "Crie sua senha" do link de confirmação (spec docs/specs/cadastro-senha-no-link.md): só a régua de força.
 *
 * A mesma régua e os mesmos rótulos da troca de senha por código (entrada.js), iguais ao ForcaDaSenha do servidor.
 * Repetida aqui porque o entrada.js só roda na tela de entrada. Nada aqui decide: o servidor revalida tudo.
 */
(() => {
    'use strict';

    const FORCA = [['', '#c9d3da', '0%'], ['Fraca', '#c0392b', '25%'], ['Média', '#c76a12', '50%'], ['Boa', '#0a63c9', '75%'], ['Forte', '#1e7b45', '100%']];
    const tamanho = (p) => [...p].length; /* em caracteres, como o mb_strlen do servidor */
    const pontos = (p) => (!p ? 0 : (tamanho(p) >= 8) + (/[A-Z]/.test(p) && /[a-z]/.test(p)) + /\d/.test(p) + /[^A-Za-z0-9_]/.test(p));

    document.querySelectorAll('[data-forca]').forEach((caixa) => {
        const campo = document.getElementById(caixa.dataset.forca);
        const barra = caixa.querySelector('.criar-senha__barra');
        const texto = caixa.querySelector('.criar-senha__txt');
        if (!campo || !barra || !texto) {
            return;
        }
        const pintar = () => {
            const [txt, cor, largura] = FORCA[pontos(campo.value)];
            caixa.style.color = cor;
            barra.style.width = largura;
            barra.style.background = cor;
            texto.textContent = txt;
        };
        campo.addEventListener('input', pintar);
        pintar();
    });
})();
