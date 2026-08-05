# Spec — Abertura do cadastro público a usuários externos

> **Risco:** ALTO (identidade User/Tenant, camada de autorização)
> **Data:** 2026-08-05 · **Status:** 🚧 EM EXECUÇÃO — **F1 implementada** (suíte 3280 verde), F2–F7 pendentes
> **Domínios tocados:** Auth (senha, OAB), Tenant (módulos), Permission (novo gate), Profile
> **Spec-mãe:** `docs/specs/self-service-escritorios.md` (o funil em si já está implementado e publicado)

---

## 🧭 Leia primeiro

O funil de auto-cadastro **já existe e está no `origin/master`**: `/cadastro` → `CadastroPendente` →
e-mail de confirmação → `ConfirmarCadastroUseCase` cria `User` + `Tenant` + vínculo de dono + seed,
em transação única. O aceite de Termos, o limite de 3 escritórios próprios, o soft delete, o switcher
e a purga de PII também existem.

**Esta spec não constrói o funil. Ela fecha o entorno que falta para a porta ficar aberta para
desconhecidos.** A decisão do dono (2026-08-05) foi **abrir ao público**, não fazer piloto fechado.

**Decisões do dono que delimitam o escopo:**

| Tema | Decisão |
|---|---|
| Cobrança / plano / assinatura | **Fora desta rodada.** Cadastro segue livre e gratuito. |
| Provedor de e-mail transacional | **O dono contrata.** A spec cobre só o lado da aplicação. |
| OAB | **Aprovação manual na fila** do super-admin. Sem gate bloqueante. |
| Módulos por escritório | **Implementar toggle.** Escritório novo nasce só com o núcleo. |
| Política de privacidade, exclusão de conta, exportação de dados (LGPD) | **Fora desta rodada.** |

---

## 🎯 Problema

Hoje o cadastro funciona, mas o entorno não sustenta gente de fora. Quatro fatos medidos no código:

1. **Não existe recuperação de senha.** O link "Esqueceu a senha?" em
   `app/templates/security/login.html.twig:72` é `href="#"`. Não há `ResetPassword` em lugar nenhum
   (`src/`, `config/`, `templates/`), e o `ProfileController` não tem rota de troca de senha. Senha só
   nasce no cadastro/convite ou é trocada por um admin em `TenantController.php:525`. **Quem esquecer
   a senha fica trancado fora para sempre** e vira suporte manual.
2. **Escritório novo enxerga tudo.** `TenantBootstrapService::attachAllPermissions()` dá ao perfil admin
   os 26 códigos do catálogo, incluindo `modules.cobrancas`, `modules.bi` e `modules.djen`. Não existe
   toggle de módulo por tenant. Um cliente novo entra e vê o módulo de Cobrança, em construção ativa
   para outro cliente.
3. **A OAB falsa passa e ninguém é avisado.** `IniciarCadastroPublicoUseCase` já grava
   `StatusOab::NaoVerificada` e a fila de revisão existe (`/admin/platform/oab`, `ROLE_SUPER_ADMIN`),
   mas **nada notifica o super-admin** de que há cadastro novo esperando. A fila só é vista por quem
   lembra de abrir a página.
4. **O funil público não tem defesa além do rate limit por IP** (5/h, `framework.yaml:26`). Sem captcha,
   sem limite por e-mail, sem honeypot. Com IP rotativo, cria contas à vontade.

Some-se a isso a purga de PII implementada mas **sem cron em produção**, e a verificação de que a
produção realmente serve o `/cadastro` com URL e e-mail corretos.

---

## 🧱 Estado atual vs. Gap

| Capacidade | Hoje | Gap |
|---|---|---|
| Cadastro público + confirmação por e-mail | ✅ implementado e publicado | — |
| Aceite de Termos com gate por request | ✅ `TermoAceiteListener` | — |
| Limite de escritórios próprios (3, configurável) | ✅ `services.yaml:33` | — |
| Purga de PII (cadastro expirado + tenant em quarentena) | ✅ `PurgarDadosExpiradosCommand` (tem `--dry-run` e `--force`) | ❌ sem cron em prod |
| Fila de revisão de OAB do super-admin | ✅ `OabReviewController` | ❌ ninguém é avisado; usuário não sabe que está em análise |
| **Recuperação de senha** | ❌ não existe | Fluxo completo + troca no perfil |
| **Reenvio do e-mail de confirmação** | ❌ não existe (só reiniciar o cadastro) | Rota de reenvio com limite |
| **Módulos por escritório** | ❌ não existe | Tabela + gate + tela de plataforma |
| **Anti-abuso do funil** | ⚠️ só rate limit por IP | Captcha + limite por e-mail + honeypot |
| **Escritório zerado abre sem erro?** | ❓ não verificado | Teste funcional do tenant recém-criado |
| **E-mail transacional com domínio autenticado** | ❌ Gmail pessoal | Dono contrata; app precisa de remetente coerente e diagnóstico |

