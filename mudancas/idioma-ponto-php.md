---
tipo: alteracao
---
## Changelog
- Ponto: os flashes e as respostas JSON de texto fixo dos controllers (justificativa, batida, feriados, jornada, home office, ajuste de batida, horas pagas e equipe) passam pelo tradutor no ponto de saída, no bloco novo `ponto_servidor`. O português fica idêntico, cada chave é conferida contra o texto antigo. As recusas das regras (exceções e Services) e o aceite da folha ficam como estão (PR B).

## Para o cliente
- No Ponto, as mensagens de confirmação e de erro que vêm do servidor (salvar configurações, feriados, jornada, ajustes de batida, horas pagas e justificativas) passam a aparecer em inglês para quem usa o sistema em inglês. Em português, os textos seguem os mesmos.
