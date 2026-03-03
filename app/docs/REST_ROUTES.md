# Arquitetura de Rotas REST - JusPrime

## Visão Geral

Este documento descreve a estrutura de rotas REST do sistema JusPrime, seguindo as boas práticas de design de APIs RESTful.

## Entidades e Relacionamentos

```
Cliente (opcional) → Contrato (opcional) → Processo (opcional) → Tarefa
```

**Regras de negócio:**
- Contratos podem existir sem clientes associados
- Processos podem existir sem contratos associados  
- Tarefas podem existir sem processos associados (mas sempre têm criador e responsáveis)

---

## Estrutura de Rotas

### Clientes (`/clientes`)

| Método | Rota | Ação | Nome da Rota |
|--------|------|------|--------------|
| GET | `/clientes` | Lista todos os clientes | `cliente_index` |
| GET | `/clientes/novo-pf` | Formulário de criação PF | `cliente_new_pf` |
| POST | `/clientes/novo-pf` | Cria cliente PF | `cliente_new_pf` |
| GET | `/clientes/novo-pj` | Formulário de criação PJ | `cliente_new_pj` |
| POST | `/clientes/novo-pj` | Cria cliente PJ | `cliente_new_pj` |
| GET | `/clientes/from-pre-cadastro/{id}` | Cria a partir de pré-cadastro | `cliente_from_pre_cadastro` |
| GET | `/clientes/{id}` | Exibe detalhes do cliente | `cliente_show` |
| GET | `/clientes/{id}/editar` | Formulário de edição | `cliente_edit` |
| POST | `/clientes/{id}/editar` | Atualiza cliente | `cliente_edit` |
| POST | `/clientes/{id}/deletar` | Remove cliente | `cliente_delete` |

### Contratos (`/contratos`)

| Método | Rota | Ação | Nome da Rota |
|--------|------|------|--------------|
| GET | `/contratos` | Lista todos os contratos | `contrato_index` |
| GET | `/contratos/{id}` | Exibe detalhes do contrato | `contrato_show` |
| POST | `/contratos/{id}/associar-cliente` | Associa cliente | `contrato_associar_cliente` |
| POST | `/contratos/{id}/desassociar-cliente/{clienteId}` | Desassocia cliente | `contrato_desassociar_cliente` |
| POST | `/contratos/{id}/status` | Altera status | `contrato_toggle_status` |

### Processos (`/processos`)

| Método | Rota | Ação | Nome da Rota |
|--------|------|------|--------------|
| GET | `/processos` | Lista todos os processos | `processo_index` |
| GET | `/processos/novo` | Formulário de criação | `processo_new` |
| POST | `/processos/novo` | Cria novo processo | `processo_new` |
| GET | `/processos/{id}` | Exibe detalhes do processo | `processo_show` |
| GET | `/processos/{id}/editar` | Formulário de edição | `processo_edit` |
| POST | `/processos/{id}/editar` | Atualiza processo | `processo_edit` |
| POST | `/processos/{id}/deletar` | Remove processo | `processo_delete` |
| POST | `/processos/{id}/documentos/upload` | Upload de documento | `processo_documento_upload` |
| POST | `/processos/{id}/documentos/{documentoId}/excluir` | Remove documento | `processo_documento_delete` |
| POST | `/processos/api/search` | Busca no CNJ/DataJud | `api_datajud_search` |

### Tarefas (`/tarefas`)

| Método | Rota | Ação | Nome da Rota |
|--------|------|------|--------------|
| GET | `/tarefas/admin` | Lista todas (admin) | `tarefa_admin_index` |
| GET | `/tarefas/nova` | Formulário de criação | `tarefa_new` |
| POST | `/tarefas/nova` | Cria nova tarefa (status: PENDENTE) | `tarefa_new` |
| GET | `/tarefas/minhas` | Tarefas do usuário | `tarefa_minhas` |
| GET | `/tarefas/{id}` | Exibe detalhes | `tarefa_show` |
| POST | `/tarefas/{id}/mensagem` | Envia mensagem no chat | `tarefa_mensagem` |
| POST | `/tarefas/{id}/enviar-revisao` | Funcionário → Revisão | `tarefa_enviar_revisao` |
| POST | `/tarefas/{id}/enviar-pendencia` | Admin devolve pendência | `tarefa_enviar_pendencia` |
| POST | `/tarefas/{id}/encerrar` | Admin encerra tarefa | `tarefa_encerrar` |
| POST | `/tarefas/{id}/reabrir` | Admin reabre tarefa | `tarefa_reabrir` |

