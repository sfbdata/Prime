---
tipo: alteracao
---
## Changelog
- Ponto Eletrônico, etapa E2 do desenho 16 (VS 08.11.26): a Folha de Ponto da /ponto ganha o molde do desenho (tabela `pe-tab`, barra do mês, selos, rodapé de totais e cartões no celular até 760 px), sobre o motor de hoje. Na /ponto, a folha mostra só os dias com batida e o feriado com horas (P25); o dia sem batida sai da tela e continua descontando do saldo. Lista de meses do início da contagem até o mês corrente (P23, D7). O "+" do esquecimento vale em qualquer dia passado com batida, sem hoje nem feriado, e o "Em análise" vai para a coluna Justificativa (P30). A ficha do colaborador usa o mesmo molde e continua com todos os dias do mês. PDF, XLSX e cálculo do saldo sem mudança. Sem migration.
- A spec docs/specs/ponto-desenho-0811.md registra as decisões da E2 e as chaves do HMAC por finalidade (`hash_hkdf` sobre o `PONTO_HMAC_SECRET`, com a versão da chave em cada registro).

## Para o cliente
- A Folha de Ponto está de cara nova: o mês aparece numa barra azul, cada dia mostra as batidas, o saldo do dia e o saldo acumulado do mês, e o rodapé traz os totais.
- Na sua folha aparecem os dias em que você bateu ponto e os feriados trabalhados. O dia sem batida sai da lista, mas continua contando no saldo, como antes.
- O botão "+" para justificar um esquecimento aparece em qualquer dia que já passou, inclusive no fim de semana. A justificativa em análise aparece na coluna Justificativa.
- No celular, cada dia vira um cartão fácil de ler.
- Na ficha do colaborador, o gestor vê o mesmo visual novo, com todos os dias do mês.
