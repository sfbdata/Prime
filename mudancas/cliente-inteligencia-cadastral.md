---
tipo: novidade
---
## Changelog
- Cadastro do cliente: bloco "Inteligência cadastral" no topo da aba Documentos (fatia F-A, só regras internas, sem leitura de documento nem consulta externa). Aparece para PF com CPF válido e PJ com CNPJ válido, carregado ao abrir a aba (`GET /cliente/{id}/inteligencia-cadastral`). Alertas no mesmo escritório: "Possível homônimo." (mesmo nome sem acento, caixa e pontuação, documento diferente) e "Mesmo CPF com outro nome." (outro cliente com os mesmos dígitos; pessoa da Cobrança só para quem acessa o módulo de cobranças). Nenhum CPF ou CNPJ no texto; o nome do outro cliente só aparece para quem pode vê-lo.
- "Encontrados no menu Documentos": documentos de identificação e comprovante de residência das pastas do cliente que o usuário vê (pasta excluída fica fora), fora da lixeira, ainda não vinculados e só da categoria que o cadastro ainda não tem. "Vincular ao cadastro" (`POST /cliente/{id}/inteligencia-cadastral/vincular/{documentoId}`, CSRF no header) liga o documento da pasta ao cadastro sem cópia do arquivo e grava no Histórico do cliente. Migration nova: `cliente_documento.pasta_documento_id` (FK com `ON DELETE CASCADE` e índice único por cliente).
- O documento vinculado abre e baixa pelo arquivo da pasta (com a permissão da pasta); remover o vínculo ou excluir o cliente nunca apaga o arquivo da pasta; documento da pasta na lixeira some da aba e das pendências do cadastro; o vínculo de pasta que o usuário não vê não aparece para ele; o vinculado não é editável na página do cliente, só desvinculado. A purga do escritório não trata o vinculado como arquivo do cliente.
- Textos do bloco num ponto único (`RotulosDaInteligenciaCadastral`), neutros e sem rótulo de IA, a confirmar com o dono.

## Para o cliente
- Na aba Documentos do cadastro do cliente, o sistema avisa quando há outro cliente com o mesmo nome e CPF diferente, ou o mesmo CPF com outro nome, e mostra os documentos de identificação e comprovantes que já estão nas pastas do cliente, para vincular ao cadastro com um clique, sem enviar o arquivo de novo.