**Arquivos-âncora:**
- `app/src/Auth/Controller/CadastroController.php` — funil público
- `app/src/Auth/UseCase/IniciarCadastroPublicoUseCase.php` · `ConfirmarCadastroUseCase.php`
- `app/src/Auth/Service/CadastroMailer.php` — padrão de e-mail a reusar
- `app/src/Auth/Entity/CadastroPendente.php` — padrão de token/expiração a reusar
- `app/src/Service/PermissionChecker.php:18-39` — `canAccessModule()`, onde o gate de módulo entra
- `app/src/Service/TenantBootstrapService.php` — seed do escritório novo
- `app/src/Auth/Controller/OabReviewController.php` — fila de revisão (`/admin/platform/oab`)
- `app/templates/_sidebar.html.twig` — 10 chamadas de `can_access_module`
- `app/config/packages/framework.yaml:26` — rate limiters
- `app/config/packages/security.yaml` — `access_control`
- `docs/AUTORIZACAO.md` — modelo de autorização (precisa registrar a camada nova)

---

## ⚠️ O achado que muda o desenho do toggle de módulos

`PermissionChecker::canAccessModule()` (`app/src/Service/PermissionChecker.php:18-39`) libera **antes**
de olhar qualquer permissão:

```php
if ($this->isGlobalSuperAdmin($user)) { return true; }        // linha 20
...
if ($userTenant?->getTenantRole()?->isSystem() === true) { return true; }   // linha 30
```

O dono de um escritório self-service **tem** o perfil `isSystem` (é o que o `TenantBootstrapService`
cria). Consequência medida: **tirar as permissões do seed não esconde módulo nenhum dele.**

Portanto o gate de módulo **tem que rodar antes dos dois bypasses** — é uma camada nova, acima da
permissão, não uma configuração de permissão. Isso torna a fatia de módulos uma alteração no coração
da autorização: risco ALTO, revisão adversarial obrigatória, e `docs/AUTORIZACAO.md` atualizado no
mesmo commit.

---

## 🛠 Regras de Negócio (RN) e de Sistema (RS)

### Recuperação de senha
- **RN01 — Todo usuário recupera a própria senha.** Sem depender de admin nem de suporte.
- **RN02 — Resposta neutra.** A tela de "esqueci minha senha" responde igual para e-mail existente e
  inexistente ("se houver conta com esse e-mail, enviamos o link"). *Diferente do `/cadastro`, que
  revela a existência por decisão anterior — ali a informação é necessária para orientar o login.*
- **RS01 — Token de uso único, 1 hora.** Gerado com `random_bytes(32)`, **armazenado com hash** (o
  `CadastroPendente` guarda o token em claro; aqui não se repete esse padrão, porque este token
  destrava conta existente). Consumido na redefinição; expira em 1h.
- **RS02 — Um token vivo por usuário.** Pedir de novo invalida os anteriores.
- **RS03 — Trocar a senha derruba as sessões antigas.** Inclusive o cookie `remember_me`
  (`security.yaml` tem `remember_me` com 30 dias). **Verificar por teste**, não por suposição.
- **RS04 — Usuário inativo não recupera.** `User.isActive = false` recebe a mesma resposta neutra, mas
  nenhum e-mail sai.
- **RN03 — Trocar a senha estando logado.** Rota no perfil, exigindo a senha atual.

### OAB (aprovação manual)
- **RN04 — Escritório nasce funcionando com OAB não verificada.** Sem gate bloqueante (decisão do dono).
- **RN05 — O super-admin é avisado.** Todo cadastro público confirmado gera aviso ao super-admin, com
  link direto para a fila. Sem isso a fila é decorativa.
- **RN06 — O usuário sabe que está em análise.** Aviso visível no app enquanto `oabStatus` for
  `nao_verificada` ou `divergente`, com o que acontece se não for aprovada.

