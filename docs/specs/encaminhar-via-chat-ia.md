# Encaminhar via Chat I.A (desenho 02)

Risco **MÉDIO**: leva conteúdo de pasta a outra pessoa. Desenho: `docs/design/redesign-19-10-26/02 - EXPEDIENTES - VS
18.10.26.dc.html` (D) e `bluejus-central.js` (BC). "Chat I.A" é o nome que o desenho dá à Central de Comunicação.

## Estado da frente

- **19/10:** investigação concluída, perguntas de produto respondidas pelo coordenador (§2).
- **19/10, PR1 (backend):** implementado na branch `encaminhar-chat-ia` (commit 82327702), em revisão.
- **19/10, PR2:** a base de presença (#61) entrou; PR2 (tela de despacho e API da janela, §4.1) na branch
  `encaminhar-tela`, empilhada sobre `encaminhar-chat-ia` (#108).
- **19/10, PR2 implementado (em revisão):** `GET /central/encaminhar/item` (`ObterItemDoEncaminhamentoUseCase`, as
  guardas e o leitor do envio), `window.BJCentral` no `central-flutuante.js` (só com o lançador na página; na `/central`
  não há API nesta fatia), tela "Despachar via Chat I.A" no `central.js` (`mostrarTela('despacho')`) e o chip "Pasta N"
  **só como texto**: a mensagem não guarda o id da pasta, o chip sai da linha "Pasta NUP" e não abre a pasta (link
  depende da coluna `referencia`, §5). Pendente: smoke do dono e o Playwright comparativo.
- **20/10, PR1, correção de segurança (bloqueante da revisão):** quem entrava no grupo depois do despacho (ou perdia
  o acesso à pasta) lia no histórico o título, o trecho do teor e o NUP. Agora a mensagem guarda a referência
  (`despacho_pasta_id`, `despacho_tipo`; migration `Version20261021180000`) e toda leitura confere a pasta na hora
  (`LeituraDasMensagens`); o contrato novo da `MensagemOutput` está em §4.1. Também: conversa sem ninguém que receba é
  recusada, e o registro do Financeiro pede o financeiro de quem envia e de quem recebe.

### Para o dono

- **Desvio de segurança (informação, não pedido):** o desenho lista todos os colegas em "Para quem?". Aqui só aparece
  quem vê a pasta (`AcessoAPasta::podeVer`), porque mandar o conteúdo a quem não vê furaria o cadeado da pasta. O
  protótipo não filtra porque não tem cadeado.

## 1. O que o desenho pede

Um item "Encaminhar via Chat I.A" (ou o botão ↪) em 14 pontos da pasta. Todos chamam `BJCentral.despachar({tipo,
titulo, sub, id?, link?, ctx:{pasta, cliente}})` (D:4885), que abre a tela de despacho (BC:6218-6238):

- cabeçalho "DESPACHAR VIA CHAT I.A" e "Cancelar";
- cartão do item (ícone por tipo, título, resumo);
- "Instrução (opcional). Ex.: favor concluir até sexta.";
- prioridade **Normal / Urgente / Para hoje**;
- "Para quem?": conversas (grupos inclusive, menos a da IA) e colegas sem conversa.

Ao escolher o destino: a mensagem `[🔴 URGENTE · | 🟠 PARA HOJE · ]Despacho: TÍTULO\nRESUMO\n\nINSTRUÇÃO` vai para a
conversa (aberta ou criada); "Urgente" põe também um aviso no sino de cada membro; abre a conversa e avisa
"Despachado para NOME.". Na conversa aparece o chip "Pasta N", que abre a pasta.

Pontos do desenho (linha D: tipo):

| D | Onde | tipo |
|---|---|---|
| 1405, 1936, 2463, 6305, 6869 | registros de Dados, Financeiro e Detalhes (botão ↪ e menu) | registro |
| 6958 | arquivo do Financeiro | documento |
| 3072 | ⋮ do processo | processo |
| 3737 | menu da meta | meta |
| 6561 | ⋮ do prazo no trilho | meta (sem id) |
| 5071 | menu do explorador | documento ou pasta |
| 2658, 5641 | movimentação do Push | push |
| 5632 | documento do PDF do processo | push (sem id) |
| 5659 | nota técnica do Push | push |

À parte: **"Enviar no Chat I.A"** (D:6872, menu da observação de Detalhes) abre a Central e copia o texto da
observação para colar ("Chat I.A aberto. Texto copiado para colar na conversa"). E **"Responsáveis nas metas"**
(D:1686/6706): clicar na pessoa abre a conversa 1:1 com ela.

## 2. Decisões (coordenador, 19/10)

1. Destinatário que não vê a pasta **some da lista** (desvio de segurança, ver "Para o dono").
2. Aba oculta não restringe: item do Financeiro pode ir para quem tem o Financeiro oculto (aba oculta é organização).
3. Grupo com algum membro que não vê a pasta: **o grupo inteiro fica fora**, com o motivo.
4. v1 com o chip "Pasta N" que abre a pasta, como o desenho mostra. Sem link para o registro exato, sem migration.
5. Quem perde o acesso depois mantém o texto já entregue.
6. **Segue o desenho:** prefixo com 🔴 e 🟠. "Urgente" gera aviso no sino de tipo novo `central_despacho`; "Para hoje" é só o prefixo.
7. Auditoria só com tipo, item #id e destinatário #id, sem texto (regra 3 da Central).
8. "Enviar no Chat I.A" (D:6872) **entra**, em fatia própria.
9. Itens sem id no desenho (prazo, documento do PDF do Push): a referência é a pasta.
10. A conversa da IA não existe no sistema: nada a excluir.
11. Membro de grupo sem vínculo ativo com o escritório não conta como destinatário (segue a decisão 5): não trava o
    grupo e não ganha aviso; ele já não lê a Central. Pasta excluída (lápide) dá 404 na lista e no envio.

## 3. Contratos

- `AcessoAPasta::podeVer(User, Pasta): bool` (`App\Pasta\Service\AcessoAPasta`, frente `pasta-cadeado` da b5). Se o
  PR1 sair antes, ele traz um provisório com o mesmo nome e assinatura sobre `PermissionChecker::canAccessResource(...,
  RESOURCE_PASTA, id, VIEW)`, que a b5 substitui.
- `EncaminharItemDaPastaUseCase::executar(User $eu, Tenant, EncaminharItemInput): EncaminhamentoOutput`
  - Input: `pastaId`, `tipo` (enum `TipoDeItemEncaminhado`: pasta, registro_dados, registro_detalhes,
    registro_financeiro, documento, secao, processo, meta, prazo, push, nota_tecnica; item sem id vai como `pasta`),
    `itemId?`, destino (`usuarioId` XOR `conversaId`), `instrucao?` (até 1000), `prioridade` (enum: normal, urgente,
    para_hoje).
  - O **servidor** relê o item e monta título e resumo (nunca o texto do cliente); item de outra pasta ou de outro
    escritório = 404. No tipo `pasta` o `itemId` é ignorado e a auditoria grava `item_id = null`.
  - Texto: `[🔴 URGENTE · | 🟠 PARA HOJE · ]Despacho: TÍTULO` + `\nRESUMO` (se houver) + `\nPasta NUP` (sempre; no
    tipo `pasta` ela já é o título e não se repete) + `\n\nINSTRUÇÃO` (se houver). Os resumos por tipo não repetem a
    pasta. O chip "Pasta N" do PR2 sai dessa linha OU de uma coluna futura (`referencia`, §5); a decisão é do PR2.
  - Uma transação só: abrir a 1:1, a mensagem, os avisos e a auditoria entram juntos ou não entram. A corrida de dois
    abrindo a mesma 1:1 segue em 409 (`ConversaCriadaAoMesmoTempoException`).
  - Exige: eu vejo a pasta; o destino (cada participante ativo, no grupo) vê a pasta. Senão recusa com o motivo.
    Conversa sem ninguém que receba (a 1:1 com quem saiu do escritório, o grupo em que só eu fiquei) é recusada com
    "Ninguém mais nesta conversa pode receber." (`todosVeem([])` é falso; na lista de destinos o grupo fica
    indisponível com esse motivo). Colega de outro escritório pelo `usuarioId`: a mesma recusa de um id que não existe
    (422 "Colega não encontrado neste escritório."), sem dizer se ele existe.
  - Registro do Financeiro (`registro_financeiro`): quem envia e cada um que recebe também passam por
    `VisibilidadeDoFinanceiroDaPasta::podeVer` (quem envia sem ver = 404; destinatário sem ver = 422 com o motivo).
    A lista de destinos não recebe o tipo e segue filtrando só pela pasta (hoje as duas regras coincidem).
  - **Referência e conferência na leitura (20/10).** A mensagem do despacho grava `despacho_pasta_id` (FK para a pasta,
    `ON DELETE SET NULL`) e `despacho_tipo` (o `TipoDeItemEncaminhado`), pelo `EnviarMensagemUseCase` (parâmetro
    `ReferenciaDeDespacho`), na mesma transação; a mensagem comum fica com as duas nulas. Toda saída do texto de uma
    mensagem (histórico, novidades e janela de 30 s, prévia da lista, trecho citado de `respostaA`, a própria resposta
    do envio) passa por `LeituraDasMensagens`: com `despacho_tipo`, quem lê precisa ver a pasta NAQUELA HORA
    (`AcessoAPasta::podeVer`; no Financeiro também o financeiro). Pasta apagada (nula), excluída (lápide) ou de outro
    escritório conta como não vê. Quem não vê recebe "Item de pasta sem acesso" e nenhuma referência. A pasta é buscada
    com o escritório da mensagem explícito; a resposta fica em memória só durante aquela leitura. Isto substitui a
    decisão 2.5 ("quem perde o acesso depois mantém o texto já entregue") e o "recheck na leitura" de §5, por causa
    da ressalva do dono na spec B (o histórico respeita as permissões, inclusive para participantes novos).
  - Abre ou reaproveita a 1:1 (`AbrirConversaUseCase`) e grava a mensagem pelo caminho do `EnviarMensagemUseCase`
    (participação, menções, limite de 4000).
  - Urgente: `Notificacao` tipo `central_despacho` para cada membro, sem o texto do item (só "Despacho urgente de
    NOME"), com o link da conversa.
  - Auditoria: linha manual no `audit_log` com `action = 'despacho'` (a coluna tem 10 caracteres), entidade Pasta,
    só tipo, item_id, conversa_id e prioridade.
- `ListarDestinosDoEncaminhamentoUseCase(User, Tenant, pastaId)`: conversas e colegas que veem a pasta; grupos fora
  levam `motivo`.
- Rotas (Central, CSRF `ajax`): `GET /central/encaminhar/destinos?pasta=N`, `POST /central/encaminhar`.
- API da janela: `window.BJCentral.despachar(item)` e `window.BJCentral.conversar(userId)`, expostas pelo lançador
  flutuante (e pela `/central`). Texto puro, `textContent`.

## 4. Fatias

1. **PR1, backend:** os dois use cases, rotas, tipo `despacho` em `TiposDeAvisoDaCentral`, testes (unit + functional
   + isolamento sem `TenantFilter`).

   **Contrato da `MensagemOutput` para a tela (PR2 em diante).** Toda mensagem que a Central devolve (histórico
   `GET /central/conversas/{id}/mensagens`, `GET /central/novidades`, `ultima` de `GET /central/conversas`, a resposta
   de `POST /central/conversas/{id}/mensagens`) leva dois campos novos:

   - `despacho`: `{"pastaId": int, "nup": string|null}` só quando a mensagem é um despacho E quem lê vê a pasta agora;
     `null` na mensagem comum e no despacho que o leitor não vê. O chip "Pasta N" sai daqui (`nup`, ou o id quando
     `nup` é nulo), nunca do texto.
   - `ocultoPorPermissao`: `true` quando é despacho e o leitor não vê a pasta; então `conteudo` é
     "Item de pasta sem acesso" e `despacho` é `null`. `false` em todo o resto.

   A citação (`respostaA`) ganha `ocultoPorPermissao` também: citação de um despacho que o leitor não vê traz `trecho`
   = "Item de pasta sem acesso". A tela não deve tentar reconstruir a pasta a partir do texto.
2. **PR2, tela de despacho e API da janela** (`central.js`, flutuante, `_janela`): espera a base de presença entrar
   (arquivo quente em ~10 PRs).
3. **PR3, "Responsáveis nas metas" abre a conversa:** `PastaMetasResumoOutput` leva o `userId`.
4. **PR4 em diante, os menus, um por área:** (a) Dados, Detalhes, Financeiro; (b) metas e prazo; (c) processo, depois
   de #91 e #75; (d) explorador e nota técnica; (e) Push, com a 0d, depois de #82, #87 e #98.
5. **"Enviar no Chat I.A"** (D:6872): fatia pequena com a API do PR2.

## 4.1 Contrato da API da janela (PR2, 19/10): para as outras áreas e a 0d

A janela da Central expõe, onde o lançador flutuante está na página (`central_disponivel()`; a `/central`, sem
lançador e sem menus de pasta, não expõe), o objeto
`window.BJCentral` (nome do desenho, BC:6645) com três funções. Só identificadores saem da tela; o servidor relê
tudo.

| Função | O que faz |
|---|---|
| `BJCentral.abrir()` | abre a janela flutuante, sem trocar de conversa |
| evento `bj-central:abrir` no document | o mesmo que `BJCentral.abrir()`: só abre a janela (vem do #111, `enviar-chat-ia`) |
| `BJCentral.despachar({pastaId, tipo, itemId})` | abre a janela na tela "Despachar via Chat I.A" (BC:6218-6238) com o cartão do item; `tipo` é um valor de `TipoDeItemEncaminhado` (§3), `itemId` é `null` no tipo `pasta` |
| `BJCentral.conversar(userId)` | abre a janela na conversa 1:1 com o colega (PR3); si mesmo ou colega fora do escritório: só abre a janela |

Também por evento, para quem carrega antes da janela: `document.dispatchEvent(new CustomEvent('bj-central:despachar',
{detail: {pastaId, tipo, itemId}}))` e `bj-central:conversar` com `{userId}`. Ausente (`window.BJCentral`
indefinido), o item de menu não aparece (a tela que monta o menu confere `central_disponivel()` no Twig ou
`window.BJCentral` no JS).

Rotas que a tela usa: `GET /central/encaminhar/item?pasta=N&tipo=T&item=I` (novo no PR2: `{titulo, resumo}` do
cartão, montado pelo mesmo `ItensEncaminhaveisDaPasta`; 404 se não achar ou eu não vir), `GET
/central/encaminhar/destinos?pasta=N` e `POST /central/encaminhar` (PR1).

Tela de despacho (BC:6218-6238, literal): cabeçalho "DESPACHAR VIA CHAT I.A" e "Cancelar"; cartão com ícone por tipo,
título e resumo; "Instrução (opcional). Ex.: favor concluir até sexta."; prioridade Normal / Urgente / Para hoje;
"Para quem?" com os destinos (grupo travado mostra o motivo, sem clique); ao escolher, envia, abre a conversa com o
destino e avisa "Despachado para NOME.". Erro do servidor: o `erro` da resposta, na própria tela. "Cancelar" volta à
lista (BC:6223 só zera o despacho). Desvio registrado: a busca e as abas da lista, que no protótipo ficam à vista e
inertes atrás da tela (BC:6362-6372), não aparecem no despacho. O chip "Pasta N" sai da linha "Pasta NUP" do texto,
só como texto (sem o id da pasta na mensagem, não abre a pasta; abrir exige a coluna `referencia` da §5).

## 5. Fora

- Link para o registro exato e recheck na leitura (§2.4); entram com uma coluna `referencia` numa fase futura.
