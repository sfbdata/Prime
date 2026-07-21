# Cobrança — Qualificação completa da pessoa cobrada + listas de endereço/telefone/e-mail

> Ponto **#1** dos ajustes pós-taxa. Risco **MÉDIO** (dado pessoal/PII, multi-tenant). Não toca dinheiro.
> Independente da feature de taxa por-obrigação (arquivos distintos). Base de implementação a confirmar
> (deve conter o módulo de cobrança já em prod: `App\Cobranca\Entity\Pessoa`).

## 1. Objetivo (na voz do dono)

Cadastrar a **qualificação completa** de cada pessoa cobrada e **não perder** dados que mudam ao longo do tempo
(endereço, telefone, e-mail) — importante pro jurídico. Cada unidade já pode ter mais de uma pessoa (o vínculo
`VinculoPessoaObjeto` já existe). O modelo é **listas**: a pessoa tem **vários** endereços/telefones/e-mails, com
**um marcado como atual** em cada lista. Botão "adicionar", preenche, salva — nada é apagado.

## 2. O que já existe

`App\Cobranca\Entity\Pessoa` (tenant-aware, auditável) com: `nome`, `cpf`, `cnpj`, `email` (único), `telefone`
(único), `observacao`, `criadoEm/atualizadoEm/criadoPor`. CRUD via `CriarPessoaUseCase`,
`AlterarPessoaCobradaUseCase` (hoje **sobrescreve** — sem histórico), `VincularPessoaAObjetoUseCase`.

## 3. Campos NOVOS de qualificação (únicos na ficha)

Adicionar em `Pessoa` (todos nullable — cadastro parcial é válido):
- `dataNascimento` — `date_immutable`.
- `estadoCivil` — enum `EstadoCivil` (**campo único**, não lista; decisão do dono): `Solteiro`, `Casado`,
  `Divorciado`, `Viuvo`, `UniaoEstavel`, `Separado`. Backed string, com `label()`.
- `profissao` — `string(120)`.
- `rg` — `string(20)`.
- `orgaoEmissorRg` — `string(20)` (ex.: "SSP/CE").

`cpf` e `nome` já existem; `cnpj` permanece (pessoa jurídica).

## 4. As três listas (histórico por adição, um "atual")

Três entidades novas, cada uma `ManyToOne Pessoa` (com `onDelete: CASCADE`), tenant **derivado da pessoa**,
auditáveis (`criadoEm`, `criadoPor`). Ordem de exibição: `criadoEm ASC` (linha do tempo). Regra transversal:
**exatamente um `atual = true` por lista por pessoa**.

- **`PessoaEndereco`** (`cobranca_pessoa_endereco`): `logradouro` (255), `numero` (20), `complemento` (120, null),
  `bairro` (120), `cidade` (120), `uf` (2), `cep` (9), `atual` (bool).
- **`PessoaTelefone`** (`cobranca_pessoa_telefone`): `numero` (20), `atual` (bool).
- **`PessoaEmail`** (`cobranca_pessoa_email`): `email` (255), `atual` (bool).

**Regra do "atual"** (garantida no UseCase, não só na UI):
- Adicionar o **primeiro** item de uma lista → nasce `atual = true`.
- Marcar outro como atual → o anterior vira `atual = false` (o antigo **permanece** na lista). Transação única.
- Nunca fica sem atual enquanto houver ≥1 item.

## 5. Migração de dados (não perder nada)

`Version<AAAAMMDDHHMMSS>` (DDL PostgreSQL; conferir colisão de número — falha silenciosa conhecida):
1. `ALTER TABLE cobranca_pessoa ADD` das colunas únicas novas (todas NULL).
2. Criar as 3 tabelas de lista (FK `pessoa_id` NOT NULL, `tenant_id`, `atual` bool default false, timestamps).
3. **Backfill:** para cada `Pessoa` com `email` não vazio → um `PessoaEmail(atual = true)`; idem `telefone` →
   `PessoaTelefone(atual = true)`. Endereço antigo **não existe** hoje (não havia campo) → nada a migrar.
4. As colunas `email`/`telefone` de `Pessoa` **permanecem por 1 release como sombra** (rollback seguro), sincronizadas
   com o item atual; remover no release seguinte (mesma cautela do `encargos_reconhecidos`).

## 6. Compatibilidade de leitura (`getEmail()`/`getTelefone()`)

Código existente lê `Pessoa::getEmail()`/`getTelefone()` (ex.: importação, listagens, vínculo). Manter a assinatura,
mas passar a **derivar do item atual** da lista (fallback para a coluna-sombra durante o release de transição).
`setEmail()`/`setTelefone()` viram ponte: criam/atualizam o item **atual** da lista respectiva (não gravam só a
coluna). Mapear todos os usos antes (grep amplo + smoke): importação, telas de pessoa, vínculo, dashboards.

## 7. UI

Na tela de cadastro/edição da pessoa (`PessoaController` + forms): seção "Qualificação" (campos únicos) + três
seções de lista (Endereços/Telefones/E-mails), cada uma com a lista atual, marcação de "atual" (radio/estrela) e um
botão **"adicionar"** que abre o mini-form do item. Preserva os padrões de form/modais do módulo.

## 8. Invariantes / segurança

- **Multi-tenant:** toda query dos itens filtra por tenant (via pessoa); IDOR guard ao adicionar/marcar (a pessoa
  tem de ser do tenant do usuário). Itens nunca cruzam tenant.
- **Nunca apagar histórico:** marcar outro como atual só troca a flag; excluir um item é ação explícita à parte
  (fora do escopo inicial — YAGNI).
- Um item `atual` por lista (invariante do UseCase).

## 9. Testes

- Unit dos UseCases: adicionar 1º item marca atual; adicionar 2º não desmarca sozinho; marcar outro troca a flag e
  preserva o antigo; guard de tenant (IDOR) ao adicionar/marcar em pessoa de outro tenant.
- Unit da migração/compat: `getEmail()` deriva do item atual; backfill move `email`/`telefone` legados.
- Functional do controller: adicionar endereço/telefone/e-mail via HTTP; marcar atual; cross-tenant retorna 403/404.
- Suíte de Cobrança verde + global verde (container `-d memory_limit=512M`).

## 10. Fora de escopo (YAGNI)

- Estado civil como histórico/lista (decisão do dono: campo único).
- Exclusão/edição in-place de itens de lista (só adicionar + marcar atual nesta entrega).
- Validação de CEP via API / autopreenchimento de endereço.
- Deduplicação avançada de pessoa (já existe `SugerirPessoasDuplicadasUseCase`; não muda).
