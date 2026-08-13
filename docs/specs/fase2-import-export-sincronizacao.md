# Spec — Importar / Exportar / Sincronizar (botões do "Conectar meu Drive")

> Evolução da Fase 2 do sync Drive↔sistema (já em prod — ver
> `fase2-tempo-real-sistema-drive.md` e `sincronizacao-drive-bidirecional.md`).
> **Risco: MÉDIO** (mexe no motor de reconciliação, adiciona estado por tenant +
> migration, e jobs em segundo plano). Exige spec (este doc), aprovação humana,
> implementação, revisão adversarial (`feature-review-agent`) e smoke.

## 1. Motivo (o problema que resolve)

Hoje "conectar o Drive" liga **sincronização contínua bidirecional**: sistema→Drive
por evento (worker) **e** Drive→sistema pelo cron de hora em hora, que **varre a
pasta-raiz e importa** o que encontrar. Isso foi desenhado para o **acervo do
tenant 1** (onde trazer os arquivos do Drive *era* o objetivo).

Para um **escritório novo** isso é perigoso e indesejado: conectar passaria a
**criar pastas no sistema e baixar arquivos** a partir do Drive — quando a
intenção é o inverso (os documentos do sistema irem para o Drive dele). Não há
nenhum freio por tenant: a conexão só guarda conta/pasta/status.

**Fato de produto (2026-07-15):** o escritório do tenant 1 agora **só usa o
sistema** — ninguém joga arquivo direto no Drive. Logo, **Drive→sistema contínuo
não é mais necessário para ninguém**; a importação vira uma **ação pontual**.

## 2. Desenho (decisão do humano, aprovada)

Separar **ação** de **estado**, com três controles na tela "Conectar meu Drive":

1. **Exportar** — leva as pastas/documentos do **sistema → Drive**. Ação pontual
   (a carga inicial de um escritório). Roda como **job em segundo plano** com
   **prévia** ("vou enviar N pastas / M arquivos — confirmar?") e progresso.
2. **Importar** — traz as pastas/arquivos do **Drive → sistema**. Ação pontual
   (é exatamente o que foi feito no tenant 1: os 20.812 documentos). Job em
   segundo plano, com **prévia + confirmação** (é o botão mais perigoso — nunca
   executa sem o usuário ver o que virá) e progresso.
3. **Sincronização (liga/desliga)** — estado contínuo, **só sistema→Drive**:
   criar pasta / anexar documento reflete no Drive em segundos (o gatilho da
   Fase 2, já em prod) + o cron como rede de segurança **do envio**. Pausável
   sem desconectar (preserva token e pasta-raiz).

**Não existe mais "Drive→sistema contínuo".** Esse sentido só acontece via o
botão **Importar**, sob demanda. (Fase 3 — Drive→sistema em tempo real por
webhooks — segue adiada, D11.)

## 3. Estado no modelo (o que muda em `TenantDriveConexao`)

Hoje `ativo` = "conexão configurada e utilizável" (token + pasta-raiz), e o
mesmo flag serve de gate tanto para a **fábrica** (montar o client — necessário
em qualquer operação) quanto para o **dispatcher** (enfileirar o contínuo). Isso
funde os dois conceitos. Separar:

- **`ativo`** (existente) — mantém o sentido: **conexão pronta** (token +
  pasta-raiz). É o gate da **fábrica** — vale para Importar, Exportar e
  Sincronizar. Renomear na cabeça: "conectada".
- **`sincronizacaoAtiva`** (NOVO `bool`, default `false`) — o **liga/desliga da
  sincronização contínua** sistema→Drive. Só o **dispatcher** e o **cron**
  olham para ele.

Migration: `ALTER TABLE sync_drive_conexao ADD sincronizacao_ativa BOOLEAN
DEFAULT false NOT NULL`. **Backfill do tenant 1 = `true`** (ele já sincroniza
continuamente hoje e deve continuar) — data-migration ou passo de ativação.

Repositório:
- `findConectadaDoTenant(Tenant)` = pronta (o atual `findAtivaDoTenant`, para a
  fábrica). *(Renomear ou manter o nome; decidir na implementação.)*
- `findComSincronizacaoAtiva(Tenant)` = pronta **e** `sincronizacaoAtiva`
  (dispatcher). `findTodasComSincronizacaoAtiva()` (cron multi-tenant).

O **dispatcher** (`SincronizacaoPastaDispatcher::despachar`) passa a checar
`findComSincronizacaoAtiva` — se a sincronização está desligada, **não enfileira**.

## 4. Motor direcional (o que muda no `ReconciliarCommand` / `ReconciliadorDePasta`)

