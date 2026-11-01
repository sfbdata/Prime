---
tipo: alteracao
---
## Changelog
- O nginx de produção serve os 3 sites estáticos do repositório sfbdata/sites-bluejus no bluejus.com.br: o site institucional em `/` e nas páginas dele (como-funciona, duvidas, funcionalidades, gratuito, para-escritorios, seguranca, sobre, contato), mais robots.txt, sitemaps, `/_astro/` e `/imagens/`; o blog em `/blog` e o gratis em `/gratis`. Arquivo ausente responde 404 sem passar pelo PHP; o POST de `/contato` vai ao Symfony. Volume `./sites-publicados:/var/www/sites:ro` (fora do git).
- A rota `homepage` do sistema passa de `/` para `/inicio`; sair do sistema volta à tela de entrada (`target: app_login`). O "espaço esgotado" sem Referer volta para `/inicio`.

## Para o cliente
- O endereço bluejus.com.br passa a abrir o site do BlueJus, com o blog e a página do plano gratuito. O sistema continua no mesmo lugar: entrar pela tela de login leva ao Expediente, e sair do sistema volta à tela de login.
