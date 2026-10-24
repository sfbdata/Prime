/*
 * Selo "EXCLUSIVO DO MASTER / DO PRIMÁRIO" (spec edicao-sem-prazo §5) nos itens de menu que o JS pinta (o ⋮ e o botão
 * direito do Registro, de Detalhes e do Financeiro). O botão que já nasce no servidor traz o selo pelo parcial
 * `_partials/_selo_sem_prazo.html.twig`; aqui é o mesmo span, com a mesma classe (menu-usuario.css).
 * `pintar(item, '')` tira o selo.
 */
(function () {
    'use strict';

    window.SeloSemPrazo = {
        pintar: function (item, rotulo) {
            if (!item) { return; }
            var velho = item.querySelector(':scope > .selo-sem-prazo');
            if (velho) { velho.remove(); }
            item.classList.toggle('com-selo-sem-prazo', !!rotulo);
            if (!rotulo) { return; }
            var selo = document.createElement('span');
            selo.className = 'selo-sem-prazo';
            var estrela = document.createElement('i');
            estrela.className = 'bi bi-star-fill';
            estrela.setAttribute('aria-hidden', 'true');
            selo.appendChild(estrela);
            selo.appendChild(document.createTextNode(rotulo));
            item.appendChild(selo);
        }
    };
})();
