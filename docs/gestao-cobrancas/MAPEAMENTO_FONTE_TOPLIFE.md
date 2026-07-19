# Mapeamento fonte → domínio — Importação TOPLIFE (Cobranças Etapa 7)

> Fonte real: relatórios "Inadimplência detalhada" gerados por **L. G Soluções Contábeis** para os
> condomínios **TOPLIFE I** (3963 linhas) e **TOPLIFE II** (472 linhas). Arquivos `.xlsx` reais têm PII e
> **não são versionados** (`.gitignore` cobre `docs/gestao-cobrancas/*.xlsx`). Este doc guia o adapter
> específico dessa fonte (§21) — NÃO é importador universal (§24). **Decisões de negócio em aberto ao fim.**

## 1. Estrutura física (idêntica nos dois arquivos)
- 1 aba: **"Inadimplência"**.
- L1–L4: cabeçalho institucional (nome da contábil, "INADIMPLÊNCIA DETALHADA", "Número de unidades: N",
  "Juros: 1,00% ao mês; Multa: 2,00%; Honorários…"). **Contém os parâmetros de encargos/honorários da carteira.**
- L5: vazia.
- **L6: cabeçalho das colunas.**
- L7…: dados.
- **Rodapé (descartar):** bloco de totais por classe (rótulos caem na col A, Competência="0"), "Total de
  inadimplência", linha "Filtros: …", endereço da contábil, "Emissão: …". Heurística de linha VÁLIDA:
  Unidade não-vazia **E** Competência no formato `MM/AAAA` **E** Vencimento é data `dd/mm/aaaa`.

## 2. Colunas (L6) e mapeamento proposto
| Col | Cabeçalho | Exemplo | Conceito de domínio (proposto) |
|---|---|---|---|
| A | Unidade | `01-01`, `01-01 (05-03,06-01…)`, `CHACARA 08 LOTE 13` | **Objeto de Cobrança** (unidade do condomínio). Código = a Unidade; dedup do Objeto por código dentro da Carteira. Parênteses = unidades associadas ao mesmo sacado (**ver decisão B**). |
| B | Sacado | `ANTONIO JOSE PORTELA DE SOUZA` | **Pessoa (devedor)** e **pessoa cobrada atual** do Caso. **SEM CPF/CNPJ na fonte** (0 ocorrências) → identidade por nome (**ver decisão A**). |
| C | NN | `74608` | Nº do lançamento/boleto. **NÃO é único por obrigação** (mesmo NN em várias linhas-componente). Sozinho não serve de chave de idempotência (**ver decisão C**). |
| D | Classe de conta | `1.1 - Taxa de condomínio`, `1.14 - Energia`, `1.4 - Juros`, `1.5 - Multas`, `1.15 - Honorário advocatício`, `1.6 - Descontos` | Tipo do lançamento → define se vira **Obrigação**, **encargo** ou **honorário** (**ver decisão D**). |
| E | Competência | `02/2026` | Mês de referência da obrigação. |
| F | Vencimento | `10/02/2026` | **Obrigacao.vencimento**. |
| G | Atraso | `148` | Derivado (dias). **NÃO importar** (o sistema deriva). |
| H | Valor (R$) | `190` | Valor principal do lançamento. |
| I | Juros (R$) | `9.37` | Encargo (juros). |
| J | Multa (R$) | `3.8` | Encargo (multa). |
| K | Correção (R$) | `0` | **Encargo (correção monetária)** — importada para o balde próprio `correcao`. Medição: K = 0 em **4.412/4.412** linhas reais dos dois arquivos, então hoje ela não move nenhum valor; ainda assim **é lida**, e não mais descartada, para que a fonte deixe de mentir por omissão se um relatório futuro trouxer correção. |
| L | Honorários (R$) | `40.63` | Honorários advocatícios do lançamento (§18, separado da dívida). |
| M | Total (R$) | `243.8` | Derivado (= H+I+J+K+L). Conferência, não importar como campo. |
| N | Informações do acordo | `-` ou `Acordo 396 - Parc. 2/11` | Vínculo a acordo (**ver decisão E**). |
| O | Recebimento | `-` | **Sempre vazio** nos dois arquivos → sem Pagamento a importar nesta fonte. |

