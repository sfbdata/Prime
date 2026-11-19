---
tipo: novidade
---
## Changelog
- Conector de I.A. (spec mcp-remoto-oauth, PR 13): ferramenta de gravação `processos_da_pasta`, com o recurso `pasta` de editar e a aba Processo. Diretas: vincular processo novo (cadastra e liga à pasta), já cadastrado ou apensado; definir o principal; preencher os dados do cartão ainda vazios e as marcas sim/não; ação da pasta vazia; via administrativa; criar nota técnica. Por proposta e `confirmar_acao`: desvincular, trocar dado do cartão ou ação da pasta já preenchidos, editar e excluir nota técnica. Dados do cartão e nota técnica pedem também o recurso `processo` de editar.
- `processos_da_pasta` recusa vincular quando o cliente principal recebe o envio automático do Push, e recusa processo em segredo de justiça (do tribunal ou do cartão). O campo de segredo do cartão não é exposto. Excluir processo fica para o PR 14.
- Ferramenta de gravação `gravar_na_agenda`, com o módulo `agenda`. Diretas: criar compromisso, concluir, cancelar ou reabrir (cancelar avisa os participantes) e avisar colegas de compromisso ou prazo. Por proposta: editar, mover e excluir. Editar, mover, mudar a situação e excluir valem só para compromisso que a pessoa criou; participantes e avisados têm de ser da equipe ativa. A resposta lembra que o compromisso vai para a agenda Google de quem a ligou.
- `ler_processos` ganha a ação `cnj`: consulta ao DataJud pelo número, a partir de uma pasta (recurso `pasta` de editar e aba Processo). Antes de consultar, procura o número no escritório: processo em segredo recusa e o CNJ não é chamado. Partes só pelo nome.
- A consulta ao DataJud sai do `ProcessoController` para o `ConsultarProcessoNoCnjUseCase`, usado pela tela e pelo conector, com as mesmas respostas e mensagens de hoje.
- Toda proposta guarda o valor de antes e recusa na confirmação se ele mudou depois da proposta.

## Para o cliente
- Com o conector de I.A. ligado, o Claude ou o ChatGPT passa a cuidar dos processos da pasta: vincular processo, escolher o principal, preencher os dados do cartão, a ação da pasta e escrever notas técnicas.
- Na Agenda, o app de I.A. pode criar compromissos, marcar como concluído ou cancelado e avisar colegas. Mudar, mover ou excluir só vale para compromisso que você mesmo criou.
- O app de I.A. também pode consultar um processo no CNJ pelo número, como o botão da tela da pasta. Processo em segredo de justiça não é consultado.
- Trocar o que já estava preenchido, desvincular ou excluir nunca acontece direto: o BlueJus mostra um resumo e só grava depois da sua confirmação.
- Para a tela de Processos nada muda: a consulta ao CNJ continua igual, com as mesmas mensagens.
