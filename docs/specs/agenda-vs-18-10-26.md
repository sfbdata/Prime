# Agenda nova (desenho "09 - Agenda - VS 18.10.26")

Desenho: `docs/design/agenda-vs-18-10-26/09 - Agenda - VS 18.10.26.dc.html` (+ `bj-lembretes.js` no
projeto do Claude Design). Risco: BAIXO na tela; **MÉDIO** na ligação com o Google (token de usuário).
O protótipo guarda tudo em `localStorage`; aqui tudo vai para o servidor, por escritório e por usuário.

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
5. Preferências de calendários (migration) + lembretes globais (V7).
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
| V5 | 10 calendários com marcação | ◐ lembrada no navegador; no servidor na entrega 5 |
| V6 | Feriados estaduais de: UF | ✓ |
| V7 | TESTAR LEMBRETES | ✗ entrega 5 |
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
`AppAgenda` no `doctrine.yaml`. Migration de **dados** `Version20261019125154`: troca
`audit_log.entity_class` de `App\Entity\Agenda\Evento` para `App\Agenda\Entity\Evento` (112 linhas no
banco de dev em 19/10/2026), para o histórico seguir no filtro da auditoria e no "Desfazer"; o down faz
a troca inversa. O `audit_log` não tem hash nem encadeamento; só o nome da classe muda.

**Campos novos** (migration `Version20261019125854`): `tipo` (enum `TipoDeCompromisso`: meus, aud,
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
