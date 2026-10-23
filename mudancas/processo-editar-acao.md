---
tipo: novidade
---
## Changelog
- Pasta, aba Processo: "Editar ação da pasta" no ⋮ do cartão do processo principal e no ⋮ do cabeçalho da aba (decisão do dono de 23/10/2026; desenho 02: "Ação · vem do menu Processo"). Formulário no padrão da edição no cartão (P5b), com XHR; rota `pasta_acao_salvar` (POST + CSRF), `EditarAcaoDaPastaUseCase` com a guarda de EDIT na pasta (teto do cadeado incluído) e do escritório; a lápide e o cadeado em Visualizar são recusados pelos listeners. A troca escreve o cartão do sistema "Dados da pasta: ação alterada pela aba Processo" no Registro, entra no audit_log e, se o nome da pasta mudou, enfileira a renomeação no Drive. Sem migration.

## Para o cliente
- Na aba Processo da pasta, o menu de opções agora tem "Editar ação da pasta". A alteração fica registrada no histórico da pasta.