### Módulos por escritório
- **RN07 — Escritório novo nasce só com o núcleo.** Padrão ligado: `expediente`, `pastas`, `processos`,
  `clientes`, `tarefas`, `agenda`, `kanban`, `ponto`, `servicedesk`. Padrão desligado: `cobrancas`,
  `bi`, `djen`. *(`financeiro` — ver §Decisões pendentes.)*
- **RN08 — Escritórios existentes não mudam.** A migration liga **todos** os módulos para todo tenant
  que já existe. Ninguém perde acesso a nada por causa desta entrega.
- **RN09 — Só o super-admin liga módulo.** Tela de plataforma; o dono do escritório não se auto-libera.
- **RS05 — O gate precede os bypasses.** Módulo desligado nega para **todos** naquele tenant, inclusive
  perfil `isSystem` e `ROLE_SUPER_ADMIN`. Um super-admin que precise entrar liga o módulo antes — o
  caminho é explícito e auditável, não um bypass silencioso.
- **RS06 — Negar de verdade, não só esconder.** Esconder no `_sidebar.html.twig` não basta: a rota
  precisa recusar. Os controllers dos módulos desligáveis têm que checar `canAccessModule` — auditar
  os 25 arquivos que hoje chamam o método e cobrir os que faltarem.
- **RS07 — `djen` desligado por padrão também protege a chave compartilhada.** `DATAJUD_API_KEY` é uma
  só para toda a plataforma (`services.yaml:62`); tenant novo não entra consumindo a cota de todos.

### Anti-abuso do funil público
- **RS08 — Captcha no `/cadastro` e no "esqueci minha senha".** Provedor a definir (ver §Decisões).
- **RS09 — Rate limit por e-mail, além de por IP.** O limite atual de 5/h por IP não segura IP rotativo.
- **RS10 — Honeypot no formulário público.** Campo oculto; preenchido = descarta em silêncio.
- **RS11 — Reenvio de confirmação limitado.** Rota própria, com limite por e-mail e por IP, sem revelar
  se o cadastro pendente existe.

---

## 🧩 Fatias

Cada fatia entrega valor isolado e é revisável sozinha. A ordem abaixo é a de execução proposta.

### F1 — Recuperação e troca de senha ✅ IMPLEMENTADA (2026-08-05)

> **Entregue e revisado.** Passou por revisão adversarial (`feature-review-agent`) em 2026-08-05, que
> levantou 10 achados — 1 ALTO, 3 MÉDIOS, o resto baixo. Todos tratados (ver §"O que a revisão mudou").
> **Falta:** re-revisão (o ciclo exige, por ser risco ALTO) e smoke no navegador (do dono).
>
> **Sobre a prova por mutação.** Durante a implementação foram aplicados defeitos de propósito, um por vez,
> para conferir que algum teste falha. É verificação **manual e pontual**, feita em 2026-08-05 — não roda
> sozinha e não fica provada por este documento; quem quiser reconferir precisa repetir. Os pontos
> exercitados foram: token gravado em claro, uso único removido, guard do controller removido, conta inativa
> recebendo link, senha atual não conferida, busca de e-mail voltando a ser sensível à caixa, rate limiter
> consumido antes do CSRF, e purga da PII desligada no comando. **Duas mutações passaram batido na primeira
> tentativa** e obrigaram a escrever teste novo: a purga (o teste cobria o UseCase, não a ligação com o
> comando) e o uso único ponta a ponta.
>
> **Decisões tomadas durante a implementação, que a spec não previa:**
> - `RedefinicaoSenhaMailerInterface` foi extraída porque o mailer é `final` e o teste precisa observar a
>   decisão de enviar (é ela que sustenta a resposta neutra). Aliasada em `services.yaml`.
> - `RedefinicaoSenhaRepository::invalidarPendentesDoUsuario()` ganhou o parâmetro `$exceto`: o DELETE é
>   DQL e roda na hora, enquanto a marca de uso só vai ao banco no flush — sem a exceção, a linha corrente
>   seria apagada antes de ser marcada como usada.
> - O honeypot precisou de `empty_data: ''` no form: campo em branco chegava como `null` e estourava no DTO.
> - `RedefinicaoSenha` entrou em `NAO_AUDITAVEIS` (teste de cobertura de auditoria), pelo mesmo motivo do
>   `CadastroPendente`. O `password` já é filtrado por `IGNORED_FIELDS` no `AuditLogSubscriber`, então a
>   troca de senha é auditada no `User` sem gravar hash nenhum.
> - **RS03 confirmada no vendor antes de escrever o teste:** `ContextListener::hasUserChanged()` compara o
>   hash da senha, e `signature_properties` do remember-me tem default `['password']`. É comportamento do
>   framework — mas há teste, com uma guarda extra que prova que a sessão foi mesmo derrubada (senão o
>   teste de remember-me passaria por engano).