O motor hoje faz as duas vias juntas. Torná-lo **direcional**, sem reescrever a
lógica (as vias já são blocos/métodos separados):

- **Enviar (sistema→Drive):** `sistemaParaDrive` (cria folder da pasta) +
  a **Via A** de `ReconciliadorDePasta` (sobe documentos sem `drive_file_id`).
- **Importar (Drive→sistema):** `driveParaSistema` (descobre subpastas novas →
  cria Pasta) + a **Via B** de `ReconciliadorDePasta` (baixa arquivos).

Como as vias A e B de arquivos vivem juntas em
`ReconciliadorDePasta::reconciliarArquivosDaPasta`, elas ganham um parâmetro de
**sentido** (`enviar` / `importar` / `ambos`) — ou métodos separados
`enviarArquivosDaPasta` / `importarArquivosDaPasta`. Contrato: preservar a
idempotência e o anti-OOM atuais (flush+clear por item, `conhecidos` etc.).

Interface do comando (proposta): `--modo=enviar|importar` (sem `--modo` = comportamento
atual, para não quebrar nada no cron até a migração). Flags atuais (`--dry-run`,
`--limit`, `--pasta-id`, `--tenant-id`) preservadas.

## 5. Jobs em segundo plano + prévia (Importar / Exportar)

Um clique **não pode** ser síncrono: a carga do tenant 1 levou **dias**; uma
request HTTP morre em ~30s. Então:

- **Prévia:** o botão primeiro roda o motor em **`--dry-run`** (que já sabe dizer
  exatamente o que *seria* feito) e mostra o resumo — *"Importar: 48 pastas + 1.203
  arquivos. Confirmar?"*. Só após confirmar é que enfileira o job real. Isso
  transforma o botão perigoso (Importar) em algo seguro.
- **Execução:** um job (Symfony Messenger — já temos worker) roda o motor
  direcional para o tenant. **Idempotente** (reexecução retoma de onde parou).
- **Progresso:** estado do job por tenant (contadores + "em andamento / concluído
  / erro") que a tela **consulta periodicamente** e exibe ("importando… 320 de
  1.035"). *(Mecânica de armazenamento do progresso a detalhar — campo/tabela
  leve ou reuso do relatório do motor.)*
- **Trava:** um Importar e um Exportar do mesmo tenant não rodam ao mesmo tempo
  (o `flock` por tenant já existe no motor); a UI reflete "já há um trabalho em
  andamento".

## 6. Correção acoplada: `select_account` no OAuth

Para **trocar de conta** funcionar de verdade, o início do OAuth precisa de
`prompt=select_account consent` (hoje só `consent`). Sem `select_account`, se o
navegador já está logado na conta antiga, o Google pode ir direto ao
consentimento dela e o usuário **reconecta a mesma conta achando que trocou**.
Um-liner em `GoogleDriveOAuth::clientBase` + teste que assere o parâmetro na URL.

## 7. UI ("Conectar meu Drive")

Na tela existente (`sync/drive_conexao.html.twig`), com a conta conectada:
- Bloco **Sincronização**: etiqueta de estado (Ligada/Desligada) + botão
  liga/desliga (POST + CSRF).
- Botões **Exportar agora** e **Importar agora** → abrem a prévia (confirmação)
  → enfileiram o job; enquanto roda, mostram o progresso e desabilitam o
  disparo duplicado.
- Textos honestos: *"Exportar: envia as pastas do sistema para o seu Drive."* /
  *"Importar: traz as pastas do seu Drive para o sistema (use só se o acervo já
  está no Drive)."* / *"Sincronização: mantém o Drive atualizado automaticamente
  conforme você trabalha (só do sistema para o Drive)."*
- Gate atual preservado (`admin.tenant.settings.manage`), CSRF em todo POST.

## 8. Migração do tenant 1 (sem downtime do que já funciona)

1. Deploy com a coluna nova (default false) + `sincronizacaoAtiva = true` no
   tenant 1 (data-migration ou clique/comando) — o contínuo dele **não pode
   parar**.
2. O **cron** passa a rodar **só enviar** (`--modo=enviar`): deixa de importar do
   Drive de hora em hora (que não é mais desejado). A importação do acervo já foi
   feita e concluída; daqui pra frente é só envio + o botão Importar sob demanda.

## 9. Ordem de implementação sugerida (staging)

**Fatia A — Freio + toggle + select_account (SEGURANÇA — habilita conectar
escritórios novos):**
- coluna `sincronizacao_ativa` + migration + backfill tenant 1;
- `ReconciliarCommand` direcional (`--modo`), cron do tenant 1 → `--modo=enviar`;
- dispatcher checa `findComSincronizacaoAtiva`;
- botão liga/desliga sincronização na tela;
- `select_account` no OAuth.
- Resultado: um escritório novo conecta e **nada é importado** sem ele mandar;
  a sincronização (envio) é opt-in.

