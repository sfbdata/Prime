---
tipo: correcao
---
## Changelog
- Teste `VersaoDoPdfTest::testParaAssinar`: o cancelamento na Lixeira usava a data de hoje, mas o documento do teste foi enviado em 09/11 com prazo de 7 dias. Em 16/11 o prazo venceu, o documento não era mais cancelado e o teste falhava no master (Qualidade vermelha, Release sem tag). A data agora é fixa (10/11), dentro do prazo.

## Para o cliente
- Corrigimos uma verificação automática da Assinatura de documentos que passou a acusar um erro que não existia quando o prazo de 7 dias de um documento de teste acabou. Isso estava atrasando a publicação das novidades do Voto.