## 3. Conceitos estáveis (independem de decisão)
- **Carteira de Cobrança escolhida no import** (§21): um arquivo = um condomínio = uma Carteira (o operador escolhe/cria a Carteira; o credor é o condomínio, um Cliente já cadastrado). O arquivo NÃO traz o credor — vem do contexto do import.
- **Objeto** por Unidade (98 unidades distintas no TOPLIFE I ≈ 98 sacados → ~1 sacado por unidade).
- **Caso de Cobrança** por Objeto (uma cobrança ativa por objeto = modo A da carteira, provável).
- **Correção (K)** entrou no escopo: é lida e somada no balde `correcao` da obrigação (zerada em 100%
  das linhas medidas, mas não mais ignorada). **Recebimento (O)** segue ausente → sem Pagamento a importar.
- **Atraso, Total** derivados → não importar.
- **Rodapé e linhas-lixo** → "ignorado" (não são dados) ou "rejeitado" com motivo.

## 4. Idempotência / dedup (análise)
- Reimportar o MESMO relatório (ou um mais novo do mesmo condomínio) **não pode duplicar** (§21). A chave
  estável precisa ser definida com a decisão C (granularidade da Obrigação) — candidatos: `NN` + `Classe` +
  `Competência` + `Unidade` (composto), pois `NN` sozinho repete.
- **Pessoa**: sem CPF/CNPJ, a dedup por dígitos (entregue no ponto seguro) não se aplica a esta fonte; a
  identidade cai em nome/Unidade (decisão A). Escopo SEMPRE intra-tenant (invariável 24).

## 5. Decisões de negócio (RESOLVIDAS pelo humano — 2026-07-10)

### A. Identidade do devedor (sem CPF/CNPJ nesta fonte)
Chave técnica da fonte = **Carteira + Objeto/Unidade + nome normalizado do Sacado**.
- mesma Carteira + mesmo Objeto + mesmo nome normalizado → **reutiliza a mesma Pessoa**;
- mesmo nome em OUTRO Objeto → **não** funde automaticamente;
- se o nome do Sacado mudar no MESMO Objeto → **cria nova Pessoa e novo vínculo**;
- **nunca** deduplica por nome no tenant inteiro; **nunca** cruza tenants; nome sozinho não é identidade global.
- CPF/CNPJ, quando disponível no futuro, volta a ser o identificador preferencial intra-tenant.

### B. Unidade/Objeto — **só a unidade principal**
Objeto = `01-01`; o conteúdo entre parênteses vira **metadado/observação** da importação; **não** criar
Objetos para os códigos entre parênteses. (Unidade associada com dívida própria e independente → futuro.)

### C. Granularidade da Obrigação — **1 Obrigação por boleto (NN)**
Linhas com o mesmo NN = componentes do mesmo débito → **agregadas numa única Obrigação**. Chave de
idempotência ≥ **Carteira + Objeto + NN**. **Preservar o detalhamento das linhas/componentes** (preview,
validação, auditoria da importação, diagnóstico de rejeição) — sem perder a composição, sem criar várias
Obrigações por boleto.

### D. Classes de conta → domínio (4A, sem dupla contagem)
Agregando por boleto (NN):
- **principal da Obrigação** = Σ `Valor(H)` das linhas classe `1.1 Taxa de condomínio` e `1.14 Energia`;
- **encargos em TRÊS BALDES SEPARADOS** (antes era um agregado único "encargos reconhecidos"):
  - `juros` = Σ `Juros(I)` de todas as linhas **+** Σ `Valor(H)` das linhas classe `1.4 Juros`;
  - `multa` = Σ `Multa(J)` de todas as linhas **+** Σ `Valor(H)` das linhas classe `1.5 Multas`;
  - `correcao` = Σ `Correção(K)` de todas as linhas;
  A soma dos três é idêntica ao agregado antigo (nenhum centavo entra ou sai) — o que se ganha é saber
  **qual** encargo é qual, que é o insumo do cálculo configurável em cascata;
- **honorários** (§18, separado da dívida) = por linha: `Classe==1.15 ? Valor(H) : Honorários(L)` (evita a
  dupla contagem — a linha 1.15 sempre tem L=0; provado: Σ L≈110k × Σ H(1.15)≈6k, disjuntos por linha);
- **`1.6 Descontos`** = redução/ajuste do valor (negativo), **nunca** nova Obrigação.
Semântica confirmada nos dados: `Total = H+I+J+K+L` bate em 100% das linhas; `Correção(K)` sempre 0.

### E. Acordos — **apenas metadado/observação**
NÃO criar automaticamente entidade `Acordo` a partir do texto (`Acordo 396 - Parc. 2/11`). Preservar como
observação da importação. **Não altera** o modelo de Acordos existente.

