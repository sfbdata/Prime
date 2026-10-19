# Encaminhar via Chat I.A (desenho 02)

Risco **MÉDIO**: leva conteúdo de pasta a outra pessoa. Desenho: `docs/design/redesign-19-10-26/02 - EXPEDIENTES - VS
18.10.26.dc.html` (D) e `bluejus-central.js` (BC). "Chat I.A" é o nome que o desenho dá à Central de Comunicação.

## Estado da frente

- **19/10:** investigação concluída, perguntas de produto respondidas pelo coordenador (§2). Spec v1. Nenhum código.
- **Próximo:** PR1, o backend (§4), cortado do master.

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
6. **Segue o desenho:** prefixo com 🔴 e 🟠. "Urgente" gera aviso no sino de tipo novo `despacho`; "Para hoje" é só o prefixo.
7. Auditoria só com tipo, item #id e destinatário #id, sem texto (regra 3 da Central).
8. "Enviar no Chat I.A" (D:6872) **entra**, em fatia própria.
9. Itens sem id no desenho (prazo, documento do PDF do Push): a referência é a pasta.
10. A conversa da IA não existe no sistema: nada a excluir.

## 3. Contratos

- `AcessoAPasta::podeVer(User, Pasta): bool` (`App\Pasta\Service\AcessoAPasta`, frente `pasta-cadeado` da b5). Se o
  PR1 sair antes, ele traz um provisório com o mesmo nome e assinatura sobre `PermissionChecker::canAccessResource(...,
  RESOURCE_PASTA, id, VIEW)`, que a b5 substitui.
- `EncaminharItemDaPastaUseCase::executar(User $eu, Tenant, EncaminharItemInput): EncaminhamentoOutput`
  - Input: `pastaId`, `tipo` (enum `TipoDeItemEncaminhado`: registro, documento, pasta, processo, meta, push),
    `itemId?`, destino (`usuarioId` XOR `conversaId`), `instrucao?` (até 1000), `prioridade` (enum: normal, urgente,
    para_hoje).
  - O **servidor** relê o item e monta título e resumo (nunca o texto do cliente); item de outra pasta ou de outro
    escritório = 404.
  - Exige: eu vejo a pasta; o destino (cada participante ativo, no grupo) vê a pasta. Senão recusa com o motivo.
  - Abre ou reaproveita a 1:1 (`AbrirConversaUseCase`) e grava a mensagem pelo caminho do `EnviarMensagemUseCase`
    (participação, menções, limite de 4000).
  - Urgente: `Notificacao` tipo `despacho` para cada membro, sem o texto do item (só "Despacho urgente de NOME").
- `ListarDestinosDoEncaminhamentoUseCase(User, Tenant, pastaId)`: conversas e colegas que veem a pasta; grupos fora
  levam `motivo`.
- Rotas (Central, CSRF `ajax`): `GET /central/encaminhar/destinos?pasta=N`, `POST /central/encaminhar`.
- API da janela: `window.BJCentral.despachar(item)` e `window.BJCentral.conversar(userId)`, expostas pelo lançador
  flutuante (e pela `/central`). Texto puro, `textContent`.

## 4. Fatias

1. **PR1, backend:** os dois use cases, rotas, tipo `despacho` em `TiposDeAvisoDaCentral`, testes (unit + functional
   + isolamento sem `TenantFilter`).
2. **PR2, tela de despacho e API da janela** (`central.js`, flutuante, `_janela`): espera a base de presença entrar
   (arquivo quente em ~10 PRs).
3. **PR3, "Responsáveis nas metas" abre a conversa:** `PastaMetasResumoOutput` leva o `userId`.
4. **PR4 em diante, os menus, um por área:** (a) Dados, Detalhes, Financeiro; (b) metas e prazo; (c) processo, depois
   de #91 e #75; (d) explorador e nota técnica; (e) Push, com a 0d, depois de #82, #87 e #98.
5. **"Enviar no Chat I.A"** (D:6872): fatia pequena com a API do PR2.

## 5. Fora

- Link para o registro exato e recheck na leitura (§2.4); entram com uma coluna `referencia` numa fase futura.
