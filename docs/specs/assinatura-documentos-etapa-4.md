# Assinatura de documentos: etapa 4 (contrato)

Etapa 4 da spec `docs/specs/assinatura-documentos.md` (seções 1.8, 4, 5.2, 5.4 e D5): modelos do escritório. Vale
tudo o que os contratos das etapas 1 e 2 fixaram e não é mudado aqui (`assinatura-documentos-etapa-1.md`,
`assinatura-documentos-etapa-2.md`). Desenho: `docs/design/redesign-08-11-26/17 - Assinatura - VS 08.11.26.dc.html`
(cópia principal do repositório; `bj-assina-modelos.js` na mesma pasta). Números entre parênteses (dc:N) são linhas
do desenho.

## 1. Escopo

Entra:
- aba **Modelos** inteira: "Criar novo modelo", modelos do escritório com Editar e Excluir (com confirmação, D5) e os
  13 prontos com "Duplicar e adaptar" (dc:155-156, dc:431);
- **editor de modelo** em tela própria: nome, categoria, formato, folha branca, texto lateral, barra de formatação,
  "Inserir campo...", "Criar campo", lugar de assinatura por papel, "Importar Word" e prévia ao vivo (dc:204-212,
  dc:327-335, dc:450-454);
- **passo 1** do assistente: "Criar novo modelo" na linha da busca e os modelos do escritório na lista (dc:175-176);
- modelo do escritório usado num documento (passo 2 em diante), como os prontos (dc:370-372);
- migration M3 `Version20261109260000`.

Fica como está: a tela continua atrás da chave `assinatura` do Master (desligada) até a etapa 3.

## 2. Decisões

Do Coordenador (09/11), com o motivo:

1. **Nenhum HTML chega ao servidor.** O navegador converte o editor em `corpo` (porta do `lerEd` do desenho, dc:330) e
   reabre pelo `htmlDe` (dc:329). O servidor recebe só a estrutura (seção 5) e a confere com um validador estrito, na
   linha do `ValidadorDeBlocos`. Motivo: nada de HTML no banco; o `SanitizadorDeModeloDePeca` (que a spec geral
   citava) tira os `data-*` de que o editor precisa (campos e assinaturas) e corta em silêncio acima de 256 KB.
   Toda recusa volta com mensagem na tela, nunca corte silencioso, e cada uma tem teste. Os campos da tela têm
   `maxlength` com os mesmos limites.
2. Tabela `assinatura_eletronica_modelo` sem `icone`, `descricao` nem `html` (o ícone e a descrição do desenho são
   fixos para todo modelo do escritório, dc:454). Lista por `alterado_em` decrescente: o salvo sobe, como o desenho.
3. Rotas com o prefixo `assinatura_eletronica_` (a chave do Master cobre sozinha), no `ROTAS` do controller da página,
   com módulo, CSRF e escritório como o `comAcesso` das etapas anteriores. Quem tem o módulo cria, edita e exclui
   qualquer modelo do escritório (spec 5.4).
4. Documento feito com modelo do escritório guarda `modelo_chave = esc-<id>` (até 60). Sem chave estrangeira do
   documento para o modelo: o documento guarda os blocos congelados; editar ou excluir o modelo não muda nada já
   salvo ou enviado.
5. **Editar leva a Folha branca.** O desenho reabre o modelo sem ela (dc:431), e salvar desligaria a opção sem aviso.
6. **Excluir com confirmação (D5)** na janela do Excluir que já existe: título "Excluir modelo", texto "O modelo será
   apagado e não poderá ser recuperado. Os documentos já enviados não mudam.", botões "Voltar" e "Excluir"; depois o
   aviso amarelo do desenho "Modelo excluído. Os documentos já enviados não mudam.".
7. Duplicar e adaptar como o desenho: a cópia perde `se`, `op`, `def` e a numeração viva (o `htmlDe` já aplica a
   numeração e ignora as condições). Nome repetido é permitido. "Criar novo modelo" do passo 1 sai do assistente
   (o estado dele é limpo) e o editor volta à aba Modelos ao salvar ou voltar, como o desenho (dc:450, dc:454).
   Importar Word: `mammoth.convertToHtml` (já vendorizado) lido num `DOMParser` (documento inerte, sem carregar
   imagem nem rodar nada) e posto no lugar do texto do editor, como o desenho.
