# Cadeado completo da pasta (desenho 02)

Frente `pasta-cadeado` · base `origin/master` · risco **MÉDIO** (permissão por pasta; nada de identidade) · aberta em
20/10/2026 pela sessão b5, a pedido da coordenação. **Com migration** (fila do coordenador).

## Estado da frente (20/10/2026)

**Contratos**
- 5d, dona da tranca liga/desliga de hoje: o cadeado é construído sobre a tranca e a substitui; a 5d não mexe
  mais nela.
- 5d, "Encaminhar via Chat I.A": o destinatário passa por `AcessoAPasta::podeVer(User, Pasta)` (§3). Se a 5d
  precisar antes, cria um serviço provisório com esse mesmo nome. **Colisão combinada (20/10):** se o PR da 5d
  entrar no master antes deste, no merge do `origin/master` fica a versão DESTA frente do
  `app/src/Pasta/Service/AcessoAPasta.php`, que é superconjunto; `podeVer(User, Pasta)` mantém a mesma
  assinatura e a mesma semântica (vê = não `Oculto` + permissão de ver a pasta, isto é, o `canAccessResource`
  de `view`, que já aplica o teto).
- 5d, Lista do Expediente (#85): o `expediente-lista.js` desta frente mexe só perto do `abrirOuAvisar` e
  preserva os trechos do #85 (`ExpMenu.abertoNoToque`, `menuAbertoNoToque`, `#pastaRespMenu:not([hidden])`).
- 0d, "ocultar aba" (#68, aberto): é um eixo separado, que só subtrai (§4). O resolvedor de pasta pela rota fica
  compartilhado. O #68 entra no master ANTES deste PR; ao trazer o master, este PR adapta o
  `BarreiraDeAbaOcultaListener` dela ao `PastaDaRota`, e o `BarreiraDeAbaOcultaMapaTest` tem de continuar verde.
- 9b, busca Ctrl+K (#106, SQL nativo): usa `AcessoAPasta::idsOcultosPara()` e `niveisEmLote()` (§3). Quando
  este PR entrar, a 9b troca a regra provisória dela (`BuscarRapidoUseCase::calcularVisibilidade`) e a rota de
  "Pedir acesso" (hoje `expediente_pasta_pedir_acesso`) pelas desta frente. A rota nova do pedido,
  `pasta_acesso_pedir` (POST `/pasta/{id}/acesso/pedidos`, campos `nivel`, `motivo`, `_token` =
  `pasta_acesso_<id>`; JSON em XHR), serve à Lista e à busca e não exige o módulo Expediente.

**Produção (20/10):** 0 pastas trancadas, 0 `resource_access` de pasta e 0 pedidos de acesso, em 1.214 pastas.
Nenhum comportamento atual muda para ninguém.

**A confirmar pelo dono:** no desenho a decisão do pedido de acesso é no Chat I.A; a faixa de pedidos na pasta é
provisória. Ela é um componente isolado para ser levada ao chat: parcial `pasta/_cadeado_pedidos.html.twig`, módulo
`public/js/pasta-cadeado-pedidos.js` (só chama `pasta_acesso_decidir`) e a decisão inteira no
`DecidirPedidoDeAcessoUseCase` e no endpoint JSON (XHR), que o chat pode chamar.

**Pedido pela Lista:** a janela "Pasta N protegida" pede pela rota da pasta (`pasta_acesso_pedir`, com o token da
própria pasta na linha, `data-csrf-pedir`); a rota antiga `expediente_pasta_pedir_acesso` saiu.

**Decidido:** D3 foi confirmada pela coordenação em 20/10. Cadeado = restrição; ocultar aba = organização.

## 1. Fontes

- Desenho `docs/design/redesign-19-10-26/02 - EXPEDIENTES - VS 18.10.26.dc.html`:
  - L507-550: modal "Cadeado da pasta N";
  - L1235-1239: seção "Acesso · Primário" no ⋮, com o selo Livre / Restrito / Todos;
  - L1276-1300: tela "Pasta trancada" e pedido de acesso;
  - L6353: faixa de somente visualização;
  - lógica em L6077-6117 e `bluejus-central.js:150-226` (pedido).
- Estado medido: só a tranca binária `Pasta.trancada_em/por`, aviso na tela da Lista e nenhum bloqueio no servidor
  (o docblock da Pasta diz isso). O pedido de acesso é só uma notificação, sem estados.
- `docs/AUTORIZACAO.md`, nas camadas 2a/2b (bypasses) e 5b (`canAccessResource`).

## 2. Regras

| # | Regra |
|---|---|
| K1 | **Quem configura:** o Master e o primário com vínculo ativo, por um critério só (`RegraDosPrimarios::podeGerirPrimarios`). Quem configura nunca é restringido. "O usuário primário sempre vê e edita tudo." |
| K2 | **Modos:** sem cadeado = livre. `Todos` = a equipe não vê a pasta. `PorPessoa` = cada pessoa tem um nível, `Oculto`, `Visualizar` ou `Editar`. Quem não está na lista fica `Oculto` (padrão fechado do desenho), inclusive quem entrar depois. Ao abrir um cadeado novo, todos os atuais começam em `Editar` (L6116). |
| K3 | **Oculto:** a pessoa vê só o cabeçalho com o nome do cliente e o CPF, mais a tela "Pasta trancada". Nada de abas, documentos, processo, Push, financeiro, IA nem menu ⋮. |
| K4 | **Visualizar:** lê tudo o que o "ocultar aba" deixar; toda escrita na pasta é recusada no servidor. A faixa diz "Pasta trancada pelo usuário primário: você pode visualizar, mas não alterar." e tem o botão "Pedir para editar". Preferências pessoais (favorito, visita da timeline) continuam valendo e são classificadas uma a uma no PR. |
| K5 | **Pedido de acesso:** é uma entidade. Cada pedido tem nível pedido, motivo opcional (até 300 caracteres) e estado `Pendente`, `LiberadoVisualizar`, `LiberadoEditar` ou `Recusado`. Fica um pendente por pasta e pessoa, e um novo pedido só depois de 24 h do anterior. O pedido notifica cada primário ativo e o Master; o primário decide entre "Liberar para editar", "Liberar só visualizar" e "Recusar", e o solicitante é notificado da resposta. Liberar uma pasta em modo `Todos` a converte para `PorPessoa`, com os demais em `Oculto` e o solicitante no nível liberado (`cadResolver` do protótipo). |
| K6 | **Auditoria:** abrir, salvar, remover e decidir pedido gravam na auditoria da conta e no histórico da pasta. |
| K7 | **Migração:** pasta com `trancada_em` vira cadeado `Todos`, com o mesmo autor e data (hoje são 0). As colunas antigas saem. |

## 3. Ponto único: `App\Pasta\Service\AcessoAPasta`

- `nivel(User $pessoa, Pasta $pasta): NivelDeAcesso` devolve `Oculto`, `Visualizar` ou `Editar`:
  - Master ou primário com vínculo ativo na conta da pasta → `Editar`;
  - pessoa sem vínculo ativo com a conta da pasta → `Oculto`;
  - sem cadeado → `Editar`;
  - `Todos` → `Oculto`;
  - `PorPessoa` → o nível da pessoa, ou `Oculto`.
- Atalhos `podeVer()` e `podeEditar()`: o `canAccessResource` completo (`view`/`edit`) da pasta, com o teto
  dentro (vê = não `Oculto` + permissão de ver a pasta). Memória por request; uma consulta por pasta.
- Para listas e buscas (combinado com a 9b, 20/10):
  - `niveisEmLote(User, array $pastas): array<int, NivelDeAcesso>`, indexado pelo id da pasta, uma consulta
    de cadeados para N pastas;
  - `restringirAoVisivel(QueryBuilder, string $aliasPasta, User): void`, uso (a): só as pastas que a pessoa
    vê (sem cadeado, ou `Visualizar`/`Editar`); Master e primário veem tudo;
  - `criterioDeVisivel(QueryBuilder, string $aliasPasta, User): ?string`, uso (b): o mesmo critério em DQL,
    com os parâmetros já postos, para combinar. A busca da Lista usa assim: a pasta oculta continua listada
    e casa só por número da pasta e cliente (D1);
  - `idsOcultosPara(User, int $tenantId): list<int>`, para SQL nativo (#106): os ids das pastas do escritório
    ocultas para a pessoa, numa consulta só (vazio para primário e Master). Quem usa aplica
    `p.id NOT IN (:ocultos)` nos dados completos e limita os campos de busca dessas ao número, cliente e CPF.
- A regra do nível mora em `NivelNoCadeado`, separado só para o `PermissionChecker` não depender de quem
  depende dele; o `AcessoAPasta` é a fachada que todo o resto usa.
- **Teto dentro de `PermissionChecker::canAccessResource`**, quando `resourceType === 'pasta'`, depois dos bypasses
  e ANTES do `ResourceAccess`/fallback: `view` exige pelo menos `Visualizar`, e `edit`/`delete` exigem `Editar`.
  Assim um pedido genérico aprovado (`AccessRequest`) nunca fura o cadeado. Isso cobre os ~40 pontos que já
  passam por RA, além da Central, do financeiro e da IA.
- **Barreira de rota** (`kernel.controller_arguments`), com o resolvedor de pasta compartilhado com o
  `PastaSomenteLeituraListener` e com o `BarreiraDeAbaOcultaListener` (#68), extraído para uma classe só:
  - `Oculto`: recusa toda rota de pasta, menos `pasta_show`, que desenha a tela trancada, e o pedido de acesso;
  - `Visualizar`: recusa os métodos não seguros nas rotas de pasta.

  Fica travada por um teste de arquitetura no molde do `PastaSomenteLeituraRotasArquiteturaTest`.
- **Resolvedor** `App\Pasta\Service\PastaDaRota` (combinado com a 0d, 20/10): acha a pasta pelo argumento
  `Pasta`, por uma filha com `getPasta()` (ou neta pela meta, `TarefaMensagem`), por `#[PastaPelaFilha]` e por
  `#[PastaPorId]`. Entrega também a ENTIDADE FILHA (`PastaDaRequisicao::$filha`), não só a pasta: o contrato
  com o Financeiro oculto (`recusaDoContrato`) usa a filha `PastaDocumento`. As buscas declaradas são por id E
  escritório da sessão; pasta de outro escritório passa reto pelas barreiras e quem dá o 404 é a action
  (`ehDoEscritorioDaSessao()`). Usado pela lápide, pelo cadeado e, depois do merge do #68, pela aba oculta.

## 4. Relação com o "ocultar aba" (#68)

São dois eixos e os dois só restringem. O cadeado é o **teto da pasta inteira**; a aba oculta subtrai abas dentro
do que o cadeado permite. Com `Oculto` a aba não importa; com `Visualizar` as abas visíveis ficam em leitura. Quem
configura os dois é o mesmo grupo (K1).

## 5. Onde aplicar fora da pasta (decisões)

- **D1** (busca e lista do Expediente, CSV, sugestões, cliente, processo). A pasta oculta continua listada com
  número, cliente e CPF e o cadeado na linha, como a tranca de hoje e o "nome do cliente e CPF" do desenho. Nenhum
  outro dado aparece: ação, réus, número e assunto do processo, responsável, metas. A busca não casa por número ou
  assunto do processo dessas pastas para quem não vê.
- **D2** (IA). A busca de pastas do agente e os documentos do agente respeitam `podeVer`. O agente recebe só o
  número e o cliente de pasta oculta (L4383).
- **D3** (superfícies globais), **confirmada pela coordenação em 20/10**. O cadeado é RESTRIÇÃO de verdade; o "ocultar
  aba" (#68) é ORGANIZAÇÃO, pela definição do dono. Por isso, para quem não vê a pasta:
  - o Push global (`/push-processual`) e as metas da pasta oculta (`/tarefas`) somem;
  - qualquer cadeado desliga o link externo do Push, como a tranca já faz hoje;
  - a BlueJus IA (contexto, análises e agentes da pasta) fica bloqueada, como o desenho cita.
- **D4** (auditoria). Linhas de entidades Pasta* de pasta com cadeado não aparecem com o conteúdo na auditoria do
  escritório para quem não vê a pasta (padrão `ConteudoParticular`), e o Master vê tudo.
- **D5** (imagem embutida na peça, `/uploads/pastas/{nome}`). Passa a conferir `podeVer` na pasta dona da imagem.
- **D6** (Drive sync). Pasta com cadeado não é exportada para o Drive de quem não vê, mas o sync é do escritório;
  na primeira versão, pasta com cadeado sai do sync. Confirmar na implementação.

### Decisões da implementação (20/10)

- **D6 confirmada:** pasta com cadeado, de qualquer modo, sai do sync do Drive (não cria, não envia, não
  baixa, não renomeia); remover o cadeado a devolve.
- **D1, janela do cliente:** o `ClienteResumoController` já esconde inteira a pasta que a pessoa não pode
  abrir; com o teto, a pasta oculta também some dali (mais restritivo que D1). Sugestões da Nova Pasta só
  mostram nomes de cliente, que D1 permite.
- **D3, metas:** a meta da pasta oculta sai das Minhas Metas (lista, contagens das abas, KPIs do topo e trilho)
  e de `/tarefas/equipe` (lista e total), pelos ids de `idsOcultosPara()`. Para quem só visualiza, as rotas
  de escrita da meta da pasta são recusadas (a meta tem `getPasta()` e entra na barreira).
- **D4, entidades cobertas:** `App\Pasta\Entity\*`, `Tarefa`, `TarefaMensagem` e `AnaliseDeInteligencia` com alvo
  `pasta` (linha sem o conteúdo e sem desfazer, também no servidor). Fora: `CasoCobranca` (Cobrança),
  `NotaTecnica` (processo) e `Notificacao` (pessoal).
- **K5, onde o primário decide (a confirmar pelo dono):** o desenho decide na conversa com a BlueJus IA, que não
  existe no sistema. O aviso no sino leva à pasta (`#cadeado-pedidos`), onde uma faixa PROVISÓRIA, no estilo
  da de somente visualização, traz os três botões do desenho (componente isolado, ver "Estado da frente").
- **K5, quem pode pedir:** quem veria a pasta se ela estivesse livre (`canAccessResourceIgnoringPastaLock`);
  quem nem assim veria recebe 403. O pedido avisa os primários ativos e os Masters com vínculo ativo no
  escritório.
- **K5, antispam e desvio de texto:** um pedido novo só 24 h depois do anterior, também depois de recusa.
  Enquanto a janela vale, o recusado diz "O usuário primário não liberou o pedido anterior. Você pode pedir
  de novo a partir de dd/mm às HH:MM." (o texto do desenho mais a data, **desvio de texto**); passado o
  prazo, volta a ser o texto exato do desenho. A decisão é travada no banco (UPDATE condicional "ainda
  pendente"): a segunda decisão simultânea recebe 422.
- **K3, processo (decisão do orquestrador, 20/10):** o processo cujas pastas vinculadas são TODAS ocultas
  para a pessoa não abre (403) e some da lista de processos; as notas técnicas dele seguem a mesma regra.
  Processo sem pasta, ou com pelo menos uma visível, segue normal.
- **D1, filtros como oráculo:** com qualquer filtro de atributo (responsável, situação, prioridade,
  criador, datas, painéis do Expediente, ação, marcador) a pasta oculta fica FORA do resultado e das
  contagens do trilho; ela só aparece sem filtro de atributo ou casando a busca por número ou cliente.
  Vale para a Lista e para o agente. Na Lista e na janela do cliente, a linha oculta não mostra
  prioridade nem situação.
- **Agenda (4b):** os prazos e audiências da pasta oculta saem da Agenda (`ListarCompromissosUseCase` e
  `PastaPrazoRepository::abertosDoEscritorioNoPeriodo`).
- **Cobrança:** a busca de pasta para vincular não oferece a oculta; vincular exige poder editar a pasta
  pelo cadeado (nível `Editar`), não a permissão `resources.pasta.edit`, para não mudar quem já vincula hoje.
- **Avisos da meta:** os avisos de meta criada, devolvida, concluída, em revisão e o "Alertar" não vão para
  quem está oculto na pasta da meta.
- **pasta_show:** a tela trancada só aparece para quem veria a pasta se ela estivesse livre; os demais
  seguem o caminho antigo, com o redirect do pedido genérico, sem saber do cadeado.
- **K6:** o histórico da pasta é o cartão do sistema no Registro ("Cadeado: …", `RegistroDoSistemaNaPasta`);
  a auditoria da conta vem das entidades auditáveis (`CadeadoDaPasta`, `AcessoAPastaTrancada`,
  `PedidoDeAcessoAPasta`).
- **Lista do Expediente:** "Trancar pasta" do botão direito abre o cadeado "Ocultar para todos";
  "Destrancar" remove o cadeado de qualquer modo (o ajuste por pessoa é no modal da pasta).

## 6. Telas (o desenho manda)

- ⋮ da pasta: seção "Acesso · Primário" (só quem configura), item "Cadeado" com o selo Livre / Restrito / Todos.
- Modal "Cadeado da pasta N" (L507-550):
  - modos "Escolher por pessoa" e "Ocultar para todos";
  - grade Ocultar / Visualizar / Editar por colaborador e "Aplicar a todos";
  - resumo "N editam, N só visualizam, N não veem. O usuário primário sempre vê e edita tudo." ou o texto do modo
    Todos;
  - botões "Remover cadeado" e "Trancar".
- Tela "Pasta trancada" (L1276-1293):
  - cabeçalho com o cliente e o texto "O usuário primário restringiu o acesso a esta pasta. Você vê apenas o nome
    do cliente e o CPF…";
  - campo de motivo e "Pedir acesso ao primário";
  - estados "Pedido enviado às HH:MM. Aguardando o usuário primário." e "O usuário primário não liberou o pedido
    anterior. Você pode pedir de novo.".
- Faixa de somente visualização (L1295-1297) com "Pedir para editar".
- Pedidos de acesso pendentes, para quem configura: **a confirmar pelo dono**, no desenho a decisão é no Chat
  I.A; a faixa na pasta é provisória (`pasta/_cadeado_pedidos.html.twig` + `pasta-cadeado-pedidos.js`).
- Na Lista do Expediente, a janela "Pasta N protegida" da 5d passa a refletir o nível. A pessoa com `Visualizar`
  abre a pasta normalmente; com `Oculto`, vê a janela com "Pedir acesso".

## 7. Testes

- Unit do `AcessoAPasta`: cada modo e nível, primário, Master, sem vínculo.
- Unit dos UseCases: configurar, remover, pedir, decidir e conversão de `Todos`.
- Functional:
  - `pasta_show` com cada nível;
  - escrita recusada em `Visualizar`;
  - 403 nas sub-rotas em `Oculto`;
  - o `AccessRequest` aprovado não fura o cadeado;
  - D1, D2, D3 e D5;
  - pedido duplicado e janela de 24 h;
  - notificações.
- Teste de arquitetura das rotas de pasta cobertas pela barreira.
