## Plano Mestre de Implementação - Permissões Multi-tenant (JusPrime)

Objetivo: migrar o sistema atual de perfis fixos para um modelo em que cada escritório (tenant) cria e gerencia seus próprios perfis e permissões, incluindo controle por módulo e controle granular por item (com solicitação de acesso e aprovação do admin).

Contexto do domínio:
- SaaS multi-tenant para escritórios de advocacia.
- Cada tenant é um escritório independente.
- Quem cria o escritório é admin do próprio escritório.
- Estrutura de cargos varia por tenant (ex.: secretária, estagiário, assistente administrativo, etc.).
- Módulos atuais: Pastas, Clientes, Processos, Tarefas, Agenda, Service Desk, Pré-Cadastros.
- Módulos futuros previstos: Financeiro, BI.
- Regra de negócio importante:
  - Usuário pode ter acesso ao módulo Clientes, mas não necessariamente aos itens (clientes) individuais.
  - Ao tentar acessar item sem permissão, deve abrir fluxo de solicitação.
  - Admin pode aprovar com escopo: visualizar / visualizar+editar / visualizar+editar+excluir.

Escopo da primeira onda:
- Perfis por tenant (customizáveis por nome e permissões).
- Convite de usuários com vínculo a perfil do tenant.
- Sidebar e visibilidade por permissão de módulo.
- Refatoração de autorização para sair de ROLE_ADMIN hardcoded.
- Controle granular inicial para Clientes, Pastas e Processos.
- Solicitação e aprovação de acesso por item.

Fora de escopo inicial:
- Múltiplos perfis por usuário (iniciar com 1 perfil por usuário).
- ABAC avançado (ex.: regras por departamento, horários, etc.).
- Auditoria analítica avançada de permissões.

Decisões estruturais:
1. Manter ROLE_SUPER_ADMIN como papel global de plataforma (fora da gestão do tenant).
2. Manter ROLE_USER para autenticação básica.
3. Criar autorização de negócio via entidades e serviço central (PermissionChecker).
4. Usar um perfil por usuário na primeira versão para reduzir complexidade.

Arquitetura de dados alvo:
1. Permission
- Catálogo global de permissões (ex.: modules.clientes.view, resources.cliente.edit, admin.roles.manage).

2. TenantRole
- Perfil customizado por tenant.
- Campos: tenant, name, description, isSystem, createdAt, updatedAt.

3. TenantRolePermission
- Associação N:N entre perfil e permissão.

4. User (ajuste)
- Adicionar vínculo para TenantRole (preferência: ManyToOne).
- Tratar roles antigas como legado temporário durante migração.

5. ResourceAccess
- Permissões por item de domínio (resourceType, resourceId, user, actions permitidas).

6. AccessRequest
- Solicitações de acesso pendentes/aprovadas/negadas por item.

7. (Opcional inicial) ResourceAccessPolicy
- Política padrão por tipo de recurso no tenant (OPEN, RESTRICTED, OWNER_ONLY).

Catálogo inicial de permissões (sugestão):
- modules.pastas.view
- modules.clientes.view
- modules.processos.view
- modules.tarefas.view
- modules.agenda.view
- modules.servicedesk.view
- modules.precadastros.view
- modules.financeiro.view
- modules.bi.view
- resources.pasta.view
- resources.pasta.edit
- resources.pasta.delete
- resources.cliente.view
- resources.cliente.edit
- resources.cliente.delete
- resources.processo.view
- resources.processo.edit
- resources.processo.delete
- admin.roles.manage
- admin.users.manage
- admin.users.invite
- admin.access_requests.approve
- admin.tenant.settings.manage
- admin.audit.view

Plano por etapas diárias (execução incremental):

Dia 1 - Fechamento de escopo e catálogo
- Validar lista final de permissões da primeira entrega.
- Confirmar módulos e ações por recurso.
- Registrar decisões para evitar reabertura de arquitetura.
Critério de pronto:
- Catálogo inicial aprovado.
- Escopo fechado para a primeira onda.

Dia 2 - Entidades e migrations base
- Criar Permission, TenantRole, TenantRolePermission.
- Ajustar User para vínculo com TenantRole.
- Criar migrations do schema.
Critério de pronto:
- Migrations aplicam sem erro.
- Relacionamentos consistentes por tenant.

Dia 3 - Seed técnico e bootstrap de tenant
- Popular catálogo de Permission.
- Garantir perfil "Administrador do Escritório" por tenant novo.
- Vincular criador do tenant ao perfil admin local.
Critério de pronto:
- Tenant novo nasce com admin local funcional.

Dia 4 - Camada de autorização central
- Implementar PermissionChecker (ou equivalente).
- Criar métodos para checar módulo, ação administrativa e recurso.
- Incluir fallback temporário para roles legadas durante transição.
Critério de pronto:
- Controllers já podem consumir PermissionChecker sem regressão.

Dia 5 - Segurança base (security.yaml)
- Reduzir access_control para autenticação/global.
- Tirar dependência de ROLE_ADMIN fixa para autorização fina.
Critério de pronto:
- Rotas protegidas corretamente por autenticação.
- Sem exposição indevida.

