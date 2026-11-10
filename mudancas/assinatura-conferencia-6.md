---
tipo: novidade
---
## Changelog
- Assinatura de documentos, conferência de 10/11/2026 (etapa 5, E5-12): o PDF que chega já assinado vira o original guardado mesmo quando o conteúdo não tem página dele. A tela manda o arquivo enquanto houver o quadro do já assinado (`w.pres`), o `SalvarRascunhoUseCase` confere o original de qualquer arquivo enviado e o `OriginalJaAssinado` leva o nome do arquivo (o documento sem página não tem nome de PDF). Sem PDF novo, o original fica enquanto o conteúdo tiver a página do PDF ou o parágrafo dele (o "Continuar e enviar" não perde o original) e sai quando o conteúdo é trocado; o Duplicar copia o original também sem página.
- Textos do PDF da Assinatura nas traduções (etapa 3, seção 2, item 10): `TextosDaFolha` vira objeto montado pelo tradutor com o bloco `pdf:` de `assinatura_eletronica_tela`; a folha, a moldura, o fundo de segurança, o selo e o `EscritorDePdf` (título e produtor) leem dele. O PDF sai byte a byte igual.
- Testes do cliente do validador de cadeia (etapa 6, seção 2.1): o cliente do container tem 45 s de `timeout` e de `max_duration` e nenhum redirecionamento, e o tempo esgotado é passageiro (aviso no log, sem erro).
- Spec da etapa 5: E5-10 (conferência fora do ar no passo 1), E5-11 ("Original não encontrado.") e E5-12 (original sem página do PDF).

## Para o cliente
- Na assinatura de documentos, o PDF que já chega assinado fica guardado como original mesmo quando você não coloca as páginas dele no documento, e o botão "Baixar original com as assinaturas" continua funcionando.
