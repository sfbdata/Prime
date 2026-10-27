---
tipo: novidade
---
## Changelog
- Trabalho Externo, Nova solicitação: os botões "Novo escritório" e "Salvar edição" da seção SOLICITANTE passam a funcionar. "Novo escritório" guarda nome, e-mail, telefone, UF e município digitados e já o seleciona; "Salvar edição" aparece só com um escritório guardado selecionado e dados alterados.
- Os escritórios guardados ficam numa tabela própria do usuário (`trabalho_externo_escritorio_solicitante`, migration `Version20261027153100`), listados depois dos escritórios da conta no mesmo select. O escritório da plataforma (Tenant) nunca é criado nem alterado por aqui, e um escritório guardado nunca vira o escritório da solicitação.
- Novas rotas `POST /trabalho-externo/api/escritorios-solicitantes` e `POST /trabalho-externo/api/escritorios-solicitantes/{id}` (CSRF, só o dono; id de outro usuário é 404). Teto de 20 por usuário (409). Nome vazio: 422 "Informe o nome do escritório para salvar."

## Para o cliente
- Na tela Nova solicitação do Trabalho Externo, você pode guardar os dados de um escritório que usa com frequência (botão "Novo escritório") e corrigi-los depois (botão "Salvar edição"). Eles ficam só para você e aparecem na lista de escritórios nas próximas solicitações. Cada pessoa guarda até 20.
