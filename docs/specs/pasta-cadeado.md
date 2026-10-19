# Cadeado completo da pasta (desenho 02)

Frente `pasta-cadeado` · base `origin/master` · risco **MÉDIO** (permissão por pasta; nada de identidade) · aberta em
20/10/2026 pela sessão b5, a pedido da coordenação. **Com migration** (fila do coordenador).

## Estado da frente (20/10/2026)

**Contratos**
- 5d, dona da tranca liga/desliga de hoje: o cadeado é construído sobre a tranca e a substitui; a 5d não mexe
  mais nela.
- 5d, "Encaminhar via Chat I.A": o destinatário passa por `AcessoAPasta::podeVer(User, Pasta)` (§3). Se a 5d
  precisar antes, cria um serviço provisório com esse mesmo nome.
- 0d, "ocultar aba" (#68, aberto): é um eixo separado, que só subtrai (§4). O resolvedor de pasta pela rota fica
  compartilhado.

**Produção (20/10):** 0 pastas trancadas, 0 `resource_access` de pasta e 0 pedidos de acesso, em 1.214 pastas.
Nenhum comportamento atual muda para ninguém.

**Para o dono:** o alcance nas telas globais (§5, D3) é decisão da coordenação, pelo texto do desenho.

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
- Atalhos `podeVer()` e `podeEditar()`. Memória por request; uma consulta por pasta.
- **Teto dentro de `PermissionChecker::canAccessResource`**, quando `resourceType === 'pasta'`, depois dos bypasses
  e ANTES do `ResourceAccess`/fallback: `view` exige pelo menos `Visualizar`, e `edit`/`delete` exigem `Editar`.
  Assim um pedido genérico aprovado (`AccessRequest`) nunca fura o cadeado. Isso cobre os ~40 pontos que já
  passam por RA, além da Central, do financeiro e da IA.
- **Barreira de rota** (`kernel.controller_arguments`), com o resolvedor de pasta compartilhado com o
  `PastaSomenteLeituraListener` e com o `BarreiraDeAbaOcultaListener` (#68), extraído para uma classe só:
  - `Oculto`: recusa toda rota de pasta, menos `pasta_show`, que desenha a tela trancada, e o pedido de acesso;
  - `Visualizar`: recusa os métodos não seguros nas rotas de pasta.

  Fica travada por um teste de arquitetura no molde do `PastaSomenteLeituraRotasArquiteturaTest`.

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
- **D3** (superfícies globais: `/push-processual`, `/tarefas`, links externos já criados). O desenho diz que, com
  o cadeado, "Push, financeiro e a BlueJus IA ficam bloqueados". Ao contrário do "ocultar aba", que é
  organização, o cadeado é restrição. Proposta da b5: o Push global e as metas de pasta oculta somem para quem
  não vê (filtro por `podeVer`), e o cadeado `Todos` ou `PorPessoa` desliga o link externo do Push, como a tranca
  já faz hoje. **A coordenação confirma.**
- **D4** (auditoria). Linhas de entidades Pasta* de pasta com cadeado não aparecem com o conteúdo na auditoria do
  escritório para quem não vê a pasta (padrão `ConteudoParticular`), e o Master vê tudo.
- **D5** (imagem embutida na peça, `/uploads/pastas/{nome}`). Passa a conferir `podeVer` na pasta dona da imagem.
- **D6** (Drive sync). Pasta com cadeado não é exportada para o Drive de quem não vê, mas o sync é do escritório;
  na primeira versão, pasta com cadeado sai do sync. Confirmar na implementação.

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
