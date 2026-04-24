# UseCase/ — Regras da Camada de Aplicação

## Antes de implementar — storytelling obrigatório

UseCase sem storytelling vira código que resolve o problema técnico errado. Antes de escrever qualquer código, responda:

1. **Quem** dispara essa ação? (usuário comum, admin, processo automático?)
2. **O que** esse ator quer alcançar? (objetivo final, não só a ação imediata)
3. **Pré-condições** — o que precisa ser verdade antes da ação?
4. **Fluxo principal** — o que o sistema faz passo a passo no caminho feliz?
5. **Fluxos alternativos** — o que pode dar errado? Quais os casos de erro?
6. **Pós-condições** — o que muda no sistema após a ação?
7. **Regras de negócio** — há alguma restrição não óbvia envolvida?

A User Story responde o "quem" e o "por quê" (valor). O UseCase responde o "como" (comportamento do sistema). Só implemente depois de ter ambos claros.

> Referência canônica para todos os domínios.

## Responsabilidade

UseCase = orquestração de um único caso de uso de negócio. Uma classe, uma ação.

**Permitido:**
- Chamar repositories para buscar/persistir entidades
- Chamar services de infraestrutura (storage, email, etc.)
- Disparar eventos de domínio
- Aplicar regras de negócio que envolvem múltiplos objetos
- Validar regras que dependem de estado persistido (ex.: "CPF já existe?")

**Proibido:**
- Lógica HTTP (Request, Response, Session, Cookie)
- Renderizar templates Twig
- Regras puras de domínio que pertencem à entidade (mover para a entity)
- Fazer `flush()` múltiplas vezes — uma transação por use case

## Estrutura obrigatória

```php
<?php
declare(strict_types=1);
namespace App\<Dominio>\UseCase;

use App\<Dominio>\DTO\<Nome>Input;
use App\<Dominio>\DTO\<Nome>Output;
use App\<Dominio>\Entity\<Nome>;
use App\<Dominio>\Repository\<Nome>Repository;

final class <Acao><Nome>UseCase
{
    public function __construct(
        private readonly <Nome>Repository $repository,
    ) {
    }

    public function executar(<Nome>Input $input): <Nome>Output
    {
        // 1. buscar entidades necessárias
        // 2. aplicar regras de negócio / chamar métodos da entidade
        // 3. persistir
        // 4. retornar DTO de saída
    }
}
```

## Naming

- Padrão: `<Verbo><Entidade>UseCase`
- Exemplos: `CriarClienteUseCase`, `AtualizarStatusUseCase`, `ArquivarProcessoUseCase`
- Um único método público: `executar()`
- Sempre `final`

## Transação

O UseCase é responsável pelo `flush()` — o controller nunca chama flush diretamente.

**Opção 1 — via repository (preferida para operações simples):**
```php
$this->repository->salvar($entidade, flush: true);
```

**Opção 2 — via EntityManager injetado no construtor (quando há múltiplas entidades ou transação explícita):**
```php
public function __construct(
    private readonly <Nome>Repository $repository,
    private readonly EntityManagerInterface $entityManager,
) {}

// no método executar():
$this->repository->salvar($entidade);
$this->entityManager->flush();
```

## Multi-tenancy

Sempre validar que o recurso pertence ao tenant do usuário:
```php
if ($entidade->getTenant() !== $usuarioAtual->getTenant()) {
    throw new AccessDeniedException('Recurso não pertence ao tenant.');
}
```

## Retorno

- UseCases de comando (criar/atualizar/deletar): retornam DTO de saída ou `void`
- UseCases de query (buscar): retornam DTO de saída ou coleção de DTOs
- **Nunca retornar entidade Doctrine diretamente para o controller**
