---
tipo: novidade
---
## Changelog
- Central de envios: "Enviar e-mail de teste" aceita de 1 a 5 endereços (vírgula, ponto e vírgula ou espaço), validados no servidor; o teto continua 10 testes por hora por Master, agora somando cada endereço pelas linhas de teste dele; descadastrado é bloqueado por endereço e a resposta diz quem não recebeu; cada teste entra na trilha como `camp_teste` só com `{total}`. Cai a regra C4 da spec (pedido do Farlei M5.17, 23/11/2026).
- Relatório de campanhas: "Encaminhar para um e-mail" deixa de estar bloqueado; `POST /master/comercial/campanhas/{id}/encaminhar` manda a cópia congelada da campanha como teste, pelas mesmas regras.

## Para o cliente
- No Painel do Master, o e-mail de teste de uma campanha pode ir para até 5 endereços de uma vez, não só para o próprio Master. Quem pediu para não receber campanhas continua sem receber, e a tela avisa quem ficou de fora.
- No Relatório de campanhas, a opção "Encaminhar para um e-mail" passou a funcionar: manda a campanha escolhida como teste para o endereço informado.
