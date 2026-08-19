# SPEC — Pasta dentro de pasta no gerenciador de arquivos da pasta

**Risco: MÉDIO.** Não toca ponto eletrônico nem identidade User/Tenant, mas altera o modelo de uma
tabela com **623 linhas e 21.197 documentos em produção**, e muda o significado de uma coluna
(`ordem`). Exige spec (este arquivo) e `/review` antes de integrar.

**Necessidade que originou a frente (dono, 2026-08-19):** *"o pessoal aqui está precisando organizar
os arquivos e precisa colocar pasta dentro de pasta no sistema"*. É demanda de uso corrente, não
melhoria especulativa.

**Frente:** `pasta-subpastas-aninhadas` (worktree própria). ⚠️ Toca `app/templates/pasta/show.html.twig`
e `app/public/js/pasta-arquivos.js` — **os dois são compartilhados com a frente `expediente-ux`**, que
está ativa e mexe em `app/templates/pasta/`. Ver §10.

---

## 1. O problema, em uma frase

O gerenciador de arquivos da pasta tem **exatamente um nível** de subpasta, e o pessoal já está
contornando isso à mão — numerando os nomes (`01 - `, `01.4 - `, `01.5 `) para simular hierarquia.

---

## 2. O que foi MEDIDO (PROD, 2026-08-19, via MCP `jusprime-prod`)

| medição | resultado |
|---|---:|
| pastas (`pasta`) | 1.070 |
| seções (`pasta_secao`) | 623 |
| pastas que têm ao menos uma seção | 189 |
| documentos (`pasta_documento`) | 21.197 |
| documentos na raiz da pasta (`secao_id IS NULL`) | 12.587 |
| documentos dentro de uma seção | 8.610 |
| documentos **com** `drive_file_id` | **21.197 (100%)** |
| seções cujo nome começa com prefixo numérico (`01 - `, `3 - `, `01.4 `) | **318 (51%)** |
| máximo de seções numa única pasta | **62** |
| média de seções por pasta que tem seções | 3,3 |
| p95 de seções por pasta | 8,6 |
| maior nome de seção (coluna comporta 255) | 114 chars |
| seções em `cobranca_secao` | **0** |

Duas leituras importam:

1. **A hierarquia já existe, improvisada no nome.** 51% das seções têm prefixo numérico manual, e há
   uma seção com **849 arquivos** (`01 - FINANCEIRO - CONTROLE E RECIBO DAS DEPESAS`) — o sintoma
   clássico do achatamento do import.
2. **62 nós no pior caso.** Isso elimina qualquer razão para modelar a árvore com caminho
   materializado ou nested sets: otimizar leitura de subárvore aqui é resolver problema inexistente.

O ritmo de arquivos novos em uso normal (fora das duas cargas de acervo de maio e julho, ~10 mil
cada) é de **~200/mês** (junho: 122; agosto até o dia 19: 241). É o que dimensiona o passivo da §9.

---

## 3. Decisões do dono (2026-08-19)

| # | Decisão | Consequência |
|---|---|---|
| D1 | A árvore espelha o Drive **nos dois sentidos** | Vale como destino; **não** é a Entrega 1 (ver D5) |
| D2 | O passivo de 8.610 arquivos achatados **é realinhado em fatia própria**, com dry-run conferido antes | Escrita em massa não viaja de carona com feature |
| D3 | Apagar pasta com conteúdo **apaga a árvore**, com aviso que **conta** o que vai sumir | Mantém o modelo mental de hoje e o do Drive |
| D4 | Modelo por **auto-referência**, com teto de **10 níveis** | Migration aditiva; teto é guarda-corpo, não regra de produto |
| D5 | **Entrega 1 = só o sistema.** Drive fica para a Entrega 2 | O pessoal organiza já; o Drive segue achatado como hoje |
| D6 | Mover pasta: **arrastar E menu**, os dois | Conflito de gesto resolvido pela alça (§7.3) |

**Decisão técnica do orquestrador, registrada por ser contra-intuitiva:** **não** há unicidade de nome
entre irmãs. O Drive permite, e como o destino é espelhar (D1), proibir aqui criaria um estado do
Drive que o sistema recusaria a representar. Isso só se sustenta porque na Entrega 2 a identidade da
seção passa a ser o `drive_folder_id`, não o nome (§8.1).

---

## 4. Escopo da ENTREGA 1

**Entra:** aninhar pastas dentro de pastas no sistema, em até 10 níveis — criar, renomear, mover,
excluir, navegar, buscar.

