---
tipo: novidade
---
## Changelog
- Aba Push da pasta: cartão "Avisar cliente" na coluna direita (desenho de 18/10), com os e-mails do cliente principal da pasta (lista por cliente, vale em todas as pastas dele): até 5, um principal (estrela), rótulos Pessoal, Trabalho, Familiar e Outro, switch "Recebendo avisos" / "Pausado" e remover com confirmação. O e-mail da ficha do cliente aparece como "Cadastro · principal" até a primeira alteração. E-mail descadastrado pelo cliente (assunto push_cliente) aparece com o selo "Descadastrado pelo cliente" e o switch travado. Histórico de envios da pasta (6 mais recentes). Rotas em /pasta/{id}/push/cliente (exigem editar a pasta e CSRF).
- Cartão da Análise IA: "Enviar ao cliente" / "Reenviar ao cliente" e "Enviado dd/mm às HH:MM", só na análise concluída e não interna. Modal "Enviar ao cliente" com Para, Cópia (CC) da Controladoria travada, Assunto, Mensagem padrão editável e o checkbox obrigatório "Revisei e corrigi o conteúdo". O envio sai um e-mail por destinatário (cópia da Controladoria só no primeiro), Responder para o responsável da pasta, remetente com o nome do escritório e o link "Não quero mais receber estes avisos" (List-Unsubscribe). Sem Controladoria cadastrada, envia e avisa o primário no sino. Limites: assunto até 200 caracteres, mensagem até 20.000, no máximo 10 envios por pasta por hora, e a mesma análise ao mesmo e-mail em 2 minutos não sai de novo. Exige editar a pasta e a BlueJus IA.
- Controladoria e envio isolados atrás de ControladoriaDoEscritorioInterface (provisória: ninguém, até o #75) e EnvioDeEmailAoClienteInterface (provisória: Symfony Mailer síncrono, espelhando a MensagemDeEmail do Carteiro da frente Comercial e e-mails).
- Migration: tabelas cliente_email e envio_ao_cliente (incluídas na purga do escritório).

## Para o cliente
- Na aba Push da pasta, o cartão "Avisar cliente" guarda os e-mails do cliente, e a análise da BlueJus IA pode ser enviada a ele por e-mail depois de revisada, sempre com cópia para a Controladoria do escritório.
