# Referência do desenho: Planos e limites (painel do Master)

Fonte: `docs/design/perfis-1.2/05 - Perfis 1.2 - VS 18.10.26.dc.html` (idêntico ao protótipo servido em
`http://127.0.0.1:8193/desenho.html`, conferido com `cmp`). Números de linha são do `.dc.html`.
Capturas em 1580x900, na pasta deste arquivo (`a-*` viewport, `*-full` página inteira).
Para chegar: card "Master do Sistema" na lista de perfis, aba "Painel do sistema", botão da seção.

Legenda: **[ESTRUTURA]** = vale para a implementação · **[EXEMPLO]** = dado fictício do protótipo, não copiar.

## 0. Modelo de dados do protótipo (para ler as fórmulas)

`Component.CONTAS` (L425-431) + `Component.EXTRA` (L432) concatenados por conta. Índices usados:

| índice | significado | exemplo (Rodrigues) |
|---|---|---|
| `c[0]` | nome da conta | "Escritório Rodrigues Associados" |
| `c[1]` | primário | "Carlos Rodrigues" |
| `c[2]` | secundários (usuários = `c[2] + 1`, conta o primário) | 7 → 8 usuários |
| `c[3]` | uso em GB | 17.6 |
| `c[4]` | cota em GB (limite de armazenamento) | 20 |
| `c[8]` | situação (Ativa/Teste/Suspensa) | "Ativa" |
| `c[10]` | plano | "Essencial" |
| `c[11]` | limite de usuários | 8 |
| `c[12]` | crescimento em GB nos últimos 30 dias | 1.6 |

Todos os valores de `CONTAS`/`EXTRA` são **[EXEMPLO]**.

Utilitários (L592-593, L596), **[ESTRUTURA]**:

```js
/* L592 */ const gb = v => v.toFixed(1).replace('.', ',') + ' GB', FX = s.faixas;
/* L593 */ const faixa = p => p >= FX[3] ? ['Limite atingido', '#7a1b22'] : p >= FX[2] ? ['Crítico', '#a3232b'] : p >= FX[1] ? ['Alerta', '#c0392b'] : p >= FX[0] ? ['Atenção', '#c08a12'] : ['Normal', '#1e7b45'];
```

```js
/* L596 */ const abrirLim = (tipo, c) => e => { e && e.stopPropagation(); this.setState({ menu: null, lim: { tipo, nome: c[0], novo: tipo === 'arm' ? c[4] : c[11], just: '' } }); };
```

- `gb(v)`: 1 casa decimal, vírgula, sufixo " GB" (ex.: "17,6 GB").
- `FX = s.faixas`, inicial `[70, 90, 95, 100]` (estado, L481: `faixas: [70, 90, 95, 100]`). **[ESTRUTURA]** como padrão.
- `faixa(p)` (p em %), comparações `>=`, de cima para baixo:
  - `p >= FX[3]` → "Limite atingido", `#7a1b22`
  - `p >= FX[2]` → "Crítico", `#a3232b`
  - `p >= FX[1]` → "Alerta", `#c0392b`
  - `p >= FX[0]` → "Atenção", `#c08a12`
  - senão → "Normal", `#1e7b45`
- Ao abrir o modal de limite, o campo já vem com o limite atual (`novo: c[4]` ou `c[11]`) e justificativa vazia; fecha o menu.

## 1. Planos e limites (seção `planos`)

Captura: `a-planos-e-limites.png` (a página inteira cabe na viewport).

Navegação da seção (L470, `Component.SECS`): `['planos', 'Planos e limites', 'bi-sliders']`. **[ESTRUTURA]**

### Markup exato (L205-210)

```html
<!-- L205 -->
<sc-if value="{{ secPlanos }}" hint-placeholder-val="{{ false }}">
<!-- L206 -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(230px, 1fr)); gap:14px;">
<!-- L207 -->
<sc-for list="{{ planos }}" as="p" hint-placeholder-count="3"><div style="background:#fff; border:1px solid #dfe7ed; border-radius:4px; box-shadow:0 2px 10px rgba(18,36,47,.05); padding:18px 20px; display:flex; flex-direction:column; gap:8px; border-top:3px solid {{ p.cor }};"><div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;"><b style="font-size:16px;">{{ p.nome }}</b><sc-if value="{{ p.temTag }}" hint-placeholder-val="{{ false }}"><span style="height:20px; padding:0 7px; border-radius:3px; background:#fff4d6; color:#7a5200; font-size:11px; font-weight:bold; display:inline-flex; align-items:center;">{{ p.tag }}</span></sc-if></div><span style="font-size:24px; font-weight:bold;">{{ p.preco }}<span style="font-size:12px; color:#5b7383; font-weight:normal;"> / mês</span></span><span style="font-size:13px; color:#34505f;">{{ p.arm }} · {{ p.usu }} usuários</span><span style="font-size:12.5px; color:#5b7383;">{{ p.contas }} conta(s) · {{ p.acima }}</span></div></sc-for>
<!-- L208 -->
</div>
<!-- L209 -->
<span style="font-size:12px; color:#5b7383;">Toda conta nova entra no Plano Free (10 GB). A partir de 90% de uso o usuário primário recebe aviso no sistema e por e-mail recomendando ampliar o espaço; em 100% novos envios ficam bloqueados, mas consultar e baixar arquivos continua liberado. Preços de exemplo. Mudanças de plano com impacto comercial exigem confirmação e ficam registradas na auditoria.</span>
<!-- L210 -->
</sc-if>
```

