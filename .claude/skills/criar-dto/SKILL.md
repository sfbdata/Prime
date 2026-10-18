---
name: criar-dto
description: "Padrões para criar ou refatorar DTOs do jusprime: Input vs Output, validação com #[Assert], fromEntity(), exceção readonly+Symfony Form. Carregue ao criar, editar ou revisar arquivos *Input.php ou *Output.php em app/src/<Dominio>/DTO/."
---

# DTO/ — Regras de Data Transfer Objects

## Responsabilidade

DTO = objeto de transferência de dados entre camadas. Sem comportamento de domínio.

**Dois tipos:**
- **Input DTO** — dados vindos do form/request para o UseCase
- **Output DTO** — dados do UseCase para a view/response

## Fluxo obrigatório

```
Form (validação) → Input DTO → UseCase → Entity (persistência)
                                       → Output DTO → View/Response
```

**Nunca:**
- Passar entidade Doctrine para a view (`return $this->render('...', ['cliente' => $entidade])`)
- Passar entidade Doctrine para o form diretamente sem DTO intermediário
- Colocar lógica de negócio no DTO

## Estrutura de Input DTO

```php
<?php
declare(strict_types=1);
namespace App\<Dominio>\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class Criar<Nome>Input
{
    public function __construct(
        #[Assert\NotBlank(message: 'Nome é obrigatório.')]
        #[Assert\Length(max: 255)]
        public readonly string $nome,

        #[Assert\Email(message: 'E-mail inválido.')]
        public readonly string $email,
    ) {
    }
}
```

## Estrutura de Output DTO

```php
<?php
declare(strict_types=1);
namespace App\<Dominio>\DTO;

final class <Nome>Output
{
    public function __construct(
        public readonly string $id,
        public readonly string $nome,
        public readonly string $status,
        public readonly \DateTimeImmutable $criadoEm,
    ) {
    }

    public static function fromEntity(<NomeEntity> $entidade): self
    {
        return new self(
            id: (string) $entidade->getId(),
            nome: $entidade->getNome(),
            status: $entidade->getStatus()->value,
            criadoEm: $entidade->getCriadoEm(),
        );
    }
}
```

## Regras

- Sempre `final`
- Propriedades `readonly` (PHP 8.1+) — DTO é imutável após criação
- Constructor property promotion
- Validação via `#[Assert\...]` apenas no **Input DTO**, nunca na entity
- Named `fromEntity()` estático para conversão — mantém o DTO desacoplado da entity
- Nomes descritivos: `CriarClienteInput`, `AtualizarEnderecoInput`, `ClienteListaItem`

## Exceção — Input DTO com Symfony Form

Quando o Input DTO é usado com `createForm()`, as propriedades devem ser `public` e **não** `readonly` — o Form Component precisa escrever nos campos via data mapper.

Use `readonly` apenas em:
- Input DTOs usados com `#[MapRequestPayload]` (APIs JSON)
- Todos os Output DTOs (sempre readonly)

```php
// Input DTO para Symfony Form — sem readonly:
final class CriarClienteInput
{
    public string $nome = '';
    public string $email = '';
}

// Input DTO para API JSON com #[MapRequestPayload] — com readonly:
final class CriarClienteInput
{
    public function __construct(
        public readonly string $nome,
        public readonly string $email,
    ) {}
}
```

## Validação no Input DTO

A validação `#[Assert\...]` no DTO é chamada automaticamente pelo Symfony Form ou `#[MapRequestPayload]`. O UseCase recebe dados **já validados** — não revalida o que o DTO já garantiu.

Validações de negócio que dependem de estado persistido (ex.: "CPF já existe") ficam no UseCase, não no DTO.

## Tamanho e organização de método

- **Meta: ~20 linhas por método.** O CI reprova acima de 40 (`tests/Arquitetura/MetodoLongoArquiteturaTest`,
  contado do `function` ao `}`; docblock e atributos não contam). 40 é o limite, não a meta.
- **Um método, um nível de abstração.** O método público conta a história chamando métodos privados com
  nome do negócio; o detalhe mora no privado.
- **Bloco que precisa de comentário para ser entendido vira método**, e o comentário vira o nome dele.
- **Método legado longo** (está em `tests/Arquitetura/metodos-longos-tolerados.json`): pode crescer no
  máximo 5 linhas sobre o número da lista, no total. O código novo vai para um método privado novo; não
  reescreva o legado sem liberação. Mover para o domínio é trocar a chave no JSON com o mesmo número.
- `fromEntity()` grande se divide por grupo de campos em privados estáticos (`camposDoCliente($c)`) ou em DTOs aninhados, em vez de um array de 40 linhas.

