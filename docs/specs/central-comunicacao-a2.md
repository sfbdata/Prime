# Central de Comunicação — A2: título, @menção, responder e menus de botão direito (spec)

Frente `designer-central-ia`. Continua a A1 (`central-comunicacao-a1.md`), cujas regras de acesso valem
inteiras aqui. Decisões do dono que valem: **D2** polling; **D3** sem papel novo; **D5** só ação com regra e
servidor de verdade; **D6** componente isolado, sem tocar `base.html.twig` nem `_sidebar.html.twig`. Título
definido pelo dono em 14/10: **"Central de Comunicação"**. Base de desenho: `bluejus-central.js` do Designer
13/10 (`menuCtxEl` L5697, `menuConversa` L5758, `menuMensagem` L5873, `pintarTexto` L5856, citação L6035 e
L6068, autocomplete L6094). Risco **MÉDIO** (conteúdo entre pessoas, notificação, multi-tenant).

## 1. Escopo

**Entra:**

1. Título da janela: "Chat I.A" → **"Central de Comunicação"** (template e JS).
2. **@menção** no texto da mensagem, com **notificação real** (`App\Entity\Notificacao`) para quem foi mencionado.
3. **Responder** uma mensagem (citação no balão e faixa "Respondendo a…" no compositor).
4. **Marcar conversa como não lida** (e "como lida", que já existe no servidor desde a A1).
5. **Menu de botão direito da conversa:** Abrir · Marcar como lida *ou* Marcar como não lida.
6. **Menu de botão direito da mensagem:** Responder · Copiar · Mencionar ‹Primeiro nome› (só em mensagem de
   outra pessoa).
7. `/central?conversa=N` abre direto a conversa N — é o link da notificação.

**Fica fora (sem regra aprovada → D5; nenhum item aparece desabilitado nem simulado):** fixar, silenciar,
arquivar, ocultar, "marcar para revisar", excluir conversa, ver informações, renomear/foto de grupo; editar,
excluir, fixar, favoritar, encaminhar mensagem, "exibir contexto", reações; `@Todos` e `@IA` (não há grupo nem IA
ativa); grupos, anexos, presença; botão global/link no menu (D6).

## 2. @menção

- **Formato gravado:** o token canônico da casa, o mesmo do Registro da pasta e da nota técnica:
  `@[Nome](user:ID)`. O compositor mostra `@Nome`; o envio troca pelo token de quem foi escolhido na lista.
- **Quem pode ser mencionado:** participante da conversa, **com vínculo ativo** no escritório da sessão, que não
  seja o autor. Na A2 (só conversa 1:1) é o outro participante; a regra já vale para grupo.
- **No servidor, ao enviar:** cada token é conferido. Mencionável → o rótulo é **reescrito com o nome do banco**
  (ninguém forja "@Diretor" com o id de outro). Não mencionável (id de fora da conversa, de outro escritório,
  sem vínculo, o próprio autor) → o token vira texto comum `@Rótulo` e **não** notifica. No máximo 20 tokens lidos
  por mensagem (mesmo teto do Registro).
- **Notificação** (uma por pessoa mencionada, gravada no mesmo `flush` da mensagem):
  - `tipo = central_mencao` (constante nova em `Notificacao`, ícone `bi-at text-primary` — o tipo "mencao" do
    desenho), categoria pessoal;
  - `titulo = "‹Primeiro nome do autor› mencionou você na Central de Comunicação"`;
  - `mensagem = null` — **nenhum trecho da conversa**. A `Notificacao` é `Auditavel`: o `audit_log` grava o estado
    inteiro e é lido por quem administra o escritório; o texto da mensagem não pode ir para lá (regra 3 do
    `src/Central/CLAUDE.md`);
  - `url = /central?conversa=N`; `tenant` = o da conversa.
  - **Sem enxurrada:** se a pessoa já tem uma notificação `central_mencao` **não lida** com a mesma `url`, não se
    cria outra.
  - Ao **ler a conversa** (`POST …/lida`), as notificações `central_mencao` não lidas daquela conversa, do próprio
    leitor, viram lidas.
- **Exposição aceita e registrada (pendência para o dono):** o `audit_log` da criação da notificação registra
  *quem* mencionou *quem* e em que conversa (ator, destinatário, título, url) — metadado, nunca o texto. É o mesmo
  que já acontece com a menção do Registro. Tirar a `Notificacao` da auditoria é mudança em arquivo compartilhado
  e fica para decisão do dono.
- **Exibição:** a menção válida aparece em negrito na cor do desenho (`#0a7aad`; branco no balão próprio). Na
  prévia da lista, na citação e no "Copiar" vira texto `@Nome`.
- **Autocomplete:** ao digitar `@` (`/@([^\s@]{0,30})$/`), lista os mencionáveis da conversa aberta, que o
  servidor devolve junto com as mensagens (`mencionaveis: [{id, nome}]`). ↑/↓ navegam, Enter/Tab escolhem, Esc fecha.

