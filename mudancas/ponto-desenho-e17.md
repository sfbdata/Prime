---
tipo: alteracao
---
## Changelog
- Ponto Eletrônico, etapa E17 do desenho 16 (VS 08.11.26): banco de horas por lotes (P51 a). Cada mês com saldo positivo vira um lote com vencimento no último dia do mês "competência mais prazo"; as horas negativas abatem primeiro os créditos mais antigos. O banco não zera mais em 1º de janeiro: `calcularSaldoAteMes` atravessa o ano e `calcularSaldoAnual` saiu (`BancoDeHorasPorLotes`, fonte única `saldosDoBancoPorCompetencia` no `FolhaPontoBuilder`).
- Horas pagas continuam no saldo, na competência do lançamento (P53), inclusive no saldo anterior do PDF e do XLSX de janeiro, que agora herda o banco de dezembro.
- Aba "Banco de Horas" no cartão de abas da /ponto (saldo atual, limite, prazo, tabela de lotes e a nota da lei), faixa amarela acima do limite (P52) e triângulo amarelo no número do topo (P2). Limite e prazo pela interface `LimitesDoBancoDeHoras`, lidos das Configurações do Ponto de cada escritório (`LimitesDoBancoDeHorasDoEscritorio`, E7: prazo de 1 a 24 meses, limite maior que zero; fora disso ou sem configuração, 120h e 6 meses). A linha do escritório é lida uma vez por pedido.
- A mesma jornada em todo lugar (spec jornada §10.4, `FolhaPontoBuilder::jornadaDaFolha`): a pessoal ou, sem ela e nos dias antes da primeira versão, a do escritório. Vale para o banco, o saldo anterior, as linhas da folha na /ponto, no PDF, no XLSX e na ficha do gestor; antes o banco, a ficha e as exportações contavam zero nesses dias. O total "Saldo do mês" da /ponto e da ficha passa a ser a conta do banco para a competência, sem as horas pagas: inclui os dias sem batida depois da última batida do mês e, na ficha, também o mês sem batida nenhuma.
- Coluna "Saldo mês" do dia em andamento mostra o acumulado até ontem (campo novo `saldoAcumuladoAteOntem` da linha de hoje).
- Comando só de leitura `app:ponto:banco:comparar-virada`: saldo de cada pessoa antes (ano corrente) e depois (lotes), só com ids. Sem migration.

## Para o cliente
- O banco de horas do Ponto não zera mais na virada do ano: o saldo continua de um ano para o outro.
- Nova aba "Banco de Horas" no Ponto: mostra o saldo, o limite e o prazo de compensação, e cada mês com horas a compensar, com a data em que vence e a situação (no prazo, perto de vencer, vencido ou compensado).
- As horas que faltam num mês abatem primeiro as horas extras mais antigas.
- Quando o saldo passa do limite, o Ponto mostra um aviso amarelo para planejar a compensação.
- No dia de hoje, antes da saída, a coluna "Saldo mês" da folha já mostra o saldo acumulado até ontem.
- As horas pagas lançadas pelo gestor continuam contando no saldo.
- O total "Saldo do mês" da folha passa a contar também os dias sem registro de ponto depois do último registro do mês. Assim, a soma dos meses da folha é igual ao banco de horas.
- Na ficha do colaborador vista pelo gestor, o mês sem nenhum registro de ponto e com horas pagas passa a mostrar as faltas do mês no "Saldo do mês" (antes mostrava 0h), e o "Total do mês" muda junto.
- O limite e o prazo do banco de horas são os que o escritório salvou nas Configurações do Ponto. Sem configuração, valem 120 horas e 6 meses.
- Quem não tem uma jornada própria cadastrada no Ponto passa a ter as faltas contadas pela jornada do escritório, em todas as telas do Ponto e na folha de ponto (PDF e planilha). Antes, esses dias não contavam no banco de horas. Por isso, o saldo de algumas pessoas pode ficar negativo.
