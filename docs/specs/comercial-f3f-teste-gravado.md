# Spec: F3f do Comercial, o envio de teste novo e gravado (desenho 05 VS 21.10.26)

Risco: BAIXO (tela do Master e um registro a mais por teste; sem migration). Combinado com a 5d (bluejus-e3), dona do
editor (F3a, #218), e com a 9b (Relatório, #209). Decisões da coordenação (21/10/2026): o teste é gravado e conta no
KPI "Campanhas" (decisão 2); o destino segue só o Master logado ("qualquer endereço", pergunta 1, está com o dono).

## Tela (dc L497-498, L668-669)

- O ícone de teste da barra do editor (`[data-mc-cp-teste]`, L290) abre "Enviar e-mail de teste" (`bi-send-check`):
  ENVIAR PARA (separe vários por vírgula) com o e-mail do Master, TRAVADO, e a nota "Por enquanto o teste vai só para o
  seu e-mail; enviar para outros endereços depende de decisão."; a nota do desenho "O teste chega com [TESTE] no assunto
  e não entra nas métricas da campanha."; Cancelar e "Enviar teste" (`bi-send`). Erro em vermelho, embaixo.
- Enviado: "Teste enviado" (`bi-check2-circle`), "Para {e-mail}. Confira a caixa de entrada (e o spam) em instantes.",
  Ok. O Relatório volta para a aba Enviadas e recarrega (evento `mc:relatorio-recarregar`).
- Partial `templates/master/_comercial_teste.html.twig`, JS `public/js/master/comercial-teste.js` (ouve o clique em
  captura e usa `window.McCampanha.modeloAtual()`). O clique do ícone deixou de postar direto no `comercial-campanha.js`.

## Gravação (`EnviarTesteDaCampanhaUseCase`)

- O assunto vai com "[TESTE] " na frente.
- O Carteiro envia primeiro (o descadastrado continua bloqueado e registrado, sem linha de teste). Enfileirado, numa
  transação: a linha em `comercial_campanha` com estado `teste` (fora de `CampanhaDeEmail::ATIVAS`: o expansor nunca a
  pega), público `teste`, total 1, uma execução; o destinatário em `comercial_campanha_destino`, ligado ao envio
  (`envio_id`).
- O Relatório mostra a linha em Enviadas com o selo "Teste" e o público "Teste · N e-mail(s)" (L717); o KPI "Campanhas"
  (`contarNaoCanceladas`) a conta (L690).
- O teto continua 10 por hora por Master (contado pelo destinatário na origem `teste_campanha`).
- O visualizador (`/master/comercial/campanhas/{id}`, L709) usa o mesmo rótulo de público da lista
  (`ListarRelatorioDeCampanhasUseCase::rotuloDoPublico`).
- O endereço do Master não vai para `publico_parametros` (nulo): ali a retenção não anonimiza; o destino já é anonimizado.
- Se a gravação falhar depois do envio, o e-mail já saiu: a tela recebe a recusa "O teste foi enviado, mas não entrou no
  Relatório. Não precisa enviar de novo." em vez de um 500.