### Lógica (L469 e L697)

```js
/* L469 */ static PLANOS = [['Free', 'R$ 0', 10, 3, '#c08a12', 'Padrão: toda conta nova começa aqui'], ['Essencial', 'R$ 189', 20, 8, '#0a6fa0'], ['Profissional', 'R$ 389', 50, 15, '#1e7b45']];
```

```js
/* L697 */ planos: Component.PLANOS.map(([nome, preco, arm, usu, cor, tag]) => { const cs = s.contas.filter(c => c[10] === nome); return { nome: nome === 'Free' ? 'Plano Free' : nome, tag: tag || '', temTag: !!tag, preco, arm: arm + ' GB', usu, cor, contas: cs.length, acima: cs.filter(c => c[3] / c[4] * 100 >= FX[1]).length + ' perto ou acima da franquia' }; }),
```

Regras **[ESTRUTURA]**:
- Grade `repeat(auto-fit, minmax(230px, 1fr))`, gap 14px. Cartão: branco, borda `1px solid #dfe7ed`, raio 4px,
  sombra `0 2px 10px rgba(18,36,47,.05)`, padding `18px 20px`, coluna com gap 8px, **`border-top:3px solid <cor do plano>`**.
- Nome: `<b>` 16px. O plano "Free" é exibido como **"Plano Free"**; os demais pelo nome.
- Selo (só se o plano tem `tag`): altura 20px, padding `0 7px`, raio 3px, fundo `#fff4d6`, cor `#7a5200`, 11px negrito.
  Texto: "Padrão: toda conta nova começa aqui" (só no Free).
- Preço: 24px negrito + `<span>` 12px `#5b7383` normal com " / mês".
- Linha de capacidade: 13px `#34505f`: `{{ arm }} · {{ usu }} usuários` onde `arm = <GB> + ' GB'` (ex.: "10 GB · 3 usuários").
- Linha de contas: 12.5px `#5b7383`: `{{ contas }} conta(s) · {{ acima }}` com
  `contas` = nº de contas cujo plano (`c[10]`) é este, e
  `acima` = nº dessas contas com `uso/cota*100 >= FX[1]` (faixa "Alerta", 90% por padrão) + " perto ou acima da franquia".
- Nota de rodapé: 12px `#5b7383`.

Textos exatos (como renderizados):
- "Plano Free" · "Padrão: toda conta nova começa aqui" · "R$ 0" · " / mês" · "10 GB · 3 usuários" · "1 conta(s) · 0 perto ou acima da franquia"
- "Essencial" · "R$ 189" · "20 GB · 8 usuários" · "3 conta(s) · 0 perto ou acima da franquia"
- "Profissional" · "R$ 389" · "50 GB · 15 usuários" · "1 conta(s) · 0 perto ou acima da franquia"
- Nota: "Toda conta nova entra no Plano Free (10 GB). A partir de 90% de uso o usuário primário recebe aviso no sistema e por e-mail recomendando ampliar o espaço; em 100% novos envios ficam bloqueados, mas consultar e baixar arquivos continua liberado. Preços de exemplo. Mudanças de plano com impacto comercial exigem confirmação e ficam registradas na auditoria."

Dado de exemplo versus estrutura:
- **[EXEMPLO]** preços "R$ 189" e "R$ 389", a lista de só 3 planos (sem Avançado), as contagens de contas, e a frase
  "Preços de exemplo." da nota. A spec `perfis-1.2-planos-e-limites.md` já fixa: Free 10 GB R$ 0 3 usuários; Essencial 20 GB R$ 19,90 8;
  Profissional 50 GB R$ 39,90 15; Avançado 100 GB R$ 69,90 sem limite; e tira "Preços de exemplo".
- **[ESTRUTURA]** cores de topo por plano no desenho: Free `#c08a12`, Essencial `#0a6fa0`, Profissional `#1e7b45`
  (o desenho não define cor para um 4º plano: omisso, decidir pela convenção).
