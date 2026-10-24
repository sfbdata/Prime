# Como um `.dc.html` é feito (e onde cada coisa se esconde)

Os desenhos do Claude Design chegam como `.dc.html` em `docs/design/<frente>/` (às vezes numa
subpasta de versão, `vs-22-10-26/`) ou como **Artefato** do claude.ai. O print mostra **um** estado;
o arquivo guarda **todos**. Quem lê só o print perde metade da tela.

## Sumário

1. Anatomia do arquivo
2. Onde mora cada valor
3. Estados escondidos
4. Textos escondidos
5. Comportamentos
6. Arquivos vizinhos (canvas, assets, `bj-*.js`)
7. Artefato do claude.ai

## 1. Anatomia do arquivo

```html
<script src="./support.js"></script>          <!-- motor do Claude Design, não é tela -->
<x-dc>
  <helmet>
    <script src="./bj-tema.js"></script>        <!-- bibliotecas da família BlueJus: tema,  -->
    <script src="./bj-idioma.js"></script>      <!-- idioma, dicas, plano, lembretes...      -->
    <link href="...bootstrap-icons@1.11.3...">  <!-- ícones: os nomes bi-* valem             -->
    <style> body{...} [data-meta]:hover{...} @media (max-width:1240px){...} </style>
  </helmet>
  <div data-screen-label="Esteira da pasta" style="display:grid;gap:5px;...">
    <sc-for list="{{ kpis }}" as="k" hint-placeholder-count="4"> ... {{ k.rot }} ... </sc-for>
    <sc-if value="{{ vazio }}" hint-placeholder-val="{{ false }}"> ... </sc-if>
    <button onClick="{{ limpar }}">Limpar filtros</button>
  </div>
  <script data-dc-script>
    class Component extends DCLogic {
      static METAS = [ ... ];          // dado de exemplo: mostra o que a tela precisa ter
      state = { aba: 'resp', ... };    // estado inicial: qual aba abre, o que vem marcado
      renderVals() { return { kpis: [...], vazio: ..., ... }; }  // textos e cores calculados
    }
  </script>
</x-dc>
```

## 2. Onde mora cada valor

| O que | Onde procurar | Observação |
|---|---|---|
| Tamanho, peso, cor, espaçamento de cada elemento | atributo `style="..."` inline | É a maior parte do desenho (centenas de `style=`). |
| Hover, foco, responsivo, grid | `<style>` dentro do `<helmet>` | `:hover`, `:focus`, `@media` e seletores por `data-*` só existem aqui. |
| Cor que muda com o dado (KPI vermelho se > 0, selo por prioridade) | JavaScript (`renderVals`, mapas como `VIS = {'#a3232b': [...]}`) | O `style` só tem `{{ k.cor }}`; o valor está no JS. |
| Rótulos, títulos de cartão, textos de selo | marcação **e** JS | Rótulo calculado (`'ATRASADAS'`, `conc + ' de ' + tot`) só está no JS. |
| Ícone | classe `bi-*` na marcação ou string no JS | Usar o mesmo nome do Bootstrap Icons; não trocar por "parecido". |
| Fonte | `body { font-family }` no `<style>` | Não herdar a fonte do AdminLTE se o desenho diz outra. |

Valor tirado do documento **não é opinião**: é `font-size:13px;font-weight:bold;color:#12242f`,
não "título em destaque". Quando o desenho usa `px`, converta só se a convenção do arquivo de CSS
da tela pedir (`13px` = `.8125rem`), sem arredondar.

## 3. Estados escondidos

O print do desenho é o estado inicial com o dado de exemplo. Tudo isto está no arquivo e quase
nunca no print:

- **`<sc-if value="{{ x }}">`**: cada um é um bloco que aparece só em certa condição (vazio,
  filtro ativo, erro, atrasado, permissão, primeira vez). `hint-placeholder-val="{{ false }}"`
  significa que no print ele está **escondido**.
- **`<sc-for ... hint-placeholder-count="8">`**: a lista tem item repetido; confira o item
  **e** o que acontece com zero itens (procure o `sc-if` de vazio ao lado).
- **`state = {...}`**: qual aba, filtro ou modo abre selecionado; quais modais começam fechados
  (`modal: null`) e portanto existem mas não aparecem.
- **Ternários no JS**: `at ? '#0a6fa0' : '#4a6373'` são dois estados (ativo/inativo) de um mesmo
  elemento. Cada ramo é item do inventário.
- **Outros artboards**: `canvas.json` na pasta lista as pranchetas (aba "Criei", "Modo lista",
  celular). Cada uma é um estado que a tela tem que ter.
- **`@media`**: o responsivo também é desenho. `repeat(8,...)` → `repeat(4,...)` em 1240px
  → `repeat(2,...)` em 460px é requisito, não sugestão.

## 4. Textos escondidos

- `title="..."` (tooltip), `placeholder`, `aria-label`, `alt`.
- Mensagens do JS: toast ("foi movida para"), confirmação, vazio ("Nenhuma meta"), erro, contagem
  com plural ("pessoa(s) serão avisadas").
- Texto de dado de exemplo (nomes de cliente, números de pasta) **não** é texto fixo: é o formato
  que o dado real precisa ter (caixa alta? data `dd/mm/aaaa`? "Pasta 828"?).

Texto do desenho entra **literal**, com a pontuação e a caixa do desenho. Exceção única: travessão
(`—` `–`), proibido pelo CLAUDE.md. Reescreva com ponto, dois-pontos, vírgula ou parênteses e
cite na conferência.

## 5. Comportamentos

Cada `onClick`, `onInput`, `onChange`, `onDoubleClick`, `onContextMenu`, `onBlur` é um
comportamento que a tela precisa ter. Leia o handler no JS para saber **o que** ele faz:
`ir: () => this.setState({ aba: k, fPrazo: '', ... })` diz que trocar de aba **limpa os filtros**;
`location.href = './02 - EXPEDIENTES...#pasta=828'` diz para onde o clique leva. Clique que
alterna (clicar de novo desmarca) aparece como `s.fPrazo === prazo ? {fPrazo:''} : {...}`.

## 6. Arquivos vizinhos

- `canvas.json`: lista de pranchetas, com título e largura (`w: 1440` = desktop; `w: 390` = celular).
- `assets/`: imagens e logos do desenho. Use essas, não as do sistema.
- `bj-*.js`, `bluejus-central.js`: bibliotecas compartilhadas da família de desenhos (tema claro e
  escuro, troca de idioma, dicas, plano, lembretes, menu central). Quando o desenho as carrega,
  o comportamento delas faz parte da tela. Às vezes **não estão na pasta** do desenho: o script de
  inventário avisa e aponta cópias em outras pastas, que podem ser de outra versão. Na dúvida sobre
  qual versão vale, pergunte.

## 7. Artefato do claude.ai

Link `claude.ai/artifact/...` ou `claude.ai/code/artifact/...`: leia com a ferramenta `Artifact`,
`action: "read"` (nunca WebFetch/curl). A leitura salva o HTML num arquivo local: grave uma cópia
em `docs/design/<frente>/` e rode o inventário nela. Artefato é React/HTML comum: os estados
moram em `useState`, condicionais `{cond && ...}` e ternários, com a mesma regra da seção 3.
