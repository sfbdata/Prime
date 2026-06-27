# Spec — C5: uploads com PII fora do `public/` (app-wide)

> Frente **C5** da segurança residual (`followups-seguranca-residual.md`). Uploads com PII servidos
> como arquivo estático de dentro do `public/` são baixáveis por URL direta **sem auth/tenant/posse**.
> O H2 (`servicedesk-anexo-download-seguro.md`) tratou só o ServiceDesk. Aqui fechamos o resto.
> **Risco ALTO** (PII exposta). Sem migration de banco.

> ## ✅ STATUS FINAL (deploy 2026-06-27) — C5.1 LIVE, C5.2/3/4 CANCELADOS
> **C5.1 está em produção e provado (`200→404`).** Defesa = bloqueio de `/uploads/` estático no nginx
> (`nginx.prod.conf`/`nginx.conf`: `location ^~ /uploads/ { rewrite ^ /index.php last; }`) + firewall
> `^/ ROLE_USER` → toda URL `/uploads/*` exige login; o que é servido legitimamente passa por rota
> controlada (PHP lê o arquivo do disco). Rota `PecaImagemController` (`/uploads/pastas/{nome}`, só imagens)
> mantém as imagens do editor de peças funcionando.
>
> **C5.2/C5.3/C5.4 (mover pastas/perfil/tarefas para `var/uploads`) foram CANCELADOS.** No deploy
> descobriu-se que **`var/uploads` NÃO é volume persistido** em prod (o `docker-compose.prod.yml` só monta
> `uploads_prod` em `/var/www/app/public/uploads`) — apontar para `var/` perderia os arquivos no rebuild e
> deixava os existentes órfãos (404). Como o bloqueio do nginx já protege todo o `/uploads/`,
> **a decisão é manter TODOS os uploads em `public/uploads/` (volume persistido), protegidos pelo nginx.**
> Os configs de clientes/justificativas/chamados que `dcceb14`/`7f269e4` haviam apontado para `var/` foram
> **revertidos para `public/`** (`16fc10d`). Nenhum arquivo foi movido (já estavam todos em public).
>
> ⚠️ Se um dia se quiser de fato tirar os arquivos do web root (defesa-em-profundidade além do nginx),
> o pré-requisito é **adicionar um volume persistido para `var/uploads`** no compose de prod — só então a
> abordagem "mover para var" faz sentido. O conteúdo abaixo (mapa/plano original) fica como HISTÓRICO.

## Mapa de exposição (investigação read-only + verificação empírica, jun/2026)

| Módulo | Dir (config) | Rotas de serve | Estado | Ação |
|---|---|---|---|---|
| **pastas** | `public/uploads/pastas` ❌ | já seguras (`canAccessResource`) | 971 arquivos PII (procurações, RG, contratos, 337 peças HTML) | mover dir→var + tratar imagens do editor (C5.2) |
| **perfil** | `public/uploads/perfil` ❌ | já segura (`app_profile_foto_serve`) | 2 órfãos | mover dir→var (C5.3) |
| **tarefas** | hardcoded `public/uploads/tarefas/chat` ❌ | já seguras | helper legado grava caminho público inteiro na entidade | refactor + mover dir→var (C5.4) |
| justificativas | `var/uploads/justificativas` ✅ | já seguras | 8 leftover órfãos | faxina (C5.1) |
| clientes | `var/uploads/clientes` ✅ | já seguras | 6 leftover órfãos | faxina (C5.1) |
| chamados (H2) | `var/uploads/chamados` ✅ | seguras | — | feito |

**Insight central:** o "servir com controle" (rotas com auth+tenant+posse) **já existe** em quase todos.
O furo é (a) os arquivos morarem em `public/` e o nginx servi-los estático — **inclusive em produção**:
`location ^~ /uploads/ { try_files $uri /index.php; }` serve o arquivo **se ele existir**; só cai no PHP
quando o arquivo não existe (verificado lendo `nginx.prod.conf`) — e (b) o vazamento explícito de URL
estática das imagens do editor de peças (`PeticionarController::uploadImagem` devolve `/uploads/pastas/...`).

## Decisões do dono

- **Defesa-em-profundidade no nginx primeiro** + faxina dos leftovers — fecha a exposição de prod de
  TODOS os módulos rápido; os moves por-módulo (var/) vêm depois sem pressa.
- **Endurecer o `nginx.prod.conf`** (parar de servir `/uploads/` estático) está no escopo (mudança de infra prod).

## C5.1 — defesa-em-profundidade (esta entrega)

1. **nginx (dev `nginx.conf` + prod `nginx.prod.conf`):** `location ^~ /uploads/` deixa de servir arquivo
   estático e roteia tudo ao front controller (`rewrite ^ /index.php last`). Como o firewall exige
   `ROLE_USER` em `^/` (`security.yaml`, catch-all), **toda** URL `/uploads/*` passa a exigir login →
   anônimo recebe redirect p/ `/login`. Fecha o bypass estático de **todos** os módulos de uma vez.
