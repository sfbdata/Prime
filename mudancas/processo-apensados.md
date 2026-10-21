---
tipo: novidade
---
## Changelog
- Pasta, aba Processo: botão "Vincular apensado" abre o modal de vínculo em modo apenso, com o "Tipo de apenso" (Embargos, Agravo de instrumento, Cumprimento de sentença, Execução, Impugnação, Recurso, Cautelar, Outro); o cartão do apensado mostra "Apensado · Tipo" e o X que o desvincula, com confirmação (desenho 02 VS 18.10.26).
- Pasta: nova coluna `pasta_processo.tipo_apenso`; o vínculo que não é o principal é apensado (sem tipo gravado, "Outro") e apensado nunca vira principal por omissão.
- Pasta, timeline de Dados: cartões do sistema "apensado X (Tipo) vinculado ao processo principal Y." e "apensado X desvinculado.".
- Pasta, aba Dados: a pílula do Processo vinculado passa a contar e listar os apensados, com o tipo, a ação e "classe · tribunal · situação".
- Pasta, aba Push: chips por processo (Todos os processos, Principal e um por apensado, com contagem) antes de Todas/Novas/Geram prazo, e a fonte da movimentação de apensado ganha " · Apensado · Tipo número".
- Pasta: a confirmação de marcar "Administrativo sem processo" com processo vinculado avisa que o andamento passa para a via administrativa.

## Para o cliente
- Na aba Processo da pasta, vincule processos apensados (embargos, agravo, execução e outros); eles aparecem também em Dados da pasta e no Push processual, onde dá para filtrar as movimentações por processo.
