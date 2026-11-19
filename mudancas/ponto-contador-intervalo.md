---
tipo: correcao
---
## Changelog
- Ponto, janela "Antes de registrar": no retorno antes do mínimo do intervalo, a resposta da batida leva `contador: intervalo` e `faltamSegundos` (`IntervaloDaBatida::faltamDoIntervalo`, na hora do servidor) e a janela mostra um contador regressivo HH:MM:SS em destaque, ao vivo, com a mesma conta do aviso do dia (`segundosParaOMinimoDoIntervalo`), acertada pelo `faltamSegundos` (relógio do aparelho fora da hora não abre a janela em "cumprido" logo depois da recusa). Zerou com a janela aberta: texto de intervalo cumprido. "x", "Aguardar" e "Registrar assim mesmo" continuam.
- Aviso azul do intervalo no cartão: "Em intervalo desde 12:00 (há 00:04:22). Faltam 00:55:38 para completar o mínimo de 1h." O que falta passa a HH:MM:SS (antes "se completa em 00:56", que parecia segundos) e sem arredondar, igual ao contador da janela (antes 56 no aviso e 57 na janela).
- Textos novos no catálogo `ponto_intervalo.js` (português e inglês), pelo `BJ_T`.

## Para o cliente
- Ao registrar o retorno do intervalo antes do mínimo, a janela "Antes de registrar" agora mostra, em destaque, quanto tempo falta, contando de segundo em segundo. Quando o tempo acaba com a janela aberta, ela avisa que o intervalo mínimo foi cumprido.
- O aviso azul do intervalo no cartão ficou mais claro: mostra a hora em que o intervalo começou, há quanto tempo você está nele e quanto falta para o mínimo, com o mesmo número da janela.