---

## Fluxo de Status das Tarefas

### Diagrama de Estados

```
┌─────────────┐
│   CRIAR     │ Admin cria tarefa
└──────┬──────┘
       ↓
┌─────────────┐
│  PENDENTE   │ Funcionário trabalha na tarefa
└──────┬──────┘
       │ Funcionário clica "Enviar para Revisão"
       ↓
┌─────────────┐
│ EM_REVISAO  │ Admin revisa a tarefa
└──────┬──────┘
       ├─── Admin clica "Enviar Pendência" → volta para PENDENTE
       │
       └─── Admin clica "Encerrar Tarefa" ↓
┌─────────────┐
│  CONCLUIDA  │ Tarefa bloqueada (somente leitura)
└──────┬──────┘
       │ Admin clica "Reabrir Tarefa"
       ↓
┌─────────────┐
│  PENDENTE   │ Volta ao fluxo normal
└─────────────┘
```

### Checklist de Transições de Status

| Status Atual | Ação | Quem Executa | Novo Status | Rota |
|--------------|------|--------------|-------------|------|
| PENDENTE | Enviar para Revisão | Funcionário | EM_REVISAO | `/tarefas/{id}/enviar-revisao` |
| EM_REVISAO | Enviar Pendência | Admin | PENDENTE | `/tarefas/{id}/enviar-pendencia` |
| EM_REVISAO | Encerrar Tarefa | Admin | CONCLUIDA | `/tarefas/{id}/encerrar` |
| CONCLUIDA | Reabrir Tarefa | Admin | PENDENTE | `/tarefas/{id}/reabrir` |

### Botões Contextuais por Status

| Status | Visível para Admin | Visível para Funcionário |
|--------|-------------------|-------------------------|
| PENDENTE | "Aguardando funcionário" | **"Enviar para Revisão"** |
| EM_REVISAO | **"Enviar Pendência"**, **"Encerrar Tarefa"** | "Aguardando revisão" |
| CONCLUIDA | **"Reabrir Tarefa"** | - (bloqueado) |

### Regras de Negócio

1. **Seleção manual de status foi REMOVIDA** - as transições ocorrem apenas via botões contextuais
2. **Tarefa concluída está bloqueada** - não permite edição, mensagens ou uploads
3. **Chat bloqueado após conclusão** - preserva o histórico sem alterações
4. **Instruções são adicionadas à descrição** - ao enviar pendência ou reabrir
5. **Todas as mudanças são AUDITADAS** - automaticamente pelo AuditLogSubscriber

---

## Checklist: Quando usar Rotas Planas vs Aninhadas

### ✅ Use Rotas PLANAS quando:

1. **Acessar um recurso individual diretamente**
   - `/processos/{id}` em vez de `/clientes/{clienteId}/contratos/{contratoId}/processos/{id}`
   - Simplifica a URL e não exige conhecer toda a hierarquia

2. **O recurso pode existir independentemente**
   - Processos sem contrato, tarefas sem processo
   - A relação é validada no banco de dados, não na URL

3. **Operações CRUD básicas**
   - Criar, ler, atualizar, deletar um recurso específico

4. **Evitar URLs com mais de 2 níveis de aninhamento**
   - `/a/{id}/b/{id}/c/{id}` é difícil de manter

### ✅ Use Rotas ANINHADAS quando:

1. **Listar recursos dentro de um contexto específico**
   - `/clientes/{clienteId}/contratos` → contratos deste cliente
   - `/processos/{processoId}/tarefas` → tarefas deste processo

2. **Criar recurso associado a um pai**
   - `POST /contratos/{contratoId}/processos` → criar processo para este contrato

3. **A operação só faz sentido no contexto do pai**
   - `/processos/{id}/documentos/upload` → upload para este processo específico

4. **Limitar escopo de listagem por performance/segurança**
   - Listar apenas histórico de um cliente específico

---

## Sugestões de Melhorias Profissionais

### 1. UUIDs em vez de IDs sequenciais

```php
// Entidade
#[ORM\Id]
#[ORM\Column(type: 'uuid', unique: true)]
private Uuid $id;

// Vantagens:
// - Não expõe quantidade de registros
// - Evita ataques de enumeração
// - Facilita sincronização entre sistemas
```

### 2. Versionamento de API

```php
#[Route('/api/v1/processos')]
class ProcessoApiV1Controller extends AbstractController { }

#[Route('/api/v2/processos')]
class ProcessoApiV2Controller extends AbstractController { }
```

