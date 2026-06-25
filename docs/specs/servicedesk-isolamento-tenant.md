# Spec — Hotfix: isolamento multi-tenant do ServiceDesk

## Motivo (risco ALTO)
O dashboard do ServiceDesk (`servicedesk_index`) vaza chamados entre escritórios: o
`ChamadoRepository` não filtra por tenant e a entidade `Chamado` não tem coluna `tenant`.
Um gestor de TI de um tenant vê os chamados de TODOS os tenants (e a busca pesquisa em
todos). Hotfix aplicado **antes** da migração de domínio (E4), por decisão do responsável.

## Solução
1. **Adicionar `tenant` ao `Chamado`** (`ManyToOne Tenant`, not null). Toda criação passa a
   gravar o tenant do contexto (solicitante).
2. **Migration** (`chamado.tenant_id`): adiciona coluna nullable → backfill a partir do
   `user_tenant` ativo do solicitante → define NOT NULL + FK + índice.
3. **Filtrar por tenant** nas queries que retornam dados de todos os tenants:
   `findAllFiltered`, `findAbertosNaoAtribuidos`, `findUrgentes`, `findRecentes`,
   `countByStatus`, `countByCategoria`, `getTempoMedioResolucao` (SQL puro). Cada uma recebe
   `Tenant $tenant` e filtra `c.tenant = :tenant` (ou `tenant_id = :id` no SQL).
   `findBySolicitante`/`findByResponsavel` já são seguras (filtram por usuário).
4. **Controller:** `novo()` grava o tenant atual no chamado; `index()` passa o tenant atual
   às chamadas do repositório.
5. **IDOR cross-tenant (descoberto durante o hotfix):** as actions `show`/`interacao`/
   `atribuir`/`status` carregavam o `Chamado` por ID sem verificar o tenant — um gestor de um
   escritório lia/alterava chamados de outro enumerando IDs (o gate `canAdminister` valida o
   tenant *atual*, não o do chamado). Adicionado guard `garantirChamadoDoTenant()` que
   responde **404** quando o chamado não é do tenant atual.

## Backfill
```sql
UPDATE chamado SET tenant_id = (
  SELECT ut.tenant_id FROM user_tenant ut
  WHERE ut.user_id = chamado.solicitante_id AND ut.is_active = true
  ORDER BY ut.id LIMIT 1
) WHERE tenant_id IS NULL;
```
Premissas e limites:
- Todo chamado tem solicitante com `user_tenant` ativo. O módulo esteve quebrado (HTTP 500)
  até a correção dos métodos, então a base de chamados reais deve ser ~vazia. Se sobrarem
  linhas sem tenant, a migration falha ao aplicar NOT NULL — sinal para investigar.
- **Solicitante multi-tenant:** o schema permite o mesmo usuário ativo em vários tenants
  (`user_tenant` único por `(user_id, tenant_id)`). O `ORDER BY ut.id LIMIT 1` escolhe o
  vínculo mais antigo — pode não ser o tenant onde o chamado foi aberto. Aceitável dado o
  volume ~zero; com chamados reais de solicitante multi-tenant, conferir manualmente.

## Aplicação
- **Dev (`saas`):** migration via `doctrine:migrations:migrate` (exercitou add-nullable →
  backfill → NOT NULL → FK/índice).
- **Teste (`saas_test`):** banco de teste NÃO é gerido por migrations (schema vem do
  mapping); sincronizado via `doctrine:schema:update --force --env=test`. `schema:validate`
  OK em dev e teste; `doctrine:fixtures:load` validado.

## Testes
- `ChamadoRepositoryTest` (KernelTestCase): `findAllFiltered` e contagens retornam só o
  tenant alvo (isolamento cross-tenant). Substitui o teste skipped do E4.1.
- Atualizar helpers de teste que criam `Chamado` para gravar o tenant.

## Problema em aberto (NÃO resolvido aqui — fix separado)
🔴 **Anexos servidos como arquivo público estático, sem auth e sem tenant.** O template
`templates/servicedesk/show.html.twig` linka o anexo via `asset('uploads/chamados/' ~
anexo.nomeArquivo)` — não há rota controladora de download. Qualquer um que saiba/adivinhe
o nome do arquivo baixa o anexo (PII de chamado de qualquer escritório). É um vazamento
cross-tenant pré-existente, **fora do escopo deste hotfix** (precisa de rota de download com
permissão + tenant + tirar os arquivos do diretório público). Registrar como item de
segurança próprio (candidato a hotfix dedicado ou E4.2).

## Não-objetivos
- Não migra o domínio (isso é E4.2+). Edição cirúrgica no legado por ser hotfix de segurança.
- Não corrige aqui o CSRF de `atribuir`/`status` nem o label de `status` (ficam no E4.2).
