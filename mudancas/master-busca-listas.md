---
tipo: novidade
---
## Changelog
- Master > Segurança e auditoria: busca na trilha (`q` em `GET /master/dados/auditoria`, `ConsultarAuditoriaUseCase::executar(busca:)`). Procura em responsável, conta, ação e detalhe, sem acento e sem maiúsculas, a partir de 3 letras (`BUSCA_MINIMO`; o termo é cortado em 100). Filtra a mesma janela traduzida (até 1000 linhas, 12 meses) junto com ação e conta, então paginação, pílulas e PDF valem para o resultado da busca. Não vai ao SQL: ação e detalhe são rótulos montados no PHP.
- Tela da trilha: campo de busca no cabeçalho, com atraso de 300 ms; a lista atual fica na tela até a resposta e o foco volta ao campo; resposta antiga que chega atrasada é descartada. O PDF diz a busca usada.
- Helper `public/js/master/bj-busca.js` (`window.BJBusca`: normalizar, termo, casa, atrasar), carregado antes do `painel.js` e do `masters.js`.
- Permissões > Masters: busca no navegador na lista de Masters (nome, e-mail, OAB) e no select "Promover a Master" (esconde as opções que não casam; a escolhida fica).
- Sem tabela, coluna nem migration.

## Para o cliente
- Na Central do Master, a trilha de Segurança e auditoria ganhou um campo de busca: basta digitar 3 letras de um nome, de uma conta ou de uma ação para achar o registro, sem precisar folhear as páginas. O PDF sai com o resultado da busca.
- Na aba de Permissões, a lista de Masters e a escolha de quem promover a Master também ganharam busca por nome, e-mail ou OAB.
