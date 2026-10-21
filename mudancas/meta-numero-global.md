---
tipo: novidade
---
## Changelog
- Número da meta no escritório, o "Nº 0001" do desenho 02: selo depois do título na aba Metas da pasta, no cartão de Minhas Metas e no detalhe da meta, com a dica "Meta N desta pasta · nº X na sequência geral do sistema". A sequência é por escritório (nunca o id) e nunca reaproveita número: a meta excluída não o devolve.
- Toda meta nova ganha o número ao nascer (`GravarMetaNovaUseCase`, contador `tarefa_numeracao` sob a trava `pg_advisory_xact_lock(4717, escritório)`), inclusive as criadas pelo "Criar tarefa" do Push e do drawer da IA, que usam o mesmo modal.
- Migration `Version20261020148700`, só estrutura: coluna `tarefa.numero` (nula), índice único `(tenant_id, numero)` e a tabela `tarefa_numeracao`. As metas antigas ficam sem número, e sem selo, até o comando `app:tarefa:numerar-metas` rodar uma vez depois da publicação (DEPLOY.md); ele numera por escritório, em ordem de criação, aceita `--dry-run` e `--escritorio=ID`, e rodar de novo não muda nada.

## Para o cliente
- Cada meta agora tem um número no escritório (Nº 0001, Nº 0002...), mostrado ao lado do título. As metas antigas ganham o número logo depois da atualização.
