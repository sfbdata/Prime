---
tipo: novidade
---
## Changelog
- Relatório mensal de pastas paradas (GET /expediente/pastas-paradas e /pdf, módulo Expediente): as pastas ativas sem movimentação há mais de 30 dias, da mais parada para a menos, com quatro cartões (pastas paradas, mais de 90 dias, sem contrato, sem processo), a tabela-resumo e um cartão por pasta com nível (Acompanhar, Atenção, Crítico), pré-análise (abertura, última movimentação, andamento, processo, contrato, documentos e financeiro) e sugestão. A última movimentação é a mais recente entre as movimentações dos processos, o Push, os registros de pessoas e a entrada na fase atual do andamento; sai da lista a pasta com andamento encerrado ou, sem andamento, com o processo arquivado. Cada leitor vê só as pastas que pode ver, e o financeiro só aparece para quem pode vê-lo.
- Envio automático pelo comando app:pastas:relatorio-pastas-paradas (primeira segunda-feira do mês, a partir das 10h, uma vez por escritório; --dry-run e --tenant): avisa no sino e na Caixa da Central (tipo relatorio_pastas_paradas) cada responsável com as pastas dele e a Controladoria e os primários com a lista, só quem tem o módulo Expediente. O mês é gravado antes dos avisos (tabela relatorio_pastas_paradas_envio, INSERT com ON CONFLICT), então duas execuções não duplicam. Linha do cron em DEPLOY.md.

## Para o cliente
- Novo relatório de pastas paradas: todo mês, na primeira segunda-feira, cada responsável recebe as pastas dele sem movimentação há mais de 30 dias, com uma pré-análise e a sugestão do que fazer, para decidir se a pasta vai receber movimentação ou ser arquivada. O relatório pode ser baixado em PDF.