**Fatia B — Botões Importar / Exportar com job + prévia + progresso:**
- mensagens/handlers de import e export; prévia via dry-run; estado de progresso;
  UI de confirmação e acompanhamento.

## 10. Testes (obrigatórios)
- Unit do motor direcional (enviar não baixa; importar não sobe).
- Dispatcher: **não enfileira** quando `sincronizacaoAtiva=false`; enfileira
  quando true. Cross-tenant preservado.
- Migration: coluna criada; tenant 1 backfillado.
- OAuth: URL contém `prompt=select_account consent`.
- Fatia B: prévia bate com o executado; job idempotente; trava de concorrência.
- `use Factories;` NUNCA `ResetDatabase` (DAMA).

## 11. Não-objetivos
- Drive→sistema em tempo real (Fase 3, adiada).
- Importar/Exportar entre escritórios ou de pasta que não a raiz configurada.
- Picker visual de pastas (segue follow-up).

---

## 12. Requisitos consolidados com o P.O. (sessão 2026-08) — LER PRIMEIRO

Depois de dias em produção, o P.O. observou problemas reais e fechou novos requisitos.
Isto AMPLIA o escopo original (§1–§11). Dados abaixo medidos na PROD (tenant 1) por MCP só-leitura.

### 12.1 Diagnóstico medido na produção
- **1070 pastas** no tenant 1, **todas com `drive_folder_id`** (0 sem vínculo — o sync está em dia).
- **32 pastas criadas após 2026-07-14** (o "ponto correto" = fim da carga inicial). É o conjunto a auditar/alinhar no Drive (o "relatório" que o P.O. pediu).
- **Números duplicados: 3** (`1214`, `1221`, `1227`), cada um com 2 pastas, ambas vinculadas ao Drive.
- **Pastas LITERALMENTE duplicadas: 2** (mesmo nup+cliente+ação) → gente criando a mesma pasta ao mesmo tempo (o sync não inventa; ele reflete as duas que o sistema deixou criar). A 3ª tem cliente/ação diferente.
- **Formato dos números (medido 2026-08-13, tenant 1, MCP só-leitura):** dos 1070, **1030 são só dígitos** e **40 são dígitos + uma letra** (10A/10B); **0 fora do padrão** `^\d+[A-Za-z]?$`. Maior prefixo numérico = **1231**, menor = 1, **1047 prefixos distintos** → há ~184 buracos na faixa 1–1231. Só o tenant 1 tem pastas em produção. *(Insumo direto do R1: `MAX+1` por tenant dá 1232; preencher buracos seria reaproveitar número de pasta apagada — não fazer.)*
- **Causa-raiz da duplicação:** `CriarPastaUseCase` (`src/Pasta/UseCase/CriarPastaUseCase.php`) só valida nup não-vazio — **NÃO checa duplicidade** (a trava UNIQUE de nup foi removida de propósito para o sync aceitar 10A/10B). Sem trava, duas pessoas criam o mesmo número e o sync cria 2 pastas no Drive.