**O que a revisão mudou (2026-08-05):**

| # | Achado | Correção |
|---|---|---|
| 1 | **ALTO** — conta gravada com maiúscula (`Ana@Adv.com`) logava normal mas **nunca** recuperava a senha, e ainda recebia "se houver conta, enviamos o link". O índice de `user.email` é btree comum, sem `lower()`, e o cadastro público só fazia `trim`. | Cadastro público passa a normalizar (como os convites já faziam) **e** a recuperação busca por `LOWER()` — assim quem já estiver nesse estado também se recupera. Duas contas que difiram só na caixa: nada é enviado e o erro vai para o log. Teste funcional contra o SQL real. |
| 2 | **MÉDIO** — o `catch (\RuntimeException)` não cobria `RfcComplianceException` (estende `InvalidArgumentException`) nem erro de Twig. `MAILER_FROM` malformado → **500 para e-mail existente, 302 para inexistente**: oráculo de existência. Relevante porque a F2 vai justamente trocar esse valor. | `catch (\Throwable)`. |
| 3 | **MÉDIO** — o limiter de IP era consumido **antes** do CSRF: 5 POSTs anônimos com corpo lixo derrubavam a recuperação da plataforma inteira por 1h (atrás do nginx o IP é o mesmo para todos). | Os dois limiters passaram para depois da validação. ⚠️ **Isto encarece o abuso, não o elimina** — ver a 2ª revisão, achado 2. |
| 4 | **MÉDIO** — `purgar()` e `contarPurgaveis()` existiam **sem chamador**: IP e user agent ficariam para sempre, e o comentário que tirava a tabela da auditoria afirmava um "purgado depois de usado" que o código não entregava. | `PurgarRedefinicoesSenhaUseCase` ligado ao `app:purgar-dados-expirados`, com teste **no comando** (não só no UseCase — foi assim que o furo nasceu). |
| 5 | BAIXO-MÉDIO — caminhos sem cobertura. | Testes novos: honeypot por HTTP, `/senha/enviado` renderizando, uso único ponta a ponta (redefinir de verdade e reusar o link), requirement `[a-f0-9]{64}` da rota, e o momento de consumo das duas cotas. |
| 7 | BAIXO — 3/h por e-mail deixava um atacante trancar a vítima, e quem digitasse errado 2× já chegava no limite. | Subiu para 5/h. |
| 8 | BAIXO — `RateLimiterFactory` está deprecado. | Trocado por `RateLimiterFactoryInterface`. *(Dívida pré-existente do projeto inteiro, não desta frente — corrigida só aqui.)* |
| 9 | BAIXO — consumo do token não era atômico. | `UPDATE ... WHERE usado_em IS NULL`: o banco decide quem chegou primeiro, e quem perde a corrida não troca senha nenhuma. |
| 6 | BAIXO — **oráculo de tempo**: e-mail existente custa banco + SMTP, inexistente retorna na hora. A resposta é idêntica; o tempo não. | **Aceito nesta rodada, registrado para a F2.** Só some com envio assíncrono, que exige um worker em produção — é trabalho da F2, não desta fatia. |
| 10 | Processo — a spec afirmava validação por mutação sem artefato reconferível. | Reescrito acima, dizendo o que é: verificação manual e pontual, com data. |

**O que a 2ª revisão mudou (2026-08-05) — ela achou defeito NAS CORREÇÕES da 1ª:**

