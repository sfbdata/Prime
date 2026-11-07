---
tipo: correcao
---
## Changelog
- Chat I.A, contexto `metas` (painel da IA em Minhas Metas): a meta de pasta com algum processo em segredo de justiça (sigilo do tribunal ou "Sim" manual do cartão, regra 4 da Inteligencia) sai das listas antes de o bloco `<metas>` ser montado; o modelo recebe só quantas ficaram de fora, nunca título, NUP ou cliente. As listas também passam a tirar, por guarda explícita, meta de outro escritório. Novo `ProcessoRepository::idsDasPastasEmSegredoDeJustica` (o mesmo da branch `chat-ia-prazos-e-metas`).
- O contexto `metas` passa a exigir o módulo Tarefas, como a tela Minhas Metas: sem ele, o envio é recusado com 403 `sem_acesso` antes de qualquer leitura.

## Para o cliente
- O assistente de I.A. de Minhas Metas deixa de considerar as metas de processos em segredo de justiça. Ele só avisa quantas ficaram de fora, e você continua vendo todas na tela Minhas Metas.
- O assistente de I.A. de Minhas Metas só responde a quem tem acesso às metas do escritório.