### 12.2 Requisitos novos (do P.O.)
- **R1 — Numeração automática.** O sistema atribui o número da pasta automaticamente (elimina a escolha manual e a colisão concorrente). Substitui a necessidade de "trava de duplicado" no caminho do usuário. (Definir: próximo número livre por tenant? formato? o que fazer com os 10A/10B e com pastas que vêm do Drive.)
- **R2 — Só sistema→Drive; REMOVER Drive→sistema por completo** (não é só um toggle: tirar a via de descoberta `driveParaSistema` e a Via B de download do fluxo automático — cron e worker). O sistema é a fonte.
- **R3 — Propagar MUDANÇAS do sistema para o Drive.** Hoje o motor **NÃO renomeia** no Drive. ⚠️ **CORREÇÃO MEDIDA (2026-08-13):** a frase anterior desta linha dizia que "`renomearPasta` existe na interface mas não é chamado" — **está errado**. `grep -rn renomearPasta app/` devolve **zero** ocorrências em PHP (as 2 ocorrências são JS do gerenciador de arquivos local, sem relação com o Drive). A `GoogleDriveClientInterface` tem **exatamente 5 métodos** — `criarPasta`, `listarSubpastas`, `listarArquivos`, `enviarArquivo`, `baixarArquivo` — e nenhum renomeia. **Logo R3 não é "ligar uma chamada existente": exige criar o método** na interface + em `GoogleDriveClient` (Drive API `files.update` com `name`) + no `FakeGoogleDriveClient` dos testes + o gatilho de propagação. O P.O. quer que **alterar dado no sistema (ex.: número/nome da pasta) atualize o Drive** → ligar `renomearPasta` no fluxo sistema→Drive. Isso também é a cura do problema "pasta no Drive com número diferente do sistema" (§ pergunta do P.O.: *alterar número no sistema NÃO muda no Drive hoje → resposta era NÃO; R3 muda para SIM*).
- **R4 — Alinhar o Drive ao sistema (o sistema manda).** Corrigir as divergências fazendo o Drive refletir o sistema (nome/número). Sob R3, muitas se resolvem sozinhas quando a propagação ligar; as substantivas (cliente/número trocado) o P.O. revisa.
- **R5 — Relatório das pastas criadas desde o "ponto correto" (2026-07-14) até agora** — são as 32 acima. SQL pronto para o próximo chat rodar (ver 12.4).
- **R6 — Limpeza das duplicatas atuais** (2 literais + 1 de número): decisão humana de qual manter, juntar documentos, apagar a outra pasta **e** a pasta correspondente no Drive.
- **R7 — Migração para o Drive do Farlei (dono único):** sequência decidida = **ALINHAR PRIMEIRO, depois migrar.** Preferir **exportar do sistema** (não copiar a pasta velha), MAS só depois de garantir (a) completude — o sistema NÃO tem 100% do Drive (pula Google-native/nome>255/tamanho>2GB/pasta sem número), então exportar às cegas perderia esses; e (b) nomes corretos — senão vão números errados. Farlei já tem armazenamento pago (não é Workspace, não há Shared Drive). "Pasta ser dona" não existe sem Workspace → o alcançável é "Farlei dono de tudo". Ownership: quem sobe/copia vira dono; a exportação pelo sistema (logado como Farlei) já resolve.

### 12.3 Sequência recomendada (uma frente grande, em ordem)
1. **Estancar a duplicação:** R1 (numeração automática) — impede novas colisões. (Ou, mais rápido como paliativo, um aviso de duplicado na criação.)
2. **Fatia A revisada:** R2 (remover Drive→sistema do automático) + R3 (propagar mudanças/renomeações ao Drive) + `select_account` no OAuth. *(O "toggle sincronizacaoAtiva" da §3 vira secundário — o P.O. quer o sentido único fixo, não um liga/desliga.)*
3. **Alinhamento (R4/R5):** rodar o relatório, revisar as 32 recentes + as divergências, corrigir no sistema; limpar as duplicatas (R6).
4. **Migração para o Drive do Farlei (R7):** com tudo alinhado, exportar do sistema para a pasta nova do Farlei (tratando os Google-native/pulados à parte).
5. **Fatia B:** botões Importar/Exportar com job+prévia+progresso (§5) — o Exportar é o motor da migração do passo 4; pode ser feito junto.

### 12.4 SQL do relatório (R5) — rodar via MCP jusprime-prod (só leitura)
```sql
-- as 32 pastas criadas desde o ponto correto (ajuste PII: sem nome_cliente se for compartilhar)
SELECT id, nup, nome_cliente, nome_acao, drive_folder_id, created_at
FROM pasta WHERE tenant_id = 1 AND created_at >= '2026-07-14'
ORDER BY created_at;
-- os duplicados
SELECT nup, COUNT(*) FROM pasta WHERE tenant_id = 1 GROUP BY nup HAVING COUNT(*) > 1;
```
As divergências de NOME (sistema × Drive) saem do dry-run: `app:sync:reconciliar --tenant-id=1 --usuario-id=1 --dry-run` (linhas `[divergência]`).

### 12.5 Decisões travadas com o P.O. (2026-08-13) — frente `sync-sistema-manda`

Worktree isolada `.claude/worktrees/sync-sistema-manda`, base `master` local @ `3702452f`,
banco de teste `saas_testsync-sistema-manda`. **Esta fatia (R1 + Fatia A) NÃO tem migration.**

**D12.1 — R1, numeração automática (aprovado).**
- `MAX(prefixo numérico) + 1` **por tenant**. Próximo do tenant 1 = **1232**.
- **Não preencher buracos** — reaproveitar número de pasta apagada confunde Drive e histórico.
- `CriarPastaDTO.nup` vira `?string`; `null` = gerar. CSV (`ImportarAcervoCommand`) e Drive
  continuam passando o número da origem.
- **Concorrência: `pg_advisory_xact_lock` por tenant** na geração. `MAX+1` puro tem corrida — é
  exatamente o defeito que o R1 existe para matar. Advisory lock serializa sem migration e sem
  depender da limpeza do R6.