| # | Achado | Correção |
|---|---|---|
| 1 | **MÉDIO-ALTO** — a guarda de ambiguidade da correção anterior **piorou o problema**: com `Ana@` e `ana@` coexistindo, antes a conta minúscula recuperava; depois da "correção", **as duas** perdiam a recuperação, para sempre, em silêncio. E 3 caminhos ainda criavam duplicata (`AceitarConviteEscritorioSemConta`, `AceitarConvitePlataforma`, `CreateSuperAdminCommand`). | Quatro camadas: **(a)** desempate por correspondência exata — quem digita o próprio e-mail recupera, então as duas voltam a funcionar; **(b)** normalização nos 3 caminhos restantes; **(c)** migration `Version20260805150000` com backfill `lower(email)` + **índice único funcional** `uq_user_email_lower`, que torna a duplicata impossível; **(d)** o caso ambíguo real continua silencioso, mas logado. |
| 2 | **MÉDIO** — a correção anterior **não fecha o DoS**: o token CSRF do Symfony não é de uso único na sessão, então um GET rende token reutilizável para N POSTs. Custo saiu de 5 para 6 requisições. A tabela acima vendia como resolvido. | Afirmação corrigida aqui e no comentário do código. O limite de IP subiu de 5 para **30/h** — enquanto `SYMFONY_TRUSTED_PROXIES` não estiver confirmado na VPS a chave é o IP do nginx, e limite baixo pune escritório inteiro em vez do atacante. **A defesa real é o limite por e-mail; fechar de vez é o captcha da F4.** |
| 3 | **MÉDIO** — **defeito NOVO, introduzido pela F1**: trocar a senha no perfil não invalidava os links de redefinição pendentes. Atacante com acesso momentâneo ao e-mail pede um link, guarda; a vítima troca a senha; o link ainda reseta por até 1h, passando por cima. | `AlterarSenhaUseCase` invalida os pendentes. A troca de senha passa a ser a última palavra. |
| 4 | **MÉDIO** — o `catch (\Throwable)` não tinha teste que o exigisse (reverter para `\RuntimeException` mantinha a suíte verde) e o log gravava string, sem `'exception' => $e`, então o Monolog não emitia stack trace. | Teste com `RfcComplianceException` (que não é `RuntimeException`) + teste que exige a chave `exception` no contexto. |
| 5 | BAIXO-MÉDIO — `ConfirmarCadastroUseCase` gravava o e-mail cru, e pendentes de antes do deploy confirmariam com a caixa original; `CadastroPendenteRepository::encontrarPorEmail` era exato, então o pendente antigo sobrevivia ao reinício do cadastro. | Normalização também na confirmação; busca do pendente por `LOWER()`. |
| 6 | BAIXO — o teste do POST inválido **percorria caminho diferente do que afirmava** (corpo `['x' => '1']` nem é submetido pelo Symfony), e o dublê aceitava sempre, então nada provava que estourar o limite bloqueia. | Teste passou a submeter o form de verdade com CSRF inválido (resposta é **422**, não 200 — confirmando o furo) e o dublê ganhou modo recusa, com teste que exige o 429. |
| 7 | BAIXO — as três escritas da redefinição não eram atômicas: falha no flush deixaria token queimado e senha inalterada. | `wrapInTransaction`, como no `ConfirmarCadastroUseCase`. |
| 8 | BAIXO — o comentário do `exceto` e a spec mantinham uma justificativa que o consumo atômico tornou falsa. | Reescritos: hoje é defesa em profundidade, não necessidade. |

**O smoke no navegador achou o que nenhum teste achou (2026-08-05):** depois de trocar a própria senha no
perfil, o usuário era mandado para `/login` — mas **a própria sessão não cai**. O token é regravado na
sessão, com o hash novo, ao fim da mesma requisição; só as *outras* sessões caem, na requisição seguinte
delas. Resultado na tela: o usuário passava pelo login e era jogado de volta para dentro, sem entender o
desvio. O teste funcional passava porque afirmava o redirect e **não seguia**. Corrigido: volta para
`/perfil` com a mensagem certa, e o teste agora segue o redirect e exige que o usuário continue conectado.

**Mutação nas correções da 2ª rodada** (mesma ressalva de antes — manual e pontual, 2026-08-05): sem desempate → falha; troca de senha sem invalidar pendentes → falha; `catch` estreito de volta → falha. Os três pegos.

**Prova da trava da migration:** em banco de brinquedo com `Ana@Adv.com` + `ana@adv.com`, a consulta de colisão retorna a linha e o backfill sem a trava falha com `duplicate key value violates unique constraint`. A trava existe porque **só o dev foi medido** — a produção é outro banco.

