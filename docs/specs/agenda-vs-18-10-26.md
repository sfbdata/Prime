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