8. Erro do servidor ao salvar: aviso vermelho com o `{erro}` do 422 e o editor continua aberto com o conteúdo.
   Modelo apagado por outra pessoa (404): aviso vermelho e volta à aba Modelos.
9. Componentes e valores do desenho; o que o sistema tem e o desenho não tem sai; sem travessão no texto visível.

Desvios novos, desta implementação (para a revisão do Coordenador):

- **D-a.** O JSON usa `folhaBranca` (camelCase, como o `Rascunho` da etapa 1), não `folha_branca`.
- **D-b.** "Criar campo" com colchete no nome recusa na linha de erro do editor: "O nome do campo não pode ter
  colchetes." (texto novo; o desenho não trata o caso e o servidor recusaria só ao salvar, sem dizer onde).
- **D-c.** O servidor repete as quatro conferências do desenho (nome, texto, assinatura, título no formato de folha
  única) com os mesmos textos, para a rota não gravar modelo que a tela recusaria.
- **D-d.** A prévia do editor usa a logo da BlueJus, como o desenho (`html()` cai na `BJAssinaLogoBJ` sem logo,
  dc:352).
- **D-e.** O `htmlDe` escapa também aspas e apóstrofo (o do desenho escapa só `&` e `<`, e uma chave de campo com
  aspas quebraria o atributo `data-c`), e separa as chaves antes de escapar (o do desenho escapa duas vezes uma chave
  com `&`).

## 3. Tabela (M3 `Version20261109260000`)

`assinatura_eletronica_modelo` (`ModeloDoEscritorio`, TenantAware e Auditavel):

| Coluna | Tipo |
|---|---|
| id | INT identidade |
| tenant_id | INT NOT NULL, FK `tenant` (NO ACTION; sai pela purga explícita) |
| nome | VARCHAR(200) NOT NULL |
| categoria | VARCHAR(80) NOT NULL |
| campos | JSON NOT NULL |
| signatarios | JSON NOT NULL |
| corpo | JSON NOT NULL |
| layout | VARCHAR(10) NOT NULL (`''` ou `2col`) |
| "lateral" | TEXT NULL (palavra reservada, entre aspas) |
| folha_branca | BOOLEAN NOT NULL |
| criado_por_id | INT NULL, FK `"user"` ON DELETE SET NULL |
| criado_em, alterado_em | TIMESTAMP(0) NOT NULL |

Entra no `PurgarEscritorioUseCase` (`tenant_id = :tenant`) e nos testes de cobertura (`PurgaCoberturaSchemaTest`,
`TenantAwareCoberturaTest`, `AuditavelCoberturaTest`). Nenhum arquivo: o modelo é só texto.

## 4. Rotas (nome com prefixo `assinatura_eletronica_`)

| Método e caminho | Nome | Corpo | Resposta |
|---|---|---|---|
| GET `/assinatura/modelos` | `modelos` | | `{modelos: [Modelo]}`, por `alterado_em` decrescente (empate: id decrescente) |
| POST `/assinatura/modelos` | `modelo_criar` | JSON `Entrada` | `{id}` 201, ou 422 `{erro}` |
| POST `/assinatura/modelos/{id}` | `modelo_salvar` | JSON `Entrada` | `{id}`, ou 404/422 `{erro}` |
| POST `/assinatura/modelos/{id}/excluir` | `modelo_excluir` | | `{ok: true}`, ou 404 `{erro}` |

Sem o módulo: 403. POST sem o `X-CSRF-Token` válido: 403. Modelo de outro escritório ou que não existe: 404 "Modelo
não encontrado.". As quatro URLs entram na raiz `.bja` como `data-url-modelos`, `data-url-modelo-criar`,
`data-url-modelo-salvar` e `data-url-modelo-excluir` (marcador `0` no lugar do `{id}`); o JS esconde o botão cuja URL
falta.

## 5. Formas do JSON

```
Entrada = { nome, categoria, layout ('' | '2col'), lateral, folhaBranca (bool),
            campos: [Campo], signatarios: [{papel}], corpo: [Bloco] }

Modelo  = Entrada + { id, chave ('esc-<id>'), alteradoEm (ISO) }   (categoria já com o padrão aplicado)

Campo   = [chave, rótulo]                                  campo criado no editor (chave = rótulo)
        | [chave, rótulo, largo (0|1)]
        | [chave, rótulo, largo, exemplo]
        | [chave, rótulo, largo, exemplo, [opção, ...]]    a forma do catálogo BJAssinaCampos

Bloco   = {t: texto} título | {s: texto} subtítulo | {h: texto} seção | {n: texto} número
        | {d: texto} data | {p: texto, full?: 1} parágrafo | {a: índice, r?: texto} lugar de assinatura
```

