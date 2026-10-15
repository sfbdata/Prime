# Central de Comunicação — B: conversas em grupo e preferências individuais (spec)

Frente `designer-central-ia`. Continua a A1 (`central-comunicacao-a1.md`) e a A2 (`central-comunicacao-a2.md`), cujas
regras valem inteiras. Decisões do dono que valem aqui: **D2** polling; **D3** sem papel novo; **D5** só ação com regra;
**D6** sem tocar `base.html.twig`/`_sidebar.html.twig`. Decisões de 14/10: fixar, silenciar e arquivar são **preferências
individuais**; silenciar **não impede** receber mensagens; arquivar **não exclui** e permite reabrir; nenhuma dessas ações
altera a preferência de outro participante. Base de desenho: `bluejus-central.js` do Designer 13/10 (grupos: `criarGrupo`
L342, `telaNovoGrupo` L6202, `telaInfo` L6164, `sistemaGrupo` L346, preferências `convPrefs` L300, `menuConversa` L5758).
Risco **MÉDIO** (conteúdo entre pessoas, participação, notificação, multi-tenant).

## 1. Escopo

**Entra:** criar grupo (nome + participantes do mesmo escritório); grupos na mesma lista das conversas individuais;
enviar/receber, histórico, não lidas e polling em grupo; adicionar e remover participante; sair do grupo; renomear;
@menção e aviso no sino em grupo; responder e menus de contexto em grupo; mensagens de sistema ("Ana adicionou Bruno");
fixar/desafixar, silenciar/reativar, arquivar/desarquivar (por participante, em grupo e individual); correção do cursor
do polling (limitação registrada na A1 §6).

**Fica fora:** `@Todos` e `@IA`; foto do grupo; grupo automático "Equipe Pasta N" e grupos de Meta/Demanda/Processo;
ocultar, excluir conversa, "marcar para revisar"; mensagem fixada e favoritas; anexos, presença, "digitando"; Chat I.A.

## 2. Regras de participação (backend é quem decide)

| Ação | Quem pode | Observação |
|---|---|---|
| Criar grupo | quem tem vínculo ativo | nome 1–60 caracteres; de 1 a 99 outros participantes, todos com vínculo ativo no MESMO escritório; quem cria vira o **administrador do grupo** |
| Ler e escrever | participante **ativo** (não saiu nem foi removido) com vínculo ativo | como na A1: alheio é 404 |
| Adicionar participante | só o **administrador do grupo** | o novo precisa de vínculo ativo no escritório; quem já está ativo → 422; quem tinha saído **volta** (a mesma participação é reativada) |
| Remover participante | só o administrador | não remove a si mesmo (usa "Sair"); não-administrador → 403 (é participante: saber que o grupo existe não é vazamento) |
| Sair do grupo | qualquer participante ativo | se o administrador sai, a administração passa ao participante ativo mais antigo **com vínculo ativo**; se não sobrar ninguém, o grupo fica sem administrador e sem leitores |
| Renomear | qualquer participante ativo | como no desenho (L6191, L5768) |
| Fixar / silenciar / arquivar | cada participante, só para si | em grupo e em individual |

- **Administração efetiva (correção da revisão, 15/10):** se o administrador gravado saiu do grupo ou perdeu o vínculo
  com o escritório, quem administra é o participante ativo mais antigo com vínculo ativo — e a troca é gravada na
  primeira ação dele. Sem isso, um administrador desligado travaria o grupo para sempre (D3 não tem bypass).
  ⚠️ Proposta ao dono (§8).
- **Readmissão** recomeça limpa: cursor na última mensagem, sem as preferências de antes (fixada, silenciada,
  arquivada, marcada como não lida). Adicionar e readmitir correm sob a trava do grupo (`pg_advisory_xact_lock(4715,
  grupo)`): duas adições simultâneas não duplicam linha de sistema, aviso nem passam do teto.
- **Quem sai ou é removido** tem os avisos daquele grupo marcados como lidos (apontavam para algo que não abre mais).
- **Id inválido** na criação (texto, negativo, grande demais) recusa o pedido inteiro (422), como o id de outro
  escritório.
- **Administrador do grupo não é papel do sistema (D3):** é uma coluna da conversa (`administrador_id`), não passa pelo
  `PermissionChecker`; nem o administrador do escritório nem o super admin administram grupo de que não participam.
- **Histórico de quem entra:** vê as mensagens anteriores do grupo, como no desenho (L308) — mas elas **não contam como
  não lidas** (o cursor começa na última mensagem do grupo no momento da entrada). ⚠️ Proposta ao dono: se preferir que
  quem entra só veja dali para frente, é uma coluna `visivel_desde_id` (ver §8).
- **Quem sai ou é removido:** perde o acesso ao grupo inteiro (404), inclusive ao histórico; o grupo some da lista dele.
  Readmitido, volta a ver tudo.
- **Grupo não é excluído:** o desenho não tem "excluir grupo".
- **Concorrência:** adicionar a mesma pessoa duas vezes ao mesmo tempo → a segunda recebe 409 (único `(conversa_id,
  usuario_id)`); nada duplica.

## 3. Mensagens de sistema

Gravadas na própria conversa (`central_mensagem.sistema = true`, autor = quem fez), texto montado pelo servidor:
"‹Primeiro› criou o grupo", "‹Primeiro› adicionou ‹Primeiro›", "‹Primeiro› removeu ‹Primeiro›", "‹Primeiro› saiu do grupo",
"‹Primeiro› renomeou o grupo para "‹nome›"". Não contam como não lidas, não podem ser respondidas nem mencionam ninguém.
Na tela: pílula centralizada (L6029: fundo `#e8eef2`, texto `#5f7684`, 11.5px/600, raio 999px).

## 4. Notificações

