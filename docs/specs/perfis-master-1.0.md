# Perfis 1.0 + Painel do Master (desenho "05 - Perfis 1.0")

Frente `perfis-master` · base `origin/master` @ `1b08bc89` · risco **ALTO** (identidade User/Tenant,
poder global do Master, suspensão e exclusão de conta) · aberta em 16/10/2026.

## 1. Fontes

- Desenho aprovado: projeto Claude Design `4f6a5f61-…`, arquivo `05 - Perfis 1.0.dc.html` (lista de perfis,
  edição Master/Primário/Secundário, Painel do sistema com 9 seções), mais `mapa-brasil.html`,
  `mapa-mundo.html`, `mapa-dados.js`, `bj-dica.js` e `ESPEC_DADOS_REAIS_MAPA.md` do mesmo projeto.
- Fonte pública OAB: <https://www.oab.org.br/institucionalconselhofederal/quadroadvogados> (Quadro da
  Advocacia: quantitativo, gênero e faixa etária por seccional; a OAB atualiza todo dia às 00:01). A página
  vem renderizada no servidor com as 3 tabelas no HTML (conferido em 16/10: SP = 396.761 advogados).
- Fonte pública IBGE: malhas (`/api/v3/malhas`), localidades (`/api/v1/localidades`) e população
  (`/api/v3/agregados/6579`).

**Regra do dono (do próprio desenho):** o painel não inventa informação. Dado que não existe aparece como
"sem informação" / "DADO AINDA NÃO COLETADO", nunca estimado.

## 2. Decisões do dono (16/10/2026)

| # | Decisão |
|---|---|
| D1 | Painel com **dado real**. O que não existe usa o estado vazio do próprio desenho. |
| D2 | **Master é único**: o desenvolvedor do sistema, conta `farlei.rocha@gmail.com`, invisível para os outros escritórios. **`ROLE_SUPER_ADMIN` passa a ser o Master**: nenhuma competência atual do super admin é removida; as prerrogativas se fundem e a nomenclatura visível passa a ser só "Master" (some "Super-admin", "Painel da Plataforma", "Convites de Plataforma"). |
| D3 | Cada escritório tem o seu primário: **quem abre a conta é o primário titular** (`Tenant.criadoPor`). O primário convida secundários. |
| D4 | **Sócios primários como no desenho**: o primário promove um secundário da própria conta a "primário sócio" e remove sócios. O titular nunca é rebaixado nem removido. Tudo na auditoria. |
| D5 | **Permissões do perfil primário: marcadas e travadas**, com a nota de que o primário tem acesso total (o `PermissionChecker` já ignora as caixas do perfil `isSystem`). A autorização não muda. |
| D6 | Menu da conta no Painel **conforme o protótipo**: estatísticas, histórico, suspender/reativar, excluir, **e limites, especialmente de armazenamento (GB) por conta**. |
| D7 | "Ver como Master / Primário": **prévia só para o Master**. "Primário" esconde a linha Master para ele ver o que o escritório enxerga. Não troca de usuário. |
| D8 | Fontes externas: **o servidor busca e guarda em cache**, registrando fonte e data. O navegador não chama site externo. |

### 2.1 Desvios do desenho decididos pelo dono (não são escolha da implementação)

- **Bloco "Masters do sistema"** (D2): mostra o Master único com cadeado; **sem** "Promover a Master" e sem
  remover. O cartão "Poderes exclusivos do Master" troca o item "Promover e remover Masters secundários" por
  nada (o item some). Os papéis em "Atribuições por papel" deixam de citar "Master secundário".
- **Caixas do perfil primário** (D5): marcadas e desabilitadas, "Marcar/Desmarcar todos" escondido, nota no
  lugar do resumo "N de M permissões ativas".
- **Planos** (D1): os cartões do protótipo têm preços "de exemplo". Sem plano no banco, a seção usa o estado
  vazio; o rótulo do plano na lista de contas mostra "sem plano".
- **Situação "Teste"**: não existe no banco. Contas são **Ativa** ou **Suspensa** (`Tenant.isActive`).

## 3. Estado medido do sistema (16/10)