Um bloco tem exatamente uma chave de tipo; além dela, só `full` no `p` e `r` no `a`. O texto usa a sintaxe dos
prontos (`[[campo]]`, `{b}...{/b}`); `se` e `op` não existem no modelo do escritório. O servidor devolve o que
guardou: `largo` como 0 ou 1, `full: 1` só no parágrafo que o tem, `r` só quando preenchido. Na tela, cada
signatário vira `{papel, nome: '', email: ''}` e o modelo entra em `usar()` como um pronto (`id` = `chave`).

## 6. Limites e recusas (422 com o texto, nesta ordem)

| Regra | Texto do `{erro}` |
|---|---|
| corpo do pedido que não é objeto JSON, ou `campos`, `signatarios` ou `corpo` que não é lista | Os dados do modelo não puderam ser lidos. Recarregue a página e tente de novo. |
| nome vazio (depois de aparar) | Informe o nome do modelo. |
| nome acima de 200 | O nome do modelo pode ter até 200 caracteres. |
| categoria acima de 80 (vazia vira "Meus modelos") | A categoria pode ter até 80 caracteres. |
| layout fora de `''` e `2col` | Formato do modelo inválido. |
| lateral acima de 20.000 | O texto lateral pode ter até 20.000 caracteres. |
| mais de 100 campos | O modelo pode ter até 100 campos. |
| campo que não está na forma do catálogo (não é lista; 1 ou mais de 5 itens; rótulo vazio ou acima de 120; largo que não é 0, 1 ou booleano; exemplo acima de 120; opções que não são lista de 1 a 20 textos de até 60) | O modelo tem um campo inválido (posição {posicao}). |
| chave de campo vazia, acima de 60 ou com `[` ou `]` | O nome do campo {posicao} deve ter de 1 a 60 caracteres, sem colchetes. |
| chave repetida | O campo "{nome}" aparece mais de uma vez no modelo. |
| mais de 50 signatários | O modelo pode ter até 50 assinaturas. |
| signatário que não é objeto, ou papel vazio | A assinatura {numero} do modelo é inválida. |
| papel acima de 120 | O papel da assinatura {numero} pode ter até 120 caracteres. |
| mais de 2.000 blocos | O texto do modelo pode ter até 2.000 blocos (títulos, parágrafos e assinaturas). |
| bloco desconhecido: não é objeto, tipo fora da lista, mais de um tipo, chave a mais, texto vazio ou que não é texto, `full` que não é 0, 1 ou booleano | O texto do modelo tem um bloco inválido (posição {posicao}). |
| texto de um bloco acima de 20.000 | Um trecho do modelo passa de 20.000 caracteres (posição {posicao}). |
| `a` que não é inteiro, negativo ou não menor que o número de signatários; `r` que não é texto ou acima de 500 | O texto do modelo tem um lugar de assinatura inválido (posição {posicao}). |
| texto somado dos blocos acima de 300.000 | O texto do modelo passa de 300.000 caracteres. Divida em mais de um modelo. |
| nenhum parágrafo `p` | Escreva o texto do modelo. |
| nenhum bloco `a` ou nenhum signatário | Inclua ao menos uma assinatura. |
| formato `2col` sem título `t` | No formato de folha única, marque uma linha como Título. |

Os quatro últimos textos são os do desenho (dc:454), e a tela confere os mesmos antes de chamar o servidor. `r` vai
até 500 como no `ValidadorDeBlocos` (é o texto sob a linha da assinatura, com campos). `maxlength` na tela: nome 200,
categoria 80, texto lateral 20.000, novo campo 60, papel 120.

## 7. Domínio

- `Entity/ModeloDoEscritorio` (TenantAware, Auditavel): nasce com escritório, autor e hora; `aplicar(DadosDoModelo,
  agora)` troca tudo e marca `alterado_em`.
- `DTO/DadosDoModelo::deJson()` (nome, categoria, layout, lateral, folha branca) e `Service/ValidadorDoModelo`
  (campos, signatários e corpo, seção 6), estático como o `ValidadorDeBlocos`.
- `Repository/ModeloDoEscritorioRepository`: `doEscritorio(id, tenant)`, `doEscritorioEmOrdem(tenant)`, `salvar`,
  `remover` (com o `flush`); toda leitura leva o escritório à mão, além do `TenantFilter`.
