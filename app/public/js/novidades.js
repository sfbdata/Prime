// Selo de "novidade" no menu do usuário: aparece enquanto a pessoa não abriu a página Novidades
// desde a última versão. A última versão vista fica no próprio navegador (localStorage): sem
// banco, por dispositivo. Sem localStorage (modo privado bloqueado), o selo simplesmente não aparece.
(function () {
    var CHAVE = 'bluejus.novidades.vista';

    function ler() {
        try { return window.localStorage.getItem(CHAVE); } catch (e) { return null; }
    }

    function gravar(versao) {
        try { window.localStorage.setItem(CHAVE, versao); } catch (e) { /* sem armazenamento: ignora */ }
    }

    // Na própria página Novidades: marca a versão mais recente como vista.
    var pagina = document.querySelector('[data-novidades-vista]');
    if (pagina && pagina.dataset.novidadesVista) {
        gravar(pagina.dataset.novidadesVista);
    }

    var link = document.querySelector('a[data-novidades-versao]');
    if (!link) {
        return;
    }
    var maisRecente = link.dataset.novidadesVersao;
    var vista = ler();
    if (maisRecente && vista !== null && vista !== maisRecente) {
        link.querySelectorAll('.novidades-selo').forEach(function (selo) { selo.classList.remove('d-none'); });
    }
    // Primeira visita neste navegador: não acende o selo para tudo o que já existia.
    if (maisRecente && vista === null) {
        gravar(maisRecente);
    }
})();
