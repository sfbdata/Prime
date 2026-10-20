---
tipo: novidade
---
## Changelog
- Contribua: "Relatório de contribuições" no Painel do Master (desenho 08, chave "rel" da Integração bancária), na seção Visão geral. Grade dos últimos 12 meses (mês sem apoio aparece zerado) e tabela por conta (nome do escritório, total, quantidade e último apoio, maior valor primeiro, conta excluída marcada), em R$; só apoios confirmados entram nos totais, os só informados pelo escritório aparecem à parte. Duas consultas agregadas em `contribua_apoio` (`ApoioRepository::totaisPorMes` e `totaisPorConta`), sem e-mail, CPF ou CNPJ. Com a chave desligada, ou sem a integração salva, o cartão diz que o relatório está desligado e leva a `/contribua#integracao`. Rota `contribua_relatorio_master` (só ROLE_SUPER_ADMIN), incluída no painel por `render()`.