### 3. Autorização por Voter

```php
// src/Security/Voter/ProcessoVoter.php
class ProcessoVoter extends Voter
{
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        $processo = $subject;
        
        return match ($attribute) {
            'VIEW' => $this->canView($processo, $user),
            'EDIT' => $this->canEdit($processo, $user),
            'DELETE' => $this->canDelete($processo, $user),
            default => false,
        };
    }
}

// No controller
$this->denyAccessUnlessGranted('EDIT', $processo);
```

### 4. Auditoria de Alterações

```php
// Já implementado via AuditLogController
// Recomendações adicionais:
// - Registrar IP do usuário
// - Registrar user-agent
// - Implementar retenção de logs (LGPD)
```

### 5. Rate Limiting para APIs

```yaml
# config/packages/rate_limiter.yaml
framework:
    rate_limiter:
        api_search:
            policy: 'sliding_window'
            limit: 100
            interval: '1 hour'
```

### 6. Paginação Padronizada

```php
// Retorno padrão de coleções
return $this->json([
    'data' => $processos,
    'meta' => [
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => ceil($total / $perPage),
    ],
    'links' => [
        'self' => '/processos?page=' . $page,
        'next' => $page < $totalPages ? '/processos?page=' . ($page + 1) : null,
        'prev' => $page > 1 ? '/processos?page=' . ($page - 1) : null,
    ],
]);
```

### 7. Filtros via Query Parameters

```
GET /processos?status=EM_ANDAMENTO&tribunal=TJSP&page=1&limit=20
GET /tarefas?assignee=current_user&status=PENDENTE
```

### 8. HATEOAS (Hypermedia)

```php
return $this->json([
    'id' => $processo->getId(),
    'numeroProcesso' => $processo->getNumeroProcesso(),
    '_links' => [
        'self' => ['href' => '/processos/' . $processo->getId()],
        'contrato' => ['href' => '/contratos/' . $processo->getContrato()?->getId()],
        'tarefas' => ['href' => '/processos/' . $processo->getId() . '/tarefas'],
        'documentos' => ['href' => '/processos/' . $processo->getId() . '/documentos'],
    ],
]);
```

---

## Rotas Aninhadas Opcionais (para implementar)

Se necessário listar recursos por contexto, adicionar:

```php
// Em ClienteController
#[Route('/{clienteId}/contratos', name: 'cliente_contratos', methods: ['GET'])]
public function contratos(int $clienteId, ContratoRepository $repo): Response
{
    $contratos = $repo->findByClienteId($clienteId);
    return $this->render('contrato/index.html.twig', ['contratos' => $contratos]);
}

// Em ContratoController
#[Route('/{contratoId}/processos', name: 'contrato_processos', methods: ['GET'])]
public function processos(int $contratoId, ProcessoRepository $repo): Response
{
    $processos = $repo->findByContratoId($contratoId);
    return $this->render('processo/index.html.twig', ['processos' => $processos]);
}

// Em ProcessoController  
#[Route('/{processoId}/tarefas', name: 'processo_tarefas', methods: ['GET'])]
public function tarefas(int $processoId, TarefaRepository $repo): Response
{
    $tarefas = $repo->findByProcessoId($processoId);
    return $this->render('tarefa/index.html.twig', ['tarefas' => $tarefas]);
}
```

---

## Validação de Relacionamentos

As relações entre entidades são validadas no banco de dados e na lógica de negócio, **não na URL**:

```php
// Exemplo: Associar processo a contrato
public function new(Request $request, ContratoRepository $contratoRepo): Response
{
    $contratoId = $request->request->get('contrato_id');
    
    if ($contratoId) {
        $contrato = $contratoRepo->find($contratoId);
        if (!$contrato) {
            throw $this->createNotFoundException('Contrato não encontrado');
        }
        // Validar se usuário tem acesso ao contrato (tenant)
        $this->denyAccessUnlessGranted('VIEW', $contrato);
        $processo->setContrato($contrato);
    }
    
    // Processo pode ser criado sem contrato
    $em->persist($processo);
    $em->flush();
}
```

---

## Resumo das Mudanças

| Antes | Depois |
|-------|--------|
| `/cliente` | `/clientes` |
| `/contrato` | `/contratos` |
| `/cliente/{clienteId}/processo` | `/processos` |
| `/tarefa` | `/tarefas` |

Todas as rotas agora seguem o padrão REST com:
- Plural para coleções
- Rotas planas para acesso a recursos individuais
- Documentação inline nos controllers
