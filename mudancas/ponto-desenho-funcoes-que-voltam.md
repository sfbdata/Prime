---
tipo: alteracao
---
## Changelog
- Ponto Eletrônico, desenho 16 (VS 08.11.26): voltam à /ponto três funções que as etapas E1 e E2 tinham tirado (regra 2, função do sistema não sai). Sem migration.
- Folha: o "+" do registro de esquecimento volta a valer no dia de hoje (`chaveDia <= dataHoje` em `_folha_table.html.twig`), como antes da E2. Feriado segue sem "+", e a batida de hoje que já tem esquecimento pendente ou abonado não oferece outro (`EsquecimentosRegistrados`).
- Cartão: durante o repouso, o aviso do dia mostra o tempo do intervalo ao vivo em HH:MM:SS, com ou sem a validação do repouso ("Intervalo em andamento há 00:42:10. O mínimo de 1h se completa em HH:MM.", "Intervalo mínimo de 1h cumprido. Em intervalo há 01:05:00." e, sem a validação, "Intervalo em andamento há 00:42:10."). O mínimo vem da Jornada do escritório. Usa o tique de 1 s do relógio do dia e a hora da batida de repouso que o cartão já tem, sem busca nova.
- Cartão: durante o repouso, o aviso também traz "Saída prevista às HH:MM.", quando há previsão.

## Para o cliente
- No Ponto Eletrônico, o "+" da folha também aparece no dia de hoje: se você esqueceu de bater a entrada hoje de manhã, já pode enviar o registro de esquecimento.
- Durante o intervalo, o cartão do ponto mostra há quanto tempo você está em intervalo, contando segundo a segundo, e a hora prevista da sua saída.