**Arquivos:** `Auth/Entity/RedefinicaoSenha.php` · `Auth/Repository/RedefinicaoSenhaRepository.php` ·
`Auth/UseCase/{Solicitar,Redefinir,Alterar}*.php` · `Auth/Service/RedefinicaoSenhaMailer{,Interface}.php` ·
`Auth/Controller/RecuperacaoSenhaController.php` · `Auth/DTO/*` · `Auth/Form/*` ·
`templates/auth/senha/*` · `templates/email/redefinicao_senha.html.twig` ·
`Profile/Controller/ProfileController.php` (rota `/perfil/senha`) ·
`migrations/Version20260805120000.php` (tabela) e `Version20260805150000.php` (normalização de e-mail) ·
`config/packages/{security,framework}.yaml` · `config/services.yaml` ·
`Auth/UseCase/{AceitarConviteEscritorioSemConta,AceitarConvitePlataforma,ConfirmarCadastro,IniciarCadastroPublico}UseCase.php` ·
`Auth/Repository/CadastroPendenteRepository.php` · `Repository/UserRepository.php` · `Command/CreateSuperAdminCommand.php`

> ⚠️ **Antes do deploy em produção, rode a consulta de colisão.** A migration `Version20260805150000`
> **recusa rodar** se existirem contas que diferem só na caixa, porque decidir qual sobrevive é decisão
> humana, não de migration. Só o dev foi medido (13 usuários, 0 colisões).
>
> ```sql
> SELECT lower(email), count(*), array_agg(email), array_agg(id)
> FROM "user" GROUP BY lower(email) HAVING count(*) > 1;
> ```
>
> ⚠️ **Não aceite o `DROP INDEX uq_user_email_lower`** que o `doctrine:schema:update --dump-sql` vai
> propor daqui em diante: o Doctrine não representa índice funcional no mapeamento, e sem ele a duplicata
> por caixa volta a ser possível.

<details>
<summary>Escopo original da fatia</summary>


**Entrega:** o usuário externo destrava a própria conta sem falar com ninguém.

- Entidade `RedefinicaoSenha` em `src/Auth/Entity/` (user, `tokenHash`, `expiresAt`, `usadoEm`, `ip`,
  `criadoEm`) + migration. Padrão de referência: `CadastroPendente`.
- `SolicitarRedefinicaoSenhaUseCase` — resposta neutra (RN02), invalida tokens anteriores (RS02),
  ignora usuário inativo (RS04).
- `RedefinirSenhaUseCase` — valida token/expiração/uso único, rehasheia a senha, marca `usadoEm`.
- `AlterarSenhaUseCase` — troca autenticada, exige a senha atual (RN03).
- `RecuperacaoSenhaController` em `src/Auth/Controller/`:
  `/senha/esqueci` (GET/POST) · `/senha/enviado` (GET) · `/senha/redefinir/{token}` (GET/POST).
- Rota no perfil para a troca autenticada.
- `RedefinicaoSenhaMailer` (espelha o `CadastroMailer`) + template em `templates/email/`.
- `security.yaml`: `^/senha` como `PUBLIC_ACCESS`, **acima** da regra `^/` (a ordem importa).
- `framework.yaml`: limiters `senha_esqueci_ip` e `senha_esqueci_email`.
- Corrigir o `href="#"` do login (`login.html.twig:72`).
- **Testes:** unit dos 3 UseCases (token expirado, reusado, usuário inativo, e-mail inexistente
  responde igual) + functional do fluxo ponta a ponta + **teste de RS03** (senha trocada invalida
  sessão e `remember_me`).
- **Migration:** sim (tabela nova).

</details>

### F2 — E-mail: reenvio, remetente e diagnóstico

**Entrega:** o lado da aplicação pronto para o provedor que o dono contratar.

- Rota de **reenvio da confirmação** de cadastro, com os limites da RS11.
- `MAILER_FROM` coerente com o domínio do produto (`bluejus.com.br`) — hoje é
  `naoresponda@jusprime.com.br` (`app/.env:16`), divergente do produto e do que os Termos dizem.
- Comando `app:email:diagnostico <destino>` — envia um e-mail de teste e relata o transporte,
  o remetente efetivo e o erro bruto. É o que permite conferir a configuração **em produção** sem
  depender de cadastrar alguém de verdade.
- **Envio assíncrono (Messenger).** Herdado da revisão da F1 (achado 6): hoje o envio é síncrono, então
  e-mail existente custa banco + SMTP e inexistente retorna na hora — a resposta é idêntica, o tempo não.
  Fila resolve os dois: fecha o oráculo de tempo e tira o SMTP do caminho da requisição. **Exige um processo
  worker em produção**, o que é decisão de infraestrutura do dono.
- Tratamento de falha de envio: o cadastro pendente já foi persistido antes do envio; a tela precisa
  oferecer o reenvio em vez de mandar recomeçar.
- **Fora desta fatia:** contratar provedor, configurar SPF/DKIM/DMARC no DNS (dono).
- **Migration:** não.

