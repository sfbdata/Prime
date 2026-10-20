/* sw-lembretes.js: Service Worker do push dos lembretes da Agenda (entrega 5b; prometido no bj-lembretes.js
   do desenho: "o mesmo aviso chega por push e o servidor dispara no horário mesmo com o navegador fechado").
   Fica na raiz para valer para o site todo. Só mostra a notificação do sistema; com a página aberta, o
   cartão do lembretes.js usa a mesma tag (chave do lembrete), então o aviso não duplica. */
self.addEventListener('push', evento => {
    let d = {};
    try { d = evento.data ? evento.data.json() : {}; } catch (e) { d = {}; }
    const titulo = d.titulo || 'BlueJus · Lembrete';
    evento.waitUntil(self.registration.showNotification(titulo, {
        body: d.corpo || '',
        tag: d.tag || 'bj-lembrete',
        icon: '/icons/favicon-32x32.png',
        data: { url: d.url || '/agenda' },
    }));
});

self.addEventListener('notificationclick', evento => {
    evento.notification.close();
    const destino = new URL((evento.notification.data && evento.notification.data.url) || '/agenda', self.location.origin).href;
    // Se uma aba já está no destino (a Agenda, a pasta), foca ela; senão abre uma janela. Não leva uma aba
    // aberta em outra tela para o destino: ela pode ter um formulário pela metade.
    const caminho = new URL(destino).pathname;
    evento.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(janelas => {
        const noDestino = janelas.find(j => { const u = new URL(j.url); return u.origin === self.location.origin && u.pathname === caminho; });
        return noDestino ? noDestino.focus() : self.clients.openWindow(destino);
    }));
});
