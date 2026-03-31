# Guia de Fixtures Idempotentes

## Análise do Sistema

Após análise completa do sistema JusPrime, foi identificado que as fixtures originais causavam conflitos de **chave única** quando executadas múltiplas vezes:

### Problemas Identificados

1. **Email duplicado** - User.email é único
   - ❌ Erro: `SQLSTATE[23505]: Unique violation... already exists`
   - Ocorria em: `admin@escritorio.com.br`

2. **CPF duplicado** - ClientePF.cpf é único  
   - ❌ Mesmo erro ao carregar fixtures novamente

3. **CNPJ duplicado** - ClientePJ.cnpj é único
   - ❌ Mesmo erro para clientes PJ

4. **Número de Processo duplicado** - Processo.numeroProcesso é único
   - ❌ Mesmo erro para processos

### Solução Implementada

As fixtures foram **refatoradas para serem idempotentes** (seguro re-executar). Padrão adotado:

- **Verificação antes de criar**: Usar `$repo->findOneBy()` para procurar registros existentes
- **Atualização de dados**: Se existir, atualiza os dados (permite correções e melhorias)
- **Limpeza de relacionamentos**: Para processos, limpa partes/movimentações/documentos antes de recarregar
- **Flushes estratégicos**: Cada seção agora tem `flush()` após carregamento

## Como Usar

### Opção 1: Apenas recarregar fixtures (recomendado)

```bash
# Dentro do container
docker exec -it jusprime_php_dev bash -c "cd /var/www/app && php bin/console doctrine:fixtures:load --purge --no-interaction"
```

**Resultado**: Todas as fixtures são recarregadas, duplicatas são evitadas.

### Opção 2: Reset completo do banco

```bash
# Do host
bash reset_db.sh
```

**Resultado**: 
- Bean é dropado e recriado
- Todas as migrations rodam
- Todas as fixtures são carregadas de novo

### Opção 3: Apenas limpar caches

```bash
bash reset_symfony.sh
```

**Resultado**: Caches são limpos, nenhuma alteração no banco.

## Dados de Fixture

Após executar `doctrine:fixtures:load`, o banco terá:

### Tenant
- **Nome**: `Escritório Almeida & Associados`
- **Admin**: `admin@escritorio.com.br` / senha: `admin123`

### Usuários (6 total)
| Email | Nome | Role | Senha |
|-------|------|------|-------|
| admin@escritorio.com.br | Dr. Ricardo Almeida | ADMIN | admin123 |
| advogado1@escritorio.com.br | Dra. Fernanda Costa | ADVOGADO | senha123 |
| advogado2@escritorio.com.br | Dr. Marcelo Souza | ADVOGADO | senha123 |
| estagiario@escritorio.com.br | Lucas Pereira | ESTAGIARIO | senha123 |
| secretaria@escritorio.com.br | Ana Paula Rodrigues | SECRETARIA | senha123 |
| ti@escritorio.com.br | Carlos Eduardo Lima | TI | senha123 |

### Clientes PF (5)
- João Carlos Ferreira (CPF: 123.456.789-01)
- Maria Aparecida Silva (CPF: 234.567.890-12)
- Roberto Nascimento Santos (CPF: 345.678.901-23)
- Patrícia Oliveira Mendes (CPF: 456.789.012-34)
- Antônio Carlos Gomes (CPF: 567.890.123-45)

### Clientes PJ (3)
- Construtora Horizonte Ltda. (CNPJ: 12345678000190)
- Supermercados Família S.A. (CNPJ: 23456789000101)
- TechSoft Sistemas de Informação (CNPJ: 34567890000112)

### Pré-Cadastros (5)
- Associados a clientes conforme relacionamento

### Processos (3)
- `0001234-56.2024.5.02.0001` (TRT - Trabalhista)
- `1234567-89.2023.8.26.0100` (TJSP - Divórcio)
- `9876543-21.2022.8.19.0001` (TJRJ - Apelação)

Cada processo inclui:
- 2 partes
- 4-5 movimentações  
- 3-6 documentos

### Tarefas (5)
- Vinculadas a processos e usuários

### Chamados ServiceDesk (5)
- Atribuídos a usuários

### Eventos Agenda (6)
- Com participantes variados

## Padrão de Código (Para Novas Fixtures)

Se precisar estender as fixtures, siga este padrão:

```php
private function loadMeusDados(ObjectManager $manager): array
{
    $dados = [
        // ... dados aqui
    ];
    
    // Obter repositório
    $meuRepo = $manager->getRepository(MinhaEntidade::class);
    $resultados = [];
    
    foreach ($dados as $dado) {
        // IDEMPOTENT: Procura por chave única
        $item = $meuRepo->findOneBy(['campoUnico' => $dado['campoUnico']]);
        
        if (!$item) {
            $item = new MinhaEntidade();
            $item->setId($dado['campoUnico']);  // Sempre set o campo único
            $manager->persist($item);
        }
        
        // Atualizar todos os dados
        $item->setName($dado['nome']);
        // ... outros setters ...
        
        $resultados[] = $item;
    }
    
    return $resultados;
}
```

## Troubleshooting

### Erro: SQLSTATE[23505] Unique violation

**Causa**: Chave única violada (provavelmente fixture antiga não idempotente)

**Solução**:
```bash
bash reset_db.sh
```

### Erro: Doctrine migrations not found

**Causa**: Migrations não foram executadas

**Solução**:
```bash
docker exec -it jusprime_php_dev bash -c "cd /var/www/app && php bin/console doctrine:migrations:migrate"
```

### Erro: Class not found in fixture

**Causa**: Import faltando no arquivo de fixture

**Solução**: Adicione `use` statement no topo do arquivo

```php
use App\Entity\MinhaEntidade;
```

## Performance

- ✅ Fixtures idempotentes executam rapidamente (apenas atualizações, sem violação de chave)
- ✅ Não necessário dropar/recriar banco na maioria dos casos
- ✅ Seguro para desenvolvimento contínuo
- ✅ Documenta os dados de teste do sistema

## Referências

- [Symfony Fixtures Documentation](https://symfony.com/doc/current/bundles/DoctrineFixturesBundle/index.html)
- [Doctrine UPSERT Pattern](https://www.doctrine-project.org/projects/doctrine-orm/en/2.11/reference/query-builder.html)
- Arquivo modificado: [AppFixtures.php](../app/src/DataFixtures/AppFixtures.php)
