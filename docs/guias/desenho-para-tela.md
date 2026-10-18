# Implementar a partir de um desenho

Texto integral que morava no CLAUDE.md raiz até 18/10/2026. A regra resumida continua lá; aqui ficam
a origem, os exemplos e as armadilhas.

Vale sempre que existir um desenho aprovado (OpenPencil, captura, maquete). Escrito depois de uma
sessão em que a tela entregue não se parecia com o que o dono tinha aprovado, em **quatro** frentes
diferentes — e nenhuma delas foi pega por teste.

**A regra de decisão:**

> Onde o desenho **mostra algo diferente**, o desenho manda.
> Onde o desenho é **omisso**, a convenção do sistema manda.
> **Desviar do desenho aprovado exige perguntar antes** — nunca decidir e entregar.

O desvio típico não é preguiça, é convicção: "o saldo devia ter mais destaque que a contagem" virou
borda, divisória e fonte maior num lugar em que o desenho pedia quatro números iguais numa faixa
limpa. Julgamento de design é legítimo **como proposta**, nunca como entrega silenciosa.

**Antes de escrever front a partir de um desenho:**

1. `mcp__open-pencil__get_codegen_prompt` — a própria ferramenta diz *"Call before generating
   frontend code"*. Foi pulada na sessão que originou esta seção.
2. `mcp__open-pencil__design_to_tokens` para tamanhos, cores e espaçamentos. Valor que vem do
   documento não é opinião: é `1.875rem/700`, não "com destaque".
3. **Validar o desenho contra o dado que existe.** Um wireframe pode mostrar indicador que o sistema
   não tem — na sessão original havia um KPI "Em acordo" sem lastro (`StatusCaso` só tem `Ativo`,
   `Judicializado`, `Encerrado`). Descobrir isso na hora de implementar é tarde.
4. **Não escrever ressalva de "isto é só um wireframe" no artefato.** Na sessão original o próprio
   agente escreveu isso no `.jsx` — e foi essa frase que licenciou cada desvio depois.

**Depois de implementar, antes de entregar:** percorrer o desenho item a item (posição de cada
bloco, variante de cada botão, tamanho/peso/cor de cada texto) e reportar ✓/✗. Suíte verde **não
diz nada sobre aparência** — na sessão original 3.459 testes passaram com o layout visivelmente
quebrado, porque teste de PHPUnit lê HTML e não posição.

**O que dá para testar de verdade:** arranjo, com combinador de **filho direto** —
`.row > .col-lg-4 .trilho` distingue "está ao lado" de "existe em algum lugar da página", que era
verdade mesmo com o layout errado (ver `CarteiraArranjoTelaTest`). Estilo (borda, fonte, cor) segue
invisível para o teste.

**Armadilha de CSS herdado:** antes de culpar o próprio HTML por desalinhamento, conferir o CSS de
terceiros. O AdminLTE traz `.card-header::after { display:block; clear:both; content:"" }` — um
clearfix que, num cabeçalho `d-flex`, vira um **terceiro item** e empurra o botão para o meio com
`justify-content-between`.
