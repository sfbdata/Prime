---
tipo: novidade
---
## Changelog
- Assinatura de documentos, etapa 8, bloco 8B (docs/specs/assinatura-documentos-etapa-8.md): cliente PHP do selo PAdES do BlueJus no PDF final. `SeladorDePdf` com a implementação nula (sem `ASSINATURA_VALIDADOR_URL` ou sem `ASSINATURA_VALIDADOR_TOKEN`, nenhum pedido sai) e a `HttpSeladorDePdf` (`POST /v1/selar` do `validador_pdf`, token Bearer, 45 s, teto de 20 MB, sem redirecionamento), escolhidas pela fábrica `EscolhaDoSeladorDePdf` em `services.yaml`.
- Cada resposta vira o PDF selado (conferido como acréscimo do original: começa com os bytes dele e é maior), nulo (`503 selo_nao_configurado`) ou `FalhaNoSelo` com o código e a classe da tabela 2.1 da etapa 6: passageira, configuração, definitiva ou certificado inválido. O conteúdo do PDF e o token nunca vão para o log nem para a exceção.
- `SeloDoPdfFinal::aplicar()`: o gancho que o gerador do PDF final (etapa 3, bloco 1b) chama antes de guardar o arquivo, só no worker. Grava `pdf_selado` ou `selo_nao_aplicado` (com o código da falha em `dados.motivo`) na trilha; a falha que não é definitiva relança para a mensagem tentar de novo e, na última tentativa, o PDF é guardado sem o selo. Ainda sem chamador: o gerador do PDF final não existe.
- Eventos novos da trilha `pdf_selado` e `selo_nao_aplicado`, com os textos. Sem migration.
- Testes: a tabela de respostas com `MockHttpClient` e a ida e volta pelo motor `ConferenciaDePdf` com PDFs selados pelo próprio validador com o certificado de teste (`app/tests/AssinaturaEletronica/Fixtures/Selo`).

## Para o cliente
- O PDF final do documento assinado passa a poder sair com o selo digital do BlueJus, que permite conferir que o arquivo não foi alterado depois de concluído. O selo entra em uso quando o certificado digital do BlueJus for instalado; até lá, o PDF final sai como hoje. A Assinatura de documentos continua desligada e só aparece para a equipe do BlueJus.