- `UseCase/ListarModelosUseCase`, `SalvarModeloUseCase` (criar com id nulo, salvar com id) e `ExcluirModeloUseCase`;
  modelo de outro escritório lança `ModeloNaoEncontrado` (404).
- `Controller/ModelosDaAssinaturaController` com as quatro rotas.

## 8. Tela

- Template: tela nova `#bjaTelaMod` (vazia, desenhada pelo JS), depois de `#bjaTelaDet`.
- JS (`public/js/assinatura.js`): `modelos()` = do escritório (GET `modelos`) seguidos dos prontos; grupos por
  categoria na ordem da primeira aparição; aba Modelos com a coluna de ações (dc:156); passo 1 com "Criar novo modelo"
  (dc:175); editor com `lerEd`, `htmlDe`, `noCursor`, `insChip`, `bloco`, `alinhaDir`, "Criar campo", "Assinatura",
  "Importar Word" e a prévia `data-folha="m"`; janela do Excluir com o caso do modelo; avisos do desenho.
- CSS (`public/css/assinatura.css`): coluna de ações do cartão, topo, campos, barra, editor `.bjaEd` (dc:85-90),
  linha de erro, colunas 560px e 460px.
- Textos: bloco próprio `js.mod` em `translations/assinatura_eletronica_tela`; erros do servidor no bloco
  `modelo.erro` de `translations/assinatura_eletronica`.

## 9. Testes

- Unit: `ValidadorDoModelo` e `DadosDoModelo` (cada recusa da seção 6 e a normalização), `ModeloDoEscritorio`,
  UseCases (criar, salvar, excluir, outro escritório = `ModeloNaoEncontrado`, ordem da lista).
- Functional: cada rota sem o módulo (403), POST sem CSRF (403), criar, listar (o salvo sobe), salvar, excluir,
  outro escritório = 404 sem mudar nada, 422 com o texto, documento feito com o modelo não muda ao excluí-lo, URLs
  na raiz, arranjo da tela nova por filho direto, textos do catálogo, sem travessão.
- `node --check` no JS tocado.

## 10. Inventário do desenho (conferência ✓/✗ ao fim da implementação)