Dia 6 - Painel de perfis por tenant
- CRUD de perfis (listar/criar/editar/excluir).
- Associar permissões ao perfil.
- Regras de segurança de tenant isolation.
Critério de pronto:
- Admin local cria perfil customizado com nome livre e permissões.

Dia 7 - Convites e funcionários
- Convite passa a selecionar TenantRole.
- Edição de usuário passa a trocar perfil, não roles fixas.
- Remover dependência funcional de lista hardcoded.
Critério de pronto:
- Fluxo convite -> usuário -> perfil funcionando fim a fim.

Dia 8 - Sidebar dinâmica por permissão
- Refatorar visibilidade de menu por modules.*.
- Usuário só vê módulo permitido.
Critério de pronto:
- Perfis diferentes enxergam menus diferentes.

Dia 9 - Refatorar controllers prioritários
- Migrar checagens hardcoded de ROLE_ADMIN para PermissionChecker.
- Prioridade: Tenant/Invitation/gestão de usuários.
Critério de pronto:
- Núcleo administrativo sem dependência de roles fixas.

Dia 10 - Módulos operacionais
- Migrar ServiceDesk, Tarefas, Agenda, Pré-Cadastros e pontos de Clientes.
Critério de pronto:
- Ações críticas protegidas por permissões semânticas.

Dia 11 - ResourceAccess para Clientes/Pastas/Processos
- Implementar checagem por item (view/edit/delete).
- Integrar com fluxo de acesso de leitura e ação.
Critério de pronto:
- Usuário pode ter módulo visível e ainda ser bloqueado por item.

Dia 12 - Solicitação de acesso
- Ao negar acesso a item, criar AccessRequest e exibir modal.
- Ajustar experiência para web e endpoints async.
Critério de pronto:
- Solicitação criada com status pendente automaticamente.

Dia 13 - Aprovação pelo admin
- Painel de aprovações pendentes.
- Aprovar com granularidade de ação ou negar.
- Notificação do solicitante.
Critério de pronto:
- Fluxo completo pedido -> decisão -> acesso efetivo.

Dia 14 - Migração de dados legados
- Mapear usuários antigos para TenantRole novo.
- Preservar acesso durante transição.
- Planejar rollback seguro.
Critério de pronto:
- Base legada convertida sem perda funcional.

Dia 15 - Limpeza e hardening
- Remover fallback legado.
- Remover condicionais antigas de role fixa.
- Revisão final de segurança e consistência.
Critério de pronto:
- Sistema operando apenas no novo modelo.

Validações obrigatórias por fase:
1. Testes de isolamento entre tenants.
2. Testes de regressão de login/convite.
3. Testes de visibilidade de menu por perfil.
4. Testes de acesso por item com cenário de bloqueio.
5. Testes de aprovação e revogação de acesso.
6. Testes de perfil admin local e super admin global.

Riscos principais e mitigação:
1. Risco: quebrar autorização durante migração.
Mitigação: fallback temporário controlado e removido no final.

2. Risco: query sem filtro de tenant.
Mitigação: revisão de repositories e testes de isolamento.

3. Risco: migração de dados incompleta.
Mitigação: script idempotente + backup + validação pós-migração.

4. Risco: UI mostrar módulo sem backend autorizado.
Mitigação: checagem dupla (frontend + backend), backend é fonte final.

Protocolo para continuar em novo chat (memória zero):
1. Ler este arquivo completo antes de qualquer alteração.
2. Identificar o último dia concluído.
3. Executar somente o próximo dia.
4. Não expandir escopo fora da etapa atual.
5. Ao encerrar, gerar handoff padronizado e salvar no histórico do chat.

Prompt de início (copiar e usar em cada novo chat):
"Você é responsável por continuar a implementação de permissões multi-tenant do JusPrime.
Primeiro leia o plano mestre de implementação do projeto.
Depois:
1) Resuma o estado atual e identifique o último dia concluído.
2) Proponha somente o escopo do próximo dia.
3) Implemente apenas esse escopo.
4) Ao final, rode validações e gere handoff no formato padrão.
Restrições:
- Não alterar arquitetura sem justificativa.
- Não avançar para o próximo dia sem concluir o atual.
- Preservar isolamento de tenant e segurança por padrão."

Prompt de encerramento (obrigatório no fim de cada sessão):
"Antes de encerrar, gere o resumo de handoff exatamente neste formato:

Handoff do Dia X
1. Objetivo do dia
2. O que foi concluído
3. Arquivos alterados
4. Migrations/commands executados
5. Testes/validações executados e resultado
6. Pendências abertas
7. Riscos/decisões tomadas
8. Próximo passo exato para o próximo chat
9. Prompt recomendado para iniciar o próximo chat

Se algo não foi concluído, explique por quê e deixe um plano mínimo de retomada."

Definição de pronto global (projeto):
- Cada tenant cria perfis com nomes livres.
- Permissões de módulos e ações são configuráveis por perfil.
- Convites vinculam perfil do tenant.
- Sidebar e controllers respeitam permissões semânticas.
- Clientes/Pastas/Processos têm controle por item com solicitação.
- Admin aprova acesso com granularidade (view/edit/delete).
- Não há dependência funcional de perfis hardcoded legados.
