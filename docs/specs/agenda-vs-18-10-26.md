# Agenda nova (desenho "09 - Agenda - VS 18.10.26")

Desenho: `docs/design/agenda-vs-18-10-26/09 - Agenda - VS 18.10.26.dc.html` (+ `bj-lembretes.js` no
projeto do Claude Design). Risco: BAIXO na tela; **MÉDIO** na ligação com o Google (token de usuário).
O protótipo guarda tudo em `localStorage`; aqui tudo vai para o servidor, por escritório e por usuário.

## Estado da frente (sessão 4b; atualizado em 19/10/2026; reler antes de continuar após compactação)

**Decisões do dono (com data)**
- 19/10: tudo o que está no desenho entra; não perguntar escopo.
- 15/10 (Fase A): balão de dica do `bj-dica.js` só na Central; no resto, dica do navegador (`title`).
- O smoke no navegador é do dono; a 4b foi autorizada a abrir a implementação no navegador (Playwright e
  `scripts/central-demo.sh` na porta 8106, banco isolado `demo_agenda4b_*`) para comparar com o desenho.
- PENDENTE (coordenador levou ao dono): os 23 registros antigos de auditoria de "Só eu" em produção. Não
  mexer até a resposta.

**Decisões do coordenador (19/10, sem o dono)**
- Push: Messenger sem cron, cadeia de 60 s; chaves VAPID e worker na lista do dono.
- Google: D1-D5 de `docs/specs/agenda-google.md`; cancelado no Google vira "cancelado" (não apaga);
  Desconectar por LGPD art. 8º §5º; a mesma conta Google num escritório só (agenda pessoal não vaza).
