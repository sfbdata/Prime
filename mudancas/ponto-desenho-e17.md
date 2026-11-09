---
tipo: alteracao
---
## Changelog
- Ponto Eletrônico, etapa E17 do desenho 16 (VS 08.11.26): banco de horas por lotes (P51 a). Cada mês com saldo positivo vira um lote com vencimento no último dia do mês "competência mais prazo"; as horas negativas abatem primeiro os créditos mais antigos. O banco não zera mais em 1º de janeiro: `calcularSaldoAteMes` atravessa o ano e `calcularSaldoAnual` saiu (`BancoDeHorasPorLotes`, fonte única `saldosDoBancoPorCompetencia` no `FolhaPontoBuilder`).
- Horas pagas continuam no saldo, na competência do lançamento (P53), inclusive no saldo anterior do PDF e do XLSX de janeiro, que agora herda o banco de dezembro.
- Aba "Banco de Horas" no cartão de abas da /ponto (saldo atual, limite, prazo, tabela de lotes e a nota da lei), faixa amarela acima do limite (P52) e triângulo amarelo no número do topo (P2). Limite e prazo pela interface `LimitesDoBancoDeHoras` (padrão 120h e 6 meses; a configuração por escritório entra com a E7).
- Coluna "Saldo mês" do dia em andamento mostra o acumulado até ontem (campo novo `saldoAcumuladoAteOntem` da linha de hoje).
- Comando só de leitura `app:ponto:banco:comparar-virada`: saldo de cada pessoa antes (ano corrente) e depois (lotes), só com ids. Sem migration.

## Para o cliente
- O banco de horas do Ponto não zera mais na virada do ano: o saldo continua de um ano para o outro.
- Nova aba "Banco de Horas" no Ponto: mostra o saldo, o limite e o prazo de compensação, e cada mês com horas a compensar, com a data em que vence e a situação (no prazo, perto de vencer, vencido ou compensado).
- As horas que faltam num mês abatem primeiro as horas extras mais antigas.
- Quando o saldo passa do limite, o Ponto mostra um aviso amarelo para planejar a compensação.
- No dia de hoje, antes da saída, a coluna "Saldo mês" da folha já mostra o saldo acumulado até ontem.
- As horas pagas lançadas pelo gestor continuam contando no saldo.
