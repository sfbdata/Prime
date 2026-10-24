# Armadilhas que já custaram retrabalho

Cada item aconteceu de verdade e voltou em outro PR. Leia antes do CSS e de novo antes da
conferência ✓/✗: é a lista do que costuma passar.

## Sumário

1. Componente do sistema no lugar do desenho
2. Medidas, cores e fonte chutadas ou herdadas
3. O que só existe no JavaScript do desenho
4. Texto diferente do desenho
5. O que o sistema tinha e o desenho não, mantido
6. Item do desenho esquecido no primeiro passe
7. Desenho truncado ou desatualizado
8. Responsivo
9. Ícones
10. Travessão
11. O desenho promete o que o sistema não faz
12. CSS de terceiros (clearfix do AdminLTE)

## 1. Componente do sistema no lugar do desenho

- **Vincular processo** saiu com modal Bootstrap, Select2, `btn-*`, `card`, `alert` e `d-none`;
  foi refeito com a sobreposição e a lista de busca do desenho (#307).
- **Editar dados da pasta** usava modal Bootstrap: além da aparência, o Bootstrap travava a
  rolagem da página, e o desenho não trava. Faltava ainda o foco no número com o texto selecionado
  (#340). Componente do sistema traz **comportamento** do sistema, não só aparência.
- **Minhas Metas** passou por acerto retroativo de 4 PRs (#298 #302 #305 #309 → #310), ~100 ✗.
- **Mover para outra carteira** ganhou o X automático das janelinhas do sistema; o menu do desenho
  não tem X (#346).

## 2. Medidas, cores e fonte chutadas ou herdadas

- Painel do Master: fonte e `line-height` não eram os do `body` do desenho (16px/normal), e a
  fonte herdada anulava o negrito das abas. Declare a fonte do desenho na raiz da tela.
- Cartões de vaga: altura de linha e ícones diferentes do desenho.
- Raio de canto: a Carteira toda precisou de ajuste para 4px; botões do Objeto ficaram com 7px
  contra 4px (#135); telas legadas tinham 6 a 16px (#147). Raio é dos itens mais esquecidos.
- O guia antigo mandava usar o MCP do OpenPencil, que não existe nas sessões; o passo virou "tirar
  do CSS do próprio .dc.html" (#306). Não dependa de ferramenta que não está disponível.

## 3. O que só existe no JavaScript do desenho

O print não mostra; o arquivo tem. Foi a causa mais frequente de segundo PR.

- Expediente (#85): dicas do cabeçalho e da paginação, dica do responsável, "nenhuma dica com o
  menu aberto", "clicar na linha com menu aberto só fecha o menu", Nova Pasta permanecendo na lista,
  Limpar que **não** zera a Situação. Tudo estava em funções do JS do dc.
- Dica azul (`data-tip`) no escritório e no selo de presença esquecida no primeiro passe (#156).
- Agenda: dica nos compromissos, menu do dia por teclado, toque longo só no Mês, lembretes em
  outras abas.
- Comercial: "Cancelar envio" aparecia sempre; no desenho, só no envio programado. O teste de
  e-mail postava direto no clique em vez de abrir o modal "Enviar e-mail de teste" e mostrar
  "Teste enviado" (#222).

Leia cada handler (`onClick`, `ir`, `limpar`, `abrir`, `salvar...`) e cada condicional.

## 4. Texto diferente do desenho

- "Precisa de atenção": faltava "· de <criador>", e o vazio dizia "nesta lista" em vez de
  "Nada pedindo atenção." (#206).
- Página da meta: faltavam "Pasta: X · cliente", DEMANDA, "Sem descrição." e o formato de data
  "dd/mm/aaaa, hh:mm" (#310). Formato de data e separador `·` são texto do desenho.

## 5. O que o sistema tinha e o desenho não, mantido

O ciclo #206 → #252 → #310 nasceu de manter "porque já existia": filtro Status, legenda "em
aberto", nota dos grupos, modo lista, ⋮ do cartão, selo, contador, migalha, bloco Pessoas.
A lateral da meta tinha Excluir e Voltar à Pasta, que o desenho não tem. O papel do usuário estava
fora do selo do desenho 07. **Remova no primeiro PR** e trave a ausência com teste.

## 6. Item do desenho esquecido no primeiro passe

- Página da meta precisou de um segundo commit com os itens L1 a L8; a spec teve de receber "os
  itens do desenho que faltavam".
- Cobrança: selo "Cobrança ativa", contadores e área de arrastar arquivo foram para um PR de
  acabamentos (#207); chips da área vazia e Exportar .xlsx adiados (#135 → #141).
- Minhas Metas: aba Criei agrupada por prazo e "Seu andamento" independente dos filtros só no #206.

O inventário completo **antes** de codar é o que evita isso.

## 7. Desenho truncado ou desatualizado

O `.dc.html` chegou cortado em 256 KiB; #196 e #210 foram feitos com convenções provisórias e
refeitos no #257 quando o desenho inteiro entrou (#250). O seletor de modos ficou de fora porque
estava no trecho que faltava. Confira que o arquivo termina em `</x-dc>`/`</html>` (o script avisa)
e que é a versão vigente.

## 8. Responsivo

Expediente de 901 a 1280px seguiu uma decisão antiga (o Réu sumia, piso de 1262px) em vez do
desenho 03 (saem Ação, Responsável e Metas; o Réu aparece), e as porcentagens das colunas não
somavam 100% (#80). Cada `@media` do desenho é item do inventário; some as larguras.

## 9. Ícones

O sino é de contorno no sistema e cheio no desenho 03; um teste antigo fixava o contorno e o item
ficou pendente com o dono (#156). Cores dos ícones do Peticionar ficaram parciais (#130). Ícone
é `bi-*` com o mesmo nome, variante (`-fill`) e cor do desenho. Teste antigo que contradiz o
desenho é pergunta ao dono, não motivo para manter.

## 10. Travessão

Textos de tela tinham travessão; houve varredura e catraca por arquivo (#20,
`SemTravessaoNovoArquiteturaTest`). Texto do desenho com `—` ou `–` é reescrito e citado na
conferência.

## 11. O desenho promete o que o sistema não faz

Dica do Buscar no CNJ promete PJe e diários (#307); "Imagem até 10 MB" contra limite real de 3 MB
(#207); "Segurança e LGPD" promete criptografia que não existe (#344); o "Preencha:" esqueceu
uma etapa (#351). Isso vai ao dono **antes** de entregar, com a diferença explicada.

## 12. CSS de terceiros (clearfix do AdminLTE)

O AdminLTE traz `.card-header::after { display:block; clear:both; content:"" }`. Num cabeçalho
`d-flex` com `justify-content-between`, esse pseudo-elemento vira um **terceiro item** e empurra o
botão para o meio. Antes de culpar o próprio HTML por desalinhamento, abra o CSS computado da
classe herdada. É mais um motivo para não usar `.card`/`.card-header` como moldura do desenho:
classe própria não herda o clearfix.

## Como travar o acerto para não regredir

- Arranjo por filho direto (#204 #310 #340): `.grade > .trilho`, nunca só `.trilho`.
- Ausência do que saiu: `assertCount(0, ...)` para `.card-outline`, migalha, ⋮, modal Bootstrap.
- Valor a valor no CSS quando o valor é o ponto do acerto (raio 4px, cor do selo): o #310 testou
  as regras do arquivo de CSS da tela.
