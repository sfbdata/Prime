---
tipo: correcao
---
## Changelog
- Painel de presença do cabeçalho (a bolinha ao lado do avatar, "N on-line"): a lista mostrava só as iniciais, mesmo para quem tem foto, inclusive o próprio usuário. O `/central/equipe` já mandava a URL da foto (a mesma `app_profile_foto_serve` do avatar do topo), mas o `presenca-cabecalho.js` descartava o campo ao guardar a equipe. Agora a lista mostra a foto do perfil e deixa as iniciais só para quem não tem foto; a bolinha de status continua sobre o avatar. A cópia da equipe guardada na sessão do navegador mudou de chave, para não reaproveitar a antiga sem foto. A lista continua sendo só a equipe ativa do escritório.

## Para o cliente
- No painel de quem está on-line, cada colega aparece com a foto do perfil.