## 3. Responder

- Coluna nova `central_mensagem.resposta_a_id` (FK para `central_mensagem`, `ON DELETE SET NULL`, nula).
- `POST …/mensagens` aceita `respostaAId` opcional. Precisa ser mensagem **da mesma conversa e do mesmo
  escritório**; senão 422 (não confirma se a mensagem existe em outro lugar).
- `Mensagem` (JSON) ganha `respostaA: {id, autorNome, trecho} | null`; `trecho` = conteúdo da original com as
  menções como texto, cortado em 120 caracteres. É a mesma conversa — quem vê a resposta já pode ver a original.
- Clicar na citação rola até a original, se ela estiver carregada.

## 4. Marcar como não lida

- Coluna nova `central_participante.marcada_nao_lida` (booleano, padrão `false`). **O cursor não recua** — a
  invariante da A1 continua: a marca é um sinal à parte, do próprio participante.
- `POST /central/conversas/{id}/nao-lida` (`central_conversa_nao_lida`, CSRF `ajax`) liga a marca. Só
  participante; conversa sem mensagem nenhuma → 422 (não há o que "não ler").
- `POST …/lida` (já existente) **também desliga** a marca, mesmo quando o cursor não precisa andar.
- A lista devolve `marcadaNaoLida`; a linha aparece como não lida (negrito e selo sem número). O total de não
  lidas do cabeçalho segue contando **mensagens** (a marca não inventa mensagem).
- Abrir a conversa a marca como lida (fluxo da A1), o que desliga a marca.

## 5. Rotas novas ou alteradas

| Método e rota | Nome | Mudança |
|---|---|---|
| `GET /central?conversa=N` | `central_index` | abre a conversa N se for minha (a tela confere contra a minha lista; id inválido é ignorado) |
| `GET /central/conversas` | `central_conversas` | + `marcadaNaoLida` por conversa |
| `GET /central/conversas/{id}/mensagens` | `central_mensagens` | + `mencionaveis`; cada mensagem + `respostaA` |
| `POST /central/conversas/{id}/mensagens` | `central_mensagem_enviar` | + `respostaAId` opcional; menções resolvidas e notificadas |
| `POST /central/conversas/{id}/lida` | `central_conversa_lida` | também desliga `marcadaNaoLida` e lê as notificações de menção da conversa |
| `POST /central/conversas/{id}/nao-lida` | `central_conversa_nao_lida` | nova |

## 6. Tela

- Menus de contexto no estilo do `menuCtxEl` do desenho: 220 px, fundo branco, raio 4 px, sombra
  `0 8px 28px rgba(0,0,0,.24)`, itens de 33 px, 13 px, cor `#243845`, ícone 16 px `#5f7684`, hover `#eef5fa`;
  limitado à janela do navegador com margem de 8 px; fecha com clique fora, Esc, rolagem ou outro botão direito.
  Acessível por teclado: `Shift+F10`/tecla de menu na linha ou no balão focado; ↑/↓ e Enter dentro do menu.
- Citação no balão e faixa do compositor com os valores do desenho (L6035, L6068).
- Autocomplete com os valores do desenho (L6094): cabeçalho "Mencionar", itens de 13 px, selecionado `#e5f1f7`.
- Tudo por `createElement`/`textContent`, nunca `innerHTML`. Clipboard via `navigator.clipboard.writeText`
  (com recuo para `document.execCommand('copy')` em contexto sem HTTPS).

## 7. Modelo e migration

Uma migration nova, só colunas novas e nulas/com padrão (sem dado a migrar):
`central_mensagem.resposta_a_id INT NULL` + FK `ON DELETE SET NULL` + índice;
`central_participante.marcada_nao_lida BOOLEAN DEFAULT false NOT NULL`. DDL tirada do `--dump-sql` filtrado por
`central_`, nunca `make:migration` puro.

## 8. Testes

- Unit: resolução das menções (rótulo reescrito, id de fora vira texto, autor não se menciona, teto 20);
  UseCases de enviar (resposta da mesma conversa, resposta de outra → 422, notificação sem texto, sem duplicar),
  marcar não lida, lida desliga a marca.
- Functional: menção gera `Notificacao` só para o participante; **não** para terceiro do mesmo escritório, nem para
  quem é de outro escritório, nem para quem tem vínculo nos dois mas não participa; notificação sem o texto;
  `respostaAId` de outra conversa/escritório → 422; marcar não lida/lida; conversa alheia → 404 nas rotas novas;
  CSRF nas rotas novas; `mencionaveis` só com participantes ativos.
- Prova por reintrodução: retirar cada guarda nova (participante na menção, tenant da resposta, dedupe, texto fora
  da notificação, participação no `nao-lida`) derruba o teste certo.
