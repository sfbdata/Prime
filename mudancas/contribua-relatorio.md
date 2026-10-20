---
tipo: novidade
---
## Changelog
- Contribua: "Relatório de contribuições" no Painel do Master (desenho 08, chave "rel" da Integração bancária), na seção Visão geral. Grade dos últimos 12 meses (mês sem apoio aparece zerado) e tabela por conta (nome do escritório, total, quantidade e último apoio, maior valor primeiro, conta excluída marcada), em R$; só apoios confirmados entram nos totais, os só informados pelo escritório aparecem à parte. Duas consultas agregadas em `contribua_apoio` (`ApoioRepository::totaisPorMes` e `totaisPorConta`), sem e-mail, CPF ou CNPJ. Com a chave desligada, ou sem a integração salva, o cartão diz que o relatório está desligado e leva a `/contribua#integracao`. Rota `contribua_relatorio_master` (só ROLE_SUPER_ADMIN), incluída no painel por `render()`.
- Contribua (bancos PIX): a hora do pagamento devolvida pelo banco (`horario` RFC 3339 da API Pix do BACEN e `occurrence_date` da Cora, quando vier com fuso) passa a ser convertida para o fuso do PHP antes de ser gravada; antes, `2026-10-31T01:30:00Z` ficava gravado como 31/10 01:30 em vez de 30/10 22:30, e o apoio caía no mês errado do relatório.
