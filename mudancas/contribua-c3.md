---
tipo: novidade
---
## Changelog
- Contribua C3: assinatura mensal de espaço no cartão. "Quero +N GB" abre o checkout "Pagamento seguro"; no Asaas o cartão é digitado na página segura do gateway (cliente por escritório e checkout recorrente pela API).
- O webhook do gateway (`/api/webhooks/<gateway>`) só avisa: o BlueJus reconsulta a assinatura antes de mudar o espaço. Conciliação de hora em hora (`app:contribua:conciliar-assinaturas`).
- Modal "Pagamento por cartão · só Master": gateway, chave pública, links por pacote (fora do Asaas), página de retorno, carência da inadimplência (7 dias) e "Confirmar pagamento" manual.
- Cancelar no bloco "Assinatura" do Meu Perfil: o espaço vale até o fim do mês pago e nada é apagado.
- Limite próprio do Master nunca deixa um plano pago com menos espaço ou usuários do que o plano.

## Para o cliente
- Agora dá para ampliar o espaço do escritório com uma assinatura mensal no cartão, sem fidelidade. O espaço sobe assim que o pagamento é confirmado e você cancela quando quiser em Meu Perfil.
