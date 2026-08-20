# Handoff — DJEN → "Push Processual"

**Pausado em 2026-08-19.** Frente pronta, revisada duas vezes, **nada publicado**.
Memória do projeto: `project_push_processual_renomeacao.md`.

---

## Onde está

| | |
|---|---|
| Worktree | `/home/prime/projetos/jusprime/.claude/worktrees/push-processual` |
| Branch | `push-processual`, cortada de `origin/master` @ `19cfd9a9` |
| Banco de teste | `saas_testpush-processual` (clone do `saas_test`, feito 19/08) |
| Suíte | **3891/3891**, `lint:twig` e `lint:container` OK |

```
0c40e8bc aplica as correcoes das duas revisoes da renomeacao
e8fd7259 marca na spec do DJEN que o modulo virou Push Processual
4cdb22c7 renomeia o modulo DJEN para Push Processual na tela e nas URLs
```

No `master` principal há 3 commits só de coordenação (`docs/frentes-ativas.md`), sem código.

---

## Retomar

```bash
# rodar a suíte DA frente (NUNCA `cd app && php bin/phpunit` — isso testa o repo principal)
cd /home/prime/projetos/jusprime
scripts/frente-testar.sh push-processual                    # completa
scripts/frente-testar.sh push-processual --filter Djen      # só o domínio
```

⚠️ **O `frente-testar.sh` tem de rodar a partir do repositório principal.** Chamado de dentro da
worktree ele procura `.claude/worktrees/` dentro dela mesma e falha. Isso me mordeu 3× nesta sessão.

⚠️ **A abertura da frente falhou no meio e foi completada à mão.** O `composer install` estourou
memória no `cache:clear` e o `set -e` abortou antes dos uploads e do clone do banco — os dois foram
feitos manualmente depois. Se reabrir do zero, confira.

---

## O que a frente faz

O dono escolheu, entre 3 faixas medidas (superfície total: **847 ocorrências**), a do meio:
**rótulo visível + URLs**. Ficaram fora **de propósito**: namespace `App\Djen`, as 16 classes com
"Djen" no nome, a tabela `publicacao_djen` e o **código** da permissão `modules.djen.view`.

**A regra que resolve todo caso duvidoso:**

> **DJEN** = o sistema do CNJ, de onde as publicações vêm. **Push Processual** = o nosso módulo.
> Texto sobre o *nosso módulo* vira Push Processual. Texto sobre o *sistema do CNJ* fica DJEN.

Por isso `MotivoFalhaDjen`, a mensagem da notificação ("capturada **no DJEN**") e o h1 "Publicação do
DJEN" **não** mudaram. Já "módulo DJEN" no flash de permissão negada virou Push Processual.

### Os dois achados que não estavam no levantamento inicial

1. **199 notificações em produção têm `url = '/djen'` GRAVADA na linha** (mais recente 19/08). O path é
   persistido quando a notificação nasce, não montado na exibição. Daí `RotasLegadasDjenController`
   (301 em `/djen`, `/djen/oabs`, `/djen/{id}`, preservando query string).
2. **O rótulo da permissão mora no BANCO.** `TenantRoleType` monta a tela de papéis com
   `permission.description`, e `PermissionFixture` semeia só dev/teste. Sem a
   `Version20260819160000` (**só dado, uma linha**), produção continuaria dizendo "Acesso ao módulo
   DJEN" depois do deploy.

---

## Pendente

### 1. Smoke — ✅ FEITO no navegador em 20/08, a pedido do dono

Todos os pontos verificados no dev (`saas_ux`, dataset real, usuário `farlei.rocha@gmail.com`,
tenant 1). Evidência por inspeção do DOM, não por impressão visual:

