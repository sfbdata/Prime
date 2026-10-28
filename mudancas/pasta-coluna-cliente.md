---
tipo: correcao
---
## Changelog
- Pastas: na coluna Cliente, o cliente principal de uma pasta comum passa a aparecer pelo nome fantasia (senão a razão social ou o nome completo), a mesma regra das pastas judicializadas. Cadastros com razão social composta ("a - b") deixam de aparecer assim na lista do Expediente.
- Pastas: o cartão da lista de demandas, os cartões e a tela de Metas, o resumo do processo, o financeiro da pasta, o relatório de registros do Dashboard, o LEIA-ME do ZIP de documentos e as análises de pastas paradas e sem processo passam a mostrar o mesmo nome da coluna Cliente, e não mais o texto composto gravado nas pastas antigas.
- O comando `app:cobranca:regravar-nome-pasta-judicial` também alinha as pastas que não são judiciais, têm cliente principal e ainda guardam texto composto ("a - b"): passam a guardar o nome do cliente principal. Simula por padrão e é idempotente.
