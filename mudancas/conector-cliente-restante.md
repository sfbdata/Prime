---
tipo: novidade
---
## Changelog
- Conector de I.A.: `ler_clientes` ganha a ação `documentos_do_cliente` (lista só os dados dos documentos do cadastro que a pessoa vê na tela; documento de pasta em segredo de justiça ou sem acesso sai e é contado). `atualizar_cliente` ganha `excluir_documento` e `excluir_cliente`, sempre por proposta e com a mesma permissão da tela. Cliente com carteira de cobrança é recusado antes de propor e na confirmação. Os arquivos saem do disco só depois do COMMIT.
- A exclusão de cliente e de documento do cadastro foi extraída da tela para `ExcluirClienteUseCase` e `ExcluirDocumentoDoClienteUseCase`, sem mudar o que a tela faz. A leitura de documentos virou `ListarDocumentosDoClienteUseCase`.

## Para o cliente
- O assistente de I.A. conectado ao BlueJus agora consegue listar os documentos que estão no cadastro de um cliente, sem abrir o conteúdo, e só os que você já veria na tela.
- Também consegue propor a exclusão de um documento do cadastro ou de um cliente inteiro. Nada é apagado antes de você dizer sim, o aviso explica que não tem volta, e cliente com cobrança cadastrada não pode ser excluído.
- A exclusão pelas telas continua do mesmo jeito de sempre, com as mesmas permissões e os mesmos avisos.