### F3 — OAB: fila operante

**Entrega:** OAB falsa deixa de passar despercebida.

- Aviso ao super-admin a cada cadastro público confirmado (e-mail com link para a fila).
- Contador de pendentes visível na área de plataforma.
- Banner no app do usuário enquanto a OAB não for confirmada (RN06).
- **Sem gate bloqueante** — o escritório funciona durante a análise (decisão do dono).
- **Testes:** unit do disparo do aviso + functional do banner.
- **Migration:** não.

### F4 — Anti-abuso do funil público

- Captcha no `/cadastro` e no `/senha/esqueci` (RS08).
- Limiter por e-mail (RS09) somado ao de IP que já existe.
- Honeypot (RS10).
- **Testes:** functional — requisição sem captcha é recusada; honeypot preenchido não persiste nada;
  6ª tentativa do mesmo e-mail em uma hora é barrada.
- **Migration:** não.

### F5 — Módulos por escritório ⚠️ RISCO ALTO

**Entrega:** escritório novo não vê módulo em construção. Frente própria (worktree), revisão
adversarial obrigatória, e o único item desta spec que mexe na camada de autorização.

- Entidade `TenantModulo` (tenant, `modulo`, `habilitado`) com `UNIQUE(tenant_id, modulo)` + migration
  que **liga tudo para os tenants existentes** (RN08).
- Enum `Modulo` em `src/Shared/` com os 13 códigos do catálogo — hoje eles são string solta em 34
  chamadas e 10 pontos do sidebar.
- **Gate em `PermissionChecker::canAccessModule()`, antes das linhas 20 e 30** (RS05). Mesmo tratamento
  em `hasPermission()` para os códigos `modules.*`, senão o gate vaza por outra porta.
- `TenantBootstrapService`: escritório novo nasce com o núcleo da RN07.
- Tela `/admin/platform/escritorios` (`ROLE_SUPER_ADMIN`) para ligar/desligar por escritório.
- **Auditoria dos 25 arquivos que chamam `canAccessModule`** — garantir que toda rota de módulo
  desligável recusa no servidor, não só some do menu (RS06).
- Atualizar `docs/AUTORIZACAO.md` **no mesmo commit** — é uma camada nova no modelo.
- **Testes:** unit do checker (módulo off nega até para `isSystem` e super-admin) + functional (rota do
  módulo desligado devolve 403; menu não mostra) + teste de que tenant existente não perdeu nada.
- **Migration:** sim (tabela nova + backfill).

### F6 — O escritório zerado abre sem erro

**Entrega:** prova de que um tenant recém-criado navega o núcleo inteiro sem 500.

- Teste funcional que cria um tenant pelo `ConfirmarCadastroUseCase` e percorre as rotas do núcleo com
  banco vazio: sem sede, sem cargo, sem jornada, sem cliente, sem processo. O seed atual cria só perfil
  admin, feriados e marcadores — nada garante hoje que as telas suportem o resto vazio.
- Corrigir o que quebrar (escopo aberto por natureza; medir antes de dimensionar).
- **Migration:** não.

### F7 — Produção (checklist do dono, executado por ele)

Não é código. É a lista do que precisa estar certo na VPS antes de divulgar o endereço:

1. `DEFAULT_URI` no `.env.prod` da VPS = `https://bluejus.com.br`. **A cópia local do `.env.prod` está
   com `http://localhost` e é comprovadamente stale** — vale o que estiver na VPS. Se estiver errado,
   todo link de confirmação e de redefinição de senha nasce quebrado.
2. `MAILER_DSN` do provedor novo + `MAILER_FROM` do domínio autenticado.
3. `SYMFONY_TRUSTED_PROXIES` / `SYMFONY_TRUSTED_HOSTS` ativos (já estavam em 2026-07-01; reconferir —
   sem eles o rate limiter agrupa todo mundo no IP do nginx).
4. Deploy com `./scripts/deploy-prod-tls.sh` — prod é imagem baked; `git pull` no container não aplica.
5. `app:email:diagnostico` apontando para um e-mail externo (Gmail/Outlook) — conferir se **chega na
   caixa de entrada**, não no spam.
6. Cadastro real ponta a ponta em prod: criar conta → receber e-mail → confirmar → logar → esquecer a
   senha → redefinir → entrar.
7. **Cron da purga:** `--dry-run` primeiro, conferir o relatório, depois agendar diário com `--force`.

---

## 📌 Critérios de Aceite (BDD)

