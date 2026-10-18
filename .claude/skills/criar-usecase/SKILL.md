---
name: criar-usecase
description: "Padrões para criar ou refatorar UseCases do jusprime: storytelling obrigatório antes de implementar, estrutura, transação, multi-tenancy. Carregue sempre que a tarefa envolver criar, editar ou pensar em um UseCase."
---

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
        $entidade = $this->carregarDoEscritorio($input);   // buscar o que precisa
        $this->exigirRegraDoNegocio($entidade, $input);     // validar / aplicar a regra
        $this->repository->salvar($entidade, flush: true);  // persistir

        return <Nome>Output::fromEntity($entidade);         // devolver o DTO de saída
    }

    // cada passo é um método privado com nome do negócio (ver "Tamanho e organização de método")
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

## Tamanho e organização de método

- **Meta: ~20 linhas por método.** O CI reprova acima de 40 (`tests/Arquitetura/MetodoLongoArquiteturaTest`,
  contado do `function` ao `}`; docblock e atributos não contam). 40 é o limite, não a meta.
- **Um método, um nível de abstração.** O método público conta a história chamando métodos privados com
  nome do negócio; o detalhe mora no privado.
- **Bloco que precisa de comentário para ser entendido vira método**, e o comentário vira o nome dele.
- **Método legado longo** (está em `tests/Arquitetura/metodos-longos-tolerados.json`): pode crescer no
  máximo 5 linhas sobre o número da lista, no total. O código novo vai para um método privado novo; não
  reescreva o legado sem liberação. Mover para o domínio é trocar a chave no JSON com o mesmo número.
- `executar()` narra os passos (carregar → validar → aplicar a regra → persistir → montar a saída), cada um num privado: `carregarPastaDoEscritorio()`, `exigirPrazoAberto()`. O closure de transação chama esses privados; não guarda a regra inteira dentro dele.