- `ROLE_SUPER_ADMIN`: bypass total no `PermissionChecker` (`isGlobalSuperAdmin`), pode ficar sem escritório,
  acessa `/admin/platform/*` (dashboard, revisão de OAB, convites). Em PROD/dev o papel está com
  `jusprime.samuel@gmail.com` (id 2); o Farlei (id 1) só tem `ROLE_USER`. **A troca é dado de produção: do dono.**
- Perfil primário = `TenantRole` `isSystem=true` "Administrador do Escritório", criado por
  `TenantBootstrapService` (busca idempotente **pelo nome**: não renomear). Vários usuários podem ter esse
  perfil; o criador fica em `Tenant.criadoPor`. Trocar perfil por `edit-role` não tem guarda contra rebaixar o
  criador ou o último admin (lacuna fechada pela E1, §5).
- Dados que existem: `User.createdAt/oabNumero/oabUf/oabStatus`, `UserProfile.dataNascimento`,
  `UserTenant.lotacao/lastLoginAt/isActive/createdAt`, `Tenant.cidade/estado/createdAt/isActive/criadoPor`,
  pastas, `audit_log` (cross-tenant, sem filtro de tenant).
- Bytes armazenados: `pasta_documento.tamanho_bytes` (fora da lixeira), `cliente_documento.tamanho_bytes`,
  `documento_processo.tamanho`, `cobranca_documento` / `cobranca_carteira_documento` /
  `cobranca_acordo_documento.tamanho_bytes`, `chamado_anexo.tamanho` (via `chamado.tenant_id`),
  `kanban_anexo.tamanho` (via card → coluna → board). **Sem tamanho no banco:** justificativa de ponto e
  anexo de tarefa: entram como "não medido".
- Não existe: plano, cota, limite de usuários, faixas de alerta, data de desativação de vínculo (remover
  colaborador apaga a linha), auditoria de login, receita, custos, métricas de desempenho.

## 4. Telas (o desenho manda)

### 4.1 Lista `app_tenant_role_index` ("Perfis do Escritório")

Tabela do desenho: NOME · CLASSIFICAÇÃO · DESCRIÇÃO · PERMISSÕES · USUÁRIOS · SISTEMA · AÇÕES; linha
inteira clicável abre a edição; hover azul do desenho.

- **Master do Sistema** (linha virtual, só para `ROLE_SUPER_ADMIN`): selo MASTER roxo, descrição do
  desenho, permissões "Total", usuários = nº de Masters (1), cadeado; lápis abre o Painel (§4.3).
