# Como Usar as Fixtures Ajustadas

## Quick Start

### Primeira Vez: Reset Completo
```bash
# Do host (~/projetos/jusprime/)
bash reset_db.sh
```

Isto vai:
1. Descartar o banco atual
2. Criar novo banco
3. Rodar todas as migrations
4. Carregar todas as fixtures
5. Validar esquema

### Desenvolvimento: Recarregar Dados
```bash
# Dentro do container ou via Docker Compose
docker exec jusprime_php_dev bash -c \
  "cd /var/www/app && php bin/console doctrine:fixtures:load --purge-with-truncate --no-interaction"
```

Isto recarrega todas as fixtures sem perder histórico de migrações.

### Apenas Limpar Caches
```bash
bash reset_symfony.sh
```

Rápido e seguro para desenvolvimento local.

## Dados Disponíveis Após Carregamento

### Credenciais de Acesso

**Admin:**
- Email: `admin@escritorio.com.br`
- Senha: `admin123`

**Staff (todos com senha `senha123`):**
- `advogado1@escritorio.com.br` - Dra. Fernanda Costa
- `advogado2@escritorio.com.br` - Dr. Marcelo Souza
- `estagiario@escritorio.com.br` - Lucas Pereira
- `secretaria@escritorio.com.br` - Ana Paula Rodrigues
- `ti@escritorio.com.br` - Carlos Eduardo Lima

## Scenario de Uso

### Cenário 1: Você fez mudanças no banco e quer resetar

```bash
# Opção A: Reset rápido (recarrega dados, mantém migracoes)
docker exec jusprime_php_dev bash -c \
  "cd /var/www/app && php bin/console doctrine:fixtures:load --purge-with-truncate --no-interaction"

# Opção B: Reset completo (from scratch)
bash reset_db.sh
```

### Cenário 2: Você quer adicionar novo usuário à fixture

1. Abra `app/src/DataFixtures/AppFixtures.php`
2. Na seção `loadUsers()`, adicione ao array `$dados`:

```php
[
    'email' => 'novo@escritorio.com.br',
    'nome' => 'Seu Nome',
    'roles' => ['ROLE_NOVO'],
    'senha' => 'senha123',
    'ativo' => true,
    'isAdmin' => false,
],
```

3. Recarregue as fixtures:
```bash
docker exec jusprime_php_dev bash -c \
  "cd /var/www/app && php bin/console doctrine:fixtures:load --purge-with-truncate --no-interaction"
```

O novo usuário será criado e usuários existentes serão atualizados.

### Cenário 3: Erro ao carregar fixtures

**Se vir erro de "duplicação" (SQLSTATE[23505]):**

Isto significa que a versão anterior das fixtures ainda está no banco. Execute:

```bash
bash reset_db.sh
```

**Se vir erro de "Class not found":**

Verifique que todos os `use` statements estão presentes no topo de `AppFixtures.php`:

```php
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Entity\Cliente\ClientePF;
use App\Entity\Cliente\ClientePJ;
// ... etc
```

**Se vir erro de "Method not found":**

A entidade pode ter mudado. Verifique o setter correto. Exemplo:

```php
// ❌ Errado
$user->setPemissoes(...);

// ✅ Correto  
$user->setRoles(...);
```

## Idempotência Explicada

As fixtures agora são "idempotentes" - você pode executá-las múltiplas vezes sem erros.

**Como funciona:**

```php
// Procura existente
$user = $userRepo->findOneBy(['email' => 'admin@escritorio.com.br']);

if (!$user) {
    // Cria se não existir
    $user = new User();
    $user->setEmail('admin@escritorio.com.br');
    $manager->persist($user);
} else {
    // Apenas atualiza dados
    echo "User já existe, atualizando...";
}

// Sempre aplica campos
$user->setFullName('Dr. Ricardo Almeida');
$user->setRoles(['ROLE_USER']);
```