- **Menção em grupo:** a regra da A2 (participante **ativo** desta conversa, com vínculo ativo, não o autor; aviso sem o
  texto; trava por destinatário contra duplicata; ler a conversa lê o aviso). O título passa a citar o grupo:
  "‹Primeiro› mencionou você no grupo ‹nome›".
- **Adicionado ao grupo:** aviso "‹Primeiro› adicionou você ao grupo ‹nome›" (`central_grupo_adicionado`, L6185), link
  para a conversa. Também sem conteúdo de mensagem.
- **Mensagem comum de grupo não gera aviso** no sino: a lista e as não lidas dão conta (o desenho só cria notificação
  silenciosa que nem aparece na caixa — L385, L5782).
- **Silenciar** (desenho L87, L137, L5693): a conversa continua recebendo tudo e mostrando as não lidas dela (selo cinza),
  mas **sai do total de não lidas** do cabeçalho. O aviso de menção **continua sendo criado** (no desenho ele só perde o
  som; o sino do BlueJus não tem som). ⚠️ Proposta ao dono: "silenciar também cala a menção" é uma linha a mais (§8).
- **Arquivar** (L320, L382, L6260): some da lista principal; aparece em "Ver conversas arquivadas"; **desarquiva quando
  eu envio** mensagem nela; mensagem que chega **não** desarquiva; continua contando no total de não lidas.
- **Fixar** (L324): fixadas primeiro, depois pela mais recente; sem limite.

## 5. Privacidade

- As entidades da Central seguem **fora** da auditoria. A `Notificacao` é auditada: os avisos carregam só quem fez, o
  nome do grupo e o link — o nome do grupo vai ao `audit_log` (metadado, como o título da conversa; decisão de 14/10
  sobre metadado mantida).
- Participação inativa não lê nada: toda consulta da Central filtra `saiu_em IS NULL`, além do tenant explícito.
- Mencionável e adicionável: só pessoa com vínculo ativo no escritório da sessão.

## 6. Polling (correção da limitação A1 §6)

O cursor por id perde a mensagem confirmada fora de ordem (id 100 confirma depois do 101). Correção: `GET
/central/novidades?desde=N` devolve as mensagens com `id > N` **e também**, em consulta à parte, as das minhas
conversas criadas nos últimos **30 s** com id ≤ N (janela de sobreposição); a tela descarta as que já viu (conjunto de
ids vistos). Só as novas contam para o teto e para `completo` — a janela cheia nunca faz a tela pedir de novo sem fim
(achado da revisão). Uma transação que demore mais
de 30 s para confirmar uma mensagem ainda escaparia ao polling (aparece ao reabrir a conversa) — limite registrado.

## 7. Rotas novas

| Método e rota | Nome | Faz |
|---|---|---|
| `POST /central/grupos` `{nome, participantes:[ids]}` | `central_grupo_criar` | cria; 201 `{conversa}` |
| `GET /central/conversas/{id}/participantes` | `central_participantes` | participantes ativos `{id, nome, administrador, ativoNoEscritorio}` + `souAdministrador` |
| `POST /central/conversas/{id}/participantes` `{usuarioId}` | `central_participante_adicionar` | admin |
| `DELETE /central/conversas/{id}/participantes/{usuarioId}` | `central_participante_remover` | admin |
| `POST /central/conversas/{id}/sair` | `central_grupo_sair` | participante |
| `POST /central/conversas/{id}/renomear` `{nome}` | `central_grupo_renomear` | participante |
| `POST /central/conversas/{id}/preferencias` `{fixada?, silenciada?, arquivada?}` | `central_preferencias` | só as chaves enviadas, booleanas |

Todas as de escrita com CSRF `ajax`. Ações de grupo em conversa individual → 422. A lista (`GET /central/conversas`)
ganha `tipo`, `nome`, `participantes` (contagem), `fixada`, `silenciada`, `arquivada`, `souAdministrador`; `outro` fica
`null` em grupo; e a `Mensagem` ganha `sistema`.

## 8. Decisões que ficam com o dono (o padrão escolhido está em vigor e é reversível)

1. Quem entra no grupo vê o histórico anterior (desenho) × só dali para frente.
2. Silenciar mantém o aviso de menção (desenho) × silenciar cala também a menção.
3. Renomear por qualquer participante (desenho) × só o administrador.
4. `@Todos` em grupo — fora desta entrega.
5. Administração vaga vai ao participante mais antigo com vínculo (em vigor) × outra regra (ex.: o mais antigo de
   todos, ou exigir indicação).

## 9. Modelo e migration

Uma migration, só acrescentando (nada é apagado, nenhum dado existente muda de sentido):
`central_conversa`: `nome VARCHAR(60) NULL`, `administrador_id INT NULL` (FK `"user"`), `usuario_menor_id` e
`usuario_maior_id` passam a aceitar NULL (só grupo usa NULL; a unicidade do par continua valendo para individual — no
PostgreSQL NULL não colide); `central_participante`: `saiu_em TIMESTAMP NULL`, `fixada`, `silenciada`, `arquivada`
`BOOLEAN DEFAULT false NOT NULL`; `central_mensagem`: `sistema BOOLEAN DEFAULT false NOT NULL`. Índice para a janela do
polling: `central_mensagem (tenant_id, criada_em)`.

## 10. Testes

Unit dos UseCases e entidades (regras do §2–§4). Functional: isolamento entre escritórios em todas as rotas novas;
participante que saiu perde acesso (404) e some da lista; não-admin não adiciona/remove (403); terceiro → 404; menção em
grupo só para ativo; aviso de adicionado; preferências só do próprio; silenciada fora do total; arquivada some e volta ao
enviar; fixada no topo; polling com janela; CSRF nas novas. Prova por reintrodução das guardas novas. Suíte completa.
