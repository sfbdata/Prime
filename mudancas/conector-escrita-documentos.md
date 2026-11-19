---
tipo: novidade
---
## Changelog
- Conector de I.A. (spec mcp-remoto-oauth, PR 12): ferramenta de gravação `documentos_e_pecas`, com `acao` igual à chave da tabela de aba e chave e a ação fina em `operacao`. Toda ação exige o recurso `pasta` de editar, a pasta do escritório da chamada, fora de segredo e fora da lixeira.
- Diretas: mover, copiar, reordenar e favoritar documentos; criar subpasta; restaurar da lixeira; escrever peça (HTML pelo saneador ou texto simples convertido em parágrafos), destino da peça, usar modelo (preenchido no servidor), salvar modelo novo e favoritar modelo; checklist: adicionar, marcar e reordenar item, ligar ou desligar (com motivo ao desligar), salvar como modelo e aplicar modelo.
- Por proposta e `confirmar_acao`: editar documento, editar peça, renomear subpasta, salvar sobre modelo existente, editar texto de item e renomear modelo de checklist; e as exclusões: documentos para a lixeira, subpasta, item e modelo de checklist, modelo de peça.
- Ferramenta de gravação `financeiro_da_pasta`, só com o Financeiro da pasta visível. Diretas: situação do contrato (move o andamento), pro bono, valor da causa vazio, registrar, parcelar e marcar recebido ou desfazer pagamento, marcar ou desmarcar documento assinado. Por proposta: valor da causa já preenchido e corrigir o valor de um pagamento (os dois recusam se o valor mudou depois da proposta), excluir pagamento e excluir documento do Financeiro para a lixeira.
- As duas implementam `ExecutorDeProposta`: `reconferir` repete a guarda, a edição, o segredo, a aba e a lixeira antes de gravar. Mensagens de recusa fixas, sem dado de outra pasta.
- Ficam para o PR 14: renomear documento do Financeiro, listar modelos de checklist e excluir de vez da lixeira pelo conector (a purga apaga o arquivo antes do COMMIT da confirmação).

## Para o cliente
- Com o conector de I.A. ligado, o Claude ou o ChatGPT passa a organizar os documentos da pasta no BlueJus: mover, copiar, criar subpastas, favoritar, restaurar da lixeira, escrever peças e usar modelos, e cuidar do checklist da pasta.
- No Financeiro da pasta, o app de I.A. pode registrar e parcelar pagamentos, marcar o que foi recebido, mudar a situação do contrato e marcar documentos assinados, sempre com as mesmas permissões que você tem na tela.
- Excluir, trocar um texto já escrito ou corrigir um valor já preenchido nunca acontece direto: o BlueJus mostra um resumo e só grava depois da sua confirmação.
- Apagar de vez o que está na lixeira continua só pela tela do BlueJus.