**Não entra** (desenhado na §8 e §9, não construído):
- espelhar a árvore no Google Drive (Entrega 2);
- realinhar os 8.610 arquivos já achatados (Entrega 3).

**Não regride nada:** o sync continua enviando arquivos ao Drive exatamente como hoje —
`resolverPastaAlvoNoDrive()` recebe a seção-folha do documento e cria uma subpasta de 1º nível com o
nome dela. Uma árvore de 3 níveis aqui vira 1 nível lá. É o comportamento atual, não uma perda nova.

---

## 5. Modelo de dados

### 5.1 A migration

```
ALTER TABLE pasta_secao ADD COLUMN secao_pai_id INT NULL;
ALTER TABLE pasta_secao ADD CONSTRAINT fk_pasta_secao_pai
    FOREIGN KEY (secao_pai_id) REFERENCES pasta_secao(id) ON DELETE CASCADE;
CREATE INDEX idx_pasta_secao_pai ON pasta_secao (secao_pai_id);
```

Aditiva e anulável. **Nenhuma das 623 linhas é tocada:** `NULL` já significa exatamente o que elas
são — pasta na raiz. Sem backfill.

⚠️ **Ritual obrigatório antes de gerar** (CLAUDE.md, seção dos geradores): rodar
`doctrine:schema:update --dump-sql` ANTES do `make:migration` e retirar do arquivo gerado tudo que já
aparecia — em especial `DROP INDEX` de índice funcional, que o Doctrine não sabe representar.

⚠️ **Ao integrar, são DOIS bancos:** `saas_ux` (a tela) e `saas_test` (a suíte). Ver
`docs/frentes-ativas.md`.

### 5.2 Invariantes

- **`pasta_id` continua NOT NULL em toda seção, inclusive nas aninhadas.** Redundante com a cadeia de
  pais, e de propósito: mantém a árvore inteira recuperável numa query só, preserva o `TenantFilter` e
  os índices existentes. Preço: mãe e filha **sempre** na mesma pasta.
- **Mãe e filha sempre no mesmo tenant.**
- **Arquivo pode viver em qualquer nível.** `pasta_documento.secao_id` continua apontando para uma
  seção qualquer da árvore (ou `NULL`, para a raiz da pasta). A tabela `pasta_documento` **não muda**.
- **`ordem` passa a ser a posição entre IRMÃS**, não mais a posição na pasta. Como hoje todas as
  seções são raiz, a ordem atual já *é* a ordem entre irmãs — a mudança é de leitura, não de dado, e
  não exige backfill. Mas `PastaSecaoRepository::proximaOrdem()` precisa passar a receber o pai,
  senão a primeira subpasta criada nasce com ordem errada.

### 5.3 Entidade

`PastaSecao` ganha:

```php
#[ORM\ManyToOne(targetEntity: PastaSecao::class, inversedBy: 'filhas')]
#[ORM\JoinColumn(name: 'secao_pai_id', nullable: true, onDelete: 'CASCADE')]
private ?PastaSecao $pai = null;

#[ORM\OneToMany(mappedBy: 'pai', targetEntity: PastaSecao::class, cascade: ['remove'])]
private Collection $filhas;
```

Mais os métodos de árvore: `getProfundidade(): int`, `getAncestrais(): array`,
`descendeDe(PastaSecao $possivelAncestral): bool`.

---

## 6. Guards

**Como o nível é contado (evita a ambiguidade clássica):** uma seção com `pai = NULL` está no
**nível 1**. Uma filha dela, no nível 2. O teto de 10 significa que **`nível 10` é válido e `nível 11`
é recusado** — ou seja, 10 pastas encadeadas no máximo, contando a primeira.

**Altura da subárvore** = quantos níveis a subárvore ocupa a partir da sua raiz, contando ela mesma.
Uma pasta sem filhas tem altura 1.

| Guard | Regra | Por que existe |
|---|---|---|
| **Ciclo** | Recusa mover uma pasta para dentro da própria descendência (e para dentro de si mesma) | Sem ele a pasta some da árvore; e na Entrega 2 vira recursão infinita contra o Drive real |
| **Profundidade** | Recusa se `nível do destino + altura da subárvore movida > 10` | Valida a **subárvore inteira**, não só o nó movido — mover uma subárvore de altura 4 para dentro de uma pasta no nível 8 daria nível 11, e é recusado |
| **Mesma pasta** | Mãe e filha no mesmo `pasta_id` | Já é a regra de `MoverDocumentoParaSecaoUseCase:33` |
| **Tenant** | Mãe e filha no mesmo tenant | Padrão da casa, inegociável |

