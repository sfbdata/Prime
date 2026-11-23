---
tipo: novidade
---
## Changelog
- Conector de I.A. (Painel do Master): nova ferramenta `modelos_de_email`, só para o Master da plataforma (some do `tools/list`, da `quem_sou_eu` e do guia para os demais). Ações: `criar`, `duplicar`, `salvar_como` (diretas), `editar`, `voltar_ao_padrao` e `excluir` (por proposta e `confirmar_acao`, com assunto e texto puro no resumo e o hash do modelo conferido na confirmação). `editar` vale também para os padrões do sistema, inclusive `data-<id>`, e grava a versão do Master (M5.8).
- Reusa os UseCases do Comercial; toda gravação vai ao `audit_log` com rota `conector_mcp` e escritório nulo. Importar HTML e editar o HTML de modelo HTML ficam fora (decisão 41).
- `ler_comercial` (`modelos` e `previa`) passa a ler a versão efetiva por `ModelosEfetivos`, e o conector vê o padrão editado.

## Para o cliente
- O assistente de I.A. conectado ao BlueJus passa a ajudar o administrador da plataforma a cuidar dos modelos de e-mail do Comercial: criar um modelo novo, copiar um que já existe, mudar o texto e apagar o que não serve mais.
- Antes de mudar ou apagar um modelo, o assistente mostra o assunto e o texto que ficarão e só faz depois que a pessoa disser sim.
- Esse recurso é de quem administra a plataforma: os demais usuários do conector não veem essa ferramenta.
