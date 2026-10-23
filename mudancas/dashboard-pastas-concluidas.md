---
tipo: novidade
---
## Changelog
- Dashboard: nova coluna "Pastas concluídas" no Adicionar coluna, com as pastas que chegaram à etapa Finalizada do andamento no período, por responsável, e a seta de tendência contra o período anterior; voltar de fase ou reativar tira a pasta da conta, finalizar de novo conta na nova data e pasta excluída não conta.
- Pasta: a data de chegada à Finalizada passa a ser guardada (`pasta.finalizada_em`), também na troca entre via judicial e administrativa; as pastas já finalizadas recebem a data do andamento, e as arquivadas sem andamento, a do último arquivamento pelo Registro ou pela auditoria.
- Pasta: arquivar pelo "Editar dados" sai como na pílula (cartão "Situação da pasta" e andamento em Finalizada) também no caso de uso.
- Dashboard: tabelas `dashboard_zeramento` e `dashboard_meta_pastas` criadas para o Zerar de coluna e a meta de pastas (as telas vêm nos próximos PRs).

## Para o cliente
- Nova coluna "Pastas concluídas" no Dashboard mostra quantas pastas cada pessoa levou à etapa Finalizada no período.
