---
tipo: correcao
---
## Changelog
- `symfony/process` passa de dependência só de desenvolvimento para `require` (mesma versão, v7.4.21). A imagem de produção instala com `--no-dev`, então o `ExecucaoDoGhostscript` não achava a classe `Process` e o Ghostscript nunca rodava em produção. A falha caía só no log, porque compressão e contagem de páginas são de melhor esforço.
- Efeito medido em produção (09/11/2026): nenhum dos 15.472 PDFs de `pasta_documento` tem `paginas` preenchido.
- Teste de arquitetura novo `DependenciasDeProducaoArquiteturaTest`: falha quando `src/` usa classe de pacote que só existe em `require-dev` (fixtures e Story ficam de fora: carregam só em dev e test).
- Depois de publicar, preencher as páginas do acervo com `app:documentos:calcular-hash --paginas`, fatiado com `--limite`.

## Para o cliente
- A redução de tamanho dos PDFs enviados para a pasta passa a funcionar no sistema publicado. Antes, o PDF era guardado sempre do tamanho original.
- O sistema passa a contar as páginas de cada PDF enviado. Sem essa contagem, a leitura de PDF pela IA da pasta recusava o documento.