---

## 7. Camadas

### 7.1 UseCases

**Mudam:**

| UseCase | Mudança |
|---|---|
| `CriarPastaSecaoUseCase` | Ganha `?PastaSecao $pai`; valida os 4 guards; `proximaOrdem` por irmã |
| `ExcluirPastaSecaoUseCase` | Código não muda (o cascade já cobre a árvore); ganha a contagem que alimenta o aviso de D3 |
| `RenomearPastaSecaoUseCase` | Sem mudança estrutural |
| `ReordenarSecoesUseCase` | Passa a reordenar **entre irmãs** de um mesmo pai |

**Nasce:**

- **`MoverPastaSecaoUseCase`** — move uma pasta para dentro de outra, ou de volta para a raiz
  (`$destino = null`). É onde vivem os guards de ciclo e profundidade.

### 7.2 Repository (`PastaSecaoRepository`)

- `proximaOrdem(Pasta $pasta, Tenant $tenant, ?PastaSecao $pai): int` — **assinatura muda**
- `arvoreDaPasta(Pasta $pasta, Tenant $tenant): array` — uma query (`WHERE pasta = :p`, ≤62 linhas),
  hierarquia montada em PHP
- `contarConteudoRecursivo(PastaSecao $secao): array{subpastas: int, arquivos: int}` — alimenta o
  aviso de exclusão (D3)

### 7.3 Tela

A tela **já é** um gerenciador de arquivos. É extensão, não redesenho.

| O que | Hoje | Passa a ser |
|---|---|---|
| Entrar numa pasta | Esconde a lista de pastas, mostra só os arquivos | Mostra as **subpastas dela** em cima e os arquivos embaixo |
| Caminho no topo | Dois degraus fixos (`Raiz > Atual`) | Caminho completo clicável em todos os níveis |
| Botão "Nova pasta" | Cria sempre na raiz | Cria **dentro de onde você está** |
| Excluir | Aviso genérico | Aviso que **conta**: *"contém 3 subpastas e 127 arquivos"* (D3) |
| Busca | Varre a pasta inteira | Igual, e passa a **mostrar em que pasta** cada resultado está |

**O conflito dos dois gestos (D6) é resolvido pela alça que já existe no HTML**
(`<span class="fm-pasta-grip" title="Arrastar para reordenar">`):

- arrastar **pela alça** → reordena entre irmãs (comportamento de hoje, inalterado);
- arrastar **pelo corpo do cartão** → move para dentro da pasta sob o cursor;
- menu de três pontinhos → **"Mover para..."**, com lista de destinos (funciona no celular e no teclado).

**Não muda a estratégia de carga:** as ≤62 seções e os documentos já vêm todos no HTML e o filtro é no
navegador. Continua assim.

⚠️ **`pasta-arquivos.js` é compartilhado com a Cobrança** (`app/templates/cobranca/caso/_documentos.html.twig`,
entidade `CobrancaSecao`). A Cobrança tem **0 seções em produção**, então o risco é teórico — mas o
JS precisa continuar funcionando lá sem `secao_pai_id`. **A tela da Cobrança não entra no escopo:**
o JS degrada para o comportamento de um nível quando o container não declara suporte a árvore.

---

## 8. ENTREGA 2 — o Drive espelha a árvore (desenhada, NÃO construída)

Registrada aqui porque a investigação já foi feita e o achado é caro de redescobrir.

### 8.1 O achado: hoje a seção é identificada por NOME

O motor declara no docblock *"identidade sempre por `drive_folder_id` / `drive_file_id`"* — e a
`PastaSecao` é a exceção. Nos dois sentidos o casamento é por nome:

- **ida** — `ReconciliadorDePasta::resolverPastaAlvoNoDrive():283`, mapa plano `nomeUPPER → id`;
- **volta** — `ReconciliadorDePasta::resolverSecaoId():320`,
  `SELECT id FROM pasta_secao WHERE pasta_id = :p AND nome = :n`.

Com um nível funciona. **Com árvore quebra em silêncio:** `01 - DOCUMENTOS` em dois ramos casa com a
mesma seção e os arquivos de um ramo aterrissam no outro. Não dá erro — dá arquivo no lugar errado.

**Correção:** `pasta_secao.drive_folder_id VARCHAR(255) NULL` + índice.

### 8.2 O risco que essa coluna cria, e a adoção que o desarma

As 623 seções nascem com `drive_folder_id = NULL`. Casamento só por id faria o primeiro Importar
**criar 623 seções duplicadas** ao lado das existentes. Resolução em duas etapas:

