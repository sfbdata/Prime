# Push: editar a Análise IA com o selo "editado" (P4h)

Desenho: `docs/design/redesign-19-10-26/02 - EXPEDIENTES - VS 18.10.26.dc.html` (fora do git). Cartão N:2556-2628
(selo "editado" N:2566, kebab "Editar" N:2575, modo edição N:2602-2612, rodapé N:2615 e N:2621-2624); lógica
`pushIAVals` N:5375-5413 (`editar`, `salvar`, `cancelar`). Risco BAIXO, com regras do domínio Inteligencia.

O resto do cartão já existe no master (cadeado, recolher, lido, excluir, "Criar tarefa da providência" com prazo de 7
dias). "Enviar ao cliente" é do #98 (P4c).

## Decisões (orquestrador, 19/10)

- E1: a edição NUNCA sobrescreve a saída do modelo. `resumo`, `pontos`, `quem_age` e `texto_bruto` ficam como o modelo
  devolveu (regra 1 e 6 do `src/Inteligencia/CLAUDE.md`). A edição mora em colunas próprias: `resumo_editado`,
  `pontos_editados` (json), `editada_em`, `editada_por_id`, no padrão de `lida_em/lida_por`.
- E2: quem lê o quê. Cartão, .txt e link público (Push compartilhado) mostram a versão EXIBIDA (a editada, se houver):
  é o advogado corrigindo antes de o conteúdo chegar a alguém. O `<analise_anterior>` do próximo prompt continua com
  o resumo ORIGINAL do modelo (o bloco é "resposta anterior do próprio modelo"; texto humano não entra rotulado como
  saída da IA).
- E3: regras do desenho (`salvar`, N:5405): resumo vazio mantém o anterior; ponto vazio ou removido sai; não dá para
  incluir ponto nem trocar o tipo; "Quem deve agir" não é editável. Editar de novo parte da versão exibida.
  Salvar sem mudar nada não marca "editado" (o desenho marcaria; aqui é no-op honesto).
- E4: quem pode: a mesma regra do "Criar tarefa da providência" (C6-IA-03): ver a pasta, módulo `inteligencia`, EDIT
  na pasta, pasta viva (lápide recusada pelo `PastaSomenteLeituraListener`). Só análise `concluida` e não excluída.
  Sem limite de tempo (o desenho não traz).
- E5: limites: resumo até 2.000 caracteres, cada ponto até 500 (422 "Texto longo demais."). Quebra de linha e controle
  saem; o texto é escapado na tela (sem `|raw`).
- E6: trilha: a entidade é `Auditavel`; o changeset das colunas novas vai ao `audit_log` sozinho. O .txt ganha a linha
  "Editado por {nome} em dd/mm/aaaa HH:MM".

## Tela

- Selo "editado" (chip roxo claro) depois do aviso "Gerado por IA…", com a dica "Editado por {nome} em dd/mm às HH:MM".
- Kebab: "Editar" (`bi-pencil`) como primeiro item; rodapé: botão "Editar" antes de "Excluir". Só com E4.
- Modo edição no próprio cartão: textarea "Resumo da análise" (3 linhas), um campo por ponto com o chip do tipo e o X
  "Remover ponto", a nota "Conteúdo originalmente gerado por IA. Sua edição fica registrada como "editado"." e os botões
  "Salvar" (roxo) e "Cancelar". Cancelar restaura o que estava.
- Rota `POST /pasta/{id}/ia/analises/{analiseId}/editar` (`inteligencia_analise_editar`), campos `resumo` e
  `pontos[i]` (i = posição na lista exibida), CSRF `inteligencia_analise_{id}`.

## Testes mínimos

Unit (entidade): não toca o original, resumo vazio mantém, remoção e vazio saem, tipo preservado, sem mudança é no-op,
recusa fora de `concluida` e excluída, limites. Functional: edição grava e o cartão mostra o selo e o texto novo; .txt
e link público com o editado; prompt seguinte com o original; CSRF, sem EDIT (403), outro tenant e pasta irmã (404),
lápide, análise não concluída (409).
