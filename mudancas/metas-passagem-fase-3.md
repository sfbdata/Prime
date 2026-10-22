---
tipo: novidade
---
## Changelog
- Passagem de etapa da pasta, Fase 3 do desenho de 22/10 (spec `docs/specs/minhas-metas-esteira-2210.md`, seção 3.2 L11 a L15):
  - **Faixa ETAPA DA PASTA** no topo da página da meta, com as etapas feitas, a atual, a próxima e as saltadas.
  - **Concluir e passar adiante:** botão "Concluir etapa" no cartão de Minhas Metas e "Concluir e passar para X" na lateral da meta. O modal escolhe a próxima etapa (com salto ou volta, na página da meta), o tipo de demanda, o responsável, o prazo (2 dias úteis) e as pendências. Conclui a meta, cria a da etapa seguinte, grava o histórico com código de registro e avisa o responsável novo e os avisados da caixa.
  - **Devolver para X** na lateral, para o responsável, com os motivos fixos do desenho. Em revisão, o despacho de quem criou a meta a devolve para pendente.
  - Concluir uma meta (inclusive na aba Metas da pasta) passa a ser só do responsável ou de quem criou.
- Migration `Version20261020150200`: `tarefa.etapas_saltadas` e `tarefa_mensagem.destinatario_id`.

## Para o cliente
- Ao concluir uma meta, ela já passa a pasta para a próxima etapa, com responsável, prazo e pendências, e avisa quem vai cuidar. Quem recebeu uma meta pode devolvê-la explicando o motivo.