- **[ESTRUTURA]** "sem limite" de usuários: o desenho é omisso (o template sempre acrescenta " usuários"). Atenção para não gerar "sem limite usuários".
- O desenho **não tem** botão "Editar plano" nos cartões nem modal de plano (omisso; o P2 da spec é decisão do dono, não do desenho).
- Os "90%" e "100%" da nota são fixos no texto; no código o "perto ou acima" usa `FX[1]` (configurável).

## 2. Armazenamento (seção `arm`): faixas de alerta + ranking

Captura: `b-armazenamento.png` (cabe na viewport; `-full` igual).

### Markup exato (L194-203)

```html
<!-- L194 -->
<sc-if value="{{ secArm }}" hint-placeholder-val="{{ false }}">
<!-- L195 -->
<div style="background:#fff; border:1px solid #dfe7ed; border-radius:4px; box-shadow:0 2px 10px rgba(18,36,47,.05); padding:16px 20px; display:flex; flex-direction:column; gap:14px;">
<!-- L196 -->
<b style="font-size:14px;">Faixas de alerta de consumo <span style="font-weight:normal; color:#5b7383; font-size:12.5px;">(configuráveis)</span></b>
<!-- L197 -->
<div style="display:flex; gap:14px; flex-wrap:wrap;"><sc-for list="{{ faixas }}" as="f" hint-placeholder-count="4"><label style="display:flex; align-items:center; gap:8px; font-size:13px; padding:8px 12px; border-radius:4px; background:{{ f.bg }}; color:{{ f.cor }}; font-weight:bold;">{{ f.rot }} a partir de<input type="number" value="{{ f.val }}" onInput="{{ f.mudar }}" style="width:58px; height:28px; border:1px solid #d6e0e7; border-radius:3px; font:inherit; font-size:13px; padding:0 6px; color:#12242f;">%</label></sc-for></div>
<!-- L198 -->
<span style="font-size:12.5px; color:#4a6373; line-height:1.5;">No limite (100%), novos uploads acima da capacidade ficam bloqueados. Consulta, download e exportação continuam liberados. Nenhum documento é apagado automaticamente.</span>
<!-- L199 -->
</div>
<!-- L200 -->
<div style="background:#fff; border:1px solid #dfe7ed; border-radius:4px; box-shadow:0 2px 10px rgba(18,36,47,.05); overflow:hidden;"><div style="padding:13px 20px; background:#f5f8fa; border-bottom:1px solid #e6edf2;"><b style="font-size:14px;">Contas por consumo</b></div>
<!-- L201 -->
<sc-for list="{{ ranking }}" as="r" hint-placeholder-count="5"><div style="display:flex; align-items:center; gap:14px; padding:11px 20px; border-bottom:1px solid #eef2f5;"><span style="flex:1; min-width:0; font-size:13.5px; font-weight:bold; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ r.nome }}</span><div style="width:240px; height:8px; border-radius:4px; background:#e6edf2; overflow:hidden;"><div style="height:100%; width:{{ r.pctW }}; background:{{ r.barra }};"></div></div><b style="width:50px; text-align:right; font-size:13px; color:{{ r.barra }};">{{ r.pct }}</b><span style="width:100px; font-size:12px; color:{{ r.barra }};">{{ r.faixa }}</span><button onClick="{{ r.editar }}" style="height:30px; border:none; border-radius:4px; background:#e2e9ee; color:#243845; font:inherit; font-size:12.5px; font-weight:bold; padding:0 12px; cursor:pointer;" style-hover="background:#0a6fa0; color:#fff;">Editar limite</button></div></sc-for>
<!-- L202 -->
</div>
<!-- L203 -->
</sc-if>
```

### Lógica (L695-696)

```js
/* L695 */ faixas: [['Atenção', '#8a5a12', '#fbf1dc'], ['Alerta', '#a3232b', '#fbe7e7'], ['Crítico', '#7a1b22', '#f6dcdc'], ['Bloqueio', '#fff', '#7a1b22']].map(([rot, cor, bg], i) => ({ rot, cor, bg, val: FX[i], mudar: e => { const f = FX.slice(); f[i] = +e.target.value || 0; this.setState({ faixas: f }); } })),
/* L696 */ ranking: s.contas.slice().sort((a, b) => b[3] / b[4] - a[3] / a[4]).map(c => { const p = c[3] / c[4] * 100, f = faixa(p); return { nome: c[0], pct: Math.round(p) + '%', pctW: Math.min(100, p) + '%', barra: f[1], faixa: f[0], editar: abrirLim('arm', c) }; }),
```

