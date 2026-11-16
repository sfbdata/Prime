---
tipo: interno
---
## Changelog
- Teste `VersaoDoPdfTest::testParaAssinar`: o cancelamento na Lixeira usava a data de hoje, mas o documento do teste foi enviado em 09/11 com prazo de 7 dias. Em 16/11 o prazo venceu, o documento não era mais cancelado e o teste falhava no master (Qualidade vermelha, Release sem tag). A data agora é fixa (10/11), dentro do prazo.

## Para o cliente
- Ajuste interno nos testes automáticos da Assinatura de documentos. Nada muda na tela: o sistema continua igual, só deixa de acusar um erro falso que atrasava a publicação de novidades.
