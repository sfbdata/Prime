# templates/ — Regras de Templates Twig

## Estrutura

```
templates/
├── <modulo>/
│   ├── index.html.twig
│   ├── show.html.twig
│   ├── create.html.twig
│   └── _partials/
│       ├── _form.html.twig
│       └── _card.html.twig
└── base.html.twig
```

## Naming

- Arquivos: `snake_case.html.twig`
- Partials (incluídos em outros templates): prefixo `_` (ex.: `_form.html.twig`, `_card_cliente.html.twig`)
- Pastas: `snake_case` correspondendo ao módulo (ex.: `templates/cliente/`, `templates/expediente/`)

## Regras obrigatórias

**Segurança XSS:**
- Autoescape HTML ativo por padrão — nunca desativar
- `|raw` apenas em conteúdo comprovadamente seguro (HTML sanitizado internamente)
- Para dados em atributos HTML: `|e('html_attr')`
- Para dados em JavaScript: `json_encode` em data-attributes, nunca `|raw`
- Para URLs: `|e('url')`

```twig
{# Certo: #}
<div data-config="{{ configuracao|json_encode|e('html_attr') }}">

{# Errado: #}
<script>var config = {{ configuracao|raw }}</script>
```

**Internacionalização:**
- Todo texto visível via `|trans`: `{{ 'cliente.nome'|trans }}`
- Nunca texto hardcoded em português diretamente no template

**Permissões:**
```twig
{% if can_access_module('clientes') %}
    <a href="{{ path('app_cliente_listar') }}">Clientes</a>
{% endif %}
```

**Formulários:**
- Botões de submit ficam no template, não na classe Form
- Sempre incluir token CSRF em forms manuais: `{{ csrf_token('acao') }}`

## O que NÃO vai no template

- Lógica de negócio (cálculos, decisões complexas)
- Queries ao banco (nunca `app.user.tenant.clientes` em cadeia longa)
- O template recebe **Output DTOs** ou arrays simples — nunca entidades Doctrine brutas

## Passagem de dados

```php
// Controller — correto: passar DTO
return $this->render('cliente/show.html.twig', [
    'cliente' => ClienteOutput::fromEntity($entidade),
]);

// Errado: passar entidade Doctrine diretamente
return $this->render('cliente/show.html.twig', [
    'cliente' => $clienteEntity, // expõe estrutura interna, dificulta refactor
]);
```
