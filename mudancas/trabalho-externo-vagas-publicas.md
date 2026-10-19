---
tipo: novidade
---
## Changelog
- Trabalho Externo: "Compartilhar vagas" gera um link público (`/vagas-juridicas/{token}`, só o hash do token é gravado) com as vagas abertas escolhidas, e o modal monta a mensagem para WhatsApp, e-mail, LinkedIn ou copiar.
- Página pública de vagas, sem login: mostra só cargo, local, tipo e áreas das vagas abertas do link; remuneração, escritório, contatos e requisitos não saem do servidor. Visões "Procuro vaga" e "Quero contratar", perguntas frequentes.
- Inscrição "Receba vagas da sua área por e-mail" com consentimento LGPD (data e versão do texto), CSRF e limite de tentativas por IP. Nenhum e-mail é enviado por esta entrega.
- `security.yaml`: `^/vagas-juridicas` com `PUBLIC_ACCESS`. `framework.yaml`: limitador `vagas_alertas`. Migration `Version20261019160801`: tabelas `trabalho_externo_link_vagas` e `trabalho_externo_interessado`.

## Para o cliente
- Agora dá para compartilhar as vagas da Central por WhatsApp, e-mail ou LinkedIn, com uma página pública que mostra só o essencial.