```gherkin
Funcionalidade: Recuperação de senha

  Cenário: Usuário redefine a própria senha
    Dado um usuário ativo com e-mail "ana@adv.com"
    Quando ela pede a redefinição informando o e-mail
    Então recebe um e-mail com link de token válido por 1 hora
    E ao abrir o link e informar a nova senha, consegue logar com ela
    E o token não funciona uma segunda vez

  Cenário: E-mail inexistente não é revelado
    Dado que não existe conta com "ninguem@adv.com"
    Quando alguém pede a redefinição para esse e-mail
    Então a resposta é idêntica à de um e-mail existente
    E nenhum e-mail é enviado

  Cenário: Trocar a senha derruba as sessões antigas
    Dado um usuário logado em outro navegador, com "lembrar-me" ativo
    Quando ele redefine a senha
    Então a sessão antiga deixa de autenticar
    E o cookie remember_me antigo não autentica mais

Funcionalidade: Módulos por escritório

  Cenário: Escritório novo não enxerga módulo desligado
    Dado um escritório criado pelo cadastro público
    Quando o dono (perfil isSystem) abre o menu
    Então "Cobranças" não aparece
    E o acesso direto à rota de Cobranças devolve 403

  Cenário: Escritório existente não perde nada
    Dado um escritório criado antes desta entrega
    Quando a migration roda
    Então todos os 13 módulos ficam habilitados para ele
    E nenhum usuário perde acesso que tinha

  Cenário: Super-admin liga o módulo
    Dado um escritório com "Cobranças" desligado
    Quando o super-admin habilita o módulo na tela de plataforma
    Então o menu passa a mostrar Cobranças para os usuários com a permissão

Funcionalidade: OAB em análise

  Cenário: Cadastro confirmado avisa o super-admin
    Dado um cadastro público confirmado com OAB não verificada
    Então o super-admin recebe aviso com link para a fila
    E o novo usuário vê no app que sua OAB está em análise

Funcionalidade: Anti-abuso

  Cenário: Cadastro sem captcha é recusado
    Quando o formulário público é enviado sem o desafio resolvido
    Então nada é persistido e o erro é exibido

  Cenário: Mesmo e-mail insistindo é barrado
    Dado 5 tentativas de cadastro para "ana@adv.com" na última hora
    Quando a 6ª chega, mesmo de outro IP
    Então é recusada por limite
```

---

## ❓ Decisões pendentes (do dono)

1. **Captcha:** Cloudflare Turnstile (gratuito, sem conta paga, leve) · hCaptcha · reCAPTCHA.
   *Recomendo Turnstile* — o domínio não usa Cloudflare hoje, mas o Turnstile funciona independente disso.
2. **Módulo `financeiro`:** o catálogo tem `modules.financeiro.view`, mas não achei tela própria — parece
   ser aba dentro de Pastas. Confirmar se entra no núcleo (ligado) ou na lista de desligados.
3. **Aviso de OAB pendente:** e-mail para qual endereço? (o do super-admin cadastrado, ou um fixo de
   plataforma).

---

## 🔒 Notas de risco

- **F5 mexe no `PermissionChecker`**, que é a base de toda a autorização do sistema. Qualquer erro ali
  vaza ou tranca módulo para escritórios em produção. Frente isolada, revisão adversarial, e a suíte
  completa antes de integrar.
- **F1 mexe em autenticação.** Token de redefinição mal feito é conta tomada. Hash do token no banco,
  uso único, expiração curta, resposta neutra, limite por e-mail.
- **F1 e F5 têm migration.** Pela regra do projeto, frentes com migration vão **uma de cada vez**.
- Isolamento multi-tenant continua inegociável em tudo que for tocado: filtro, guard de posse e teste
  cross-tenant.

## ⏭ Fora de escopo (registrado, não esquecido)

- Cobrança / plano / assinatura / limites de uso por plano — decisão do dono, "bem mais para frente".
- Política de privacidade, exclusão da própria conta, exportação de dados (LGPD) — fora desta rodada.
  **Fica a exposição:** o Termo vigente promete exportação em até 30 dias após o encerramento e
  disponibilidade de 99,5%, e nenhuma das duas coisas existe hoje.
- Gate bloqueante de OAB (Passo 3 da spec de validação de OAB).
- Chave DATAJUD/DJEN por tenant — mitigado parcialmente pelo `djen` desligado por padrão (RS07).
- Monitoramento e alerta de erro em produção.