## 6. Regras de rejeição/ignorar (linha inválida com motivo)
- **IGNORADO** (não é dado): linha sem **Vencimento** válido `dd/mm/aaaa` na col F (rodapé, totais, filtros, emissão, branco).
- **REJEITADO** (candidata a dado, mas inválida) com motivo: boleto sem Sacado; Competência ausente/≠`MM/AAAA`;
  Valor não numérico; **boleto sem principal de dívida** (`valorOriginal` agregado ≤ 0 — só juros/honorário; a
  Obrigação exige principal > 0). Discriminador candidata×ignorada = **Vencimento válido em F**.
- Objeto/Pessoa/Caso/Obrigação criados pelas MESMAS regras do cadastro manual (reusa UseCases do núcleo — §21).

## 7. Arquitetura do importador (design)
Tipos e camadas em `app/src/Cobranca/Service/Importacao/` e `UseCase/`. Fonte-específico isolado no adapter (§21/§24).

**Value objects (fonte-agnósticos, saída do adapter):**
- `BoletoImportavel`: `nn`, `objetoIdentificacao` (unidade principal, sem parênteses), `unidadeMetadata` (parênteses→obs),
  `sacadoNome`, `principalCentavos` (Σ H de 1.1/1.14 + Σ H de 1.6 descontos), **`jurosCentavos`**
  (Σ I todas + Σ H de 1.4), **`multaCentavos`** (Σ J todas + Σ H de 1.5), **`correcaoCentavos`** (Σ K todas),
  `honorariosInformadosCentavos` (por linha: 1.15→H senão L), `vencimento`, `competencia`,
  `acordoTexto` (obs), `linhas[]` (detalhamento/auditoria).
  `encargosCentavos` **deixou de ser campo e virou método** (`juros + multa + correcao`) — o agregado
  continua disponível para conferência, mas a fonte da verdade passaram a ser os três baldes.
- `LinhaRejeitada`: `referencia` (NN/linha), `motivo`, `dados`.
- `ResultadoImportacao`: contadores + listas por status (`importados`, `atualizados`, `ignorados`, `rejeitados`).

**Adapter** `TopLifeInadimplenciaAdapter` (fonte-específico): lê `.xlsx` (PhpSpreadsheet), acha o cabeçalho na L6,
itera dados, **agrega por NN** em `BoletoImportavel`, aplica ignorar/rejeitar. Puro (arquivo→VOs), sem DB.

**UseCase** `ImportarRelatorioCarteiraUseCase`: recebe `carteiraId` + boletos (do adapter) + tenant + user.
- **preview** (dry-run): projeta o que seria criado/atualizado/rejeitado/ignorado, sem persistir.
- **confirmar**: por boleto, na ORDEM: resolve/cria **Objeto** (dedup por `identificacao` na carteira) →
  resolve/cria **Pessoa** (por nome normalizado **no Objeto**; nome novo no mesmo objeto = nova Pessoa — decisão A) →
  resolve/cria **Caso** ativo do Objeto (com a Pessoa cobrada) → resolve/cria/atualiza **Obrigação** (dedup por
  `referenciaExterna = NN` no Caso; existente → `reconhecerEncargos`/atualiza, **não duplica** — idempotente).
  Reusa `CriarObjeto`/`CriarPessoa`/`VincularPessoaAObjeto`/`AbrirCaso`/`RegistrarObrigacao`.
  **Honorários: PERSISTIDOS** na coluna `honorarios` da obrigação (não são mais só preview). Não afetam
  o saldo: `Obrigacao::valorExigivel()` soma apenas `valorOriginal + juros + multa + correcao`.

**A obrigação importada nasce CONGELADA** (`encargosCongeladosEm` preenchido — spec §9, INV-E4): os números
vieram da contabilidade e são a verdade, então o cron de materialização de encargos **não** os recalcula nem
os sobrescreve. Congela SEMPRE, inclusive quando os encargos são zero — boleto que a contabilidade diz valer
só o principal tem de continuar valendo só o principal, e não virar base de cálculo automático. O legado
importado ANTES de o congelamento existir foi congelado retroativamente pela migração
`Version20260719140000` (só as obrigações com encargo > 0).

**Queries de dedup a adicionar** (hoje inexistentes): `ObjetoCobrancaRepository::findOnePorIdentificacaoNaCarteira`;
`ObrigacaoRepository::findOnePorReferenciaExternaNoCaso`; busca de Pessoa por nome normalizado vinculada ao Objeto
(via `VinculoPessoaObjetoRepository`). Idempotência reforçada por índice **parcial único** em
`obrigacao(caso_id, referencia_externa) WHERE referencia_externa IS NOT NULL` (obrigação manual = null, livre).
Escopo SEMPRE intra-tenant; nunca cruza escritórios (invariável 24).
