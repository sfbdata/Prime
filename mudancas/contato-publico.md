---
tipo: novidade
---
## Changelog
- Rota pública `POST /contato` (form-urlencoded) para o formulário de Contato do site institucional: nome, e-mail, assunto (Suporte, Dúvidas comerciais, Parcerias institucionais, Sugestão de funcionalidade, Problema técnico), mensagem e consentimento=1, com honeypot `website`. Reaproveita o `EnviarMensagemSuporteUseCase` com origem `site` (sem migration): grava a mensagem, avisa o Master no sino e ela aparece na Central de Notificações como "Fale conosco". Cota por IP do limitador `suporte_entrada` numa chave própria (`site:<ip>`). Resposta JSON `{ok: true}` ou `{ok: false, erro}`.

## Para o cliente
- O formulário de Contato do site do BlueJus agora chega direto à equipe do BlueJus, que responde pelo e-mail informado.
