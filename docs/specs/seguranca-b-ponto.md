# Spec: Segurança B, ponto eletrônico

## Estado da frente (23/10/2026)

- Origem: achados de auditoria interna F2, F8, F11, F12, F17, F18 e F19, confirmados no código por uma leitura
  read-only. O relatório da auditoria fica fora do repositório e não é citado aqui.
- Risco **ALTO** (ponto eletrônico). O fluxo é este: spec, implementação, revisão read-only, correção e nova revisão.
- **PR 1** (branch `seguranca-b-ponto`): F2, F12, F18 e F19, com o F17 documentado. Não tem migration.
- **PR 2** (branch própria, depois do PR 1): F8 e F11. Ver §3.

## 1. PR 1

### 1.1 F2: o nome da sede na mensagem do GPS

- **Antes:** `templates/ponto/index.html.twig` monta a mensagem da cerca com `innerHTML` e coloca o nome da sede sem
  escape.
- **Depois:** a mensagem é montada por DOM (`createElement`/`textContent`), ou passa por escape de HTML antes de entrar.
  Nenhum dado digitado por alguém entra em `innerHTML` sem escape nessa tela. Vale também para outros pontos do mesmo
  script que usem `innerHTML` com dado que veio do servidor.
- **A tela não muda** para nomes comuns.
- **Teste:** sede com caracteres especiais no nome. O nome aparece como texto e não vira elemento. O teste pode ser
  estático, de padrão do script, mais um functional do JSON.

### 1.2 F12 e F18: fórmula no XLSX da folha de ponto

- **Antes:** o `FolhaPontoXlsxExporter` grava, como valor genérico, o nome do empregado, o nome e o endereço do
  escritório e o responsável pela assinatura. Um texto que começa com `=`, `+`, `-` ou `@` vira fórmula na planilha.
- **Depois:** todo texto livre vai para a célula com o tipo **string** explícito
  (`setCellValueExplicit(..., DataType::TYPE_STRING)`), por um único helper do exportador. A planilha mostra o texto
  exatamente como foi digitado. Nenhum caractere é trocado nem prefixado.
- **Teste unit do exportador:** para cada um dos quatro campos, com valores começando por `=`, `+`, `-` e `@`, a célula
  sai com tipo string e o valor igual ao texto. Um nome comum sai igual a antes.

### 1.3 F19: o "desfazer" da auditoria

- **Antes:** `AuditLogController` exige só `admin.audit.view` para desfazer. O `DesfazerAlteracaoAuditLogUseCase`
  reverte `Sede` (latitude, longitude e raio da cerca), `Cargo` e `Lotacao`. Editar essas entidades pela tela exige
  outra permissão.
- **Depois:** desfazer uma alteração exige, além de `admin.audit.view`, a **mesma permissão que a tela usa para editar
  aquela entidade**:
  - `Sede`: `admin.ponto.manage`;
  - `Cargo` e `Lotacao`: `admin.tenant.settings.manage` (aba do `app_tenant_edit`);
  - mesma família, acrescentada na revisão: `Chamado`, `ChamadoAnexo` e `ChamadoInteracao` com
    `admin.servicedesk.manage`; `Evento` com `admin.users.manage` (sem a exceção do criador).
  - Sem ela, a resposta é 403 e nada muda.
  - A checagem fica no servidor, no UseCase ou no Controller, pelo `PermissionChecker` do sistema. Não basta esconder
    o botão.
- **Testes funcionais:**
  - usuário só com `admin.audit.view` recebe 403 ao desfazer um update de Sede, e a sede continua intacta;
  - com `admin.ponto.manage`, o desfazer funciona;
  - o mesmo vale para Cargo e Lotação.

### 1.4 F17: GPS informado pelo navegador (limitação conhecida)

- A cerca do ponto decide a partir da posição que o navegador informa. O servidor não confirma essa posição por
  outro meio. O alcance é só a batida do próprio funcionário.
- **Não se corrige agora.** A limitação fica registrada em `docs/AUTORIZACAO.md`, na lista de falhas conhecidas, sem
  roteiro de exploração.
- Uma mitigação futura possível, com migration e decisão do dono: gravar o IP da batida e sinalizar as batidas fora
  da rede da sede.

## 2. O que o PR 1 não faz

- Não muda o desenho nem os textos das telas.
- Não toca na jornada (PR 2) nem no `AuditLogSubscriber`, que é do #123.

## 3. PR 2

O PR 2 (achados de auditoria interna F8 e F11, jornada por escritório) tem spec própria em
docs/specs/seguranca-b-jornada.md, em outra branch.