| Ponto | Resultado |
|---|---|
| sidebar | "Push Processual", `href=/push-processual` ✓ |
| listagem | `/djen` → `/push-processual`, h1 "Push Processual — Publicações", 99 publicações ✓ |
| detalhe | `/djen/93` → `/push-processual/93`, h1 "Publicação do DJEN", teor renderizado ✓ |
| OABs | `/djen/oabs` → `/push-processual/oabs`; forms POST já nascem nas URLs novas, com token ✓ |
| query string | `/djen?tribunal=TRT10` → filtro **aplicado de verdade** (31 de 99) ✓ |
| **notificação antiga** | clicada de verdade (`href="/djen"`, "7 novas publicações capturadas no DJEN.") → caiu em `/push-processual` ✓ |
| busca do menu | `djen` → Push Processual · `intima` → Push Processual · `kanban` → Kanban (não quebrou os outros) ✓ |
| tela de papéis | **antes** da migration exibia "Acesso ao módulo DJEN"; **depois**, "Push Processual", com o checkbox ainda em `modules.djen.view` e o papel mantendo a permissão ✓ |

🔑 **A tela de papéis foi o achado provado ao vivo:** ela realmente exibia o nome antigo enquanto o
banco não tinha a migration. Não era raciocínio — foi visto na tela.

**Como o smoke foi feito** (a worktree não é servida pela 8080): criei um `server` adicional dentro do
container nginx, na porta 8081, apontando para o `public/` da worktree — **aditivo, sem tocar no que a
8080 entrega ao repositório principal** (havia outra sessão ativa). Acessível em
`http://172.20.0.4:8081` (IP do bridge).

```bash
# a config ficou instalada; para remover:
docker exec jusprime_nginx_dev rm -f /etc/nginx/conf.d/zz-smoke-push-processual.conf
docker exec jusprime_nginx_dev nginx -s reload
# a worktree também ganhou um app/.env.local (gitignored) apontando para saas_ux
```

⚠️ **A migration foi aplicada ao banco de dev `saas_ux`** durante o smoke (era o único jeito de
verificar a tela de papéis). Um `UPDATE` de uma linha; os 5 vínculos de papel sobreviveram, conferido.
Produção segue **sem** ela.

### 2. Integração (do humano)

Trazer o master para dentro da frente e rodar de novo **antes** de integrar; rodar a suíte no master
**depois** do merge. É o segundo passo que todo mundo pula e é o que pega quebra cruzada.

⚠️ O `master` local se moveu durante a sessão (outra sessão commitando). Confirme a base antes.

### 3. Deploy

Precisa de **rebuild via script** (prod é imagem baked) e de rodar a migration. Antes dela, a tela de
papéis segue com o nome velho.

**Janela conhecida e aceita:** página aberta no instante do deploy posta em `/djen/sincronizar` → 404,
e o id do token CSRF mudou junto. F5 resolve. Está documentado no docblock do controller legado.

---

## Lições desta sessão (não repita)

🔴 **A prova por reintrodução pegou DOIS testes meus que não provavam o que diziam.** Vale mais que os
achados das duas revisões:

1. **`assertStringContainsString` é fraco para valor de rollback.** O teste passava com
   `DESCRICAO_ANTIGA` truncada, porque `'Acesso ao módulo DJEN'` é substring do texto completo. Agora
   extrai o literal exato do INSERT original por regex e compara com `assertSame`.
2. **`catch (\Throwable)` mascara nome de rota errado.** Trocar `generate('push_processual_index')` por
   um nome inexistente **não** falha o teste — cai no fallback e a saída fica certa. Não é furo: nesse
   caso não há defeito. O teste pega a regressão real (o método inteiro revertido).

**Conclusão prática: reintroduza um defeito por vez, para cada teste novo.** Foram 3 reintroduzidos no
teste de contrato da migration e só 2 eram pegos na primeira versão.

Outras duas, menores:

- **O firewall intercepta antes da rota.** Deslogado, `/djen` dá 302 → `/login`, nunca o 301. Todo
  teste de rota legada tem de logar primeiro.
- **Julgamento de nomenclatura vira entrega silenciosa se você não perguntar.** Eu *apaguei* "no DJEN"
  de dois textos em vez de manter — não era o pedido nem a minha própria regra. As duas revisões
  pegaram. Corrigido.