1. procura por `drive_folder_id`;
2. não achou → procura por **nome sob o mesmo pai**; ao achar, **grava o `drive_folder_id` ali** e segue.

Auto-curativo: cada seção antiga é adotada na primeira passagem e nunca mais depende do nome. Sem
isso, a Entrega 3 viraria pré-requisito da Entrega 2.

### 8.3 As duas vias e a §11.6

- **Ida:** `resolverPastaAlvoNoDrive` sobe a cadeia de pais até a raiz, garantindo cada nível no Drive
  (find-or-create por `drive_folder_id`).
- **Volta:** `coletarArquivosRecursivo` deixa de devolver lista achatada e passa a devolver os arquivos
  **com a cadeia de pastas**. Cada nível vira uma `PastaSecao` com o pai correspondente.
- **Além do teto de 10 níveis o achatamento sobrevive:** níveis 11+ vão para o nível 10, com linha no
  log. Preservar o comportamento antigo na borda evita que árvore patológica derrube o import.

**Isso altera a §11.6 de `docs/specs/sincronizacao-drive-bidirecional.md`** (declaração canônica), a
linha *"sub-subpasta (e mais fundo) → achatada para a seção avó"*. A alteração é parte da Entrega 2.

### 8.4 Capacidade nova no client

`GoogleDriveClientInterface` tem `criarPasta`, `renomearPasta`, `listarSubpastas` — **não tem
`moverPasta`**. Sem ela, mover pasta na tela não se reflete no Drive. Entra na interface, na
implementação real e no fake dos testes.

---

## 9. ENTREGA 3 — realinhar o passivo (desenhada, NÃO construída)

**100% dos 21.197 documentos têm `drive_file_id`** — a árvore original ainda existe no Drive e cada
arquivo é casável 1:1, sem risco de duplicar.

Comando com `--dry-run` que revarre o Drive e reporta **quantos arquivos mudariam de lugar e para
onde**, sem gravar. O dono confere os números; só então roda para valer.

**Ligar a Entrega 2 NÃO realinha o passivo sozinho:** os arquivos já têm `drive_file_id` e entram em
`$conhecidos`, então o import os pula por idempotência — que é o comportamento correto. Ficam onde o
achatamento os deixou até a Entrega 3 rodar.

**Custo de esperar:** ~200 arquivos/mês (§2) sobem para o lugar achatado no Drive e entram na fila da
Entrega 3. Passivo que cresce devagar, não bola de neve.

---

## 10. Frente, integração e riscos

### 10.1 Arquivos compartilhados

| Arquivo | Com quem colide |
|---|---|
| `app/templates/pasta/show.html.twig` | frente **`expediente-ux`** (ativa, 28 commits atrás do master) |
| `app/public/js/pasta-arquivos.js` | Cobrança (`_documentos.html.twig`) — 0 seções em prod |

Declarar em `docs/frentes-ativas.md` na abertura. Regra da casa: **quem toca arquivo compartilhado vai
sozinho ou por último.**

### 10.2 Migration

Esta frente **tem migration**, e a regra é **uma frente com migration por vez**. Conferir
`docs/frentes-ativas.md` antes de abrir.

### 10.3 Testes

| Camada | O que provar |
|---|---|
| Unit | Guard de ciclo (mover pasta para dentro da própria filha → recusa) |
| Unit | Guard de profundidade **com subárvore** (mover árvore de 4 níveis para o nível 8 → recusa) |
| Unit | `proximaOrdem` por irmã (2ª subpasta de um pai nasce com ordem 2, não com a ordem global) |
| Unit | Contagem recursiva do aviso de exclusão |
| Unit | Cascade: apagar a mãe apaga filhas, netas e os documentos de toda a árvore |
| Functional | Criar/mover/excluir via controller, com CSRF |
| **Cross-tenant** | Mover pasta para destino de OUTRO tenant → `AccessDeniedException` |
| Regressão | O sync continua enviando ao Drive achatado, sem erro, com árvore de 3 níveis no sistema |

**Provar por reintrodução** (`feedback_provar_teste_reintroduzindo_defeito`): cada guard só conta como
provado se, removido o guard, o teste correspondente ficar vermelho.

### 10.4 O que a suíte NÃO prova

Aparência e arranjo da tela. A regra da casa é explícita: 3.459 testes já passaram com layout
visivelmente quebrado. **O smoke no navegador é do dono** — a entrega vem com a lista do que precisa
ser olhado, não com o navegador aberto por conta própria.
