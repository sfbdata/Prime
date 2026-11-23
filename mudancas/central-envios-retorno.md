---
tipo: alteracao
---
## Changelog
- Central de envios: todo campo e botão com `id` estável (prefixo do modo `mc` ou `mc-venda`), `name` e rótulo associado (`label for`, `aria-label` com o nome do item nos repetidos); `CentralDeEnviosAcessivelTest` confere os dois modos (M5.13).
- Botões bloqueados (Limpar lista, Encaminhar, leitura desligada, automação obrigatória) mostram o motivo escrito ao lado, ligado por `aria-describedby` (M5.14).
- Toda ação da Central termina com mensagem fixa de sucesso (`is-ok`) ou erro (`is-erro`) na linha do próprio bloco; "Enviando…" e "Salvando…" enquanto o botão está travado; o erro do resumo dos KPIs deixa de ser engolido (M5.14).

## Para o cliente
- No Painel do Master, cada campo e botão da Central de envios agora tem nome e identificação próprios, o que ajuda leitores de tela e o uso por assistentes.
- Quando um botão da Central está bloqueado, o motivo aparece escrito ao lado dele.
- Cada ação na Central (ligar uma automação, salvar ou excluir um modelo, registrar um descadastro, exportar, enviar) mostra uma mensagem de sucesso ou de erro que fica na tela, sem sumir sozinha.