Regras **[ESTRUTURA]**:
- Cartão de faixas: título "Faixas de alerta de consumo" (`<b>` 14px) + " (configuráveis)" (normal, `#5b7383`, 12.5px).
- Quatro rótulos-chip (`label` flex, gap 8px, 13px negrito, padding `8px 12px`, raio 4px), cada um com cor/fundo:

  | faixa | cor do texto | fundo | valor padrão |
  |---|---|---|---|
  | Atenção | `#8a5a12` | `#fbf1dc` | 70 |
  | Alerta | `#a3232b` | `#fbe7e7` | 90 |
  | Crítico | `#7a1b22` | `#f6dcdc` | 95 |
  | Bloqueio | `#fff` | `#7a1b22` | 100 |

  Conteúdo: `<rótulo> a partir de` + `<input type="number">` (58x28px, borda `1px solid #d6e0e7`, raio 3px, 13px, padding `0 6px`, cor `#12242f`) + "%".
  Ao digitar, grava `+valor || 0` na posição (sem validação de ordem nem de faixa no desenho: omisso).
- Atenção: os rótulos/cores do **chip** (Bloqueio, `#a3232b`...) diferem dos da função `faixa()` usada nas barras
  ("Limite atingido" `#7a1b22`, "Alerta" `#c0392b`). Ambos são do desenho.
- Nota: 12.5px, `#4a6373`, line-height 1.5.
- Ranking "Contas por consumo": cabeçalho padding `13px 20px`, fundo `#f5f8fa`, borda inferior `1px solid #e6edf2`.
  Linhas ordenadas por `uso/cota` decrescente; cada linha flex, gap 14px, padding `11px 20px`, borda inferior `1px solid #eef2f5`:
  - nome: 13.5px negrito, `flex:1`, reticências;
  - barra: 240x8px, raio 4px, fundo `#e6edf2`; preenchimento `width = min(100, p)%`, cor `faixa(p)[1]`;
  - `%`: `<b>` largura 50px, alinhado à direita, 13px, cor da faixa, `Math.round(p) + '%'`;
  - rótulo da faixa: largura 100px, 12px, cor da faixa;
  - botão "Editar limite": 30px de altura, sem borda, raio 4px, fundo `#e2e9ee`, cor `#243845`, 12.5px negrito, padding `0 12px`;
    hover fundo `#0a6fa0` e texto branco. Abre o modal de **armazenamento** da conta.
- Onde `p = uso / cota * 100`.

Textos exatos:
- "Faixas de alerta de consumo" · "(configuráveis)" · "Atenção" / "Alerta" / "Crítico" / "Bloqueio" · "a partir de" · "%"
- Nota: "No limite (100%), novos uploads acima da capacidade ficam bloqueados. Consulta, download e exportação continuam liberados. Nenhum documento é apagado automaticamente."
- "Contas por consumo" · "Editar limite"
- **[EXEMPLO]** linhas: Rodrigues 88% Atenção; Pereira 48%; Farlei 37%; Moura 31%; Oliveira 12% (todas Normal).

## 3. Contas e escritórios: menu da conta (itens de limite)

Capturas: `c-contas-menu-da-conta.png` (Rodrigues, 3ª linha: no desenho o menu é **recortado** pelo contêiner
`overflow-x:auto` da tabela, comportamento do protótipo, não intenção) e `c2-menu-completo-conta-comum.png`
(Moura, com o recorte desligado só para a captura, menu inteiro).

### Markup exato (L170-176): botão e menu

```html
<!-- L170 -->
<div data-hv="1" style="display:flex; justify-content:center;"><button onClick="{{ c.menu }}" title="Ações da conta" style="width:30px; height:30px; border:none; background:transparent; border-radius:4px; color:#4a6373; cursor:pointer;"><i class="bi bi-three-dots-vertical"></i></button></div>
<!-- L171 -->
<sc-if value="{{ c.aberto }}" hint-placeholder-val="{{ false }}">
<!-- L172 -->
<div onClick="{{ parar }}" style="position:absolute; z-index:20; right:20px; top:52px; width:260px; background:#fff; border:1px solid #d6e0e7; border-radius:4px; box-shadow:0 12px 30px rgba(18,36,47,.18); overflow:hidden;">
<!-- L173 -->
<div style="background:#0a6fa0; color:#fff; padding:9px 14px; font-size:11px; font-weight:bold; letter-spacing:1.2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ c.nome }}</div>
<!-- L174 -->
<sc-for list="{{ c.opcoes }}" as="o" hint-placeholder-count="8"><button onClick="{{ o.ir }}" style="width:100%; display:flex; align-items:center; gap:9px; border:none; border-top:{{ o.sep }}; background:#fff; padding:8px 14px; font:inherit; font-size:13px; color:{{ o.cor }}; cursor:pointer; text-align:left;" style-hover="background:{{ o.hover }};"><i class="bi {{ o.icone }}"></i>{{ o.rot }}</button></sc-for>
<!-- L175 -->
</div>
<!-- L176 -->
</sc-if>
```

