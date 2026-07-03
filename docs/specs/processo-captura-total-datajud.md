# Spec — Captura total dos dados do Datajud no Processo

**Risco:** BAIXO (domínio Processo; não toca ponto, identidade User/Tenant, nem
TenantRole/Permission/Profile). Multi-tenant continua inegociável nas sub-entidades novas.

## Objetivo

Fazer o Processo receber e persistir **100% do que a API pública do Datajud
(Base Nacional CNJ) entrega** no `_source` de cada hit — hoje só capturamos parte.

## Fonte confirmada (consulta real à API)

Endpoint: `POST https://api-publica.datajud.cnj.jus.br/api_publica_<sigla>/_search`
(Elasticsearch; busca por `numeroProcesso`). Campos do `_source`:

| Campo | Sub | Status hoje | Fase |
|---|---|---|---|
| `numeroProcesso` | | ✅ | — |
| `classe` | `nome` | ✅ `classeProcessual` | — |
| `classe` | `codigo` | ❌ | 1 |
| `sistema` | `codigo`,`nome` | ❌ | 1 |
| `formato` | `codigo`,`nome` | ❌ | 1 |
| `nivelSigilo` | | ❌ | 1 |
| `tribunal` | | ✅ `siglaTribunal` | — |
| `grau` | | ✅ `instancia` | — |
| `dataHoraUltimaAtualizacao` | | ✅ `dataAtualizacao` | — |
| `dataAjuizamento` | | ⚠️ bug de parse (`AAAAMMDDHHMMSS`) | 4 |
| `orgaoJulgador` | `nome` | ✅ | — |
| `orgaoJulgador` | `codigo`,`codigoMunicipioIBGE` | ❌ | 1 |
| `id` (doc ES) | | ❌ | 1 |
| `assuntos[]` | `codigo`,`nome` | ⚠️ só o 1º vira string | 2 |
| `movimentos[]` | `nome`,`codigo`,`dataHora`,`orgaoJulgador` | ✅ (dataHora perde a hora) | 3 |
| `movimentos[]` | `complementosTabelados[]` | ❌ | 3 |
| `@timestamp` | | ➖ redundante | — |

## Fora de escopo (limite da fonte, não do código)

- **Partes** (nome/CPF/advogados) e **texto integral do andamento**: a API pública
  **não** retorna (LGPD + só o movimento codificado da TPU). Só via provedor pago
  (Escavador/Judit/Digesto/…) ou scraping do PJe — projeto à parte.
- O mapeamento de `partes` existente no código é código morto em produção.

## Decisões de arquitetura (aprovadas)

1. **Assuntos** → nova sub-entidade `AssuntoProcesso` (`OneToMany`, `TenantAware`,
   `codigo`+`nome`). Mantém `assuntoProcessual` string como **assunto principal**.
2. **Complementos das movimentações** → coluna JSON em `MovimentacaoProcesso` +
   resumo concatenado para exibição.
3. **Códigos TPU** (classe/assunto/órgão/sistema/formato) guardados junto dos nomes.
4. **`datajudRaw` (JSONB)** com o `_source` inteiro — rede de segurança e auditoria.
5. **`dataMovimentacao` vira `datetime`** (hoje é `date` e perde a hora).

## Faseamento

- **Fase 1 — Escalares:** `nivelSigilo`, `formato`(+cod), `sistema`(+cod),
  `classeCodigo`, `orgaoJulgadorCodigo`, `orgaoJulgadorMunicipioIbge`, `datajudId`.
  Entidade + mapper + migration + preview JSON + `fillProcessoFromRequest` +
  hidden no `new.html.twig` + exibição no `show.html.twig` + testes.
- **Fase 2 — Assuntos:** `AssuntoProcesso` (todos os assuntos), mantendo o principal.
- **Fase 3 — Movimentações completas:** `complementosTabelados` (coluna JSON + resumo
  concatenado em `getComplementosResumo`, exibido como "Distribuição — sorteio") + código
  do órgão do movimento (`orgaoCodigo`). `dataMovimentacao` **mantida como `date`** (ver decisão).
- **Fase 4 — Robustez:** `datajudRaw` JSONB + correção do `dataAjuizamento` +
  limpeza do `fixUtf8Encoding` morto.

## Alvo de revisão

Cada fase: implementar → `/review` (feature-review-agent, read-only) contra esta
spec → corrigir → rodar suíte. Reforçar teste **cross-tenant** ao criar
`AssuntoProcesso` (Fase 2).

## Caminhos de persistência (ambos passam pelo mapper — captura automática)

1. **Web preview** (`ProcessoController::datajudSearch`) — efêmero, devolve JSON.
2. **CLI** (`app:datajud:atualizar-processo`) — persiste via mapper (upsert por tenant).

## Decisões de escopo registradas durante a implementação

- **Fase 2 — coleção de assuntos no formulário web (RESOLVIDO):** inicialmente a coleção só
  era capturada pelo mapper (CLI sync + preview). A pedido, foi adicionada a **aba "Assuntos"**
  no formulário de novo/editar processo, espelhando o padrão de linhas de `movimentacoes`/`partes`:
  linhas editáveis (nome + código TPU), botões adicionar/remover, preenchimento automático pela
  busca no CNJ, e `syncAssuntosFromRequest` no controller (reconcilia por id: mantém/edita/remove/
  adiciona). O assunto principal (string) segue na aba Informações. Coberto por functional test
  (criar + editar reconciliando) e smoke real no browser (processo 46). Suíte 1113/1113.
- **Purga de tenant:** `assunto_processo` entrou na `ORDEM_DELECAO` do `PurgarEscritorioUseCase`
  (antes de `processo`), igual a `movimentacao_processo`/`parte_processo`. `AssuntoProcesso`
  entrou em `NAO_AUDITAVEIS` (fatia Processo não auditada, decisão de produto).
- **Bug de tipo de data (achado no smoke, corrigido):** `parseDateOnly` do mapper retornava
  `DateTimeImmutable`, mas as colunas `dataDistribuicao`/`dataBaixa`/`dataMovimentacao` são
  `type:'date'` (Doctrine `DateType`), que exige `\DateTime` mutável — o flush do sync CLI
  estourava `InvalidType` com dados reais (datas ISO do PJe). Corrigido para `\DateTime`
  (igual ao `parseDateOrNull` do controller). Regressão coberta em `DatajudIsolamentoTest`.
  Latente antes porque os stubs de teste não tinham datas e o preview web nunca dá flush.
  **Nota Fase 4:** o parse do `dataAjuizamento` no formato compacto (`AAAAMMDDHHMMSS`, ex.: TJAL)
  ainda retorna null — segue pendente para a Fase 4 (é bug de parsing, diferente deste, de tipo).
- **Fase 3 — `dataMovimentacao` mantida como `date` (datetime descartado):** o plano previa
  virar `datetime` para guardar a hora do movimento. Descartado por custo/benefício: tornar a
  coluna datetime obrigaria o input do form a virar `datetime-local` (senão qualquer edição do
  processo truncaria a hora de TODAS as movimentações — perda de dado), adicionando fragilidade
  a um form que funciona, por um ganho (a hora) que não era o pedido. O valor real da Fase 3 —
  descrição mais rica — vem do **complemento tabelado**, entregue. Complementos e `orgaoCodigo`
  são capturados pelo mapper (CLI/preview), exibidos no `show`, **não editáveis no form** e
  **preservados na edição** (o `syncMovimentacoesFromRequest` não os toca; teste cobre isso).
  Em web-create, movimentações novas nascem sem complementos (entram no 1º sync CLI).
