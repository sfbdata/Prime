---
tipo: alteracao
---
## Changelog
- Editar e excluir sem prazo para Master e usuário primário (spec `docs/specs/edicao-sem-prazo-master-primario.md`):
  - Nos 8 pontos com prazo (registro e mensagem da pasta, observações dos Detalhes e do Financeiro, nota técnica, exclusão da meta, anotação e desfazer qualificação da cobrança, apagar mensagem do Trabalho Externo), o Master edita e exclui qualquer conteúdo a qualquer momento; o primário também, quando a opção do escritório está ligada (padrão). Os demais seguem o prazo de sempre.
  - Opção na página do perfil "Usuário Primário" em Perfis: "Usuário primário pode editar e excluir qualquer registro sem limite de tempo". Só um primário altera.
  - Selo dourado "EXCLUSIVO DO MASTER" ou "EXCLUSIVO DO PRIMÁRIO" ao passar o mouse no botão liberado por esse poder.
  - Despacho da meta com código continua imutável para todos. Meta com despacho registrado só é excluída pelo Master ou primário, com confirmação explícita.
  - Toda edição ou exclusão feita por esse poder fica na auditoria.
  - Na página da meta saiu o "(editado)".
- Migration `Version20261020150600`: tabela `escritorio_configuracao_edicao`.

## Para o cliente
- O Master e o usuário primário podem corrigir ou apagar registros a qualquer momento, inclusive de colegas. O primário pode desligar isso em Perfis. Os demais continuam com o prazo de sempre.
