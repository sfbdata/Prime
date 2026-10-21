# Destacar comentário na pasta (c1 da vistoria do desenho 02)

Desenho: `docs/design/redesign-19-10-26/02 - EXPEDIENTES - VS 18.10.26.dc.html`, modal L260-270, regra L4593-4607,
menu L6305, cartão L6306 e L1392/L1923/L2451. Pedido do dono (21/10, vistoria da pasta); a frente era a F-D planejada
da 9b e passou à 5d (coordenador, 21/10). Risco BAIXO. Migration `Version20261020147200` (nome do coordenador).

## Regra
- **Onde:** os comentários das três abas que têm registro: Dados da pasta ("Registro dos expedientes"), Detalhes
  ("Relatório inicial") e Financeiro ("Relatório financeiro"), incluindo as respostas.
- **Menu (botão direito e ⋮):** "Destacar" (ou "Destacar · <cor>" quando já tem) abre a paleta "Destacar comentário"
  com as 15 cores do dc e "Sem cor"; "Remover destaque" aparece quando o comentário está destacado.
- **Um destaque por pasta e aba; o último prevalece.** Destacar outro comentário na mesma aba desfaz o anterior, e o
  aviso diz de quem era. As outras abas mantêm o próprio destaque.
- **Compartilhado:** o destaque é do comentário, e todos os que veem a pasta o veem (o dc: "propriedade visual de cada
  mensagem"). Não é o `pasta_destaque` da lista do Expediente, que é pessoal.
- **Quem destaca:** só quem pode EDITAR a pasta (coordenador, 21/10). Para os demais o item não aparece.
- **Leitura:** só na tela da pasta, que já exige ver a pasta (tenant e cadeado). Pasta oculta não mostra nada.
- **Aparência:** o cartão ganha o fundo da cor e uma faixa à esquerda (dc L6306); o texto fica claro ou escuro pelo
  contraste da cor (`destEscuro`, dc L4591).
- **Trilha:** a tabela é `Auditavel` (inclusão, troca de cor e remoção vão ao `audit_log`).

## Modelo
`pasta_comentario_destaque`: tenant, pasta (CASCADE), aba (`dados`, `detalhes`, `financeiro`), uma FK por tipo de
comentário (`pasta_mensagem`, `pasta_observacao_detalhes`, `pasta_observacao_financeira`, cada uma CASCADE, só uma
preenchida), cor (hex da paleta), destacado_por (SET NULL) e destacado_em. UNIQUE (pasta_id, aba): é a regra do
"um por aba". Excluir o comentário apaga o destaque junto.

## Endpoint
`POST /pasta/{id}/comentario/destaque` com `aba`, `comentario`, `cor` (vazio = sem cor) e `_token`
(`pasta_comentario_destaque_{id}`). Devolve `{cor, desfeito: {comentario, autor} | null}`. 404 fora do tenant ou
comentário de outra pasta; 403 sem EDIT ou token inválido; 422 cor ou aba inválidas.
