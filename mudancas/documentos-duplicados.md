---
tipo: novidade
---
## Changelog
- Conector: `ler_documentos_e_modelos` ganha a ação de leitura `duplicados`. Sem `pasta_id`, totais do escritório e pastas com documentos repetidos (100 por página); com `pasta_id`, os grupos da pasta com os documentos de cada um. Critério `conteudo` (mesmo sha256) e `provavel` (mesmo nome e tamanho entre documentos ainda sem hash), separados. Segredo de justiça, cadeado e aba Documentos oculta saem e são contados; contrato sai com a aba Financeiro oculta. Nada é excluído.
- Conector: a `copiar` da `documentos_e_pecas` devolve um aviso de que a cópia deixa o arquivo repetido na pasta.
- `pasta_id` deixa de ser obrigatório no esquema da `ler_documentos_e_modelos` (continua exigido em todas as ações menos `duplicados`).

## Para o cliente
- Pelo Claude, agora dá para pedir um relatório dos documentos repetidos: quantos são e em quais pastas, ou, numa pasta, quais arquivos se repetem. É só consulta: nada é apagado.
- Quando o Claude copia um documento dentro da pasta, ele avisa que o arquivo passa a aparecer repetido.
