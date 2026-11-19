---
tipo: alteracao
---
## Changelog
- Tabelas das telas sem desenho próprio (Guia visual v30, frente A): a classe `.bjp-linhas`, num bloco no fim do `bj-padrao.css`, substitui o `table-hover` em 16 telas (Service Desk e Meus chamados, convites, feriados, auditoria, pedidos de acesso, mensagens do suporte, lista da agenda, convites da plataforma, processo e resultado da busca de processos, horas pagas, perfis do usuário, escritórios e usuários do escritório). A linha sob o cursor fica azul #2b7ab0, com faixa interna de 4px #1f6f9c e texto branco; botões, etiquetas, selos, campos e menus guardam as cores próprias e o botão discreto fica branco. A linha de detalhe que abre na auditoria não acende. Em Usuários do escritório sai o hover cinza local. A lista de pastas já tinha o realce próprio e fica como está. Teste `LinhasDeTabelaRealceV30Test`.

## Para o cliente
- Nas tabelas do sistema (chamados, convites, feriados, auditoria, agenda em lista, processos, usuários e outras), a linha sob o cursor agora fica destacada em azul, com o texto em branco, igual às telas novas.
