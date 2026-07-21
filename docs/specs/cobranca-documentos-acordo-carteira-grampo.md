# Cobrança — Documentos no acordo e na carteira + grampo no objeto

> Pontos **#4/#5/#6** dos ajustes pós-taxa (um cluster). Risco **MÉDIO** (upload: isolamento por tenant, whitelist
> de tipo/tamanho). Reusa a mecânica de arquivo já existente. Independente da feature de taxa.

## 1. Objetivo

- **#5** Carteira: anexar documentos (atas de assembleia, contratos) numa área **logo abaixo da configuração da
  carteira**.
- **#4** Acordo: anexar documentos (termo de acordo, contrato) numa **aba "Documentos"** dentro do acordo.
- **#6** Na lista de objetos da carteira, um **ícone de grampo** que acende quando o objeto tem **algum documento**
  (num caso **ou** num acordo).

Em ambos: **não** é gerenciador de arquivos (sem drill-down). É uma **lista/linha do tempo simples** — cada linha:
*data do upload · nome · categoria · observação* — com um botão **"adicionar documento"**; os novos aparecem
**abaixo** dos antigos (ordem cronológica).

## 2. Reaproveitamento (não construir upload do zero)

A mecânica de arquivo é a mesma de `EnviarDocumentoUseCase` (documentos de caso):
- `App\Shared\Service\ArquivoStorageInterface` (`salvar`/`caminho`) + `CompressorArquivoInterface`.
- **Whitelist de MIME + limites** idêntica à const `EnviarDocumentoUseCase::MIME_LIMITS`.
- Físico em `<dir>/<tenantId>/<hash>` (isolamento por tenant no disco, padrão M5); a entidade guarda só o hash.

## 3. #5 — Documentos da carteira

- **Entidade `CarteiraDocumento`** (`cobranca_carteira_documento`): `ManyToOne Carteira` (`onDelete: CASCADE`),
  `tenant`, `titulo`, `categoria` (enum), `observacao` (text, null), `caminhoArquivo` (hash), `nomeOriginal`,
  `mimeType`, `tamanhoBytes`, `carregadoEm`. Índices por `carteira_id` e `tenant_id`.
- **Enum `CategoriaDocumentoCarteira`**: `AtaDeReuniao` ("Ata de reunião"), `Contrato` ("Contrato"),
  `Outro` ("Outro").
- **UseCases:** `EnviarDocumentoCarteiraUseCase` (espelha o de caso: guards de tenant, whitelist, storage em
  `carteiras/<tenantId>/<hash>`), `ExcluirDocumentoCarteiraUseCase`.
- **UI:** seção "Documentos" **abaixo da configuração da carteira**; lista cronológica + botão "adicionar".

## 4. #4 — Documentos do acordo

- **Entidade `AcordoDocumento`** (`cobranca_acordo_documento`): `ManyToOne Acordo` (`onDelete: CASCADE`), `tenant`,
  `titulo`, `categoria` (enum), `observacao`, + metadados de arquivo (iguais aos de carteira).
- **Enum `CategoriaDocumentoAcordo`**: `TermoDeAcordo` ("Termo de acordo"), `Contrato` ("Contrato"),
  `Outro` ("Outro").
- **UseCases:** `EnviarDocumentoAcordoUseCase` (storage em `acordos/<tenantId>/<hash>`),
  `ExcluirDocumentoAcordoUseCase`. Guard: o acordo tem de ser do tenant.
- **UI:** **aba "Documentos"** na tela do acordo; mesma lista cronológica + botão "adicionar".

## 5. #6 — Grampo no objeto (indicador)

Na visão da carteira que lista os objetos (`MontarVisaoCarteiraUseCase` + template da carteira), acrescentar por
objeto um booleano **`temDocumentos`**: verdadeiro se **existe** `CobrancaDocumento` em **qualquer caso** do objeto
**ou** `AcordoDocumento` em **qualquer acordo** de qualquer caso do objeto.
- **Eficiência:** resolver com **uma agregação** por objeto (EXISTS/COUNT agrupado), não N+1. O DTO da linha do
  objeto ganha o campo; o template mostra um ícone de grampo quando verdadeiro (só presença — sem contagem).

## 6. Migração

`Version<AAAAMMDDHHMMSS>`: criar `cobranca_carteira_documento` e `cobranca_acordo_documento` (FKs `ON DELETE
CASCADE`, índices por dono e tenant). Sem backfill (não havia documentos nesses níveis). Banco de teste é
schema:create.

## 7. Segurança / invariantes

- **Multi-tenant:** todo upload/exclusão/listagem filtra por tenant; o dono (carteira/acordo) tem de ser do tenant
  do usuário (mesmos guards do `EnviarDocumentoUseCase`). Documentos nunca cruzam tenant.
- **Whitelist de tipo/tamanho** obrigatória (rejeita fora da lista, como hoje).
- Exclusão remove o arquivo físico e a linha (mesma mecânica do de caso).

## 8. Testes
- Unit dos UseCases de envio (carteira/acordo): grava metadados + hash; rejeita MIME fora da whitelist e arquivo
  grande; guard cross-tenant (IDOR) nega.
- Unit do indicador `#6`: objeto com doc em caso → true; objeto com doc só em acordo → true; sem docs → false;
  não conta doc de objeto de outro tenant.
- Functional: aba/área renderiza a lista; upload aparece; grampo acende na visão da carteira.
- Suíte de Cobrança verde + global verde.

## 9. Fora de escopo (YAGNI)
- Drill-down / gerenciador de arquivos (é lista simples).
- Reordenar documentos, seções, mover entre donos.
- Contagem no grampo (só presença).
