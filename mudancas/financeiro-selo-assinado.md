---
tipo: novidade
---
## Changelog
- Pasta, aba Financeiro: selo "Assinado" na linha de cada arquivo, entre o nome e o menu ⋮, como no desenho 02 VS 18.10.26 (L1974-1976).
- Upload de documento da pasta (Financeiro, aba Documentos e peticionar): `.p7s`/`.p7m` e PDF com assinatura digital embutida (PAdES, ICP-Brasil, gov.br: `/Type /Sig` ou `/FT /Sig` com `/ByteRange` e `/SubFilter` conhecido) nascem assinados, origem `digital` (`DetectorDeAssinaturaDigital`, lê só o começo e o fim do arquivo, sem biblioteca nova). PDF assinado que a opção "reduzir tamanho" regravou nasce sem o selo, porque a assinatura não sobrevive à compressão.
- Menu ⋮ do arquivo no Financeiro: "Marcar como assinado" (assinado à caneta e escaneado) e, na marcação manual, "Desmarcar assinatura". Assinatura digital não se desmarca pela tela. Exige editar a pasta; pasta excluída fica somente leitura.
- Migration `Version20261020151000`: `pasta_documento.assinado_em`, `assinatura_origem` ('digital' ou 'manual') e `assinado_por_id` (FK user, ON DELETE SET NULL), todas nulas.
- Comando `app:pasta:detectar-assinatura-antigos` para os documentos que já existiam: sem `--aplicar` só lista e conta por escritório; com `--aplicar` grava a origem `digital`. Idempotente (pula o já marcado, digital ou manual, e nunca sobrescreve a marca manual), em lotes, lendo pelo armazenamento; arquivo ausente ou ilegível conta como erro e segue. Rodar uma vez depois da publicação, primeiro a simulação e depois `--aplicar` (DEPLOY.md, passo do dono).

## Para o cliente
- Na aba Financeiro da pasta, os arquivos assinados ganham o selo "Assinado". Arquivos com assinatura digital (gov.br, ICP-Brasil) são reconhecidos sozinhos no envio; contratos assinados à caneta podem ser marcados pelo menu do arquivo.
