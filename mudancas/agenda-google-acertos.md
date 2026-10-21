---
tipo: seguranca
---
## Changelog
- Google Agenda: a conexão pede só os escopos da agenda (sem herdar outras autorizações) e recusa e revoga token com permissões além das pedidas; o `id_token` tem cliente, emissor e validade conferidos; sem a chave que cifra o token a integração fica desligada e um retorno sem como guardar revoga a autorização em vez de dar erro; desconectar respeita a trava da sincronia; limitador nas rotas `/agenda/google/*`.