| # | Região | Item | Desenho | ✓/✗ |
|---|---|---|---|---|
| 1 | Aba Modelos | barra do topo | flex, gap 10px, wrap, padding 12px 16px, borda inferior #e6edf2 (dc:155) | |
| 2 | Aba Modelos | texto | "Use um modelo pronto, duplique para adaptar ou crie o seu.", flex 1 1 240px, 13px, #5b7383 | |
| 3 | Aba Modelos | botão | `.bjaP` bi-plus-lg "Criar novo modelo" abre o editor novo | |
| 4 | Aba Modelos | corpo | padding 14px 16px, coluna, gap 16px (dc:156) | |
| 5 | Aba Modelos | título do grupo | 14px #0b3550 mb 8px; categorias na ordem da 1ª aparição em meus + prontos; grupo vazio some | |
| 6 | Aba Modelos | cartão | flex stretch, borda #d3dde4, raio 3px, #fff, overflow hidden | |
| 7 | Aba Modelos | botão do modelo | `.bjaMod` flex 1 sem borda; ícone 20px #0a6fa0; título 13.5px; descrição 12px #5b7383 "Modelo do escritório"; clique usa o modelo (passo 2) | |
| 8 | Aba Modelos | coluna de ações | flex coluna, gap 3px, padding 6px, borda esquerda #e6edf2, centro | |
| 9 | Aba Modelos | pronto | `.bjaB.bjaI` bi-copy, aria e dica "Duplicar e adaptar"; abre "{nome} (cópia)" em "Meus modelos" | |
| 10 | Aba Modelos | do escritório | `.bjaB.bjaI` bi-pencil (aria "Editar modelo", dica "Editar"); `.bjaB.bjaI.bjaR` bi-trash3 (aria "Excluir modelo", dica "Excluir") | |
| 11 | Aba Modelos | Editar | reabre pelo `htmlDe`, com a folha branca (decisão 5) | |
| 12 | Aba Modelos | Excluir | janela do Excluir (D5) e aviso amarelo "Modelo excluído. Os documentos já enviados não mudam." | |
| 13 | Passo 1 | linha de busca | flex gap 8px wrap; "Buscar modelo" flex 1 1 240px; `.bjaB` 34px bi-plus-lg "Criar novo modelo" | |
| 14 | Passo 1 | lista | só `.bjaMod`, sem coluna de ações; inclui os do escritório; filtro pelo título | |
| 15 | Passo 1 | Criar novo modelo | sai do assistente; o editor volta à aba Modelos | |
| 16 | Passo 1 a 4 | modelo do escritório | nome, campos, signatários (papel, nome e e-mail vazios), folha branca, formato e lateral do modelo; `modelo_chave = esc-<id>` | |
| 17 | Editor | topo | flex gap 12px wrap mb 12px; `.bjaB.bjaN` bi-arrow-left "Modelos"; h1 clamp(22px,3vw,30px) normal flex 1 1 220px "Novo modelo"/"Editar modelo"; label `.bjaB` 38px bi-file-earmark-word "Importar Word" (.docx escondido); `.bjaP` bi-check2 "Salvar modelo" | |
| 18 | Editor | arranjo | `.bjaGrid`; `.bjaCent` 560px (editor, `.bjaCard` overflow hidden) e 460px (prévia) | |
| 19 | Editor | linha de campos | grid auto-fit minmax(170px,1fr) gap 8px padding 12px 14px borda inferior #e6edf2 | |
| 20 | Editor | Nome | "Nome do modelo"; digitar limpa o erro | |
| 21 | Editor | Categoria | "Categoria" com datalist "Meus modelos" + categorias existentes; padrão "Meus modelos" | |
| 22 | Editor | Formato | "Página inteira" ('') e "Folha única em duas colunas" ('2col') | |
| 23 | Editor | Folha branca | `.bjaChk` align-self center padding-top 16px: "**Folha branca** (sem marcas, selo e código)" | |
| 24 | Editor | Texto lateral | "Texto lateral", só no formato de duas colunas | |
| 25 | Editor | barra | flex gap 4px wrap padding 8px 14px #eef3f6 borda inferior #d3dde4; Negrito, Título, Subtítulo, Seção, Parágrafo e alinhar à direita (só ícone, dica "Alinhar à direita"), sem tirar o foco do texto | |
| 26 | Editor | divisor | 1px × 22px #d3dde4 margin 0 4px | |
| 27 | Editor | Inserir campo | select 170 × 32px "Inserir campo..." com Cliente, Escritório, Local e data; insere o campo e volta ao vazio | |
| 28 | Editor | linha criar | flex gap 8px wrap padding 8px 14px; "Novo campo (ex.: Número do processo)" + "Criar campo" (34px); "Quem assina (ex.: Outorgante)" + bi-pen "Assinatura" (34px); papel vazio vira "Signatário" | |
| 29 | Editor | área | `.bjaEd` editável, "Texto do modelo"; CSS dc:85-90 (380px a 62vh, 18px 22px, 14px/1.55, foco, h1, h2, h3, p, `p[data-d]`, `p[data-n]`, `.ch`, `div[data-ass]`) | |
| 30 | Editor | conteúdo inicial | "TÍTULO DO DOCUMENTO" + "Digite aqui o texto do modelo. Use Inserir campo para o que muda em cada documento." | |
| 31 | Editor | campo e assinatura | `span.ch` não editável com o rótulo e um espaço; "Assinatura: {papel}" | |
| 32 | Editor | erro | 12.5px #a3232b mt 6px abaixo do cartão; os quatro textos na ordem do desenho | |
| 33 | Editor | Salvar | grava, volta à aba Modelos, aviso verde "Modelo salvo."; o salvo sobe | |
| 34 | Editor | prévia | `.bjaPv[data-folha=m]` a cada mudança; escritório preenchido, campo do cliente em amarelo; duas colunas com a fonte de 12.6px a 8.6px | |
| 35 | Editor | Importar Word | cada parágrafo vira `<p>`, só negrito fica; vazio volta ao conteúdo inicial; nome vazio recebe o nome do arquivo; aviso "Texto importado. Marque título, campos e assinaturas."; erro "Não foi possível ler este arquivo." | |
| 36 | Avisos | toast | fixo, 22px do pé, 3,4 s; verde, vermelho, amarelo | |
| 37 | Janela do Excluir | modelo | camada do Excluir (D5), cabeçalho #a3232b bi-trash3 "Excluir modelo", "Voltar" e "Excluir" | |
| 38 | Texto | travessão | nenhum na aba, no editor e no passo 1 | |
