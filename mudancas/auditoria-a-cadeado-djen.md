---
tipo: correcao
---
## Changelog
- Push Processual e cadeado da pasta (auditoria de segurança, lote A: 1.01, 3.06, 3.07, 3.08, 3.09). O processo cujas pastas são todas ocultas para a pessoa (K3) não abre por nenhuma porta do Push:
  - `PublicacaoDjenRepository::findOneByIdVisivelDoTenant()` devolve `null` para a publicação de número oculto. Passam por ele o detalhe (`/publicacoes/{id}/detalhe`, 404 e sem marcar lida), as partes (`/publicacoes/{id}/partes`, 404) e os UseCases de prazo e prazo cumprido ("Publicação não encontrada.", nada gravado).
  - `ProcessosDaOabRepository::relatorio()` recebe os ids ocultos (como `acompanhados()`). O Relatório, a contagem da sub-aba e a do flash de "Buscar processos da OAB" não mostram nem contam o processo oculto.
  - Parar, incluir, incluir do Relatório, incluir cadastrado, cadastrar (número que já existe) e anotações recebem `AcessoAPasta::idsDosProcessosOcultos()` e recusam o processo oculto com "Processo não encontrado.", sem gravar nada. Parar com vários números não grava nenhum se um deles for oculto.
  - Testes: `CadeadoNoPushTest` (funcional, 15 casos) e `CadeadoNosUseCasesDoPushTest` (unitário).

## Para o cliente
- Quem não pode abrir uma pasta trancada pelo cadeado também não vê mais, no Push Processual, os processos e as publicações dela. Ficam fora o texto da intimação, as partes e o processo no Relatório da aba Processos da OAB.
- Essa pessoa também não consegue mais mudar o prazo dessas publicações, marcar o prazo como cumprido, ligar ou desligar o acompanhamento do processo nem gravar anotações nele.
