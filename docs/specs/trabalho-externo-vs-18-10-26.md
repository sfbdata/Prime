# Spec: Trabalho Externo VS 18.10.26 (demandas, vagas e currículos)

## Estado da frente (releia antes de continuar; atualizado em 19/10/2026)

**Pilha de branches** (cada uma sobre a anterior; merge na ordem):
1. `trabalho-externo-vs1810` (F1, marcações e chat avançado) → **PR #62 aberto**. Migration `Version20261019120126`.
2. `trabalho-externo-vagas` (F2a, vagas) → **PR #83 em rascunho**, base `trabalho-externo-vs1810`. Migration `Version20261019132051`.
3. `trabalho-externo-vagas-publicas` (F2c, página pública e compartilhar) → **PR #84 em rascunho**,
   base `trabalho-externo-vagas`.
   Migration `Version20261019160801`.
4. `trabalho-externo-curriculos` (F3a, §4.5) → **PR #96 em rascunho**, base `trabalho-externo-vagas-publicas`.
5. `trabalho-externo-candidatura` (F2b, §4.6) → **PR #105 em rascunho**, base `trabalho-externo-curriculos`.
   Migration `Version20261019231700`.
6. `trabalho-externo-engajamento` (F4, §4.7) → em andamento, base `trabalho-externo-candidatura`.
   Migration `Version20261019213000`.

Depois de cada merge: trazer `origin/master` para a próxima (`git merge --no-edit origin/master`),
rodar a suíte, renomear a migration dela para o timestamp do momento (arquivo e classe), trocar a
base do PR para `master` e tirar do rascunho.

**Fila de merge** (coordenador, 19/10, depois do master a7433b93): #56 → #60 → #65 → #69 → #64 → #74 →
#62 → #83 → #84 → #96 → #105. O master já tem a migration do Push (`Version20261019170000`), posterior às do F2a e do
F2c: as duas são renomeadas na vez. Teto do FORA_DO_ESCOPO: quando o #56 entrar, somar as entradas dele.

**Decisões do dono** (via coordenador, 19/10): tudo do desenho entra, mesmo que abra frente nova; o
Master é dono do sistema e pode tudo; navegador liberado nesta sessão para a verificação visual
(porta 8107, banco isolado `demo_trabalho_a7`, usuário de demonstração com senha só nesse banco).

**Decisão P1, pelo desenho completo** (19/10): o candidato é o usuário **externo**, Solicitante de
qualquer escritório ou Convidado (06 l.718 `interno()` = Master ou Equipe; l.1109 `modCvInt` para
interno e `modCvExt` "Meu currículo" para os demais; l.1001 e l.974 "Candidatar-se" e "Aceitar oferta"
para o externo, com o currículo dele). Currículo **um por usuário** (no protótipo é por perfil
simulado). Conta pelo `/cadastro` que existe, único cadastro do desenho ("Criar minha conta e
escritório", Login l.65, l.101, l.158).

**Convenções registradas pelo coordenador**: apagar mensagem para todos = autor ou Master (P de
19/10); P2 = escritório vê as vagas abertas e as dele em qualquer situação; P3 = a página pública e
o compartilhar entram como fatia própria.

**Contratos com outras sessões**:
- Scripts globais do desenho: `bj-idioma` (7d), `bj-recursos` (b5), `bj-lembretes` (4b), `bj-plano`
  (f6). Esta frente só consome.
- Descadastro de e-mail: serviço único da 0d, `App\Shared\Email\Descadastro\DescadastroDeEmailInterface`
  (`estaDescadastrado`, `descadastrar`, `gerarLink`, com Tenant, e-mail e assunto), tabela
  `email_descadastro`. Os alertas de vagas não têm escritório: Tenant `null` = plataforma, assunto
  `vagas_alerta` (confirmado pela 0d). Há opt-out total (`todos`) e descadastro de um clique por
  lista. Não criar outro.
- Campanha de vagas por e-mail e envio dos alertas: frente "Comercial e e-mails" (5d).
  - Double opt-in: `ConfirmacaoDeEnderecoInterface` da 5d (`pedir()`, `confirmar()`, `estaConfirmado()`).
    A inscrição de alertas chama `pedir()`; **nada é enviado antes de `estaConfirmado()`**.
  - Envio: `CarteiroInterface` da 5d, tipo lista, assunto `vagas_alerta`.
  - PR da 5d: **#99** (rascunho, sobre o #87 da 0d), assinatura final igual à combinada; origem
    `pagina-publica-vagas`; rota pública GET/POST `/e/confirmar/{token}` (o GET só mostra, o botão grava).
    A F2d não empilha sobre o #99 (a inscrição está no #84): sai num PR pequeno quando a primeira das
    duas pilhas entrar no master, empilhado sobre a outra.
  - Contrato fechado com a bluejus-5d em 19/10 (spec dela `docs/specs/comercial-e-emails.md` §3.1 e
    §3.3, branch `comercial-emails`): ver §4.3. A fatia 1 dela ainda não tem PR e vai empilhada sobre
    a da 0d; o #84 segue sem enviar nada, e a F2d liga tudo num PR pequeno depois do dela. Envio só
  para quem confirmou o e-mail (double opt-in) e não está descadastrado (§4.2).
- `security.yaml`: linha própria `^/vagas-juridicas`; 0d e f6 acrescentam as delas.
- Teto `MAX_FORA_DO_ESCOPO` em `TenantAwareCoberturaTest`: o #56 (b5) também sobe; quem entrar depois
  soma as entradas das duas listas. Cada entrada tem a justificativa ao lado.
- Contribua C6 (f6, desenho 08 "Preferência no Trabalho Externo"): serviço pronto na `contribua-c3`,
  `App\Contribua\Service\ApoiadoresDoBlueJus::apoiadores(list<int> $tenantIds, $agora)` e
  `ehApoiador(int $tenantId, $agora)` (apoio PIX confirmado nos últimos 30 dias). O 06 não tem disputa
  de propostas entre escritórios: onde o benefício vale é decisão de produto, com o coordenador
  (opções: ordem dos convidados, ordem dos candidatos da F2b, só o selo, fora do Trabalho Externo).
- Limite por IP da página pública só vale em produção depois do PR #77 (IP do cliente).

**Pendências**:
- **P1 resolvida pelo desenho** (19/10, confirmada pelo coordenador): ver "Decisões" abaixo. Risco
  das fases de candidato: **MÉDIO** (permissão do módulo, sem identidade nova).
- **Voltar à aba Vagas depois do login**: o `UserAuthenticator` sempre leva ao Expediente (ou à
  escolha de escritório) e ignora o destino guardado. **Dependência da frente do desenho 10 (Login)**,
  risco ALTO, atribuída pelo coordenador em 19/10; ela também corrige o convite, que perde o destino.
  Esta frente não mexe no login. Hoje "Entrar" vai ao `/login` e só.
- **Conta de pessoa física** (sem escritório): o texto de marketing sugere, o desenho não oferece. Se
  o dono quiser, vira frente própria depois. Não bloqueia.
- **Desenho completo recebido** (19/10, `docs/design/redesign-19-10-26/`, fora do git; cópia de
  referência nas worktrees). Conferência dos #62, #83 e #84 contra ele: em andamento.
- Descrição do #62 não editável pelo `gh` 2.46 desta máquina: complemento postado como comentário.

**Feito no #84 contra o 06 completo** (19/10, noite): "Divulgar vaga(s)" como o desenho (l.343 a 362,
1074 a 1077, 1098, 1166 e 1167). Quem divulga: qualquer um que vê a vaga aberta (o externo também,
"Compartilhar vaga" no menu, l.1001). "Indicadas por" = primeiro nome de quem gerou o link. Cada canal
(WhatsApp, E-mail, LinkedIn, mensagem copiada, link) soma uma divulgação e vai ao histórico com o ícone
do canal (rota `trabalho_externo_vagas_divulgacao`). Entradas: botão "Compartilhar vagas" na aba,
"Compartilhar esta vaga" no detalhe, item do menu. Página pública: áreas cortadas em 4, `og:image`.
Conferido no navegador (8107): modal de várias vagas e de uma, detalhe, menu.