**Benefícios:**
- ✅ Rodar fixtures várias vezes sem conflitos
- ✅ Atualizar dados sem perder IDs
- ✅ Seguro para CI/CD
- ✅ Correções aplicadas automaticamente

## Estrutura de Dados

```
Tenant "Escritório Almeida & Associados"
├── Users: 6 total
│   ├── Admin (1)
│   └── Staff (5): Advogado(2), Estagiário, Secretária, TI
├── Clientes PF: 5
├── Clientes PJ: 3
├── PreCadastros: 5
├── Processos: 3
│   ├── Processo 1: TRT (Trabalhista)
│   │   ├── Partes: 2
│   │   ├── Movimentações: 4
│   │   └── Documentos: 4
│   ├── Processo 2: TJSP (Família)
│   │   ├── Partes: 2
│   │   ├── Movimentações: 4
│   │   └── Documentos: 3
│   └── Processo 3: TJRJ (Apelação)
│       ├── Partes: 2
│       ├── Movimentações: 5
│       └── Documentos: 6
├── Tarefas: 5 (vinculadas a processos e usuários)
├── Chamados: 5 (vinculados a usuários)
└── Eventos: 6 (vinculados a usuários)
```

## Performance

| Ação | Tempo | Notas |
|------|-------|-------|
| `reset_symfony.sh` | ~5s | Apenas caches |
| `fixtures:load --append` | ~15s | Re-execução (idempotente) |
| `reset_db.sh` | ~60s | Reset complete (drop + create + migrations + fixtures) |

## Troubleshooting Avançado

### Verificar dados carregados

```bash
# Total de usuários
docker exec jusprime_php_dev bash -c \
  "cd /var/www/app && php bin/console doctrine:query:sql 'SELECT COUNT(*) FROM \"user\"'"

# Total de clientes
docker exec jusprime_php_dev bash -c \
  "cd /var/www/app && php bin/console doctrine:query:sql 'SELECT COUNT(*) FROM cliente'"

# Total de processos
docker exec jusprime_php_dev bash -c \
  "cd /var/www/app && php bin/console doctrine:query:sql 'SELECT COUNT(*) FROM processo'"
```

### Listar fixtures disponíveis

```bash
docker exec jusprime_php_dev bash -c \
  "cd /var/www/app && php bin/console doctrine:fixtures:load --help" | grep -A 5 "group"
```

### Carregar apenas uma fixture

```bash
# Apenas permissões
docker exec jusprime_php_dev bash -c \
  "cd /var/www/app && php bin/console doctrine:fixtures:load --group=permission --no-interaction"

# Apenas dados da aplicação
docker exec jusprime_php_dev bash -c \
  "cd /var/www/app && php bin/console doctrine:fixtures:load --group=app --no-interaction"
```

## Referências

- **Documentação Symfony Fixtures**: https://symfony.com/doc/current/bundles/DoctrineFixturesBundle/
- **Doctrine ORM**: https://www.doctrine-project.org/
- **Fixture ajustada**: [AppFixtures.php](app/src/DataFixtures/AppFixtures.php)
- **Guia completo**: [FIXTURES_GUIDE.md](FIXTURES_GUIDE.md)
- **Resumo técnico**: [FIXTURES_ADJUSTMENTS.md](FIXTURES_ADJUSTMENTS.md)

## Suporte Rápido

| Problema | Solução |
|----------|---------|
| Erro "unique constraint" | `bash reset_db.sh` |
| Dados não aparecem | `docker exec jusprime_php_dev bash -c "cd /var/www/app && php bin/console doctrine:fixtures:load --purge-with-truncate --no-interaction"` |
| Novo usuário não salvo | Verifique imports no topo do `AppFixtures.php` |
| Muito lento | Considere usar `--append` em vez de `--purge-with-truncate` |
| Precisa de reset menor | Use `bash reset_symfony.sh` |