2. **Rota autenticada das imagens do editor** (`PecaImagemController`, `GET /uploads/pastas/{nome}`,
   só extensões de imagem): as peças embutem `<img src="/uploads/pastas/<hex>.jpg">` (e
   `ExportarPecaTextoUseCase` reescreve `/uploads/` → caminho de disco no export). Sem esta rota, peças
   existentes ficariam com imagem quebrada após o nginx parar de servir estático. Serve via
   `ArquivoStorageInterface::servir` a partir de `%uploads_dir%`, com guard anti path-traversal.
   - **Residual conhecido (fecha no C5.2):** a checagem é só de **autenticação**, não de tenant/posse —
     um usuário logado consegue buscar uma imagem de pasta por nome (hex aleatório de 16 bytes). É
     redução grande frente ao acesso **anônimo** anterior, mas o isolamento por tenant só vem quando
     `pastas` sair do `public/` (C5.2) e as imagens passarem a ser servidas por entidade.
3. **Faxina dos leftovers:** com o nginx roteando ao PHP, os órfãos em `public/uploads/justificativas`
   (8) e `public/uploads/clientes` (6) já ficam inacessíveis (404 — não há rota). O runbook de deploy
   remove os arquivos físicos em prod (são órfãos: 0 refs no banco, gitignored).

### Testes (C5.1)
`tests/Pasta/Functional/PecaImagemControllerTest.php`: anônimo → redirect p/ login (bloqueado);
autenticado + arquivo existente → 200 + Content-Disposition; autenticado + inexistente → 404;
traversal/extensão não-imagem → 404 (rota não casa). (O comportamento do nginx é validado por `nginx -t`
+ smoke manual; o teste funcional bate no kernel direto.)

### Deploy (C5.1)
- **Recarregar o nginx de prod** após o deploy do `nginx.prod.conf`. ⚠️ **Recriar o container nginx**
  (`docker compose up -d --force-recreate nginx` / o `deploy-prod-tls.sh`), **não** só `nginx -s reload`:
  o `nginx.conf` é bind-mount de arquivo único e, quando o arquivo é reescrito, o container continua vendo
  o inode antigo — um `reload` recarregaria a config velha. Após recriar, rodar `nginx -t` dentro do container.
- **Faxina:** `rm -f public/uploads/justificativas/* public/uploads/clientes/*` no servidor (órfãos: 0 refs no
  banco, gitignored). Opcional: `rmdir` os diretórios vazios.
- Conferir que o peticionamento (imagens do editor) segue funcionando **logado** (smoke).

### Verificação empírica (feita no dev, 26/06)
Após recriar o container com a config nova, `curl` SEM auth a arquivos reais de `public/uploads/`:
`/uploads/justificativas/<hash>.pdf` e `/uploads/pastas/<hash>.pdf` passaram de **HTTP 200** (PII servida
anônima) para **404** (sem rota → o front controller barra); `/uploads/pastas/<img>.jpg` anônimo → **302**
para `/login` (firewall). Antes do restart, ainda 200 (o `reload` não pegou o inode novo — daí a nota acima).

## Follow-ups (próximas frentes C5)

- **C5.2 — pastas → `var/uploads/pastas`:** trocar `uploads_dir` p/ var/; **mover os 971 arquivos** no
  deploy; ajustar `ExportarPecaTextoUseCase` (o `str_replace('/uploads/', projectDir.'/public/uploads/')`
  aponta p/ public) e servir as imagens do editor por entidade/tenant (fecha o residual do C5.1). MÉDIO/ALTO.
- **C5.3 — perfil → `var/uploads/perfil`:** trocar `fotos_perfil_dir`; serve já seguro
  (`app_profile_foto_serve`); 2 arquivos são órfãos. **Risco MÉDIO (Profile)** → spec própria.
- **C5.4 — tarefas → `var/uploads/tarefas`:** o mais sujo — `TarefaController::uploadFiles` (legado) grava
  o caminho público inteiro (`/uploads/tarefas/chat/...`) na entidade `TarefaMensagem.arquivoAnexo`;
  exige refactor p/ `ArquivoStorageInterface` + param + ajustar o valor armazenado (dados em prod).
- Remover o código morto `ChamadoAnexo::getCaminho()` (retorna `uploads/chamados/...`, sem consumidores).

## Fora de escopo
- Tornar as imagens do editor de peças entidades rastreáveis com tenant (isolamento total) — parte do C5.2.
- Inconsistência do Kanban (`AdicionarAnexoUseCase` grava dir literal `'kanban'` relativo ao CWD) — já em var/ funcional, mas fora do padrão; follow-up separado.