**Para o dono** (depende dele): merges da pilha na ordem acima; smoke na tela do "Divulgar vaga(s)"
(WhatsApp e e-mail abrem fora do navegador de teste, não conferidos); decidir se quer conta de pessoa
física (pendência abaixo); F3a: minimização (esconder e-mail,
telefone e arquivos do currículo da Equipe sem `talentos`, recomendação do decisor; hoje segue o
desenho, toda a Equipe vê); prazo de retenção do Termo de Voluntariado aceito depois que o currículo
é excluído (o termo fica ligado ao usuário).

**Próximo passo**: F4 (§4.4), empilhada sobre o #105, com as notificações pelo sino da Central; depois
externa (abas e banner) e os KPIs da página pública. Conferência final do 06 inteiro no fim, com a
lista do que não tem dono. A F2d (§4.3) espera o PR da 5d.


Frente `trabalho-externo-vs1810`, base `origin/master` @ `1d3e664c` (v2.2.2). Continua a spec
`docs/specs/trabalho-externo.md`, que segue valendo para tudo o que ela já cobre.

Desenho: `06 - Trabalho Externo - VS 18.10.26.dc.html`, projeto Claude Design `4f6a5f61-…`.
Cópia de referência **completa** (zip do dono, 19/10) em `docs/design/trabalho-externo/vs-18-10-26/`, fora do
git; a pasta inteira do zip está em `docs/design/redesign-19-10-26/` (sem commitar, há documentos pessoais).
**O desenho manda** onde mostra algo; onde a parte que falta é omissa, vale a convenção do
sistema e o item fica marcado como **provisório até o zip**.

Risco **BAIXO** pela régua do projeto (não toca ponto, identidade User/Tenant nem TenantRole/Permission).
A spec existe pelo tamanho: são 754 ligações novas na marcação.

## 1. Escopo e divisão

Regra do dono (19/10, via coordenador): tudo o que está no desenho entra. Os quatro scripts globais
do `<helmet>` têm donos em outras frentes e esta só os consome: `bj-idioma` (7d), `bj-recursos` (b5),
`bj-lembretes` (4b), `bj-plano` (f6). A ordem por "preferência de assinante" (Contribua C6, f6) entra
quando a f6 combinar o contrato.

| Fase | Conteúdo | Migration | Depende do zip |
|---|---|---|---|
| **F1** | Demandas: marcações por usuário (seleção em lote, estrela, importância, lixeira pessoal, lida/não lida, desfazer) e chat avançado (seleção, favorito, lixeira pessoal de 30 dias, excluir definitivo, apagar para todos) | sim | só as listas de rótulos (§3.5) |
| **F2** | Vagas: vaga, oferta com tempo, candidatura, aprovação de vaga de escritório, compartilhar, campanha por e-mail, configuração interna | sim | regras de quem faz o quê, menus |
| **F3** | Currículos: banco de talentos, painel (KPIs, gráficos, crescimento), lista com filtros, exportar CSV, currículo em PDF, Termo de Voluntariado, nota interna | sim | KPIs, menus, PDF |
| **F4** | Visão externa: Meu currículo, Minhas candidaturas, boas-vindas, "quase lá", lembrete diário, abas de módulo e banner de engajamento/alertas | talvez | quase tudo |

Cada fase é um PR. Uma migration por vez entre os PRs abertos (fila do coordenador).

## 2. Modelo de marcação por usuário (vale para F1–F4)