### Lógica (L734-735)

```js
/* L734 */ menu: e => { e.preventDefault(); e.stopPropagation(); this.setState({ menu: s.menu === c[0] && e.type === 'click' ? null : c[0] }); },
/* L735 */ opcoes: [{ rot: 'Editar limite de armazenamento', icone: 'bi-hdd', cor: '#243845', hover: '#eef6fb', sep: 'none', ir: abrirLim('arm', c) }, { rot: 'Definir limite de usuários', icone: 'bi-people', cor: '#243845', hover: '#eef6fb', sep: 'none', ir: abrirLim('usu', c) }, { rot: 'Estatísticas de uso', icone: 'bi-bar-chart', cor: '#243845', hover: '#eef6fb', sep: 'none', ir: e => { e.stopPropagation(); this.setState({ menu: null, sec: 'arm' }); } }, { rot: 'Histórico de alterações', icone: 'bi-clock-history', cor: '#243845', hover: '#eef6fb', sep: 'none', ir: e => { e.stopPropagation(); this.setState({ menu: null, sec: 'audit', audConta: c[0] }); } }, c[8] === 'Suspensa' ? { rot: 'Reativar conta', icone: 'bi-play-circle', cor: '#1e7b45', hover: '#e6f4ec', sep: '1px solid #eef2f5', ir: pedir('reativar', c) } : { rot: 'Suspender conta', icone: 'bi-pause-circle', cor: '#8a5a12', hover: '#fbf1dc', sep: '1px solid #eef2f5', ir: pedir('suspender', c) }].concat(c[1] === 'Farlei Assis da Rocha' ? [] : [{ rot: 'Excluir conta', icone: 'bi-trash3', cor: '#a3232b', hover: '#fbe7e7', sep: 'none', ir: pedir('excluir', c) }]) }; }),
```

Regras **[ESTRUTURA]**:
- Abre pelo botão "⋮" (`bi-three-dots-vertical`, title "Ações da conta") **e** pelo botão direito na linha (`onContextMenu` na linha, L160).
- Menu: absoluto, `right:20px; top:52px`, 260px, borda `1px solid #d6e0e7`, raio 4px, sombra `0 12px 30px rgba(18,36,47,.18)`.
  Cabeçalho com o nome da conta: fundo `#0a6fa0`, branco, padding `9px 14px`, 11px negrito, letter-spacing 1.2px, reticências.
- Itens: botão largura 100%, flex, gap 9px, padding `8px 14px`, 13px, alinhado à esquerda; ícone `bi` + rótulo.
- Ordem e estilo exatos:
  1. "Editar limite de armazenamento", `bi-hdd`, cor `#243845`, hover `#eef6fb`, sem separador → modal de armazenamento.
  2. "Definir limite de usuários", `bi-people`, cor `#243845`, hover `#eef6fb`, sem separador → modal de usuários.
  3. "Estatísticas de uso", `bi-bar-chart` → seção Armazenamento.
  4. "Histórico de alterações", `bi-clock-history` → Segurança e auditoria filtrada pela conta.
  5. "Suspender conta" (`bi-pause-circle`, `#8a5a12`, hover `#fbf1dc`) ou, se Suspensa, "Reativar conta" (`bi-play-circle`, `#1e7b45`, hover `#e6f4ec`); **com** `border-top:1px solid #eef2f5`.
  6. "Excluir conta" (`bi-trash3`, `#a3232b`, hover `#fbe7e7`), omitido na conta do Master primário.

### Contexto na mesma linha (L163, L166-168), útil para "previsão"

```html
<!-- L163 -->
<div data-hv="1" style="text-align:center; font-size:13px;"><b>{{ c.usu }}</b><span style="color:#7d909d;"> / {{ c.limUsu }}</span></div>
```

```html
<!-- L166 -->
<div data-hv="1" style="padding:0 8px; display:flex; flex-direction:column; gap:5px;"><span style="font-size:12px; display:flex; justify-content:space-between; gap:6px;"><span>{{ c.uso }} de {{ c.cota }}</span><b style="color:{{ c.barra }};">{{ c.pct }}</b></span><div style="height:6px; border-radius:3px; background:#e6edf2; overflow:hidden;"><div style="height:100%; width:{{ c.pctW }}; background:{{ c.barra }};"></div></div><span style="font-size:11px; color:{{ c.barra }};">{{ c.faixa }} · livre {{ c.livre }}</span></div>
<!-- L167 -->
<div data-hv="1" style="text-align:center; font-size:12.5px; color:#4a6373;">{{ c.cresc }}</div>
<!-- L168 -->
<div data-hv="1" style="text-align:center; font-size:12px; color:{{ c.prevCor }};">{{ c.prev }}</div>
```