- **Primário** (`isSystem`): selo PRIMÁRIO, descrição do desenho ("Quem abriu a conta. Gestor do
  escritório; cadastra e administra os secundários. Não pode ser excluído."), permissões = total do catálogo,
  cadeado, só lápis.
- **Secundários**: selo SECUNDÁRIO, descrição gravada (vazia = "Usuário secundário"), contagem real, cadeado
  aberto, lápis + lixeira (confirmação interna do desenho, texto com nº de usuários afetados).
- "Ver como Master / Primário" (D7) e "Novo Perfil" no cabeçalho.

### 4.2 Edição de perfil

- **Secundário**: faixa informativa, NOME DO PERFIL + DESCRIÇÃO, 6 cartões de permissão (Administração do
  escritório · Módulos · Recursos · Cliente · Recursos · Pasta · Recursos · Processo · Outros recursos) com
  "Marcar/Desmarcar todos", rodapé "N de M permissões ativas" + Cancelar + Salvar. Rótulos do desenho.
- **Primário**: bloco "Primários do escritório" (titular com selo PRIMÁRIO TITULAR e cadeado; sócios com
  PRIMÁRIO SÓCIO e lixeira), "Promover a primário (sócio do escritório)" com select dos secundários ativos da
  conta + info (e-mail · OAB) + botão; cartões travados (D5).
- **Master**: abas "Painel do sistema" | "Permissões". Permissões = bloco "Masters do sistema" (D2),
  "Poderes exclusivos do Master" e "Atribuições por papel".

### 4.3 Painel do sistema (Master)

Faixa "Painel exclusivo do Master. Mostra metadados das contas…" e 9 seções:

| Seção | Conteúdo real | Etapa |
|---|---|---|
| Visão geral | 8 KPIs (escritórios ativas/suspensas, primários, secundários, OABs únicas, pastas, documentos, armazenamento usado/contratado, receita "—"); Crescimento (contas, usuários, pastas, GB por mês e ano; acumulado/novos; por mês/por ano/linha contínua; anos); "Onde estamos atuando" (Pessoas/Escritórios, Geral/Só primários, Brasil/Mundo, tabela + mapa + estados que faltam + ⋮ dados públicos IBGE/OAB); Faixa etária | E2 |
| Contas e escritórios | lista com busca e filtro de situação; menu ⋮ / botão direito | E2 (leitura) · E3 (ações) |
| Usuários e OABs | KPIs, distribuição por UF, pendências; atalho para a Revisão de OAB existente | E2 |
| Armazenamento | ranking por consumo; faixas de alerta configuráveis; "Editar limite" | E3 |
| Planos e limites | estado vazio (D1) | E2 |
| Custos, Desempenho, Configurações globais | estado vazio do desenho | E2 |
| Segurança e auditoria | trilha do `audit_log` (Tenant, User, UserTenant, TenantRole, Permission + ações do Master), filtro por ação/conta, resumo, PDF | E2 |

Crescimento de GB: data de criação dos registros com tamanho. Seta de tendência por estado: só entradas
(`User.createdAt`); saídas não são medidas (sem data de desativação): o título da seta diz isso.

## 5. Etapas

- **E1 · Perfis e nomenclatura (sem migration).** Lista e edição (§4.1, §4.2, Master sem o painel ainda),
  sócios (D4) com UseCases `PromoverPrimarioSocioUseCase` / `RemoverPrimarioSocioUseCase`, guarda no
  `edit-role` (não rebaixa o titular nem o último primário), renomear tudo para "Master" (dropdown, `/admin/platform`
  → título "Painel do Master", selo "Master"), subtítulo de papel na barra superior ("MASTER · USUÁRIO
  PRIMÁRIO"). Comando `app:master:definir <email>` que deixa exatamente um `ROLE_SUPER_ADMIN` (o dono roda em
  PROD; o Master atual perde o papel só com `--confirmar`).
- **E2 · Painel do Master, leitura (sem migration).** Domínio `app/src/Master/`: consultas globais só
  leitura (DBAL ou `AcessoGlobal` com escopo, no molde do `AcessoALixeira`), endpoints JSON sob `/master/*`
  com `#[IsGranted('ROLE_SUPER_ADMIN')]`, fontes públicas com cache e data (IBGE malhas/população; OAB lida
  da página oficial uma vez por dia, último retrato mantido se falhar; envio manual de Excel/PDF como
  alternativa), mapa com d3 local em `public/js/vendor`, `/admin/platform` redireciona para o Painel.
- **E3 · Limites e ações de conta (com migration).** Colunas novas em `tenant`: `cota_armazenamento_gb`
  (nulo = sem limite) e `limite_usuarios` (nulo = sem limite); faixas de alerta globais (70/85/95/100).
  Bloqueio no limite: novos uploads acima da cota recusados (consulta, download e exportação seguem);
  convite recusado acima do limite de usuários. Suspender/reativar (`isActive`), excluir (purga existente,
  nunca a conta do Master). Cada ação com confirmação + justificativa e registro na auditoria. Spec própria
  da E3 antes de escrever (ponto de bloqueio de upload tem de ser único).

## 6. Segurança (vale para todas as etapas)

- Toda rota `/master/*` e todo endpoint do painel: `ROLE_SUPER_ADMIN` no backend; teste funcional que prova
  403 para primário e secundário.
- O painel expõe **metadados**: contagens, tamanhos, datas, UF/cidade. Os endpoints de geo não enviam nome,
  CPF nem e-mail. A lista de contas mostra nome do escritório e do titular (o desenho pede).
- Sócios: só usuário **ativo do mesmo escritório**; titular intocável; a promoção não atravessa tenants
  (teste de isolamento por UseCase, não só HTTP).
- Linha Master e Painel invisíveis para quem não é Master (teste de HTML na lista do primário).

## 7. Pendências do dono

- Trocar o `ROLE_SUPER_ADMIN` do Samuel para o Farlei em PROD (`app:master:definir farlei.rocha@gmail.com --confirmar`).
- Smoke no navegador ao fim de cada etapa.
