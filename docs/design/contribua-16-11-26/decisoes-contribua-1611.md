# Decisões da Contribua (desenho de 14 e 15/11), 16/11/2026

Base: inventário `contribua-desenho-1611.md`. Regras do Samuel: fiel ao desenho, função do sistema não sai, extra só se
necessário; pedido do Samuel (fidelidade total) já vem aprovado. Preços e planos NÃO mudam.

| # | Decisão |
|---|---|
| D1 | "Configurar pagamento" e o modal do gateway continuam para o Master, no mesmo lugar de hoje (função do sistema não sai). Para o escritório, a tela segue o desenho. |
| D2 | Como o desenho: preços e opções de espaço extra ocultos até liberar (pedido aprovado pelo Master ou uso >= 97%). O servidor recusa `contribua_espaco_pedido` e o checkout sem liberação. Escritório que já paga um plano continua vendo e gerindo o próprio plano. |
| D3 | 97% fixo (como o desenho). Liberação automática vale enquanto uso >= 97%; a do Master fica gravada. Sem tela de revogar (o desenho não tem). Pop-up como o código do desenho (1 vez por dia; a 100% a cada carga). |
| D4 | Regra do sistema: só o primário (ou o Master) pede; 1 pedido pendente por escritório. Faixas 20, 50 e 100 GB = limites de Essencial, Profissional e Avançado. |
| D5 | "(inclui +N GB liberados)" = soma do extra que o sistema já tem (bônus de indicação vigente + limite próprio dado pelo Master). |
| D6 | Regra do sistema: "novos envios pausados" só com o bloqueio ligado; com ele desligado, o texto e o e-mail de 100% dizem que o espaço está cheio, sem prometer pausa. |
| D7 | Textos comerciais literais do desenho. Anotados no `pacote-juridico.md`. |
| D8 | Link do WhatsApp com o endereço do sistema vindo de configuração (não fixo). "Indicar" no menu do usuário e o botão da barra somem com a chave desligada (como `bj-compartilhar.js`). |
| D9 | Mantém o gatilho de 04/11 ("quando o escritório indicado começar a usar o BlueJus"); troca só a cauda para "O crédito expira em 3 meses." |
| D10 | Seção "Integração bancária" e prêmio do apoiador ficam como estão (estão no desenho). |
| D11 | Aviso na Central (`AvisoAoMaster`) sempre; e-mail ao Master só se o sistema já tiver o endereço configurado para avisos do Master. Sem caixa nova. |

Organização: branch de integração `contribua-gratuito`, etapas revisadas localmente, **um PR no fim** (redesign parcial,
09/11). Migration só na etapa 3: pedir o número ao Coordenador.
