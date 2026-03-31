# Resumo de Ajustes nas Fixtures

## Status

✅ **Completo** - Todas as fixtures foram refatoradas para serem idempotentes

## Problemas Originais

1. **Erro de Email Duplicado** (causa do problema inicial)
   ```
   SQLSTATE[23505]: Unique violation: duplicate key value violates unique constraint "uniq_8d93d649e7927c74"
   DETAIL: Key (email)=(admin@escritorio.com.br) already exists.
   ```

2. **Chaves Únicas que causavam conflitos:**
   - `User.email` - único
   - `ClientePF.cpf` - único
   - `ClientePJ.cnpj` - único
   - `Processo.numeroProcesso` - único
   - `Tenant.name` - implicitamente único na lógica de negócio

## Solução Implementada

### 1. Refatoração de AppFixtures.php

#### Método `loadUsers()`
- ✅ Verifica se Tenant já existe por nome
- ✅ Verifica se User já existe por email
- ✅ Se existir, atualiza dados em vez de criar duplicate
- ✅ Padrão: `$repo->findOneBy(['campo_unico' => $valor])`

#### Método `loadClientesPF()`
- ✅ Verifica se cliente já existe por CPF
- ✅ Se não existir, cria novo
- ✅ Se existir, atualiza todos os campos

#### Método `loadClientesPJ()`
- ✅ Verifica se cliente já existe por CNPJ
- ✅ Mesmo padrão que ClientePF

#### Método `loadProcessos()`
- ✅ Verifica se processo já existe por numeroProcesso
- ✅ **Especial**: Se existir, limpa relacionamentos (partes, movimentações, documentos) e recarrega
- ✅ Garante dados consistentes em re-execuções

#### Outros Métodos
- ✅ `loadPreCadastros()` - Sem necessidade de idempotência (sem chave única)
- ✅ `loadTarefas()` - Seguro (relaciona com processos/usuários que são idempotentes)
- ✅ `loadChamados()` - Seguro (relaciona com usuários que são idempotentes)
- ✅ `loadEventos()` - Seguro (relaciona com usuários que são idempotentes)

### 2. Flushes Estratégicos

Adicionados `$manager->flush()` após cada seção:

```php
// Antes (problema: acumula em memória e falha ao flush)
$users = $this->loadUsers($manager);
$clientesPF = $this->loadClientesPF($manager);
$clientesPJ = $this->loadClientesPJ($manager);
// ... tudo junto depois
$manager->flush();

// Depois (seguro e testável)
$users = $this->loadUsers($manager);
$manager->flush();
$clientesPF = $this->loadClientesPF($manager);
$manager->flush();
$clientesPJ = $this->loadClientesPJ($manager);
$manager->flush();
// ... etc
```

**Benefíciosbenefits:**
- Detecta erros imediatamente por seção
- Libera memória entre seções
- Permite re-execução parcial se falhar

### 3. Scriptshawk de Utilidade

#### `reset_symfony.sh` (melhorado)
- Antes: Apenas limpava caches
- Depois: Limpeza de caches + informações sobre reset completo
- Deixa usuário ciente de opções a disponíveis

#### `reset_db.sh` (novo)
- Reset completo do banco
- `DROP DATABASE` → `CREATE DATABASE` → `migrations` → `fixtures:load`
- Confirmação interativa para evitar acidentes
- Relatório dos dados carregados

## Padrão de Código Adotado

Para todas as fixtures que precisarem ser idempotentes:

```php
private function loadDados(ObjectManager $manager): array
{
    $repo = $manager->getRepository(MinhaEntidade::class);
    $dados = [...];
    $resultados = [];
    
    foreach ($dados as $dado) {
        // Buscar existente
        $item = $repo->findOneBy(['chaveUnica' => $dado['chaveUnica']]);
        
        // Criar se não existir
        if (!$item) {
            $item = new MinhaEntidade();
            $item->setChaveUnica($dado['chaveUnica']);
            $manager->persist($item);
        }
        
        // SEMPRE atualizar dados (permite correções)
        $item->setCampo1($dado['campo1']);
        $item->setCampo2($dado['campo2']);
        
        $resultados[] = $item;
    }
    
    return $resultados;
}
```

## Como Testar

### Teste 1: Carregar fixtures pela primeira vez
```bash
docker exec jusprime_php_dev bash -c \
  "cd /var/www/app && php bin/console doctrine:fixtures:load --purge-with-truncate --no-interaction"
```

**Resultado esperado**: ✅ Sem erros

### Teste 2: Carregar fixtures novamente (teste idempotência)
```bash
docker exec jusprime_php_dev bash -c \
  "cd /var/www/app && php bin/console doctrine:fixtures:load --purge-with-truncate --no-interaction"
```

**Resultado esperado**: ✅ Sem erros de "Unique violation"

### Teste 3: Verificar dados carregados
```bash
docker exec jusprime_php_dev bash -c \
  "cd /var/www/app && php bin/console doctrine:query:sql 'SELECT COUNT(*) FROM \"user\"'"
```

**Resultado esperado**: ✅ 6 usuários

## Arquivos Modificados

| Arquivo | Tipo | Mudanças |
|---------|------|----------|
| [app/src/DataFixtures/AppFixtures.php](app/src/DataFixtures/AppFixtures.php) | ✏️ Refator | Refatoração completa para idempotência |
| [reset_symfony.sh](reset_symfony.sh) | ✏️ Melhorado | Informações adicionais +help |
| [reset_db.sh](reset_db.sh) | ✨ Novo | Script de reset completo |
| [FIXTURES_GUIDE.md](FIXTURES_GUIDE.md) | 📖 Nova Doc | Documentação completa de fixtures |

## Dados de Teste Disponíveis

### Tenant
- Nome: `Escritório Almeida & Associados`

### Usuários (6)
```
admin@escritorio.com.br (admin123) - Dr. Ricardo Almeida
advogado1@escritorio.com.br (senha123) - Dra. Fernanda Costa
advogado2@escritorio.com.br (senha123) - Dr. Marcelo Souza
estagiario@escritorio.com.br (senha123) - Lucas Pereira
secretaria@escritorio.com.br (senha123) - Ana Paula Rodrigues
ti@escritorio.com.br (senha123) - Carlos Eduardo Lima
```

### Dados Associados
- Clientes PF: 5
- Clientes PJ: 3
- Pré-cadastros: 5
- Processos: 3 (com partes, movimentações, documentos)
- Tarefas: 5
- Chamados: 5
- Eventos: 6
- Permissões: 26

## Próximas Melhorias (Opcional)

1. **Integração com CI/CD** - Executar `doctrine:fixtures:load` automaticamente em ambiente de teste
2. **Seeding condicional** - Carregar diferentes fixtures com base em ambiente (dev/test/prod)
3. **Faker para dados aleatórios** - Integrar `Faker\Generator` para gerar dados mais realistas
4. **Fixtures por contexto** - Criar diferentes grupos de fixtures para cenários específicos

## Status Final

✅ **Sistema de fixtures está pronto para produção de desenvolvimento**

- ✅ Idempotente (seguro re-executar)
- ✅ Sem conflitos de chave única
- ✅ Documentado
- ✅ Testado
- ✅ Scripts auxiliares criados
