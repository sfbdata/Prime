---
tipo: novidade
---
## Changelog
- Chat com o BlueJus IA na tela de uma pasta: novo interruptor "Incluir outras pastas do cliente" na barra da pasta (desligado por padrão, vale só na conversa aberta). Ligado, a IA lê também as outras pastas dos clientes da pasta aberta que a pessoa pode ver (no máximo 5 pastas, a aberta primeiro), e a barra mostra o aviso de dados pessoais.
- O servidor decide quais pastas entram: mesmo escritório, não excluídas, só as que a pessoa vê (cadeado), financeiro e abas decididos pasta a pasta. Pasta em segredo de justiça é omitida e a IA recebe só "N pastas não puderam ser consultadas". Pasta aberta em segredo continua recusada.
- O escritório precisa ter dado o aceite da BlueJus IA; sem ele a pergunta com outras pastas responde 409 `cruzamento_sem_consentimento`.
- `POST /central/ia/perguntas` aceita `escopo` (`pasta` ou `clientes`; `clientes` exige `pastaId`). Prompt `chat-central-v3`. Sem migration.

## Para o cliente
- Na barra da pasta, ligue "Incluir outras pastas do cliente" para a IA cruzar as informações das pastas do mesmo cliente. Dados pessoais seguem a configuração do escritório. Pastas em segredo de justiça não são lidas.
