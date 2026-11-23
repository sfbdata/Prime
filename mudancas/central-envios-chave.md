---
tipo: alteracao
---
## Changelog
- Central de envios: chave "Envio de campanhas" no topo (só Master), com o estado atual; a troca grava em `configuracao_global` (prefixo `comercial.`) e entra na Trilha de Auditoria. Enquanto o painel não grava nada, vale `COMERCIAL_CAMPANHAS_LIGADAS` (M8.1, M8.2).
- Com o envio desligado nada é recusado nem cancelado: "Enviar campanha" grava e a campanha espera; o e-mail de campanha já na fila volta a ela e confere de novo a cada 5 minutos; ao religar, as campanhas do Master que venceram no meio passam a valer agora e saem na volta seguinte. Datas comemorativas obedecem à mesma chave (M8.4, M5.1).
- Aviso fixo no topo com quantas campanhas estão esperando e aviso na confirmação de "Enviar campanha" quando o envio está desligado (M8.2, M5.11).
- KPI "Campanhas" deixa de contar os e-mails de teste; a descrição mostra quantos testes ficaram à parte (M8.3).

## Para o cliente
- No Painel do Master, a Central de envios ganhou um botão para ligar e desligar o envio de campanhas, com o estado atual à vista. Cada troca fica registrada na Trilha de Auditoria.
- Com o envio desligado, as campanhas programadas não se perdem: ficam esperando, e a tela avisa quantas são. Quando o envio é religado, elas saem em até 1 minuto. As mensagens das datas comemorativas seguem a mesma chave.
- Ao clicar em "Enviar campanha" com o envio desligado, a confirmação avisa que a campanha vai esperar.
- O contador de campanhas não soma mais os e-mails de teste; eles aparecem à parte.