- "Só eu" escondido da auditoria (#81), antes do #78.
- Acertos finais (§17): itens 1-6 e 8; o 7 removido; `.ics` fora; botão "Abrir na agenda" não entra
  (o protótipo da Central de 05/10 descarta `acao`; o de 18/10 guarda em `ctx.acao` mas não desenha; fechado).

**Contratos com outras sessões**
- Lembretes pelo parcial `templates/layout/_globais/lembretes.html.twig` (ponto de inclusão da frente
  menu-do-usuario); `base.html.twig` não muda.
- Bloco `AppAgenda` no `doctrine.yaml` logo depois de `App`.
- ColaboradoresAtribuiveis (#69): aplicar no modal (participantes e avisados) quando entrar no master,
  com teste de participante suspenso preservado.
- Central sem dono: não mexer agora; o #61 (5d) tocou `central.js`.
- O `Evento` muda de pasta na pilha (#63): ao trazer o master depois do #81, levar o
  `implements ConteudoParticular` e os dois métodos para `App\Agenda\Entity\Evento`.

**PRs e ordem de merge (pilha)**
- #58 agenda-nova (base master) → #63 agenda-modal → #66 agenda-troca → #70 agenda-ajustes →
  #73 agenda-lembretes → #76 agenda-push → #78 agenda-google (RASCUNHO até o #81 entrar) →
  acertos finais (branch agenda-acertos, base #78, a abrir).
- #81 auditoria-so-eu (base master, independente da pilha): entra antes do #78.

**Fila de migrations (uma por vez, na fila do coordenador)**
- #63: Version20261019125154 e Version20261019125854 (inclui o UPDATE do audit_log de produção, que o
  coordenador leva ao dono).
- #70: Version20261019135744. #76: Version20261019144101. #78: Version20261019170000.
- #58, #66, #73, #81 e acertos: sem migration. Renomear o horário se outra migration entrar antes.

**Pendências**
- Acertos finais: suíte completa e revisão rodando; depois commit, push e PR sobre o #78.
- Depois do #81 no master: trazer o master para a pilha e levar o `ConteudoParticular` (ver acima).
- Depois do #69: ColaboradoresAtribuiveis.
- Material do dono: chaves VAPID e worker (#76); cliente OAuth do Google e as duas variáveis (#78, §8).
- Ponta a ponta do Google com conta real; iPhone real (push e toque longo).

**Próximo passo:** fechar o PR de acertos finais; depois, só o ColaboradoresAtribuiveis quando o #69 entrar.

## 1. Inventário do desenho

### Visível
| # | Item | Comportamento no desenho |
|---|---|---|
| V1 | Abas EXPEDIENTE · MINHAS METAS · AGENDA | Agenda vira a 3ª aba do grupo do Expediente |
| V2 | 4 cartões | Prazos hoje, Amanhã, Nos próximos 7 dias, Audiências hoje (vêm das pastas). Clique leva à visão Dia/Semana. Cinza quando zero |
| V3 | Novo compromisso | Abre o modal no dia selecionado |
| V4 | Mini calendário | Navega por mês; ponto azul no dia com evento (exceto feriado nacional); clique leva ao dia |
| V5 | CALENDÁRIOS (10) | Liga/desliga cada fonte; contagem só nos tipos próprios; escolha lembrada por usuário |
| V6 | Feriados estaduais de: UF | UF da lotação do Meu Perfil + estado do escritório |
| V7 | TESTAR LEMBRETES | Audiência, Reunião, Prazo, Compromisso: dispara o aviso de teste com som |
| V8 | GOOGLE AGENDA | Botão Sincronizar, status (Conectado / Não conectado / Disponível quando o servidor liberar). Sincronia **nos dois sentidos**; "Só eu" continua particular; feriados do sistema não vão |
| V9 | Barra | Hoje, ‹ ›, título, busca (digitar muda para LISTA), Minha agenda / Equipe do escritório, DIA/SEMANA/MÊS/LISTA |
| V10 | Faixa de aniversário | "Hoje é aniversário de X!" + balão e confete na célula |
| V11 | Visão Mês | Rótulo âmbar do feriado ao lado do número (prioridade nacional > estadual > tribunal > recesso); até 3 eventos e "+ N mais" (vai para Dia); clique no vazio cria compromisso; na Equipe, evento de outro leva "Nome:"; privado leva 🔒 |
| V12 | Visões Dia/Semana | 07h às 22h, clique na hora cria, faixa de dia inteiro, linha vermelha do "agora" |
| V13 | Visão Lista | Próximos 60 dias, agrupado por dia, com o nome do calendário |
| V14 | Próximos feriados | Mês atual + próximo: nacionais, estaduais e início do recesso; "em N dias"; selo PONTE em terça/quinta; clique leva ao dia |
| V15 | Modal leitura | Quando, calendário, dono (se de outro), visibilidade, "Abrir a Pasta", observação, avisados, origem. Botões: Google Agenda (link de modelo do Google), Avisar no Chat, Excluir e Editar (se meu) ou Fechar |
| V16 | Modal edição | Título; tipo Compromisso/Audiência/Reunião/Prazo (lembrete padrão 15/60/10/15 min); data, início, fim; Equipe do escritório / Só eu ("ninguém mais vê, nem o primário"); dia inteiro; Pasta (NUP); Lembrete (sem, 10, 15, 30 min, 1 h, 1 dia); AVISAR NO CHAT I.A com autocompletar de @pessoa (setas, Enter/Tab, Esc). Erros: título, data, fim depois do início |
| V17 | Pergunta ao criar em Minha agenda | "Compartilhar com a equipe?" Não, só eu / Sim, compartilhar |
| V18 | Link direto `#ev=<id>` | Abre o compromisso (é o link do aviso no Chat) |

### Invisível (botão direito)
| Onde | Itens |
|---|---|
| Dia vazio | Acrescentar feriado ou data em dd/mm (nome, tipo Nacional/Estadual/Tribunal/Comemorativa, fonte) · Novo compromisso em dd/mm · Restaurar datas do sistema (se algo foi ocultado/editado) |
| Compromisso | Abrir compromisso + itens do dia |
| Data do sistema (também no rótulo âmbar) | Ver detalhes · Editar nome · Ocultar da agenda (ou Excluir, se foi acrescentada à mão) + itens do dia |

### Lembretes (bj-lembretes.js), em **todas** as telas
Cartão no canto com ícone, som próprio por tipo, notificação do navegador, "Lembrar em 10 min",
"Ver na agenda" / "Abrir pasta", dispensar. Verifica a cada 30 s; cobre compromissos com hora e
prazos/audiências das pastas com hora no dia. O comentário do desenho pede, no sistema real, push
pelo servidor com o navegador fechado.

### Pré-eventos (calculados, valem para todo ano)
- **Nacionais (12 no desenho):** Confraternização, Carnaval, Sexta-feira Santa, Tiradentes,
  Trabalho, Corpus Christi, Independência, Aparecida, Finados, Proclamação, Consciência Negra, Natal
  (móveis pela Páscoa). Carnaval e Corpus Christi são ponto facultativo: ver seção 6.
- **Estaduais:** tabela por UF do desenho (22 UFs; ES, GO, MG, MT e SC sem data).
- **Comemorativas (10):** Mulher 08/03, Consumidor 15/03, Mães (2º dom. maio), Namorados 12/06,
  Pais (2º dom. ago.), Advogado 11/08, Estagiário 18/08, Cliente 15/09, Justiça 08/12, Réveillon 31/12.
- **Tribunais e eleições:** seg./ter. de Carnaval, Quarta de Cinzas (a partir das 14h), quarta e
  quinta Santas, Advogado 11/08, Servidor 28/10, Todos os Santos 01/11, Justiça 08/12 (com a fonte
  legal); eleições em ano par.
- **Recesso:** 20/12 a 06/01 "Recesso forense"; 07/01 a 20/01 "Prazos suspensos" (CPC art. 220).
- **Aniversários:** `UserProfile.dataNascimento`.
- **Prazos e audiências:** `PastaPrazo` (só leitura; editar é na pasta).

## 2. Distância do código atual
Hoje: `AgendaController` legado + `Evento` + FullCalendar 6.1.8 via CDN, legendas de cor livres,
participantes, recorrência, status concluído/cancelado, arrastar e soltar. Nada de pré-eventos,
prazos das pastas, lembretes, Google, botão direito. `Lotacao` só tem `nome` (UF sai do final,
ex. "Maceió/AL", como no desenho). Não há push nem Service Worker no sistema.

## 3. Solução

**Domínio novo `app/src/Agenda/`** (Controller/UseCase/Entity/Repository/DTO/Service), migrando o
legado aos poucos. A tela é escrita do zero no layout do desenho, **sem FullCalendar** (o desenho
tem grade própria; adaptar o FullCalendar a ela custa mais que fazer a grade).

**Fontes da agenda.** Dois endpoints JSON (o `GET /agenda/eventos` legado fica intocado até a tela
nova substituir a antiga):
- `GET /agenda/datas?de&ate` (entrega 1, `app_agenda_datas`): `CalendarioDoSistema` (serviço puro,
  sem banco: nacionais, estaduais por UF, comemorativas, tribunais/eleições, recesso) cruzado com a
  tabela `feriado` do Ponto (só leitura); depois, os ajustes do escritório (`agenda_data_ajuste`).
  Devolve `{ufs, datas[]}`, cada data com `id`, `data`, `titulo`, `calendario`, `tipo`, `fonte`,
  `observacao`, `uf`, `contaNoPonto`. Período de até 400 dias.
- `GET /agenda/compromissos?de&ate&escopo` (entrega 2): `Evento` do escritório filtrado por
  `restringirAoVisivelPara`, aniversários (perfil) e prazos/audiências (`PastaPrazoRepository`).
A tela filtra pelos calendários ligados.

**Id das datas do sistema:** `<calendário>-<AAAA-MM-DD>-<nome em slug, sem o complemento após " · ">`
(ex. `fer-2026-02-17-carnaval`); estaduais `est-<UF>-<data>`; recesso `rec-<data>`; do Ponto
`esc-<data>`. Os ajustes da entrega 4 guardam esse id: o formato não muda.

**Mudanças em `Evento`** (1 migration): `tipo` (meus/aud/reun/prazo), `pasta` (FK opcional pela
NUP), `lembreteMinutos`, `avisados` (usuários), `googleId`/`googleEtag`. Visibilidade já existe
(todos/somente_eu).

**Preferências por usuário** (calendários ligados): tabela pequena `agenda_preferencia`.

**Botão direito:** componente `menu-contexto.js` extraído do `expediente-lista.js` (teclado, tecla
Menu, Shift+F10, toque longo); a Agenda o usa. Os `prompt()` do protótipo viram um modal pequeno.

**Avisar no Chat:** `NotificacaoService::criarNotificacao` com link `/agenda#ev=<id>`.

**Lembretes:** `lembretes.js` carregado pelo parcial `templates/layout/_globais/lembretes.html.twig`
(ponto de inclusão criado pela frente menu-do-usuario; não toca no `base.html.twig`), lendo
`GET /agenda/lembretes` (próximas 24 h do usuário). Push com navegador fechado fica para depois.

**Google Agenda (dois sentidos), em duas entregas:**
1. Botão "Google Agenda" no compromisso (link de modelo do Google, sem login). Imediato.
2. OAuth por **usuário** no molde do `GoogleDriveOAuth` (escopo `calendar.events`, token cifrado,
   fora do audit log). Envio: ao salvar/excluir um compromisso meu. Retorno: tarefa agendada
   (`syncToken` incremental) a cada 5 min. Feriados e prazos das pastas não vão. Requer o dono criar
   o cliente OAuth no Google Cloud e as variáveis no servidor.

## 4. Entregas (um PR cada, uma migration por vez)
1. `CalendarioDoSistema` + endpoint de fontes + testes (sem migration).
2. Tela nova (V1 a V14, V18) com prazos das pastas, sem edição nova.
3. Migration de `Evento` + modal novo (V15 a V17) + Avisar no Chat.
4. Botão direito + `agenda_data_ajuste` (migration).
5. Lembretes globais (V7). As preferências de calendários ficam no navegador, como no desenho (sem migration).
5b. Push com o navegador fechado (Service Worker e envio pelo servidor), prometido no `bj-lembretes.js`.
6. Google: link de modelo; depois OAuth e sincronia (spec própria antes).

## 5. Convivência com o Ponto eletrônico (risco ALTO)

Já existe `App\Ponto\Entity\Feriado` (tabela `feriado`, por escritório, `recorrente` compara só
dia/mês). Ela é semeada pelo `TenantBootstrapService` e pelo `app:seed-feriados-nacionais` (9 datas
fixas) e é a **única** fonte do Ponto: `CalculadoraJornada::calcularSaldoDiaDaEscolha` zera a carga
esperada do dia (`:76-101`), e a `FolhaPontoBuilder` marca "FERIADO" e recalcula os saldos mensal e
anual (`:130-164`, `:320`, `:413`). Qualquer linha nova nessa tabela muda o banco de horas, inclusive
para trás.

Regras da Agenda:
1. **A Agenda nunca grava em `feriado`** e não chama nada do domínio Ponto que escreva. O
   `CalendarioDoSistema` só calcula, em memória.
2. **A Agenda lê `feriado` (somente leitura)** pelo `FeriadoRepository::findByTenant` e mostra cada
   linha como feriado do escritório. Quando a data do Ponto coincide com um feriado **nacional ou
   estadual** calculado, aparece uma vez só, com o nome do desenho, marcada como "conta no ponto"
   (os nomes do desenho são curtos para caber na célula; os do seed do Ponto são longos). Em data só
   de tribunal ou comemorativa, o do Ponto ganha item próprio (`tipo = escritorio`, calendário
   "Feriados nacionais", texto de origem "Cadastrado pelo escritório em Feriados e Folgas"), para que
   desligar "Tribunais e eleições" não esconda um feriado que conta no ponto. A tela usa o `tipo`, não
   o calendário, para escrever a origem.
3. **Cada data mostra se conta no ponto.** No detalhe: "Conta como feriado no ponto" (existe em
   `feriado`) ou "Não conta no ponto: para contar, cadastre em Feriados e Folgas" (só calculada).
   Assim a divergência fica visível em vez de escondida. Ponto facultativo nunca vira feriado no ponto.
4. **Ajustes do botão direito** (acrescentar, renomear, ocultar) ficam numa tabela da Agenda
   (`agenda_data_ajuste`), **separada** de `feriado`. Ocultar na Agenda não tira o feriado do ponto, e
   acrescentar "feriado municipal" na Agenda não muda o ponto. O detalhe da data acrescentada leva o
   link para Feriados e Folgas, para quem quiser que conte no ponto.
5. `Pasta\Service\LeitorDeDatas` (estimativa de prazo da pasta) tem lista própria de 9 feriados e do
   recesso. Fica como está nesta frente; unificar com o `CalendarioDoSistema` é melhoria separada.

## 6. Classificação legal das datas (D3 e D4, resolvidas pela lei)

| Data | Classificação | Fonte |
|---|---|---|
| 01/01, 21/04, 01/05, 07/09, 02/11, 15/11, 25/12 | Feriado nacional | Lei 662/1949 (red. Lei 10.607/2002) |
| 12/10 | Feriado nacional | Lei 6.802/1980 |
| 20/11 | Feriado nacional | Lei 14.759/2023 |
| Sexta-feira da Paixão (Páscoa − 2) | Feriado (religioso) | Lei 9.093/1995, art. 2º; portaria anual do governo federal |
| Carnaval (segunda e terça) | **Ponto facultativo** | portaria anual do governo federal |
| Corpus Christi (Páscoa + 60) | **Ponto facultativo** | portaria anual do governo federal |
| Eleições, 1º turno | 1º domingo de outubro, ano par | CF, arts. 28, 29 e 77 |
| Eleições, 2º turno | **último domingo de outubro**, ano par | CF, art. 77 |

Carnaval e Corpus Christi continuam no calendário "Feriados nacionais", como o desenho mostra, mas
com o nome "… · ponto facultativo" e o tipo "Ponto facultativo" em "Próximos feriados". O desenho
calculava o 2º turno como 1º + 21 dias; em 2026 coincide (25/10), em 2028 daria 22/10 em vez de 29/10.

## 7. Decisões

- **D1 (entrega 3, convenção):** a Agenda atual tem recorrência, participantes, legendas de cor,
  status concluído/cancelado e arrastar e soltar (provas: `Evento.php:18-20, 62-68, 81-83`,
  `LegendaCor.php`, `index.html.twig:319, 375-420`). O desenho é omisso, então **ficam**, no estilo
  do desenho, e entram como diferença registrada no PR.
- **D2 (entrega 4, convenção):** os ajustes de data valem para o **escritório inteiro** e exigem a
  mesma permissão do `FeriadoController` (`admin.tenant.settings.manage`). Quem não tem a permissão
  não vê "Acrescentar", "Editar nome", "Ocultar" nem "Restaurar"; vê "Ver detalhes" e "Novo compromisso".
- **D3 e D4:** pela lei, ver seção 6.
- **D5 (entrega 6):** o dono cria o cliente OAuth do Google. Passo a passo na seção 8.

## 8. Passo a passo para o dono: cliente OAuth do Google Agenda

Use o mesmo projeto do Google Cloud do Google Drive (já tem tela de consentimento).
1. Abra https://console.cloud.google.com/ e, no seletor de projeto no topo, escolha o projeto do Drive.
2. Menu ☰ → **APIs e serviços** → **Biblioteca**. Busque **Google Calendar API** → **Ativar**.
3. Menu ☰ → **APIs e serviços** → **Tela de permissão OAuth** (ou **Google Auth Platform** →
   **Acesso a dados**) → **Adicionar ou remover escopos**. Marque
   `https://www.googleapis.com/auth/calendar.events` → **Atualizar** → **Salvar**.
4. Menu ☰ → **APIs e serviços** → **Credenciais** → **+ Criar credenciais** → **ID do cliente OAuth**.
   Tipo **Aplicativo da Web**, nome "BlueJus Agenda".
5. Em **URIs de redirecionamento autorizados** → **+ Adicionar URI**:
   `https://<domínio de produção>/agenda/google/retorno` (o domínio exato vai no PR da entrega 6).
   → **Criar**.
6. Copie o **ID do cliente** e a **Chave secreta**. Não cole no chat: grave no servidor como
   `GOOGLE_CALENDAR_OAUTH_CLIENT_ID` e `GOOGLE_CALENDAR_OAUTH_CLIENT_SECRET` (o PR da entrega 6 traz o
   comando exato).
7. Enquanto o app estiver em modo **Teste**, só os e-mails em **Público-alvo** → **Usuários de teste**
   conseguem conectar. Para todos os escritórios, **Publicar app** (o escopo de Agenda pode pedir
   verificação do Google, que leva dias).

## 9. Consumidores do calendário do sistema

`App\Agenda\Service\CalendarioDoSistema::doPeriodo()` e `App\Agenda\UseCase\ListarDatasDoSistemaUseCase`
são a **única fonte** de feriados, pontos facultativos e datas comemorativas fora do Ponto. Contrato
público e estável (assinatura, campos de `DataDoSistemaOutput` e formato do id).
- Agenda nova (`GET /agenda/datas`).
- Perfis, seção "Comercial e e-mails" (desenho 05): campanhas por data comemorativa. Deve ler daqui,
  não criar outra lista.

## 10. Entrega 2: tela em /agenda/nova (conferência item a item)

A tela nova fica em `/agenda/nova` (rota `agenda_nova`, fora do menu) até a entrega 3 trazer o modal
de criação e edição; a `/agenda` antiga segue inteira. A aba "Agenda" da faixa de abas aponta para a
antiga até a troca.

Estado contra o inventário da seção 1 (✓ feito · ◐ feito em parte, falta na entrega indicada · ✗ falta):

| # | Item | Estado |
|---|---|---|
| V1 | Aba AGENDA na faixa de abas | ✓ (Demandas e Trabalho Externo continuam: diferença registrada) |
| V2 | 4 cartões e clique para Dia/Semana | ✓ |
| V3 | Novo compromisso | ◐ abre o formulário antigo no dia; modal do desenho na entrega 3 |
| V4 | Mini calendário | ✓ |
| V5 | 10 calendários com marcação | ✓ lembrada no navegador, como no desenho (o protótipo guarda em `localStorage`, `bj-agenda-v1-vis`); sem migration |
| V6 | Feriados estaduais de: UF | ✓ |
| V7 | TESTAR LEMBRETES | ✓ (entrega 5, seção 15) |
| V8 | GOOGLE AGENDA | ◐ painel e aviso "Disponível quando o servidor liberar a conexão"; ligação na entrega 6 |
| V9 | Barra: Hoje, setas, título, busca, escopo, vistas | ✓ |
| V10 | Faixa de aniversário, balão e confete | ✓ |
| V11 | Mês | ◐ clique no vazio abre o formulário antigo (entrega 3) |
| V12 | Dia e Semana | ◐ clique na hora abre o formulário antigo (entrega 3) |
| V13 | Lista dos próximos 60 dias | ✓ |
| V14 | Próximos feriados com PONTE | ✓ |
| V15 | Detalhe | ◐ leitura e "Google Agenda" ✓; "Editar" abre o formulário antigo; "Avisar no Chat" e "Excluir" na entrega 3 |
| V16 | Modal de edição | ✗ entrega 3 |
| V17 | "Compartilhar com a equipe?" | ✗ entrega 3 |
| V18 | Link `#ev=<id>` | ✓ (fora da faixa carregada, vai ao dia que o id traz; sem achar, avisa) |
| Menu | Botão direito | ◐ "Abrir compromisso", "Ver detalhes" e "Novo compromisso em" ✓; acrescentar, editar nome, ocultar e restaurar na entrega 4 |

Diferenças conscientes:
- horário "09:00 às 10:00" no lugar de "09:00 – 10:00" na grade e na lista (regra do dono, sem
  travessão; o detalhe do próprio desenho já usa "às");
- o detalhe do feriado nacional mostra a fonte legal e se conta no ponto (seção 5);
- "Próximos feriados" conta os dias pelo calendário: o desenho somava meio dia e mostrava "amanhã"
  para o feriado de hoje e "em 15 dias" para um que cai em 14;
- o texto "Clique com o botão direito no dia para editar ou ocultar" só volta na entrega 4;
- o item do menu de botão direito com foco fica realçado (teclado); o desenho não tem teclado no menu.

Conferência visual (19/10/2026, liberada pelo dono): demo da worktree na porta 8106 com banco isolado
`demo_agenda4b`, Playwright a 1280x900 e 390x844, desenho renderizado com dados equivalentes. Mês,
Semana, Lista, detalhe, menu de botão direito, tema escuro e celular conferidos lado a lado; restam só
os itens ◐/✗ da tabela acima.

## 11. Entrega 3a: modal de criar e editar

Na branch `agenda-modal`, empilhada sobre `agenda-nova` (#58). A tela continua em `/agenda/nova`; a
troca da `/agenda` vem na 3b, junto com arrastar e soltar e concluir/cancelar (convenção D1: nada da
agenda antiga sai antes de existir na nova).

**Evento migrado para o domínio** (`App\Agenda\Entity\Evento`, decisão do subagente decisor: a regra
da pasta legada manda migrar antes de evolução não trivial; precedente da Pasta, 11097f0e). Bloco
`AppAgenda` no `doctrine.yaml`. Migration de **dados** `Version20261020122000`: troca
`audit_log.entity_class` de `App\Entity\Agenda\Evento` para `App\Agenda\Entity\Evento` (112 linhas no
banco de dev em 19/10/2026), para o histórico seguir no filtro da auditoria e no "Desfazer"; o down faz
a troca inversa. O `audit_log` não tem hash nem encadeamento; só o nome da classe muda.

**Campos novos** (migration `Version20261020122500`): `tipo` (enum `TipoDeCompromisso`: meus, aud,
reun, prazo; nulo nos eventos antigos), `pasta_id` (FK anulável, `ON DELETE SET NULL`),
`lembrete_minutos`, `avisados` (JSON com os ids avisados no Chat).

**Regras** (`SalvarCompromissoUseCase`, `ExcluirCompromissoUseCase`, `AvisarNoChatUseCase`): editar e
excluir exigem ver o compromisso (404) e ser o criador ou ter `admin.users.manage` (403), como na
agenda antiga; fim depois do início; pasta pelo número, só do escritório e fora da lixeira; colegas e
participantes só do escritório; cada colega é avisado uma vez, nunca quem avisa; participantes novos
também recebem o aviso. O aviso vai pela `Notificacao` (sininho e avisos da Central), com link
`/agenda/nova#ev=ev-<id>-<dia>`. Observações e local viram um campo só ("Local, link da reunião ou
observações", como no desenho).

**Estado contra a seção 1:** V3 ✓ (abre o modal); V11 e V12 ✓ (clique no dia ou na hora abre o modal
já com o dia e a hora); V15 ✓ (Google Agenda, Avisar no Chat, Excluir e Editar; itens de pasta não
têm "Avisar no Chat", porque o aviso grava no compromisso); V16 ✓; V17 ✓.

Regras acrescentadas na revisão: o aviso cabe nos 255 caracteres da notificação (corta com "…");
evento da agenda antiga editado no modal guarda a cor da legenda em toda edição (cor que não é de
nenhum tipo é legenda; D1); quem não vê pastas não vê o campo e, ao editar, a pasta atual fica;
participantes desligados continuam no compromisso (o modal só mostra os ativos, e tirá-los abriria um
compromisso fechado para o escritório todo); compromisso de vários dias ou
que passa da meia-noite guarda quantos dias dura (campo `dias`, com nota "Termina em ..." e o atalho "Terminar no mesmo dia");
"Só eu" não avisa ninguém nem guarda avisados, e o detalhe não mostra "Avisar no Chat"; os textos de
visibilidade dizem quem de fato vê ("Só os participantes e você veem"); o "Avisar no Chat" do detalhe
é uma escolha explícita de cada colega, no lugar do `prompt()` do protótipo, que casava pedaços de nome
e avisava gente demais (spec §3: os `prompt()` do protótipo viram modal); só colegas ativos são
avisados ou viram participantes; "repetir até" antes do início é recusado; o número da pasta só sai na
listagem para quem tem acesso às pastas; a busca da pasta aceita o número em minúsculas. Editar uma
repetição avisa que vale para a série, e excluir pergunta "e todas as repetições?".

Fica como está: 17 linhas do `audit_log` de dev guardam o nome antigo da classe dentro de
`changes.association_events` (só exibição, via `entityRef`; filtro e Desfazer não usam).

Diferenças conscientes: REPETIR/ATÉ e PARTICIPANTES no modal (agenda antiga, D1); a caixa
"MENCIONAR · NOTIFICA NO CHAT I.A" com cargo e presença do protótipo vem do `bluejus-central.js`
(componente global do desenho), não da Agenda: aqui vale a lista da própria Agenda (iniciais e nome);
o aviso de lembrete "Você será lembrado com aviso e som" volta na entrega 5, com os lembretes.

## 12. Entrega 3b: troca da /agenda e conferência da agenda antiga

`/agenda` (rota `agenda_index`, usada pela barra lateral, pela aba e pelo "Voltar ao Calendário" das
páginas antigas) passa a mostrar a tela nova; `/agenda/nova` continua para os links já enviados. Sai só
o calendário FullCalendar (`AgendaController::index` e `templates/agenda/index.html.twig`). As demais
rotas antigas ficam de pé. Novos: arrastar e soltar (`POST /agenda/compromissos/{id}/mover`) e
Concluir, Cancelar compromisso e Reabrir (`POST /agenda/compromissos/{id}/status`; cancelar avisa os
participantes com "O evento foi cancelado: <título>", como antes, cortado em 255 caracteres). Em
compromisso que se repete, situação vale para a série, e a confirmação diz isso.

Conferência item a item do inventário da agenda antiga (36 itens). Legenda: **Mantido** (a tela nova
faz) · **De pé** (a página ou rota antiga continua) · **Substituído pelo desenho** (o 09 mostra outra
coisa no mesmo lugar; decisão do coordenador em 19/10/2026).

| # | Agenda antiga | Situação |
|---|---|---|
| 1 | Acesso com o módulo `agenda`; sem ele, aviso e volta ao início | Mantido (mesma mensagem e redirect) |
| 2 | Cabeçalho "Agenda", "Ver Lista", "Novo Evento" | Mantido: título e "Novo compromisso" do desenho; "Ver Lista" vira o link "Lista completa, com eventos passados e filtros" no fim da visão LISTA, que leva à Lista antiga (De pé) |
| 3 | Calendário mês, semana, dia, "Hoje", setas, "+N" | Mantido (grade do desenho, com Lista) |
| 4 | Eventos e ocorrências de recorrentes; cancelado riscado | Mantido (cancelado riscado, concluído esmaecido) |
| 5 | Regra de visibilidade | Mantido (`restringirAoVisivelPara`, a mesma) |
| 6 | Modal de detalhes (título, descrição, início, fim, local, situação, criador, selo Recorrente) | Mantido no detalhe do desenho (local e descrição num campo; fim em outro dia; "Repete: ..."; situação quando concluído ou cancelado) |
| 7 | Criar pelo clique na data | Mantido (e corrigido: a data clicada não se perde mais) |
| 8 | Criar pelo modal (AJAX) | Mantido pelo modal do desenho; `/agenda/criar-ajax` De pé |
| 9 | Campos: título, cor, descrição, início, duração, local, situação, dia inteiro, visibilidade, participantes, recorrência | Mantido: tipo no lugar da cor (desenho), fim no lugar da duração, local e descrição num campo; situação pelos botões do detalhe |
| 10 | Página `/agenda/novo` | De pé |
| 11 | Campos do formulário da página | De pé |
| 12 | Editar (página) com a regra criador ou admin | Mantido no modal; `/agenda/{id}/editar` De pé |
| 13 | Concluir (pelo campo situação) | Mantido: botão "Concluir" no detalhe (e "Reabrir") |
| 14 | Cancelar e avisar os participantes | Mantido: "Cancelar compromisso" avisa como antes |
| 15 | Excluir | Mantido no detalhe; rota antiga De pé |
| 16 | Arrastar e soltar | Mantido: Mês troca o dia; Semana e Dia, dia e hora de meia em meia hora (como o FullCalendar), inclusive soltando sobre outro compromisso; arrastar um dia do meio de compromisso de vários dias desloca pela diferença; duração em segundos |
| 17 | Redimensionar | Mantido pelo campo Fim do modal (sem arrastar a borda) |
| 18 | Série não se arrasta | Mantido (agora também no servidor) |
| 19 | Recorrência | Mantido (Repetir e Até no modal) |
| 20 | Participantes e aviso ao criar | Mantido (aviso também aos participantes novos ao editar) |
| 21 | Visibilidade "Somente para mim" | Mantido ("Só eu") |
| 22 | Ver legendas de cor na lateral | Substituído pelo desenho (calendários com cor fixa) |
| 23 | Editar legendas | Substituído pelo desenho; a cor dos eventos antigos fica, inclusive ao editar |
| 24 | Salvar legendas | Substituído pelo desenho; `/agenda/legendas` De pé no servidor, sem tela |
| 25-29 | Página Lista, filtros de situação, responsável e período, colunas e ações | De pé (o Dashboard aponta para ela; testes DASH-009 e SEC-016 seguem verdes) |
| 30 | Página de detalhe `/agenda/{id}` | De pé (avisos antigos gravados no banco apontam para ela) |
| 31 | Item Agenda na barra lateral | Mantido (`agenda_index`, agora a tela nova) |
| 32 | Aba Agenda | Mantido |
| 33 | Link do Dashboard para a Lista | De pé |
| 34 | Avisos com link para o detalhe | De pé (novos avisos apontam para `/agenda#ev=...`) |
| 35 | Outras referências às rotas antigas | Nenhuma quebrada. `agenda_criar_ajax` e `agenda_atualizar_datas` ficam sem chamador na tela (só o calendário antigo as usava), mas continuam no servidor, protegidas como antes: nada é removido nesta entrega |
| 36 | Testes da agenda antiga | Verdes (nenhum dependia do calendário FullCalendar) |

## 13. Entrega 4: ajustes de datas pelo botão direito

Branch `agenda-ajustes`, empilhada sobre `agenda-troca` (#66). Itens do menu do desenho (dc L.217-224):
"Ver detalhes", "Editar nome", "Ocultar da agenda" (ou "Excluir", na data acrescentada), "Acrescentar
feriado ou data em dd/mm/aaaa", "Novo compromisso em ..." e "Restaurar datas do sistema" (quando há
algo ocultado ou renomeado). Os `prompt()` do protótipo viram um formulário pequeno: nome, tipo
(Nacional, Estadual, Tribunal, Comemorativa; o desenho começa em Tribunal) e fonte opcional.

**Onde fica:** tabela `agenda_data_ajuste` (migration `Version20261020125000`), por escritório: vale para
a Agenda de todo o escritório. Quem pode: a mesma permissão de Feriados e Folgas
(`admin.tenant.settings.manage`, convenção D2); os demais veem só "Ver detalhes" e "Novo compromisso".
Aniversários também podem ser ocultados ou renomeados (o desenho os trata como data do sistema).

**O Ponto não muda.** Nenhum ajuste grava na tabela `feriado` nem mexe no cálculo da jornada. Um teste
compara a tabela `feriado` inteira antes e depois de cada ajuste (acrescentar, renomear, ocultar um
feriado que conta no ponto, restaurar, excluir a data acrescentada).

**Para o usuário, o que significa ocultar:**
- Ocultar um feriado na Agenda só tira o feriado da Agenda do escritório. Se ele está cadastrado em
  Feriados e Folgas, **continua contando no ponto** como feriado; o aviso depois de ocultar diz isso
  ("... removido da agenda. Restaure quando quiser. Ele continua contando no ponto.").
- Acrescentar um feriado na Agenda (ex.: feriado municipal) **não** o faz contar no ponto: o formulário
  avisa "Não muda o ponto: para contar como feriado na jornada, cadastre em Feriados e Folgas".
- Renomear muda só o nome mostrado na Agenda.
- "Restaurar datas do sistema" devolve as datas ocultadas e os nomes originais; as datas acrescentadas
  ficam (somem uma a uma, por "Excluir").

O detalhe das datas do sistema volta a dizer "Clique com o botão direito no dia para editar ou ocultar"
(para quem pode ajustar), e mostra "Nome alterado pelo escritório" na data renomeada.

Acertos da revisão: o detalhe de data que não conta no ponto traz o link "Cadastrar em Feriados e
Folgas, para contar no ponto" (§5, regra 4; só para quem pode ajustar); "Restaurar" remove pelo
EntityManager e entra na auditoria; índice único `(tenant_id, tipo, data_id)` contra ajuste duplicado em
pedidos simultâneos; aniversário renomeado também mostra "Nome alterado pelo escritório".

Diferenças conscientes do desenho: excluir uma data acrescentada avisa só "removido da agenda", sem
"Restaure quando quiser" (o restaurar não traz de volta o que foi excluído; a frase do desenho seria
falsa); "Editar nome" fecha sem aviso, como no desenho. Fica para depois: os ajustes do escritório são
lidos inteiros a cada carga (o volume é pequeno; filtrar por período quando crescer).

## 14. Pendências com outras frentes

- **ColaboradoresAtribuiveis (#69, frente 7d):** quando entrar no master, o modal de compromisso
  (participantes e avisados) passa a usar esse serviço no lugar de `findColaboradoresAtivosPorTenant`:
  suspensos não aparecem para atribuição nova, e editar preserva quem já estava (hoje o
  `SalvarCompromissoUseCase` já preserva os participantes desligados). Entra com teste de que editar
  um compromisso com participante suspenso o mantém.
- **Comercial e e-mails (desenho 05, Perfis):** lê as datas comemorativas do `CalendarioDoSistema`
  (seção 9).

## 15. Entrega 5: lembretes com som em qualquer tela

Branch `agenda-lembretes`, empilhada sobre `agenda-ajustes` (#70). Adaptação do `bj-lembretes.js` do
desenho: no horário, um cartão no canto (ícone, som próprio por tipo, rótulo "Em N min · às HH:MM",
dica, "Ver na agenda" ou "Abrir pasta", "Lembrar em 10 min", dispensar) e a notificação do navegador.

- **Onde aparece:** parcial `templates/layout/_globais/lembretes.html.twig` (ponto de inclusão da frente
  menu-do-usuario; o `base.html.twig` não muda), só para quem está logado e tem o módulo Agenda. Carrega
  `public/js/lembretes.js` e `public/css/lembretes.css`.
- **De onde vem:** `GET /agenda/lembretes` (`ListarLembretesUseCase`), pedido ao abrir a página, a cada 5
  minutos e quando a Agenda salva algo. Entram os compromissos da pessoa (que criou ou de que participa),
  com horário, agendados, com o lembrete escolhido ou o padrão do tipo (Audiência 60, Reunião 10, Prazo e
  Compromisso 15; "Sem lembrete" fica de fora), e, para quem vê pastas, os prazos e audiências abertos de
  hoje com hora (60 min antes da audiência, 15 do prazo). Janela: avisos das próximas 24 h; o cartão
  ainda aparece até 2 h depois do início.
- **Respeito ao navegador:** o som só toca depois que a pessoa interage com a página (o áudio é liberado
  no primeiro clique ou tecla); a notificação do sistema só aparece se a permissão já foi dada; o pedido
  de permissão só sai de um clique ("Testar lembretes" ou "Salvar" na Agenda). Dispensados e adiados
  ficam guardados no navegador (`localStorage`), por chave do lembrete. Com "reduzir movimento", os
  cartões não animam.
- **TESTAR LEMBRETES** na lateral (V7), entre os estados e o Google, como no desenho: Audiência, Reunião,
  Prazo e Compromisso mostram o cartão de exemplo com o som do tipo.
- Ao salvar compromisso com horário e lembrete, o aviso diz "Você será lembrado com aviso e som." (V16).
- **Push com o navegador fechado: entrega 5b, própria.** O desenho promete: `bj-lembretes.js`, linha 3,
  "Sistema real: o mesmo aviso chega por push (Service Worker) e o servidor dispara no horário mesmo com
  o navegador fechado." (a tela 09 não fala disso). O sistema não tem Service Worker hoje. A 5b traz o
  Service Worker, a inscrição de push por navegador (pedida por clique), o envio pelo servidor no horário
  (agendador) e a mesma chave de lembrete, para o aviso não repetir quando a página também está aberta.
  Orientação do coordenador (19/10/2026): o envio no horário sai pelo Messenger com o transporte que já
  existe (`doctrine://`, `config/packages/messenger.yaml`), com `DelayStamp` até a hora do aviso, em vez
  de cron novo; o dono só mantém o worker rodando. A chave VAPID (segredo) e o worker entram na lista de
  materiais do dono, com passo a passo no PR. Avisar o coordenador antes de mexer em `composer.json`/`.lock`.
- **Hora de parede:** o servidor manda início e aviso sem fuso (`AAAA-MM-DDTHH:MM:SS`), a mesma hora que a
  Agenda mostra, e o navegador lê no fuso dele, como o `bj-lembretes.js` (`new Date(dia + 'T' + hora)`).
  Quem está fora do horário de Brasília é avisado pela hora que vê na Agenda.
- Quem não vê pastas não recebe o id da pasta no lembrete (nem o botão "Abrir pasta"), como na listagem.
- **Cartão de teste:** "Abrir pasta" e "Ver na agenda" só fecham o cartão (a "Pasta 1180" do exemplo não
  existe); no desenho eles navegavam. "Lembrar em 10 min" do teste também só fecha.
- Os cartões entram e saem um a um (o leitor de tela não relê os antigos). Marcas de "adiar" vencidas há
  mais de um dia são limpas.
- **Para o smoke em celular (dono):** o som é liberado no primeiro toque (`pointerdown`, `touchend`) ou
  tecla; no Safari do iOS não foi possível provar sem aparelho.
- **Prazos de todos (decisão do coordenador, 19/10/2026):** prazos e audiências de hoje com hora tocam
  para todo colaborador com acesso às pastas, como no desenho (que lê todas as pastas). Filtrar pelo
  responsável depende de o responsável do prazo virar usuário (hoje é texto livre em `PastaPrazo`).

## 16. Entrega 5b: push com o navegador fechado

Cumpre a promessa do `bj-lembretes.js`, linha 3 (seção 15). Mesmos lembretes da tela, mesma chave.

- **Inscrição (pedida por clique):** quando a permissão de notificação já foi dada (ou é dada em "Testar
  lembretes"/"Salvar"), `public/js/lembretes.js` registra `public/sw-lembretes.js` (escopo `/`), pede a
  chave pública em `GET /agenda/push/chave` e inscreve o navegador. A inscrição vai para
  `POST /agenda/push/inscrever` (módulo agenda, CSRF) com o fuso do navegador (`Intl`). Uma linha por
  navegador em `agenda_push_inscricao` (único pelo hash do endereço): se o navegador passa a outra pessoa
  ou outro escritório, a linha muda de dono. `POST /agenda/push/cancelar` só apaga a da própria pessoa.
  Sem chave VAPID configurada, a chave volta nula e nada se inscreve (o resto dos lembretes segue).
- **O que se aceita:** endereço https de um serviço de push conhecido (`fcm.googleapis.com`,
  `push.services.mozilla.com`, `push.apple.com`, `notify.windows.com` e subdomínios), para o servidor não
  fazer POST em endereço qualquer; `p256dh` com 65 bytes começando em 0x04 e `auth` com 16 bytes. A
  gravação é um upsert pelo hash do endereço (`ON CONFLICT`), porque o filtro de escritório esconderia a
  linha de outro escritório.
- **No navegador:** a inscrição feita com outra chave VAPID é refeita; a aba não repete o POST para a
  mesma pessoa, o mesmo escritório e o mesmo endereço; ao confirmar o Sair, a navegação espera (no máximo
  2 s) o cancelamento da inscrição desta pessoa em qualquer escritório (computador compartilhado), porque
  depois do logout a sessão e o CSRF somem. Se o cancelamento não terminar em 2 s, a linha fica (melhor
  esforço). Sessão que expira sem Sair deixa a inscrição até a próxima pessoa com
  Agenda entrar naquele navegador.
- **Envio (`VarrerLembretesDePushUseCase`):** a cada minuto, para cada pessoa ativa com inscrição e módulo
  Agenda, calcula os lembretes com o `ListarLembretesUseCase` na **hora de parede do fuso guardado na
  inscrição** e envia os que venceram (aviso já passou e o início foi há menos de 2 h). Título
  "BlueJus · <título>", corpo "Às HH:MM · <observação>", `tag` = chave do lembrete (o sistema troca, em
  vez de empilhar, o aviso de mesma chave), clique abre a Agenda no compromisso ou a pasta. TTL de 1 h,
  urgência alta, cifra `aes128gcm` (a única do push da Apple), um envio por vez com tempo máximo de 5 s
  (uma inscrição torta ou um serviço lento não derrubam nem seguram os outros). Inscrições que o serviço
  dá por vencidas, recusadas por outra chave VAPID (401/403) ou com chave que não cifra são apagadas.
  Escritório desativado e pessoa inativa não recebem.
- **Nada se repete:** antes de enviar, o lembrete é reservado em `agenda_push_enviado` por
  `INSERT ... ON CONFLICT (usuario_id, chave) DO NOTHING`; só quem reserva envia. Reservas com mais de 7
  dias são limpas. A entrega é **no máximo uma vez**: se o serviço de push recusar, o aviso não sai de
  novo (o cartão na página continua valendo). "Lembrar em 10 min" e "dispensar" ficam no navegador e não
  mudam o push.
- **Desvio da orientação (decisor técnico):** em vez de uma mensagem com `DelayStamp` até a hora de cada
  aviso, uma **cadeia única** que se reagenda a cada 60 s. Motivo: compromisso editado, movido ou
  apagado mudaria a hora já agendada; a varredura sempre lê o estado atual. Continua sem cron, no
  transporte `async` (`doctrine://`) que já existe.
- **Como a cadeia começa:** a primeira mensagem é enviada por `GarantirVarreduraDePush`, chamado na
  inscrição de um navegador, a cada consulta de `GET /agenda/lembretes` (toda tela aberta, a cada 5 min)
  e pelo comando `app:agenda:push-varredura` (para o deploy). Só despacha se a marca
  `agenda_push_varredura.ultima_em` estiver nula ou com mais de 180 s.
- **Uma só cadeia:** o handler reivindica a vez com
  `UPDATE agenda_push_varredura SET ultima_em = agora WHERE id = 1 AND (ultima_em IS NULL OR ultima_em < agora - 45 s)`.
  Quem não reivindica (mensagem duplicada) morre sem reagendar. Quem reivindica varre e, no `finally`,
  despacha a próxima com `DelayStamp(60000)`.
- **Como se recupera:** worker parado, mensagem perdida ou erro: a marca envelhece; passados 180 s, a
  próxima consulta de lembretes de qualquer navegador aberto (ou o comando) religa. Com todos os
  navegadores fechados e a cadeia morta, só o comando ou a próxima abertura religam.
- **Tabelas novas** (`Version20261020129500`): `agenda_push_inscricao`, `agenda_push_enviado` e
  `agenda_push_varredura` (linha única). As duas primeiras entram no expurgo do escritório.
- **Chaves VAPID inválidas** (assunto que não é `mailto:`/`https://`, chave de tamanho errado) deixam o
  push desligado, com erro no log, em vez de reservar lembretes e falhar.
- **Material do dono:** chaves VAPID (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`) e o worker
  da fila `async` rodando (já existe no `docker-compose.prod.yml`). Passo a passo no PR.
- **Testes:** duas varreduras do mesmo minuto não duplicam; escritório desativado não recebe; chave
  torta não derruba o lote; serviço desconhecido, http e chaves de tamanho errado são recusados; corte de
  2 h; a cadeia não se bifurca e reagenda com 60 s;
  desligado não envia; inscrição vencida é apagada; fuso do navegador (Manaus) muda a hora do aviso;
  inscrever valida https e base64url; cancelar só a própria.

## 17. Acertos finais contra o desenho (conferência de 19/10/2026)

Conferência do desenho inteiro contra as entregas #58 a #78 e #81 (98 itens). O que estava sem dono:

- **Dica ao passar o mouse** nos chips do Mês e nos blocos de Semana/Dia (desenho L76 e L87): `title` com
  título e hora. O desenho usa o balão do `bj-dica.js`; fora da Central vale a dica do navegador (decisão do
  dono, Fase A, 15/10, registrada em `public/js/central-dica.js`).
- **"Importado do Google Agenda."** no detalhe (L237): compromisso com `google_id` que não é nosso
  (`bjt<escritório>e<id>`), ou seja, nascido no Google (`CompromissoOutput::doGoogle`).
- **Contagem por calendário** (L196): todos os compromissos da pessoa de cada tipo, em qualquer data
  (`ContarMeusCompromissosUseCase`, devolvida em `contagens` com os compromissos), e não só os do período.
  O da agenda antiga, sem tipo, conta no calendário em que aparece na grade (pela cor da legenda), pela mesma
  regra (`CalendarioPelaCor`); no desenho não existe esse legado.
- **Lista de @** reabre ao clicar no campo de observação (L118, `onClick=obsAuto`).
- **Aviso do colega no Chat:** título "Agenda: <título>" e o texto com o resumo (L191). O botão "Abrir na
  agenda" (`acao` em L192) **não aparece no protótipo da Central**: `notificar()` em
  `docs/design/claude-design-2026-10-05 (1)/bluejus-central.js:123-131` monta o aviso sem o campo `acao`, e
  nenhum trecho desenha botão de ação no aviso; lá, como no sistema, o clique no aviso leva ao link. A
  Central não muda (decisão do coordenador, 19/10/2026). **Conferido no zip do dono (19/10/2026), pendência fechada:** no
  `bluejus-central.js` de 18/10 (`docs/design/redesign-19-10-26/`, 1.415 KB, completo), o
  `BJCentral.notificar` guarda o `acao` em `ctx.acao` quando há link (L6644: `acao: e2.acao || 'Abrir'`),
  mas nenhum trecho do protótipo lê `ctx.acao` nem desenha botão no aviso: o clique no aviso leva ao link,
  como no sistema. O botão não entra. O 09 do zip é idêntico à cópia em `docs/design/agenda-vs-18-10-26/`.
- **Data acrescentada:** "Incluído manualmente." (L218).
- **Botão direito só no Mês** (célula, chip e rótulo do feriado), como no desenho; saiu das horas de
  Semana/Dia (acréscimo sem registro, removido por decisão do coordenador).
- **Menu pelo teclado e por toque longo** (prometido na seção 3): células e chips do Mês recebem foco
  (Tab; navegação por setas fica para depois); Enter ou espaço fazem o clique; tecla Menu ou Shift+F10 abrem o menu junto do elemento; toque
  parado de meio segundo abre o menu no iPhone (o Safari não dispara `contextmenu`), mexer o dedo cancela, e
  o clique depois do toque longo não acontece; toque cancelado pelo sistema ou que vira arrastar não abre
  o menu. Sem o balão de copiar do sistema nas células, só em tela de toque.
- **Fora, por não aparecer na tela do desenho:** exportar e importar `.ics` (L167, L200-201) e o antigo
  "Avisar alguém no Chat" (`equipe`, `eqAberto`, `eqBtn`, `pergResp`, L202-203, L231) existem só no script
  do protótipo, sem botão no template.

**Lembretes contra `docs/design/meu-perfil-18-10/bj-lembretes.js`** (além do registrado nas seções 15 e 16):
- Corrigido: outras abas refazem os lembretes na hora quando a Agenda salva algo (o desenho ouve
  `storage`; aqui a chave `bj-agenda-mudou`).
- Acréscimo (o desenho não tem Service Worker): o clique no push foca a aba que já está no destino (a
  Agenda ou a pasta); senão abre uma janela. Não leva uma aba de outra tela ao destino (formulário pela
  metade).
- Iguais ao desenho, sem mudança: perfis (rótulo, ícone, cor, notas, antecedência, dica), som, rótulo de
  tempo, cartão (medidas, cores, animações, barra), ações, notificação, teste. Audiência pelo tipo do
  prazo: no desenho é `/audi/i` sobre `tipo || título`, e no sistema o prazo sempre tem tipo.
- Diferenças registradas (adaptação ao sistema real):
  - "Sem lembrete" (0): o script do desenho avisaria na hora do início, mas a tela 09 rotula 0 como "Sem
    lembrete"; vale a tela.
  - Ícone das notificações: o favicon do sistema (`icons/favicon-32x32.png`); o `bluejus-simbolo-petroleo.png`
    do desenho não existe no repositório.
  - Destinos: `/pasta/{id}` e `/agenda#ev=ev-{id}-{dia}` (rotas reais) no lugar das páginas do protótipo.
  - Chaves: `ag:<id>:<AAAA-MM-DDTHH:MM>` e `pz:<id do prazo>:<data>` (estáveis), observação vinda da
    descrição (até 200 caracteres) e um lembrete por ocorrência de compromisso que se repete.
  - "Hoje" dos prazos pela data do servidor (Brasília); prazos mudados em Pastas aparecem na próxima busca
    (até 5 min), pois não há armazenamento compartilhado com as Pastas como no protótipo.
  - Rótulo do cartão atualizado a cada 30 s ("Em 5 min" não fica parado depois de começar).
  - Acessibilidade (`role=region`, `role=alert`, `aria-label`) e tema escuro, convenção do sistema.
  - Push: som padrão do sistema operacional (o push não carrega o perfil de som).
