---
tipo: novidade
---
## Changelog
- Painel do Master, Planos e limites: chave geral do Convênio OAB (pedido do Farlei M2.1), gravada em `configuracao_global` como `convenio.ativo` (prefixo `convenio.`, fora do catálogo, como `ia.` e `comercial.`). `POST /master/convenio` (`AlterarConvenioAtivoUseCase`, CSRF `master_convenio`, só `ROLE_SUPER_ADMIN`), com registro na Trilha de Auditoria no escopo de plataforma. A leitura é da `ChaveDoConvenio`: sem nada gravado, vale ligada.
- Desligada: somem a faixa e a tag do Plano Free no painel; no Contribua somem a seção "Convênio OAB", o atalho "Por que é gratuito", o selo do plano gratuito e o modal de benefícios. Planos, preços e assinaturas não mudam.
- A chave é discreta, em lilás (só do Master), com o atalho para o painel "Convênio OAB por estado" da seção Contas.
- Sem tabela, coluna nem migration.

## Para o cliente
- No Painel do Master, em Planos e limites, há agora uma chave para ligar e desligar o Convênio OAB no sistema inteiro, com um atalho para o convênio por estado.
- Com o convênio desligado, a página "Quem somos e contribua" deixa de mostrar a parceria com a OAB e o selo do plano gratuito. Os planos e os valores continuam os mesmos.
