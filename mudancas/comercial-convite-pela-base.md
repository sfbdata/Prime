---
tipo: novidade
---
## Changelog
- Central de envios do Master: novo público de campanha "Contatos salvos", que usa a lista de contatos salvos (a antiga "Base de contatos", agora com o nome "Contatos salvos" na tela e no botão; o público "Base de contatos" dos que confirmaram a lista continua como era). Convida contatos importados que nunca se cadastraram (convite profissional), sem a exigência de autorização que continua valendo no CSV avulso e no Individual. Para convidar alguém por CSV, importe antes para Contatos salvos, que guarda a origem de cada contato.
- Todo e-mail leva o link de descadastro e o cabeçalho List-Unsubscribe. Quem se descadastrou (inclusive por lista) nunca mais recebe.
- Cada contato salvo recebe no máximo 1 convite a cada N dias. O Master define N na Central de envios, ao lado da chave "Envio de campanhas": padrão 7 dias, de 1 a 365. A conta é por dia do calendário. Quem recebeu convite dentro do intervalo fica de fora da campanha, e a contagem mostra "N destinatários · M pulados pelo intervalo · D descadastrados".
- Ao criar o envio de uma campanha real para Contatos salvos, o sistema grava a data do último convite de cada contato, de uma vez só para a lista toda. E-mail de teste não conta. A campanha recorrente aplica o intervalo a cada execução. A cota diária de envio que já existia continua valendo. A troca do intervalo entra na trilha de auditoria.

## Para o cliente
- Na área de envio de e-mails da equipe do BlueJus, agora dá para convidar a lista de contatos salvos. Cada pessoa recebe no máximo 1 convite a cada poucos dias (7 por padrão, a equipe pode mudar), quem pediu para não receber nunca mais é incomodado, e todo e-mail traz o link para sair da lista.