- **O UNIQUE `(tenant_id, nup)` NÃO volta nesta fatia** — é passo do R6. Hoje é impossível: as 3
  duplicatas em produção barram a criação do índice.
- Precedente da casa para o gerador: `src/Tenant/UseCase/GerarCodigoFuncionario.php`.

**D12.2 — toggle `sincronizacaoAtiva`: ADIADO.** Ele existia para impedir que "conectar o Drive"
importasse conteúdo — perigo que o R2 elimina na raiz. Sem ele, R1 + Fatia A ficam **sem migration
nenhuma**, o que importa nesta janela: a frente parada `cobranca-acompanhamento-canonico` tem 4
migrations já aplicadas no banco de dev, e frente com migration vai uma de cada vez.

**D12.3 — R3/R4, renomeação: NUNCA incondicional por varredura.** *(Correção do P.O. sobre a
proposta original de renomear sempre em `garantirFolderDaPasta`.)* Renomear as 1070 pastas a cada
rodada do cron, mesmo quando o nome já bate, são ~1070 **writes/hora redundantes** no Drive —
desperdício e risco de rate limit, porque write pesa mais na cota. O desenho aprovado é:
- **(a) contínuo = por EVENTO.** O 6º ponto de dispatch (editar pasta) renomeia no Drive **quando o
  nome realmente muda**. É o coração do R3.
- **(b) legado = comando ONE-TIME de alinhamento**, que renomeia **só onde diverge** (as ~426
  divergências do D10). Não é o cron de hora em hora.
- **O cron `--modo=enviar` não renomeia por varredura.** Mesmo resultado do R4, sem custo perpétuo
  e sem precisar guardar "último nome sincronizado".
- Se algum dia a renomeação entrar no motor por-pasta, é **condicional** (só quando o nome diverge),
  nunca incondicional.

**D12.4 — R3 exige criar `renomearPasta` do zero** (confirmado com o P.O.): método novo na
`GoogleDriveClientInterface` + implementação em `GoogleDriveClient` (Drive API `files.update` com
`name`) + no `FakeGoogleDriveClient` dos testes + o gatilho de propagação. Não existe nada hoje —
ver a correção medida no R3 do §12.2.

**D12.5 — aviso de pasta duplicada por cliente+ação (escopo NOVO, decidido em 2026-08-13).**
O R1 fechou a colisão de **número**, não a dor que a originou. As 2 pastas literalmente
duplicadas da §12.1 nasceram de duas pessoas abrindo o mesmo caso ao mesmo tempo — com
numeração automática elas passam a receber **1232 e 1233**: continuam sendo duas pastas do
mesmo processo e ainda **somem da consulta de detecção do §12.4**, que procura NUP repetido.
Decisão do dono: na criação, se já existir pasta com o **mesmo cliente + mesma ação** no
escritório, **avisar e pedir confirmação** — nunca bloquear, porque o mesmo cliente pode ter
vários casos parecidos legitimamente. Comparação tolerante a acento e caixa
(`UNACCENT(LOWER(...))`, igual à busca livre da lista). Implementado em
`PastaRepository::findSemelhantesPorClienteEAcao` + tela `pasta/confirmar_duplicada.html.twig`
(reenvia os mesmos dados com `confirmar=1`).

**D12.6 — decisões do dono sobre os dois pontos que estavam em aberto (2026-08-13):**
- **R2, escopo → PRESERVAR o código, tirar só do automático.** `driveParaSistema` e a Via B são
  o motor do botão Importar (Fatia B) e da migração R7; apagar agora seria reescrever depois.
  Gate atrás de `--modo=importar`; cron e worker rodam `--modo=enviar`.
- **R1, tela → TIRAR o campo de número do modal.** O número é sequência interna do escritório.
  Remover encerra a colisão manual, e é fácil devolvê-lo como campo "avançado" se algum dia
  precisarem fixar um número. CSV e Drive seguem passando número explícito pelo UseCase.
- ⚠️ O **padrão do `--modo` é `enviar`**, e não "sem `--modo` = comportamento atual" como dizia
  a §4. Deixar o padrão bidirecional apostaria a garantia "nada importa sozinho" numa linha de
  crontab que alguém precisa lembrar de editar; com o padrão no comando, mesmo um cron antigo
  para de importar no deploy.

**⏳ EM ABERTO — OPS (não bloqueia código):** pausar o sync automático em produção até a fatia
entrar, ou deixar rodando? (Drive→sistema é aditivo e ninguém mais põe arquivo no Drive à mão,
então hoje é inofensivo.)
