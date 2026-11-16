# Candidaturas conforme o tipo de pauta

## História

Como gestor da assembleia, quero identificar explicitamente pautas de eleição de cargo e cadastrar candidaturas com nomes, para que a cédula não publique opções genéricas `Sim` e `Não` como se fossem candidatos.

## Regra de tipo

- Cada pauta tem tipo explícito: `deliberação` ou `eleição de cargo`.
- Pautas novas são `deliberação` por padrão.
- Os atalhos Síndico(a), Subsíndico(a) e Conselho fiscal criam pautas do tipo `eleição de cargo`. A classificação vem do atalho acionado, não do título digitado.
- Uma pauta criada manualmente pode ter o tipo escolhido no formulário.
- Pautas existentes recebem o tipo `deliberação` na migração. Nenhum título antigo é usado para inferir tipo.

## Candidaturas e publicação

- Em rascunho, é permitido salvar uma pauta eleitoral sem candidaturas para preenchimento posterior.
- Os campos sem nome mostram as sugestões `Candidato 1` e `Candidato 2`; são placeholders, não valores salvos.
- A publicação de uma assembleia com pauta eleitoral exige ao menos duas candidaturas com nomes próprios.
- Em pauta eleitoral, nomes vazios ou as opções genéricas `Sim` e `Não` não contam como candidatura.
- A quantidade de vagas continua limitada à quantidade de candidaturas cadastradas.
- Pautas de deliberação mantêm o comportamento atual.

## Concorrência e conferência

- O tipo participa do resumo e do hash da conferência de publicação.
- Edição e publicação usam a serialização de configuração já aplicada no domínio.

## Migração de dados

A nova coluna recebe `deliberacao` para todas as pautas existentes. Nenhuma pauta é convertida automaticamente por título ou pelas opções atuais.
