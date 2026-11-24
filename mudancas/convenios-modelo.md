---
tipo: alteracao
---
## Changelog
- Convênios, PR 2 da spec `docs/specs/convenios.md`: migration `Version20261124120000` cria as tabelas `convenio` (global) e `convenio_vinculo` (escritório num convênio, com histórico) e grava a linha do Convênio OAB de hoje.
- Entidades `Convenio` e `ConvenioVinculo`, enums, repositórios e a leitura única `ConveniosDoEscritorio`. Sem vínculo gravado, o convênio OAB vale implícito (decisão de 24/11): nada muda na cobrança nem no Contribua.
- Painel do Master, seção Contas: o rótulo de convênio de cada conta passa a ser lido pela `ConveniosDoEscritorio` e some quando a chave geral do Convênio OAB está desligada.
- A purga do escritório apaga os vínculos; `ConvenioVinculo` só é consultado pelo próprio repositório (teste de arquitetura). A linha `oab` entrou nos dados de referência do CI.

## Para o cliente
- O BlueJus ganhou a base para cadastrar convênios com outras instituições além da OAB. Para os escritórios, tudo continua igual: quem tem o Convênio OAB segue com ele, do mesmo jeito.
- Na área do Master, quando o Convênio OAB é desligado, o aviso de convênio embaixo do plano de cada conta também sai.
