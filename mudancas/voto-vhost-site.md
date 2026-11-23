---
tipo: interno
---
## Changelog
- vhost do Voto (`scripts/vps/vhosts/voto.conf.https-pendente`): `location = /` e `location ^~ /_voto/` servem do disco a página do BlueJus Voto (repositório dos sites, projeto `voto/`, em `sites-publicados/voto`); o resto continua no PHP do Voto. Runbook `voto-subir-na-vps.md`: publicar a página antes de ligar o nginx e conferir `/` e `/_voto/` com 200.

## Para o cliente
- O endereço voto.bluejus.com.br passa a abrir a página de apresentação do BlueJus Voto, com o botão para criar a conta gratuita, quando o Voto for ligado.