Fórmulas (L733) **[ESTRUTURA]**:
- `p = c[3] / c[4]`; `pct = Math.round(p*100) + '%'`; `pctW = min(100, p*100) + '%'`; cor/rótulo = `faixa(p*100)`.
- `livre = gb(max(0, cota - uso))`; texto "<faixa> · livre <livre>"; "<uso> de <cota>" (ex.: "17,6 GB de 20 GB").
- Usuários: `<b>usu</b>` + " / limUsu" (`usu = c[2] + 1`).
- 30 dias: `c[12] > 0 ? '+' + gb(c[12]) : 'estável'`.
- Previsão: `mes = c[12] > 0 ? (cota - uso) / c[12] : 0`;
  texto = sem crescimento → "sem previsão"; `mes < 1` → "menos de 1 mês"; senão "≈ " + `Math.round(mes)` + " meses"
  (no desenho sai "≈ 1 meses" para Rodrigues: plural fixo do protótipo).
  Cor: `c[12] && mes < 3` → `#a3232b`, senão `#4a6373`.

## 4. Modal de limite (armazenamento e usuários)

Capturas (conta Rodrigues: 17,6 GB de 20 GB; 8 de 8 usuários):

| estado | armazenamento | usuários | botão |
|---|---|---|---|
| recém-aberto (campo = limite atual, justificativa vazia) | `d-arm-1-aberto.png` | `e-usu-1-aberto.png` | desabilitado |
| campo vazio | `d-arm-2-vazio.png` | `e-usu-2-vazio.png` | desabilitado |
| abaixo do uso (10 GB / 5 usuários, com justificativa) | `d-arm-3-abaixo-do-uso.png` | `e-usu-3-abaixo-do-uso.png` | desabilitado |
| válido (25 GB / 10 usuários, com justificativa) | `d-arm-4-valido.png` | `e-usu-4-valido.png` | **habilitado** |
| válido sem justificativa | `d-arm-5-sem-justificativa.png` | `e-usu-5-sem-justificativa.png` | desabilitado |

### Markup exato (L396-410)

```html
<!-- L396 -->
<sc-if value="{{ temLim }}" hint-placeholder-val="{{ false }}">
<!-- L397 -->
<div onClick="{{ fecharLim }}" style="position:fixed; inset:0; z-index:60; background:rgba(13,27,42,.35); display:flex; align-items:center; justify-content:center; padding:20px;">
<!-- L398 -->
<div onClick="{{ parar }}" style="width:100%; max-width:480px; background:#fff; border-radius:4px; box-shadow:0 20px 50px -12px rgba(13,27,42,.45); overflow:hidden;">
<!-- L399 -->
<div style="background:#0a6fa0; color:#fff; padding:14px 20px; display:flex; align-items:center; gap:10px; font-weight:bold;"><i class="bi {{ limIcone }}"></i>{{ limTitulo }}</div>
<!-- L400 -->
<div style="padding:18px 20px; display:flex; flex-direction:column; gap:12px; font-size:13.5px; color:#34505f;">
<!-- L401 -->
<b style="color:#12242f;">{{ limConta }}</b>
<!-- L402 -->
<div style="display:grid; grid-template-columns:repeat(3,1fr); gap:8px;"><sc-for list="{{ limInfo }}" as="i" hint-placeholder-count="3"><div style="background:#f5f8fa; border-radius:4px; padding:9px 10px;"><div style="font-size:10.5px; font-weight:bold; letter-spacing:1px; color:#5b7383;">{{ i.rot }}</div><div style="font-size:15px; font-weight:bold; color:#12242f; margin-top:3px;">{{ i.val }}</div></div></sc-for></div>
<!-- L403 -->
<label style="display:flex; flex-direction:column; gap:5px;"><span style="font-size:11px; font-weight:bold; letter-spacing:1.1px;">{{ limCampo }}</span><input type="number" value="{{ limNovo }}" onInput="{{ onLimNovo }}" style="height:38px; border:1px solid {{ limBorda }}; border-radius:4px; padding:0 12px; font:inherit; font-size:14px;"></label>
<!-- L404 -->
<span style="font-size:12.5px; color:{{ limMsgCor }};">{{ limMsg }}</span>
<!-- L405 -->
<label style="display:flex; flex-direction:column; gap:5px;"><span style="font-size:11px; font-weight:bold; letter-spacing:1.1px;">JUSTIFICATIVA</span><input value="{{ limJust }}" onInput="{{ onLimJust }}" placeholder="Obrigatória, fica na auditoria" style="height:38px; border:1px solid #d6e0e7; border-radius:4px; padding:0 12px; font:inherit; font-size:14px;"></label>
<!-- L406 -->
</div>
<!-- L407 -->
<div style="display:flex; justify-content:flex-end; gap:8px; padding:12px 20px; background:#f5f8fa;"><button onClick="{{ fecharLim }}" style="height:34px; border:none; background:transparent; font:inherit; font-size:13.5px; padding:0 14px; cursor:pointer; border-radius:4px;" style-hover="background:#e2e9ee;">Cancelar</button><button onClick="{{ salvarLim }}" disabled="{{ limBloq }}" style="height:34px; border:none; background:{{ limBtnBg }}; color:#fff; font:inherit; font-size:13.5px; font-weight:bold; padding:0 16px; cursor:{{ limBtnCur }}; border-radius:4px;">Confirmar alteração</button></div>
<!-- L408 -->
</div>
<!-- L409 -->
</div>
<!-- L410 -->
</sc-if>
```

