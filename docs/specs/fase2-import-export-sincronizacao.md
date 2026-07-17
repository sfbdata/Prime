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
