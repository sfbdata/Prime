---
tipo: novidade
---
## Changelog
- Chat com o BlueJus IA: a pergunta pode levar o número da pasta que a pessoa tem aberta na tela (`pastaId`). O servidor confere se a pasta é do escritório e se a pessoa pode vê-la (cadeado e permissão), recusa pasta com processo em segredo de justiça e só então envia à IA os dados da pasta (cadastro, processos, movimentações, metas, anotações, documentos e, se a pessoa pode ver, o financeiro). Sem `pastaId`, tudo segue como antes.
- Pasta inexistente, de outro escritório ou oculta para a pessoa: o mesmo aviso fixo "Não encontrei essa pasta ou você não tem acesso." (404). Pasta com segredo de justiça: recusada (409). Nada é gravado nessas recusas.
- Antes de chamar a IA o sistema confere de novo o acesso: quem perdeu o acesso à pasta (ou ao financeiro) entre a pergunta e a resposta tem a pergunta encerrada sem chamar a IA.
- Com pasta, a IA só recebe as perguntas anteriores da própria pessoa, nunca as respostas antigas dela (que podem ter lido outra pasta). Prompt novo `chat-central-v2`; o `chat-central-v1` segue para perguntas sem pasta.
- Registro da análise guarda só números e marcas (pasta, financeiro, abas ocultas), nunca texto nem número de processo. Nada vai para log nem auditoria.
- Backend apenas: ainda sem tela (a barra "PASTA N · cliente" e a sugestão "Faça um resumo desta pasta" vêm no 4b). Sem migration.

## Para o cliente
- Em breve, ao abrir a conversa com o BlueJus IA com uma pasta aberta, a IA poderá responder sobre aquela pasta. Esta entrega prepara o servidor; nada muda na tela ainda.
