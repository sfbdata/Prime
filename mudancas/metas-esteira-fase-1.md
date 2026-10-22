---
tipo: novidade
---
## Changelog
- Minhas Metas: "Esteira da pasta" do desenho de 22/10 (spec `docs/specs/minhas-metas-esteira-2210.md`, Fase 1). Oito caixas (Comercial a Encerramento) com as metas abertas e atrasadas de todo o escritório, o selo GARGALO na caixa com mais atrasadas e, ao clicar numa caixa, a lista das metas daquela etapa de toda a equipe (aba Todas, filtro `etapa`). Abaixo, o divisor "Suas metas · Individual" antes dos números pessoais.
- Cada meta ganhou a etapa da pasta (`tarefa.etapa`) e o tipo de demanda (`tarefa.tipo_de_demanda`, judicial por padrão; consultoria pula Documentação e Acompanhamento). Meta nova sem etapa recebe a deduzida pelo título. O cartão mostra "i/T · Etapa" e "próximo: Etapa · Setor".
- Modal "Configurar caixa" (⋮, clique direito ou duplo clique no "CAIXA N"): nome da caixa, nome da etapa e quem é avisado quando chega demanda (@ com a equipe ativa). Só o usuário primário e quem administra perfis configuram (decisão D2). O aviso em si sai na passagem de etapa (Fase 3); nesta fase a caixa só guarda quem será avisado. Rotas `tarefa_minhas_caixa_salvar` e `tarefa_minhas_caixa_restaurar` (POST com CSRF), desligadas junto com a tela Minhas Metas pelo Master.
- Migration `Version20261020149900`: colunas `etapa` (preenchida nas metas existentes pela regra do título) e `tipo_de_demanda` em `tarefa`; tabelas `tarefa_caixa_esteira` e `tarefa_caixa_esteira_avisado`.

## Para o cliente
- Minhas Metas agora mostra a esteira do escritório: em que etapa estão as metas de toda a equipe, quantas estão atrasadas e onde está o gargalo. Um clique numa caixa mostra as metas daquela etapa. O usuário primário pode renomear as caixas e já deixar marcado quem será avisado quando chegar demanda (o aviso começa a sair na próxima etapa da entrega).
