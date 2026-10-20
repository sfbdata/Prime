---
tipo: novidade
---
## Changelog
- Aba Push da pasta, cartão "Avisar cliente": bloco "Envio automático" (desenho de 18/10) com as chaves "Novas movimentações", "Audiências e intimações" e "Resumo semanal", por cliente (valem em todas as pastas de que ele é o cliente principal) e desligadas por padrão, e a linha fixa "Análise da BlueJus IA" com o selo "Só manual". Sem e-mail cadastrado, as chaves ficam esmaecidas e o clique abre o cadastro. Rota POST /pasta/{id}/push/cliente/automatico (editar a pasta e CSRF).
- Ao fim de cada captação do DJEN, o cliente com "Novas movimentações" ligada recebe por e-mail as movimentações novas da pasta (tipo, data, órgão e o texto oficial até 4.000 caracteres); com "Audiências e intimações", só as intimações e as designações de audiência. Um e-mail por e-mail ativo do cliente (pausado e descadastrado ficam de fora), cópia da Controladoria na primeira mensagem, Responder para o responsável da pasta, remetente com o nome do escritório e o "Não quero mais receber estes avisos". Segredo de justiça não leva conteúdo. A mesma movimentação não sai duas vezes para o mesmo cliente. Sem Controladoria, envia e avisa o primário uma vez por execução.
- Comando app:push:resumo-semanal-cliente (--tenant, --dry-run): toda segunda-feira, o resumo das movimentações da semana anterior de cada pasta cujo cliente ligou a chave, uma vez por semana. Linha de cron no DEPLOY.md.
- Histórico de envios da pasta: "Envio automático: intimação de dd/mm", "…: movimentação de dd/mm", "…: resumo semanal de dd/mm a dd/mm".
- Migration: tabela cliente_envio_automatico (auditada; incluída na purga do escritório).

## Para o cliente
- No cartão "Avisar cliente" da aba Push, o escritório pode ligar o envio automático ao cliente das novas movimentações, das audiências e intimações e de um resumo semanal toda segunda-feira, sempre com cópia para a Controladoria.