O desenho trata quatro tipos de item com o mesmo conjunto de operações por usuário (`lote()` na linha
901 e `tlLote()` na linha 912): lixeira, restaurar, definitivo, lida, não lida, estrela, sem estrela,
importante, não importante. **Nada disso altera o item para os outros participantes** ("A lixeira é só
sua: as demandas continuam para os outros participantes").

Tabela única `trabalho_externo_marcacao`:

| Coluna | Tipo | Regra |
|---|---|---|
| `usuario_id` | FK `user`, `ON DELETE CASCADE` | dono da marcação |
| `tipo` | enum `TipoItemMarcado`: `demanda`, `vaga`, `curriculo`, `candidatura` | |
| `item_id` | int | id do item; sem FK (polimórfico). A remoção do item apaga as marcações no UseCase que remove |
| `estrela` | bool, padrão `false` | |
| `importante` | bool **anulável** | `null` = automático; `true`/`false` = escolha do usuário, vence o cálculo |
| `nao_lida` | bool, padrão `false` | marca manual "não lida" |
| `lixeira_em` | datetime anulável | |
| `excluida_em` | datetime anulável | some da lista do usuário para sempre |

Único em (`usuario_id`, `tipo`, `item_id`). **Decisão:** tabela única em vez de uma por tipo. As
operações e colunas são idênticas nos quatro tipos e o painel de cada aba lê por (`usuario`, `tipo`).
O preço é não ter FK para o item; ele é pago num só ponto (remoção do item), coberto por teste.

A entidade não é `TenantAware`: a marcação é do usuário, e o Master marca demandas de todos os
escritórios. Toda leitura filtra por `usuario` (o usuário logado); nenhuma rota recebe usuário.

Na prévia do Master (`?como=`), marcar é escrita e continua negado (spec base §9).

## 3. F1: Demandas

### 3.1 Operações em lote

`POST /trabalho-externo/api/demandas/marcacoes` com `{codigos: [...], op}`. `op` ∈ `lixeira`,
`restaurar`, `definitivo`, `lida`, `naolida`, `estrela`, `semestrela`, `imp`, `naoimp`.

- Só entram códigos que o usuário **vê** pelo repositório com escopo atual. Código fora do escopo é
  ignorado e não vaza existência (a resposta conta só os aplicados).
- `lida`: avança o ponteiro de `LeituraDemanda` até a última mensagem visível e zera `nao_lida`.
  `naolida`: liga `nao_lida`.
- `definitivo`: o navegador confirma antes, com o texto do desenho ("Excluir esta demanda da sua lista
  definitivamente? Ela continua existindo para os outros participantes.").
- Resposta: `{aplicados, texto}`, com o texto do desenho (`T` em `lote()`): "N demanda(s) movida(s)
  para a lixeira", "… restaurada(s)", "… excluída(s) da sua lista", "… marcada(s) como lida",
  "… marcada(s) como não lida", "… com estrela", "… sem estrela", "… marcada(s) como importante",
  "… marcada(s) como não importante".
- **Desfazer** (8 s, só para `lixeira`): o navegador chama `restaurar` com os mesmos códigos.

### 3.2 Importância (calculada no servidor, `impDe()` linha 889)

Pontos: urgente e aberta +3; prioridade Urgente +3, Alta +2; prazo (aberta) vencido ou em até 1 dia
+3, até 3 dias +2, até 7 dias +1; valor da última proposta ≥ R$ 5 mil +2, ≥ R$ 1 mil +1; mensagens
não lidas +1; situação `nova`, `info`, `proposta` ou `validacao` +1.
Nível: ≥ 6 → 3, ≥ 4 → 2, ≥ 2 → 1, senão 0. Demanda fechada (`FECHADAS`) fica no máximo em 1.
Escolha manual vence: `importante = true` → 3 ("você marcou como importante"), `false` → 0.
A linha recebe `{nivel, motivos[]}`; a dica do botão lista os motivos.

### 3.3 Lista

- Demanda com `excluida_em` não aparece mais para o usuário (nem conta nos KPIs e chips dele).
- Demanda com `lixeira_em` só aparece no filtro Lixeira, com o aviso do desenho (linha 126).
- `nao_lida` faz a linha contar 1 não lida quando o ponteiro já está em dia (`naoLidas()` linha 747).
- Linha em negrito quando há não lidas.
- A coluna nova (96 px) leva seleção, estrela e importância (linhas 140–141); cabeçalho com
  selecionar todas, "Selecionar por" e Atualizar (linhas 130–133).

### 3.4 Chat avançado

Tabela `trabalho_externo_marcacao_mensagem` (`mensagem_id` FK cascade, `usuario_id` FK cascade,
`favorita` bool, `lixeira_em`, `excluida_em`; único por par). Coluna nova
`trabalho_externo_mensagem.apagada_em` (apagar para todos).

- `POST …/demandas/{codigo}/mensagens/marcacoes` com `{ids, op}`, `op` ∈ `lixeira`, `restaurar`,
  `definitivo`, `fav` (`_chatLote()` linha 878). Só mensagens que o usuário vê na demanda.
- Lixeira do chat expira em 30 dias: na leitura, `lixeira_em` com mais de 30 dias conta como
  excluída (`_chatLixeira()` linha 886). Sem cron.
- Apagar para todos (desenho l.1233–1240): o **Master** em qualquer mensagem; o **autor** nas próprias até
  **60 min** após o envio; qualquer tipo menos o registro do sistema. A mensagem fica como "Mensagem apagada
  por X em dd/mm/aaaa hh:mm" (nome no recorte de quem lê), some das não lidas e das favoritas de todos.
  Quem não pode vê no menu o item travado "Apagar (prazo de 60 min encerrado)" ou "Só o autor ou o Master
  apagam".
- Teclas Delete ou `#` com mensagens selecionadas: lixeira (ou definitivo, dentro da lixeira).
- Desfazer de 8 s para a lixeira e o restaurar.

### 3.5 Rótulos e listas (definidos pelo desenho completo, 19/10)

Barra de lote (l.1309), "Selecionar por" (l.1307), chips "★ Com estrela", "Importantes" e "Lixeira"
(l.1302), menu da linha sem marcações (l.1214), importância com as cores e dicas da l.1305, menu da
mensagem (l.1239), filtros e vazios do chat (l.1287–1288). Nada mais é provisório no F1.

## 4. F2–F4: o que já se sabe

Da marcação e das estáticas que vieram (linhas 832–846):
- Vaga: status `VST` (Aguardando aprovação, Não aprovada, Aberta, Pausada, Preenchida, Expirada,
  Encerrada); `DUR` 5/10/15/30/60 são **minutos** da oferta com tempo; formulário em `tmVagaForm`
  (linhas 230–381). Configuração padrão `tlCfg()`: `{dur: 15, sino: true, motiv: true, maxDias: 0,
  minEnvio: 3}`, com `dur` em minutos (l.1159).
- Currículo: status `CST` (Novo, Em análise, Entrevista, Favorito, Arquivado); campos em `tmCvForm`;
  áreas `AREAS` (12), cargos `CARGOS` (7), contratação `CONTR`, modalidade `MODAL`.
- Candidatura: status `AST` (Recebida, Em análise, Entrevista, Aprovado, Contratado, Não selecionado).
- Termo de Voluntariado: `termoHTML()` completo (Lei 9.608/1998), versão `TERMO_V = '1.0'`.

Cada fase ganha sua seção aqui antes de começar, depois do zip.

## 4.1 F2a: Vagas, sem candidatura

Branch `trabalho-externo-vagas`, empilhada sobre `trabalho-externo-vs1810` (F1) porque o F1 espera a
vez na fila de migrations. O PR só abre depois do merge do F1. A candidatura (F2b) depende do
currículo (F3) e de quem é o candidato (pergunta P1, risco ALTO, com o coordenador): fica de fora.

### Modelo

`Vaga` (`trabalho_externo_vaga`, código `VG-0001`), não `TenantAware` pelo mesmo motivo da demanda
(a Central lê de todos os escritórios); o isolamento é do repositório escopado e da política.

| Campo | Origem no desenho |
|---|---|
| `escritorio` (Tenant, anulável) | quem divulga; nulo = vaga da Central (Master) |
| `situacao` `SituacaoVaga` | `VST`: pendente, reprovada, aberta, pausada, ocupada (Preenchida), expirada, encerrada |
| `titulo`*, `cargo`* (`CARGOS`), `contratacao` (`CONTR`), `modalidade` (`MODAL`), `cidade`, `uf`, `remuneracao`, `areas` (`AREAS`), `requisitos`, `inscricoesAte`, `exigeOab` | `tmVagaForm` (linhas 244–252) |
| `ofertante` (json: nome*, resp, cidade, uf, email, tel, site, e quais campos ficam ocultos) | "QUEM ESTÁ OFERECENDO", `ofCampos` com o olho (linhas 238–242) |
| `periodoDias` | "QUANTO TEMPO A VAGA FICA DISPONÍVEL", `DUR` 5/10/15/30/60 |
| `publicadaEm`, `fimDivulgacao` | `fimDiv` de `tlTick()` |
| `oferta` (bool), `qtd` (1–20), `expiraEm` | oferta relâmpago, `tfOfertaM` |
| `motivo` | reprovação |

`HistoricoVaga` (vaga, ícone, cor, texto, em): "HISTÓRICO DA OFERTA" (`tv.hist`).

Configuração das vagas em `ConfiguracaoCentral.vagas` (json, padrão de `tlCfg()`:
`{dur: 15, sino: true, motiv: true, maxDias: 0, minEnvio: 3}`), editada no modal `tmCfgV`.
`EQUIPE_PADRAO` ganha `talentos: true` (`CFG.equipe` do desenho, linha 716).

### Regras (desenho completo, l.979–1117)

- **Quem vê** (l.990): o interno (Master e qualquer Equipe) vê todas e o histórico; o externo
  (Solicitante e Convidado) vê as abertas e as que divulgou. Equipe sem `talentos` vê, mas não age.
- **Nova vaga** (`tlGere`): Master, ou Equipe com `talentos`; nasce aberta. **Só o Master** escolhe o
  tipo ("Vaga comum" ou "Oferta com tempo") e "Até a vaga ser preenchida"; os demais ficam no teto
  `maxDias`. Em "Nova vaga" a oferta vem ligada se `dur > 0`.
- **Divulgar uma vaga na Central** (`podeDivulgar: !int`): Solicitante e Convidado; nasce pendente.
- **Situação, só a Central com `talentos`**: "Aprovar e publicar" (teto de dias da vaga externa; sem tipo
  escolhido pelo Master, vira oferta de `dur` minutos se `dur > 0`; o prazo de inscrição passa a ser o
  fim da divulgação), "Não aprovar" (motivo opcional, "o solicitante verá"), "Pausar inscrições",
  "Reabrir vaga", "Ofertar novamente (N min)", "Finalizar oferta" ou "Encerrar vaga", "Excluir vaga".
- **Dono externo**: só "Editar pedido" (pendente) ou "Corrigir e reenviar" (reprovada, volta a pendente).
- **Expirar**: sem cron, como antes; reabrir e ofertar novamente recomeçam o contador.
- **Contador da oferta**: só o interno (`tempo()`, l.996); "AO VIVO" para todos.
- **OFERECIDO POR**: só os dados com o olho aberto, para todos; e-mail e telefone nascem ocultos (l.1182).
  O último ofertante vira o padrão das próximas vagas do usuário.
- **"NOVA"**: criada depois da última visita do usuário à aba Vagas (`trabalho_externo_preferencia`).
- **"Visto"**: só por abrir ou marcar (coluna própria na marcação).
- **Importância da vaga**: calculada (`impV`, l.1037); compatibilidade (F3) e candidaturas (F2b) depois.
- Histórico com os textos do desenho ("Aprovada e publicada por X", "Pausada por X", …).

### Tela

Faixa de abas (l.1105), filtros por perfil (l.1116), lote (l.1051–1052), cartões (l.1117), menu (l.1001),
detalhe e botões (l.1003, l.1095), formulário (l.1156, l.1182–1188), configuração no menu ⋮ "VAGAS E
CURRÍCULOS" (l.1265, l.1159–1161). Nada provisório além do que depende de F2b/F3/F4.

## 4.2 F2c: página pública de vagas e "Compartilhar vagas"

Desenho: `Vagas Publicas.dc.html` (projeto Claude Design, arquivo **completo**, sem corte), mais o
modal `tmShare` do 06 (linhas 343–362) e o botão "Compartilhar vagas" da aba (linha 153).

**Rota pública, sem login.** É a primeira tela do Trabalho Externo fora do login: o rigor é o de
dado pessoal (LGPD) e de formulário aberto na internet.

### Link de vagas

- "Compartilhar vagas" (Central, com vagas abertas) e "Compartilhar esta vaga" (detalhe) abrem o
  `tmShare`: prévia do link, mensagem editável com `{link}` ("Inclua {link} no texto para o link
  entrar na mensagem") e os canais (WhatsApp, e-mail, LinkedIn, copiar; provisório até o zip).
- Gravar o compartilhamento cria um `LinkDeVagas` (`trabalho_externo_link_vagas`): token aleatório
  (só o hash SHA-256 é gravado, como o convite), as vagas, quem gerou, quando. O protótipo põe as
  vagas no próprio endereço em base64; aqui isso vazaria o que a página esconde, por isso o token.
- A página `GET /vagas-juridicas/{token}` mostra **só** cargo, título, local, modalidade,
  contratação, áreas e quantidade, das vagas do link que **ainda estão abertas** ("A página pública
  mostra só cargo, local e tipo. Detalhes, remuneração, contatos e candidatura ficam liberados depois
  de criar a conta."). Remuneração, escritório e requisitos **não saem do servidor**: o bloco borrado
  do desenho é texto fixo de enfeite. Sem nenhuma aberta: "As vagas deste link já foram preenchidas".
  Token desconhecido: a mesma página sem vagas (não confirma se o link existiu).
- `GET /vagas-juridicas` sem token: a página geral, sem lista ("Sua próxima vaga na área jurídica
  começa aqui"). `?p=esc` abre a visão "Quero contratar".

### Alertas por e-mail (lead)

- "Receba vagas da sua área por e-mail": e-mail, UF ("Todo o Brasil"), áreas e o consentimento LGPD
  obrigatório. Grava `InteressadoEmVagas` (`trabalho_externo_interessado`): e-mail único (normalizado
  em minúsculas), UF, áreas, consentimento com data e versão do texto, origem (link), e o token do
  link de onde veio. Repetir o e-mail atualiza as preferências; não revela se já existia.
- POST público com CSRF da própria página e **limite de tentativas** por IP (rate limiter do
  Symfony). Resposta igual para e-mail novo e repetido.
- **A inscrição não prova que o dono do e-mail consentiu** (não há confirmação por e-mail). Por isso
  nenhum e-mail de vaga pode sair para um interessado antes de ele confirmar o endereço pelo próprio
  e-mail (double opt-in) e de conferir o `email_descadastro`. Isso é contrato da frente de e-mails
  (pendente com o coordenador); esta fatia só grava o pedido.
- "Indicadas por …" + o **primeiro nome** de quem gerou o link, nunca o escritório (VP l.165–180, 06
  l.1074; resolvido com o coordenador em 19/10).
- O envio dos e-mails de vagas é da frente "Comercial e e-mails" (contrato pelo coordenador), e o
  descadastro é o serviço de `Shared` da frente `push-avisar-quando-chegar` (tabela
  `email_descadastro`). Esta fatia não envia e-mail nem cria descadastro próprio: a tela de "Não quer
  mais receber" do desenho é a desse serviço.

### Botões de conta

- "Entrar" → `/login`. "Divulgar minha vaga grátis" e "Quero divulgar vaga" → `/cadastro` (conta e
  escritório, já existe).
- "Criar conta grátis e me candidatar", "Procuro vaga", "Quero me candidatar" e os "Criar conta" do
  candidato: também `/cadastro` (P1 decidida pelo desenho: o candidato é usuário externo, de
  escritório). Voltar à aba Vagas depois do login fica pendente (Estado da frente).

### Segurança

- `security.yaml`: uma linha `^/vagas-juridicas` com `PUBLIC_ACCESS`, própria (outras frentes
  acrescentam as delas). Avisar o coordenador ao mexer.
- Sem dado de escritório na página; token de 32 bytes; sem `find` por id.

## 4.3 F2d: alertas de vagas por e-mail (depois do PR da 5d)

Contrato da 5d (`App\Comercial`), assinatura final quando o PR dela abrir:

- `Consentimento\ConfirmacaoDeEnderecoInterface`:
  - `pedir(string $lista, string $email, string $origem, string $versaoDoConsentimento,
    string $textoDoConsentimento, ?Tenant $escopo = null): void`. Já envia o e-mail de confirmação
    (link assinado de 7 dias, reenvio no máximo 1 vez por 24 h). Grava como prova a versão e o texto
    que a pessoa viu, mais IP, agente e data;
  - `estaConfirmado(string $lista, string $email, ?Tenant $escopo = null): bool`;
  - `confirmar()` é interno da rota pública dela, `/e/confirmar/{token}` (tela e texto dela).
- `Envio\CarteiroInterface::enviar(MensagemDeEmail $m): ResultadoDoEnvio`, com
  `MensagemDeEmail(para, nome, assunto, corpoHtml, tipo: 'lista', lista: 'vagas_alerta', escopo: null,
  origem: 'consumidor:vagas_alerta', rastrear: true)`. O Carteiro confere o descadastro (inclusive
  `todos`) ao enfileirar e ao enviar, põe layout, rodapé legal, link de descadastro e
  `List-Unsubscribe`, e envia em lote pela fila. Aqui vai só o miolo.

O que a F2d faz aqui, no Trabalho Externo:

1. `InscreverInteressadoUseCase` chama `pedir('vagas_alerta', email, 'pagina-publica-vagas',
   InteressadoEmVagas::VERSAO_DO_CONSENTIMENTO, InteressadoEmVagas::CONSENTIMENTO, null)` depois de
   gravar (a resposta ao visitante continua igual para e-mail novo e repetido).
2. Uma tarefa de alertas (comando agendado) escolhe, para cada vaga aberta nova, os interessados cuja
   UF e áreas casam, e chama o Carteiro **só** para quem passa em `estaConfirmado('vagas_alerta',
   email, null)`. Regra de frequência (por vaga ou resumo diário): **pendente** (o desenho não diz;
   proposta: resumo diário).
3. A 5d não lê a tabela daqui; a "Base de contatos" dela vem das confirmações dela.

## 4.4 Plano das fases que faltam (desenho 06 completo, 19/10)

Inventário completo do script (l.707–1348) feito em 19/10. Ordem, porque a candidatura exige o
currículo (`candidatar()` l.974 abre o formulário se não houver) e a visão externa usa os dois:

| Fatia | Conteúdo | Depende de |
|---|---|---|
| **F3a** | Currículo: entidade (um por usuário externo), formulário `tmCvForm` (l.254–283, validações l.1006, campos condicionais por `sit` l.1173–1175), arquivos (foto 256×256, carteira da OAB, comprovante de curso, currículo PDF/Word), "Meu currículo" com percentual (l.1013–1014), PDF `cvHTML` (l.920–938), banco interno: lista, filtros `cvFiltros` e selects (l.1119, 1148, 1151), lote, menu (l.1000), detalhe `tmCv` com status CST e nota interna, exportar CSV (l.1150) | F2a |
| **F2b** | Candidatura: `candidatar` e aceite de oferta (l.974, 978), status AST e linha do tempo (l.1039–1047), envio de candidatos ao escritório com `minEnvio` (l.981), lista de candidatos no detalhe da vaga (l.1003), "Ofertar novamente" e "Finalizar oferta" (l.982–983), cronômetro, ordenação por compatibilidade (l.1011) | F3a |
| **F3b** | Painel do banco (só Master): KPIs (l.1146), gráficos (l.1125), crescimento (l.1128–1144); Termo de Voluntariado (l.848–875, 1025–1035, 315–327): modelo, envio, aceite eletrônico com versão | F3a, F2b |
| **F4** | Visão externa e engajamento: abas com contadores (l.1105), banner e chips (l.1020–1022), sino de avisos (l.1107), boas-vindas pelo link (l.1078, 1111), lembrete diário (l.1054–1061), "quase lá" (l.1012); campanha por e-mail do Master (l.1082–1097) consumindo a 5d | F3, F2b, contrato 5d |

**Decisões nas lacunas do desenho** (técnicas, registradas para o PR de cada fatia):
1. Nota interna e status do banco (CST) **não** saem para o dono externo da vaga quando ele abre o
   currículo de um candidato enviado: o rótulo do desenho é "NOTA INTERNA (SÓ MASTER E EQUIPE)" (l.311).
2. A candidatura é recusada no servidor se a vaga não estiver aberta ou se o prazo de inscrição tiver
   passado; "Exige OAB ativa" continua só informativo, como no desenho (l.252, 1003).
3. Mudar status do candidato e marcar para envio exigem `talentos` (Master ou Equipe com a chave),
   como as outras ações de gestão (o protótipo só pedia ser interno).
4. Aceite de oferta relâmpago cria a candidatura direto em "Aprovado", como no desenho (l.978).
5. O aceite do Termo grava data e hora em ISO no servidor (o protótipo grava texto local).
6. Arquivos (foto, carteira, comprovante, currículo, termo) seguem a skill `armazenamento-de-arquivos`;
   CPF e RG do voluntário só existem no Termo.

### Fatias seguintes

- **F2c**: §4.2. A campanha por e-mail usa a infraestrutura da frente "Comercial e e-mails"
  (contrato pelo coordenador).
- **F2b**: candidatura, depois do F3 e da P1.

## 4.5 F3a: Currículos e banco de talentos (desenho 06, 19/10)

Branch `trabalho-externo-curriculos`, empilhada sobre `trabalho-externo-vagas-publicas` (#84). Risco
**MÉDIO**. Inventário do desenho feito em 19/10 (linhas citadas abaixo). Fora desta fatia: candidatura
(F2b), painel/KPIs/gráficos e Termo (F3b), barra de engajamento, lembrete diário e boas-vindas (F4).

### Quem
- **Externo** (Solicitante de qualquer escritório ou Convidado): "Meu currículo", **um por usuário**.
  Interno não tem "Meu currículo": o servidor recusa (decisão 18).
- **Interno** (Master ou Equipe): aba "Currículos" com o banco. **Toda a Equipe vê** tudo, como o
  desenho (contato e arquivos inclusive; coordenador 19/10). **Agir** (status, nota, lote de
  marcações vale para todos porque é pessoal; CSV, status e nota exigem `talentos`). Excluir
  currículo de outra pessoa: **só o Master** (l.1000).
- Nenhum externo vê currículo de outra pessoa nesta fatia (o dono da vaga verá na F2b, sem nota e sem
  status, decisão 1 da §4.4).

### Modelo
`Curriculo` (tabela `trabalho_externo_curriculo`, sem tenant: é da plataforma, como a vaga):
`usuario` (único), código `CV-0001` (id com 4 dígitos), `status` `StatusCurriculo` (novo, analise,
entrevista, favorito, arquivado; rótulos e cores CST l.845), `nota` (texto interno), `situacao`
`SituacaoAcademica` (adv, estOab, est, bach), nome, email, tel, oabNum, oabUf, inst, sem, cargo,
cidade, uf, modalidade, anos, resumo, pretensao, vol (sim, talvez, nao ou vazio), insta, tituloP,
linkedin, lattes, habs, `exps` [{cargo, org, ini, fim, desc}], `forms` [{curso, inst, ini, fim}],
`idis` [{lng, nivel}], `areas` (de AREAS), os quatro arquivos (foto, carteira da OAB, comprovante,
currículo: chave no armazenamento + nome original), consentimento LGPD (data e versão do texto),
`recebidoEm` (primeiro envio, imutável) e `atualizadoEm` (cada edição) (decisão 1).
Marcações pessoais: `TipoItemMarcado::Curriculo` na tabela de marcações da F1.

### Formulário (`tmCvForm`, l.254 a 283; valores l.1171 a 1189; validação l.1006)
Ordem, rótulos, placeholders e textos exatamente como o inventário (foto, Instagram, nome, e-mail,
telefone, situação em 4 botões, bloco OAB se adv ou estOab, bloco curso se est, estOab ou bach com os
rótulos de bach, cargo, cidade, UF, modalidade, anos, pretensão, voluntário Sim/Talvez/Não com clique
que limpa, áreas, resumo, "APRESENTAÇÃO DO CURRÍCULO", experiências, formação, idiomas, habilidades,
arquivo do currículo, LGPD). Títulos: "Cadastrar currículo" / "Editar currículo". Rodapé: Cancelar,
Pré-visualizar, Salvar currículo, Excluir meu currículo (confirmação "Excluir seu currículo e todas as
suas candidaturas? Esta ação não pode ser desfeita."). Toast "Currículo salvo" / "Use-o para se
candidatar às vagas.".
Validações do servidor, na ordem e com o texto do desenho: "Informe o nome completo.", "Informe um
e-mail válido.", "Informe sua situação: advogado(a), estagiário(a) com OAB, estudante ou
bacharel(a).", "Informe o número de inscrição na OAB." (adv, estOab), "Informe a instituição de
ensino." (est, estOab), "É preciso autorizar o uso dos dados (LGPD) para enviar o currículo.".
Decisão 5 (lacunas): carteira, comprovante e currículo até 1,5 MB, recusa com "O arquivo passa de
1,5 MB. Envie um arquivo menor."; tipos pelo conteúdo (currículo PDF, DOC, DOCX; carteira e
comprovante PDF, JPG, PNG); foto JPG ou PNG até 5 MB, guardada em 256×256 no servidor; OAB só
dígitos (1 a 7); UF válida quando informada; LinkedIn só https em linkedin.com, Lattes só https em
lattes.cnpq.br. Arquivos pela skill `armazenamento-de-arquivos`; download sempre por controller com
permissão, `attachment` e `nosniff` (foto inline).

### "Meu currículo" do externo (l.214 a 221, 999, 1013, 1014)
Cartão com "Ver e baixar" e "Editar"; vazio com o texto e "Cadastrar currículo"; linhas do `cvItem`;
"Atualizado em" (usa `atualizadoEm`); percentual de 11 itens (nome, email, tel, sit, cidade, cargo,
resumo, arquivo, foto, áreas, documento conforme sit), cores ≥ 90% #1e7b45, ≥ 60% #0a6fa0, senão
#c76a12, "Complete: …" na ordem do desenho. O percentual sai do servidor.

### Banco interno (l.170 a 213, 995, 1000, 1038, 1049 a 1052, 1114 a 1119, 1148 a 1151)
Barra (seleção, "Selecionar por", Atualizar, busca "Buscar nome, OAB, área ou cidade" em nome, OAB,
cidade, UF, cargo, áreas), abas Ativos, Novos, Favoritos, Voluntários, Arquivados, ★ Estrela,
Importantes, Lixeira, **contando o que a lista mostra** (decisão 6), selects área, "Situação OAB",
UF, Termo (Termo fica "Sem termo" até a F3b), "Limpar filtros", "Exportar {N} currículo(s)".
Tabela com as colunas e larguras do desenho, importância `impC` (+2 candidaturas: 0 até a F2b),
lote e snackbar iguais aos das vagas, menu de contexto (Abrir currículo, Ver currículo formatado /
PDF, os status CST que não são o atual, Excluir currículo só Master; "Gerar termo" entra na F3b).
Vazio: "Nenhum currículo recebido" só com o banco vazio; com filtro, "Nenhum currículo neste filtro."
e "Limpar filtros" (decisão 7). Ordenação por `recebidoEm` decrescente.
Detalhe `tmCv` "Currículo · {nome}" (l.301 a 312) com "SITUAÇÃO NO BANCO DE TALENTOS" e "NOTA INTERNA
(SÓ MASTER E EQUIPE)" gravada ~800 ms depois da última tecla e ao sair do campo (decisão 8).
CSV `curriculos-bluejus.csv` com as colunas do desenho (l.1150), `;`, aspas e BOM, da lista filtrada.
Equipe sem talentos e o painel do Master: a Equipe vê "Estatísticas disponíveis só para o Master"; o
painel em si é da F3b (até lá, o Master vê só a lista).

### PDF (`cvHTML`, l.920 a 938)
Twig fiel ao `cvHTML` (A4, barra lateral 72 mm #0a6fa0, seções do desenho), servido como HTML para
a pré-visualização ("Currículo · pré-visualização", 960px, iframe) e como PDF pelo Dompdf em "Baixar
PDF" (decisão 12c). "Disponibilidade" some quando vazia; `tituloP` dividido em "|" (decisão 9).
Pré-visualizar no formulário manda os dados ainda não salvos e recebe o HTML.

### Rotas (contrato entre servidor e tela; JSON como as vagas, CSRF no header)
- `GET /trabalho-externo/api/curriculos` `trabalho_externo_curriculos`: `{interno, podeGerir,
  ehMaster, meu (externo: detalhe ou null, percentual, faltas), curriculos (interno: cartões), agora}`.
- `GET /api/curriculos/{codigo}` `trabalho_externo_curriculo` (CV-\d+): detalhe; externo só o seu.
- `POST /api/curriculos/meu` `trabalho_externo_curriculo_salvar`: cria ou edita o próprio (JSON).
- `POST /api/curriculos/meu/arquivos/{tipo}` `trabalho_externo_curriculo_arquivo` (foto, oab,
  matricula, curriculo; multipart `arquivo`) e `.../{tipo}/remover`.
- `POST /api/curriculos/meu/excluir` `trabalho_externo_curriculo_excluir_meu`.
- `POST /api/curriculos/{codigo}/excluir` `trabalho_externo_curriculo_excluir` (só Master).
- `POST /api/curriculos/{codigo}/status` `trabalho_externo_curriculo_status` `{status}` (talentos).
- `POST /api/curriculos/{codigo}/nota` `trabalho_externo_curriculo_nota` `{nota}` (talentos).
- `POST /api/curriculos/marcacoes` `trabalho_externo_curriculos_marcacoes` `{codigos, op}`.
- `GET /trabalho-externo/curriculos/{codigo}/arquivos/{tipo}` `trabalho_externo_curriculo_baixar`.
- `GET /trabalho-externo/curriculos/{codigo}/documento` (HTML) e `/pdf` (Dompdf);
  `POST /api/curriculos/previa` (HTML do formulário não salvo).
- `GET /trabalho-externo/curriculos/exportar.csv` `trabalho_externo_curriculos_csv` (talentos; mesmos
  filtros da lista na query).
**Formato JSON** (contrato fixo; servidor e tela não mudam sem o orquestrador):
- Cartão do banco (`curriculos[]`): `codigo, nome, iniciais, fotoUrl|null, cargo, situacao (adv|estOab|est|bach),
  situacaoRotulo (curto do filtro: Advogado(a), Estagiário(a) com OAB, Estudante, Bacharel(a)), oab (texto da coluna
  OAB, "OAB 12345/DF" ou "Sem OAB"), areas[], local ("Cidade/UF" ou "Brasil"), uf, recebido (dd/mm/aaaa),
  recebidoEm (ISO), status, statusRotulo, statusCor, vol, voluntario (bool, vol = sim), termo ("sem"), estrela,
  lixeira, vista, importancia {nivel, motivos[]}, candidaturas (0)`.
- Detalhe (`curriculo`, GET do código e respostas das escritas): o cartão + `email, tel, insta, oabNum, oabUf,
  inst, sem, cidade, modalidade, anos, resumo, pretensao, tituloP, linkedin, lattes, habs, exps[], forms[],
  idis[], arquivos {foto|oab|matricula|curriculo: {nome, url}|null}, nota (só interno; null para o externo),
  situacaoLonga (detalhe: Advogado(a), Estagiário(a) com OAB, Estudante de Direito, Bacharel(a) sem OAB),
  oabTxt (texto do `cvItem`), volTxt, experienciaTxt ("Advogado(a) · 3 ano(s) de experiência"), atualizado
  (dd/mm/aaaa), atualizadoEm (ISO), percentual {pct, cor, faltas[]}, podeEditar, podeGerir, podeExcluir,
  documentoUrl, pdfUrl, candidaturasVagas[] (vazio até a F2b)`.
- Lista: `{interno, podeGerir, ehMaster, meu, curriculos, agora}`; erro como as vagas (`{ok:false, mensagem}`,
  422/403/404). Arquivo enviado: resposta `{curriculo}`. Marcações: mesmo corpo e resposta das vagas.
- Ações de status `{status}` respondem `{curriculo}`; nota `{nota}` responde `{ok:true}`.
- `index.html.twig` ganha `data-url-curriculos`, `-curriculo` (com `CV-0`), `-curriculo-salvar`,
  `-curriculo-arquivo` (com `__tipo__`), `-curriculo-excluir-meu`, `-curriculo-excluir` (CV-0), `-curriculo-status`
  (CV-0), `-curriculo-nota` (CV-0), `-curriculos-marcacoes`, `-curriculo-previa`, `-curriculos-csv`.
  A aba "Currículos"/"Meu currículo" (`bi-person-vcard`) entra nos módulos, com o badge dos novos só no interno.

Excluir: apaga marcações, arquivos (depois do commit) e, na F2b, as candidaturas; Termo aceito fica
com o usuário (coordenador 19/10, retenção com o dono).

Revisão de 19/10 (corrigido): busca igual à do CSV; anexo do bloco que sumiu é apagado ao salvar;
foto até 12 milhões de pixels; consentimento grava a data do primeiro aceite (e de novo só com versão
nova); exclusão das marcações e do currículo numa transação; corrida do primeiro salvar vira 409.
Pendente: apagar o `User` leva o currículo (CASCADE) mas deixa os arquivos e as marcações; tratar na
frente "Excluir conta" (quarentena de 365 dias).

Fica para depois e registrado: currículo em Word (sem botão no desenho, decisão 10); texto do toast
da candidatura para "Meu currículo" (F2b, decisão 11); KPI "COM OAB" filtra adv e estOab (F3b).

## 4.6 F2b: Candidatura (desenho 06, 19/10)

Branch `trabalho-externo-candidatura`, empilhada sobre `trabalho-externo-curriculos` (#96). Risco
**MÉDIO**. Inventário de 19/10 (linhas abaixo). Fora: notificações (decididas pelo coordenador em 19/10: entram na F4 pelo sino da Central,
`TiposDeAvisoDaCentral` conforme `app/src/Central/CLAUDE.md`; e-mail só se o desenho mostrar, pelo Carteiro da 5d), painel/funil
(F3b), chips de engajamento, QUASE por evento entre abas e bem-vindo (F4).

### Modelo
`Candidatura` (tabela `trabalho_externo_candidatura`, sem tenant): `vaga` (FK CASCADE), `curriculo`
(FK CASCADE), única por (vaga, currículo), `status` `StatusCandidatura` (recebida, analise, entrevista,
aprovado, contratado, nao; rótulos e cores AST l.846), `enviadaEm`, `datas` por etapa (analise,
enviado, entrevista, aprovado, contratado, nao), `enviadoAoEscritorio` (bool + data), `aceiteDeOferta`
(bool). A vaga ganha `aceitesNaRodada` derivado das candidaturas de aceite desde o `inicio` atual.
Marcações pessoais: `TipoItemMarcado::Candidatura`.

### Regras (l.974 a 985, 1003, 1006, 1039 a 1047, 1095, 1103, 1154; decisões nas lacunas)
- **Candidatar-se** (externo com currículo): cria `recebida`, histórico da vaga `bi-send` #0a6fa0
  "Candidatura de {nome}". Toast "Candidatura enviada" / "Acompanhe em Meu currículo · Minhas
  candidaturas." (texto ajustado à aba que o externo vê, decisão 11 da F3a). Já existe: "Candidatura já
  enviada" / "Você já se candidatou a esta vaga.". Sem currículo: o servidor responde `semCurriculo` e a
  tela abre o formulário "Seu currículo para candidatura" com "Salvar e candidatar-se"; o salvar segue
  o mesmo caso de uso (com histórico, e o toast "Currículo salvo e candidatura enviada" só quando criou).
- **Servidor recusa** (lacunas 1 e 2): vaga que não está aberta ou com prazo de inscrição vencido
  ("As inscrições desta vaga estão encerradas."), vaga própria ("Você não pode se candidatar à sua
  própria vaga."), interno ("Só profissionais externos se candidatam às vagas."). OAB exigida segue
  informativa (decisão 2 da §4.4). Na tela, a vaga própria não mostra "Candidatar-se".
- **Aceite de oferta** (`aceitar`, l.978): sob trava da linha da vaga (`SELECT … FOR UPDATE`), confere
  aberta, tempo e `aceites da rodada < qtd`; cria candidatura em `aprovado` (com `datas.aprovado`),
  histórico "Aceita por {nome} ({n} de {qtd})"; ao completar, vaga `preenchida` e "Oferta preenchida em
  {mm:ss} min". Toasts "Oferta aceita" / "A vaga está reservada para você. O escritório já foi avisado.",
  "Você já aceitou" / "Sua vaga está garantida.", "Vaga indisponível" / "Esta vaga já foi preenchida ou
  encerrada." (com `motiv` ligado, o modal QUASE do desenho, l.392 a 400 e 839).
  Decidido pelo coordenador (19/10): **cada rodada** ("Ofertar novamente", "Reabrir") conta os aceites do
  zero; os aceites anteriores continuam `aprovado`. Aceite em vaga **com dono** entra direto nos
  enviados ao escritório (o toast promete que o escritório sabe).
- **Status** (`aStatus`): qualquer AST para qualquer outro, só com `talentos` (decisão 3 da §4.4).
- **Envio ao escritório** (`enviarCands`, l.981): só vaga com dono e só `talentos`; seleção **por vaga**
  (lacuna 9); `recebida` vira `analise`; histórico "{n} candidato(s) enviado(s) ao escritório por
  {quem}"; toast "Candidatos enviados" / "{n} currículo(s) disponível(is) para {escritório}."; sem
  seleção, "Selecione candidatos" / "Marque quem será enviado ao escritório."; abaixo de `minEnvio`, a
  confirmação do sistema (`abrirConf`) com o texto "O padrão é enviar pelo menos {min} candidatos.
  Enviar mesmo assim {total}?". Sem "desenviar".
- **Dono externo da vaga** vê só os enviados (título "CANDIDATOS INDICADOS PELO BLUEJUS ({n}) · O
  BLUEJUS ATUALIZA O ANDAMENTO", sem botões de status), abre o currículo formatado e o detalhe do
  candidato **sem** nota interna, sem situação no banco e sem as outras candidaturas (decisão 1 da §4.4).
  Card: "{n} indicado(s) pelo BlueJus".
- **Linha do tempo** `etapas` calculada no servidor (passos, estado, datas dd/mm/aa, dias); com `nao`,
  o último passo alcançado aparece feito (lacuna 16) e entra o passo "Não selecionado".
- **Minhas candidaturas** (l.221 a 224, 1103, 1154): lista, filtros Todas / ★ Estrela / Lixeira, lote,
  importância (marcada 3; entrevista ou aprovado 2, como l.1154; senão 1), abrir marca como vista.
- **Compatibilidade** `compat` (l.1011): áreas 60, UF/sem UF/Remoto 25, cargo 15; selo
  "{N}% compatível" (≥70 #1e7b45, ≥40 #0a6fa0, senão #6b8494) e ordem do externo (ofertas ao vivo,
  depois compatibilidade); entra no `impV` (+2 com ≥70) e as candidaturas recebidas no `impV` do
  interno (+min(3, n)); `impC` +2 com candidaturas.
- Excluir vaga ou currículo apaga as candidaturas (FK), como o desenho.
- Revisão de 19/10 (corrigido): "Salvar e candidatar-se" recusado devolve `curriculoSalvo` e a tela trata
  o currículo como salvo; ações da Central na vaga pegam a mesma trava do aceite; unit dos casos de uso.
  Decisão registrada: a vaga já publicada e fechada responde 422 ("Inscrições encerradas") em vez de 404,
  para quem tem o link; não mostra título nem dado. O dono da vaga baixa os arquivos do candidato
  ENVIADO a ele (o modal `tmCv` do desenho mostra; o consentimento LGPD cobre).

### Contrato JSON (fixo)
- `POST /trabalho-externo/api/vagas/{codigo}/candidatura` `trabalho_externo_vaga_candidatura`: candidata
  ou aceita (o servidor decide pela oferta). 200 `{vaga, toast: {titulo, texto}}`; 409 `{ok:false,
  semCurriculo:true}`; 422 `{ok:false, titulo, mensagem, quase?: bool}`.
- `POST /trabalho-externo/api/vagas/{codigo}/enviar` `trabalho_externo_vaga_enviar` `{candidaturas:[id]}`:
  `{vaga, toast}`.
- `POST /trabalho-externo/api/candidaturas/{id}/status` `trabalho_externo_candidatura_status` `{status}`:
  `{vaga}` quando veio do detalhe da vaga.
- `POST /trabalho-externo/api/candidaturas/marcacoes` `trabalho_externo_candidaturas_marcacoes`: como as vagas.
- Detalhe da vaga ganha: `candidatos[]` `{id, codigoCv, nome, iniciais, fotoUrl, info ("OAB …/UF ·
  local · enviada em dd/mm/aaaa"), status, statusRotulo, statusCor, enviado, etapas, documentoUrl}`,
  `candTitulo`, `envioInfo`, `podeEnviar`, `podeMudarStatus`, `minEnvio`, `enviadosN`,
  `minhaCandidatura` (externo: `{status, statusRotulo, statusCor, etapas, total}` ou null),
  `botaoExterno` (`candidatar`|`aceitar`|`enviada`|`indisponivel`|null), `compat`.
- Cartão da vaga ganha: `compat`, `jaFoi`, `indicadosN` (dono).
- `etapas`: `{passos:[{k, rot, ic, estado (feito|atual|futuro|nao), data, dias}], total}`.
- Lista de currículos do externo ganha `minhas[]` `{id, codigoVaga, titulo, enviadaEm, status,
  statusRotulo, statusCor, etapas, estrela, lixeira, vista, importancia}`.
- `index.html.twig` ganha `data-url-vaga-candidatura` (VG-0), `-vaga-enviar` (VG-0),
  `-candidatura-status` (com 0), `-candidaturas-marcacoes`.

## 4.7 F4: visão externa, engajamento e avisos (desenho 06, 19/10)

Branch `trabalho-externo-engajamento`, empilhada sobre `trabalho-externo-candidatura` (#105). Risco
**MÉDIO**. Inventário de 19/10. Fora: campanha por e-mail do Master (l.330 a 341, 1082 a 1097; depende
da 5d), Termo de Voluntariado e painel (F3b), envio dos alertas por e-mail (F2d).

### Tela (desenho manda; textos, ícones e cores exatos do inventário)
- **Abas** (l.85 a 87, 1105): Vagas mostra as pendentes para quem tem `talentos` e há pendente, senão as
  abertas; Currículos (interno) os novos; Demandas e "Meu currículo" sem selo.
- **Banner** (l.88 a 96, 1013 a 1023, 1106), só nas abas Vagas e Currículo: saudação "Bom dia/Boa
  tarde/Boa noite, {primeiro nome}" pelo horário de Brasília; subtítulo do interno ou do externo;
  "Tudo em dia por aqui."; chips do interno (pedidos para aprovar **só com `talentos`**, lacuna 14;
  ofertas ao vivo com o tempo da próxima; currículos novos na semana; candidaturas e aceites na
  semana; média de aceite; "Publique a primeira vaga…" sem nenhuma vaga) e do externo (ofertas
  disputadas; vagas novas desde a última visita; currículo: cadastre, X% completo com as duas
  primeiras faltas, ou 100%). O chip de termo do externo entra com a F3b.
- **Botão de avisos** (l.91, 1107), só externo e só com `sino` ligado pelo Master: "Avisar quando sair
  vaga da minha área" / "Avisos de vagas ligados", toasts do desenho. A escolha é **por usuário** no
  servidor (`PreferenciaDoUsuario` `vagas.alerta`, com o escritório do contexto), não no navegador.
- **Boas-vindas pelo link** (l.382 a 391, 1078, 1111): `/trabalho-externo?origem=link&vagas=VG-1,VG-2
  [&cadastro=1][&divulgar=1]`, só externo, com os títulos, textos, passos e botões do desenho; com
  `divulgar=1` abre "Divulgar uma vaga na Central". O retorno a esse endereço depois do login é da 7d
  (dependência registrada).
- **Lembrete diário** (l.404 a 422, 1054 a 1061): só externo, uma vez por dia **pela data de
  Brasília** (lacuna 9), não na visita com boas-vindas, quatro estados do desenho; a idade do
  currículo usa `atualizadoEm`. A marca do dia fica no navegador (conveniência por pessoa).
- **QUASE na oferta perdida** (l.1012, 1069): a tela guarda as ofertas abertas que a pessoa abriu
  (conveniência no navegador); quando, num recarregar, uma delas está preenchida ou expirada sem
  aceite dela, mostra o QUASE uma vez (com `motiv`). Frases de "aceitou primeiro" só na preenchida.
- **"Ver exemplo de mensagem"** no modal de configuração do Master (l.1099, 1162) abre o QUASE.
- **Endereços** `#mod=vagas|cv|dem` e `#vaga=VG-n`, `#cv=CV-n` abrem a aba e o item (usados pelos avisos).

### Avisos no sino da Central (decisão do coordenador; `Notificacao` + `TiposDeAvisoDaCentral`)
Sem texto pessoal na notificação (ela é auditada, `app/src/Central/CLAUDE.md`): título com o título da
vaga, nunca o nome nem o contato do candidato. URL para a tela com `#vaga=`/`#cv=`.

| Evento | Quem recebe | Tenant |
|---|---|---|
| Pedido de divulgação novo ou reenviado (l.1005) | Master e Equipe com `talentos` | sede da Central |
| Nova candidatura e aceite em vaga da Central (l.974, 978) | Master e Equipe com `talentos` | sede |
| Aceite em vaga de escritório (l.978) e candidatos enviados (l.981) | Solicitantes do escritório dono (ou o Convidado que pediu) | escritório da vaga |
| Vaga aprovada ou não aprovada, com o motivo (l.979, 980) | quem pediu (dono) | escritório da vaga |
| Mudança de status da candidatura (l.985, 1040) | o candidato | escritório gravado na candidatura |
| Vaga nova ou reaberta na área (l.1012, 1107, 1160; lacuna 1) | externos com o aviso ligado, com área em comum ou sem currículo (como o desenho) | escritório gravado na preferência |

Sem sede definida, ou sem escritório, o aviso não é criado: o **Convidado avulso fica sem aviso no sino**
(coordenador, 19/10; o desenho não mostra aviso próprio para ele). Os arquivos `Notificacao.php` e
`TiposDeAvisoDaCentral.php` são disputados (5d, 0d, 9b, f6, 4b): constantes e entradas do Trabalho Externo
num bloco próprio, com um comentário de uma linha, só acréscimos. Tipos novos
`trabalho_externo_*` com grupo, rótulo, ícone e cor no `TiposDeAvisoDaCentral`. Um aviso por evento
e destinatário, sem duplicar pendente da mesma URL.

### Contrato (fixo)
- `GET /trabalho-externo/api/engajamento` `trabalho_externo_engajamento` (`?como=` aceito; `?vagas=` para
  as boas-vindas): `{contadores: {vagas, curriculos}, banner: {saudacao, subtitulo, chips: [{ic, cor,
  txt, ir: {mod, filtro?, acao?}|null}]}, alerta: {disponivel, ligado}, lembrete: {estado
  (cadastrar|completar|revisar|emDia), pct, faltas[], dias, vagasAbertas} | null, boasVindas: {titulo,
  texto, passos: [{rot, ic, feito}], botao: {rot, acao, codigo?}} | null, motiv}`.
- `POST /trabalho-externo/api/vagas/alerta` `trabalho_externo_vagas_alerta` `{ligado}`: `{alerta, toast}`.
- Migration: `escritorio_do_candidato` na candidatura (o tenant do contexto no momento).
- `index.html.twig`: `data-url-engajamento`, `data-url-vagas-alerta`.

## 5. Verificação

- Unit dos UseCases e functional dos controllers (escopo, prévia negada, código fora do escopo).
- Lista item a item do desenho com ✓/✗ no PR. A comparação no navegador depende de autorização do
  dono nesta sessão (CLAUDE.md: o smoke é do dono).