### Lógica (L622-623, L726-732)

```js
/* L622 */ const L = s.lim, LC = L && s.contas.find(c => c[0] === L.nome), novo = L ? +L.novo || 0 : 0;
/* L623 */ const limAbaixo = L && LC && (L.tipo === 'arm' ? novo < LC[3] : novo < LC[2] + 1), limBloq = !L || !LC || novo <= 0 || limAbaixo || !L.just.trim();
```

```js
/* L726 */ temLim: !!L, limIcone: L && L.tipo === 'arm' ? 'bi-hdd' : 'bi-people', limTitulo: L && L.tipo === 'arm' ? 'Editar limite de armazenamento' : 'Definir limite de usuários', limConta: L ? L.nome : '',
/* L727 */ limInfo: !LC ? [] : L.tipo === 'arm' ? [{ rot: 'EM USO', val: gb(LC[3]) }, { rot: 'LIMITE ATUAL', val: LC[4] + ' GB' }, { rot: 'LIVRE', val: gb(LC[4] - LC[3]) }] : [{ rot: 'USUÁRIOS', val: LC[2] + 1 }, { rot: 'LIMITE ATUAL', val: LC[11] }, { rot: 'VAGAS', val: LC[11] - LC[2] - 1 }],
/* L728 */ limCampo: L && L.tipo === 'arm' ? 'NOVO LIMITE (GB)' : 'NOVO LIMITE DE USUÁRIOS', limNovo: L ? L.novo : '', onLimNovo: e => this.setState({ lim: Object.assign({}, L, { novo: e.target.value }) }), limJust: L ? L.just : '', onLimJust: e => this.setState({ lim: Object.assign({}, L, { just: e.target.value }) }),
/* L729 */ limBorda: limAbaixo ? '#a3232b' : '#d6e0e7', limMsgCor: limAbaixo ? '#a3232b' : '#4a6373',
/* L730 */ limMsg: !LC ? '' : limAbaixo ? (L.tipo === 'arm' ? 'O novo limite é menor que o volume em uso (' + gb(LC[3]) + '). Reduzir não apaga arquivos, por isso não é permitido abaixo do uso.' : 'Menor que os usuários já cadastrados.') : 'Diferença: ' + (novo - (L.tipo === 'arm' ? LC[4] : LC[11]) >= 0 ? '+' : '') + (novo - (L.tipo === 'arm' ? LC[4] : LC[11])) + (L.tipo === 'arm' ? ' GB' : ' usuário(s)') + '. A alteração fica registrada na auditoria.',
/* L731 */ limBloq, limBtnBg: limBloq ? '#9fb3c0' : '#0a6fa0', limBtnCur: limBloq ? 'not-allowed' : 'pointer', fecharLim: () => this.setState({ lim: null }),
/* L732 */ salvarLim: () => { if (limBloq) return; const ant = L.tipo === 'arm' ? LC[4] + ' GB' : LC[11] + ' usuários', nv = novo + (L.tipo === 'arm' ? ' GB' : ' usuários'); this.setState({ contas: s.contas.map(c => { if (c[0] !== L.nome) return c; const x = c.slice(); x[L.tipo === 'arm' ? 4 : 11] = novo; return x; }), aud: registrar(L.tipo === 'arm' ? 'Limite de armazenamento' : 'Limite de usuários', L.nome, ant + ' → ' + nv + ' · ' + L.just.trim()), lim: null }); },
```

Regras **[ESTRUTURA]**:
- Sobreposição fixa, `z-index:60`, fundo `rgba(13,27,42,.35)`, centralizada, padding 20px; clique fora fecha.
  Caixa: largura 100%, `max-width:480px`, raio 4px, sombra `0 20px 50px -12px rgba(13,27,42,.45)`.
- Cabeçalho: fundo `#0a6fa0`, branco, padding `14px 20px`, negrito, ícone + título:
  armazenamento `bi-hdd` "Editar limite de armazenamento"; usuários `bi-people` "Definir limite de usuários".
