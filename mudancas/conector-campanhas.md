---
tipo: novidade
---
## Changelog
- Conector de I.A. (Painel do Master): nova ferramenta `campanhas_de_email`, só para o Master da plataforma (some do `tools/list`, da `quem_sou_eu` e do guia para os demais). Ações `criar`, `editar`, `cancelar` e `enviar_teste`, todas por proposta e `confirmar_acao`, um "sim" por ação.
- `criar` usa só modelo salvo (público `leads`, `prim`, `sec` ou `todos`; frequência e data opcionais) e o resumo traz modelo, assunto, texto puro, público, total, data e frequência, com aviso se o envio de campanhas estiver desligado. `editar` muda a agenda e, se pedido, regrava o conteúdo com a versão atual do modelo, só antes de a campanha começar a sair. A proposta guarda o hash do modelo ou do estado da campanha e recusa a confirmação se mudou.
- Reusa os UseCases do Comercial (`ConfirmarCampanhaUseCase`, `EditarCampanhaUseCase`, `EncerrarCampanhaUseCase`, `EnviarTesteDaCampanhaUseCase`); toda gravação vai ao `audit_log` com rota `conector_mcp` e escritório nulo. Nenhuma resposta traz e-mail de destinatário.

## Para o cliente
- O assistente de I.A. conectado ao BlueJus passa a ajudar o administrador da plataforma a cuidar das campanhas de e-mail: criar e agendar uma campanha a partir de um modelo salvo, mudar a data ou a frequência, cancelar e mandar um teste para o próprio e-mail dele.
- Antes de qualquer uma dessas ações, o assistente mostra o que vai acontecer (modelo, assunto, texto, para quem e quando) e só faz depois que a pessoa disser sim.
- Esse recurso é de quem administra a plataforma: os demais usuários do conector não veem essa ferramenta, e a lista de e-mails dos destinatários nunca aparece.
