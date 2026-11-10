---
tipo: correcao
---
## Changelog
- Assinatura de documentos, conferência ✓/✗ das etapas 1, 2, 4 e 7 contra o desenho 17 (09/11/2026; decisão D6 em `docs/specs/assinatura-documentos.md`).
- Lixeira: o documento com prazo vencido continua Expirado e não ganha o evento "Envio cancelado pela exclusão"; só o que aguarda assinatura dentro do prazo é cancelado (`DocumentoParaAssinar::moverParaLixeira`).
- Trilha e tela pública com os textos do desenho: "Enviado para assinatura. Hash {16 caracteres}...", "Assinado por {nome digitado} · E-mail e código" (com ", selfie, localização" na Reforçada), "Recusado por {nome}: {motivo}", "Documento concluído", "Envio cancelado. Documento e evidências preservados" e o título "Assinatura" na aba da tela pública. Os textos são montados na leitura (`ApresentacaoDoDocumento`), então valem também para os eventos já gravados.
- Assistente: escolher um modelo depois de retomar um rascunho começa outro documento; o prazo vai como foi digitado e o servidor recusa fora de 1 a 90 com a regra escrita, em vez de a tela cortar em silêncio; "Salvar rascunho" volta à aba em que estava; "Alterar logo" aceita qualquer imagem; o signatário vindo do modelo com o campo do cliente vazio traz o nome "____________" para preencher (nome só de sublinhados conta como vazio no envio).
- CSS: sem mãozinha nos rótulos da logo e da ordem; a caixa de seleção da lista com 30px também no celular.

## Para o cliente
- Na Assinatura de documentos, o documento com prazo vencido que vai para a Lixeira continua aparecendo como Expirado, em vez de Cancelado.
- O histórico de cada documento ficou com os textos combinados: mostra o código do arquivo enviado, o nome que a pessoa digitou ao assinar e as frases de recusa, conclusão e cancelamento.
- Ao criar um documento, um prazo fora de 1 a 90 dias agora mostra o aviso com a regra, em vez de ser trocado sem avisar, e o nome do signatário que falta no cadastro aparece como uma linha em branco para preencher.