- Corpo: padding `18px 20px`, coluna gap 12px, 13.5px `#34505f`. Nome da conta em `<b>` `#12242f`.
- Três quadros (grade 3 colunas, gap 8px; cada um fundo `#f5f8fa`, raio 4px, padding `9px 10px`;
  rótulo 10.5px negrito letter-spacing 1px `#5b7383`; valor 15px negrito `#12242f`, margin-top 3px):
  - armazenamento: "EM USO" = `gb(uso)` · "LIMITE ATUAL" = `cota + ' GB'` (sem casa decimal) · "LIVRE" = `gb(cota - uso)`;
  - usuários: "USUÁRIOS" = `c[2] + 1` · "LIMITE ATUAL" = `c[11]` · "VAGAS" = `c[11] - c[2] - 1`.
- Campo: rótulo 11px negrito letter-spacing 1.1px: "NOVO LIMITE (GB)" ou "NOVO LIMITE DE USUÁRIOS";
  `input type=number` 38px, raio 4px, padding `0 12px`, 14px; borda `1px solid #a3232b` quando abaixo, senão `#d6e0e7`.
- Mensagem (12.5px; cor `#a3232b` quando abaixo, senão `#4a6373`), sempre visível:
  - `novo = +valor || 0` (vazio vira 0);
  - **abaixo** = armazenamento `novo < uso` · usuários `novo < c[2] + 1` (usuários cadastrados);
  - abaixo, armazenamento: "O novo limite é menor que o volume em uso (" + `gb(uso)` + "). Reduzir não apaga arquivos, por isso não é permitido abaixo do uso."
  - abaixo, usuários: "Menor que os usuários já cadastrados."
  - senão: "Diferença: " + (`delta >= 0` ? "+" : "") + `delta` + (" GB" | " usuário(s)") + ". A alteração fica registrada na auditoria."
    com `delta = novo - limiteAtual` (sem formatação decimal; "+0" quando igual ao atual).
- Justificativa: rótulo "JUSTIFICATIVA", input texto 38px, borda `#d6e0e7`, placeholder "Obrigatória, fica na auditoria".
- Rodapé: flex à direita, gap 8px, padding `12px 20px`, fundo `#f5f8fa`.
  "Cancelar": 34px, transparente, 13.5px, padding `0 14px`, hover `#e2e9ee`.
  "Confirmar alteração": 34px, branco, 13.5px negrito, padding `0 16px`; fundo `#0a6fa0` / cursor pointer quando liberado,
  `#9fb3c0` / `not-allowed` + `disabled` quando bloqueado.
- **Bloqueio** (`limBloq`): sem conta, ou `novo <= 0`, ou abaixo do uso, ou `justificativa.trim()` vazia.
  Igual ao limite atual **é permitido** (delta +0), desde que haja justificativa.
- Salvar: troca o limite da conta e registra auditoria com ação "Limite de armazenamento" / "Limite de usuários"
  e detalhe `"<anterior> → <novo> · <justificativa>"`, onde anterior/novo = "<n> GB" ou "<n> usuários"
  (ex. do estado inicial, L481: "15 GB → 20 GB · cliente contratou ampliação", "4 → 5 usuários · novo estagiário" **[EXEMPLO]**).
- Omissos no desenho: opção "sem limite" de usuários (está na spec, não no desenho); casas decimais no GB; teto máximo.

Textos exatos renderizados (Rodrigues):
- aberto (arm): "EM USO" "17,6 GB" · "LIMITE ATUAL" "20 GB" · "LIVRE" "2,4 GB" · "Diferença: +0 GB. A alteração fica registrada na auditoria."
- vazio/abaixo (arm): "O novo limite é menor que o volume em uso (17,6 GB). Reduzir não apaga arquivos, por isso não é permitido abaixo do uso."
- válido 25 (arm): "Diferença: +5 GB. A alteração fica registrada na auditoria."
- aberto (usu): "USUÁRIOS" "8" · "LIMITE ATUAL" "8" · "VAGAS" "0" · "Diferença: +0 usuário(s). A alteração fica registrada na auditoria."
- vazio/abaixo (usu): "Menor que os usuários já cadastrados."
- válido 10 (usu): "Diferença: +2 usuário(s). A alteração fica registrada na auditoria."

## 5. Observações para a implementação

- Sem travessão em texto visível: os textos do desenho acima já não têm. As setas "→" são do detalhe de auditoria.
- O protótipo não persiste nada; as faixas mudam ao digitar, sem botão de salvar (omisso: convenção do sistema).
- "Mudanças de plano com impacto comercial exigem confirmação" (nota) não tem tela no desenho.
