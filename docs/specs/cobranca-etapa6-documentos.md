# Spec — Cobranças Etapa 6: Documentos do Caso

> Risco: **MÉDIO** (toca disco/uploads pela 1ª vez no módulo; isolamento físico por tenant).
> Fonte de verdade das regras: `FEATURE_GESTAO_COBRANCAS_SPEC_FINAL.md` §15 (Documentos), §16 (Judicialização),
> invariável **25** ("O Caso de Cobrança pode possuir documentos antes da judicialização e sem depender da
> existência de Pasta") e §24 (não duplicar infraestrutura de Pasta/Processo/Documento).

## Objetivo

Permitir que um **Caso de Cobrança** possua documentos (termo de acordo, boleto, comprovante, notificação,
documento de negociação, outros) **sem depender de Pasta**, organizados opcionalmente em **seções**, reusando a
infraestrutura de arquivos existente (`ArquivoStorageService`, `CompressorArquivo`, front `pasta-arquivos.js/.css`)
sem duplicá-la. Esta etapa entrega **entidades + UseCases + testes**. A camada HTTP/controllers/templates é da
**Etapa 8** (só então os endpoints e o `data-*` do front manager são ligados).

## Regras invariáveis aplicadas

- **INV-25:** documento existe vinculado ao **Caso** (FK `caso` obrigatória), **nunca** à Pasta. Um caso sem Pasta
  pode ter documentos.
- **§15/§16 (judicialização):** ao judicializar, os documentos **permanecem no Caso** — não são movidos nem
  duplicados para a Pasta automaticamente. Nenhuma alteração nos UseCases de judicialização (Etapa 5).
- **§24:** não recriar Pasta/Processo/Documento. As entidades próprias (`CobrancaDocumento`/`CobrancaSecao`)
  existem apenas porque a FK precisa apontar para `CasoCobranca` (as de Pasta apontam para `Pasta`); a
  **mecânica de arquivo é 100% reusada** do `Shared\Service`.
- **Multi-tenant (inviolável):** toda entidade é `TenantAware`; todo UseCase que resolve entidade por id usa
  `findOneByIdDoTenant`; entidades cruzadas (seção × caso, documento × seção) têm guard `->getTenant() !== $tenant`
  → `AccessDeniedException`; teste cross-tenant real (DB) obrigatório.

## Isolamento físico no disco (padrão M5)

- Parâmetro `cobrancas_uploads_dir`:
  - dev/prod: `%kernel.project_dir%/public/uploads/cobrancas`
  - test (`when@test`): `%kernel.project_dir%/var/uploads-test/cobrancas`
  - bind: `string $cobrancasUploadsDir: '%cobrancas_uploads_dir%'`
- **Subpasta por tenant** (isolamento físico, como as imagens de peça M5 em `pastas/<tenantId>/`):
  o diretório efetivo de cada operação é **`$cobrancasUploadsDir . '/' . $tenant->getId()`**.
  - salvar: `storage->salvar($file, $cobrancasUploadsDir.'/'.$tenantId)` → retorna o **hash** (nome único),
    persistido em `CobrancaDocumento.caminhoArquivo`.
  - reconstruir caminho físico (servir/excluir): `storage->caminho($cobrancasUploadsDir.'/'.$tenantId, $doc->getCaminhoArquivo())`.
  - **Contrato congelado:** todo UseCase/consumidor que toca disco monta o diretório **exatamente** assim.
- Purga: o diretório do tenant é removido inteiro por `removerDiretorioDeTenant($cobrancasUploadsDir.'/'.$tenantId)`
  (mesmo mecanismo M5; não depende de `coletarArquivos`).

## Andaime (orquestrador — committado ANTES do fan-out)

### Enum `App\Cobranca\Enum\CategoriaDocumentoCobranca` (backed string)
`TermoAcordo='termo_acordo'`, `Boleto='boleto'`, `Comprovante='comprovante'`, `Notificacao='notificacao'`,
`Negociacao='negociacao'`, `Outro='outro'`. Default de negócio: `Outro`.

### Entidade `App\Cobranca\Entity\CobrancaSecao` (`cobranca_secao`)
`implements TenantAware, Auditavel`; não-final. Índices `idx_cobranca_secao_caso (caso_id)` e
`idx_cobranca_secao_tenant (tenant_id)`. Campos:
- `id` int
- `caso` `ManyToOne(CasoCobranca, inversedBy:'secoes')` `JoinColumn(nullable:false, onDelete:'CASCADE')`
- `tenant` `ManyToOne(Tenant)` `nullable:false`
- `nome` string(255), `NotBlank`+`Length(max:255)`, setter `mb_strtoupper(trim())`
- `ordem` int default 0
- `criadaEm` datetime_immutable (`criada_em`, no construtor)
- `documentos` `OneToMany(mappedBy:'secao', CobrancaDocumento, cascade:['remove'])`

### Entidade `App\Cobranca\Entity\CobrancaDocumento` (`cobranca_documento`)
`implements TenantAware, Auditavel`; não-final. Índices `idx_cobranca_documento_caso (caso_id)` e
`idx_cobranca_documento_tenant (tenant_id)`. Campos:
- `id` int
- `caso` `ManyToOne(CasoCobranca, inversedBy:'documentos')` `JoinColumn(nullable:false, onDelete:'CASCADE')`
- `secao` `ManyToOne(CobrancaSecao, inversedBy:'documentos')` `JoinColumn(nullable:true, onDelete:'CASCADE')`
- `tenant` `ManyToOne(Tenant)` `nullable:false`
- `titulo` string(255), `NotBlank`+`Length(max:255)`, setter `mb_strtoupper(trim())`
- `categoria` `Column(enumType: CategoriaDocumentoCobranca::class)`
- `descricao` text nullable
- `caminhoArquivo` string(255) — o hash retornado pelo storage
- `nomeOriginal` string(255)
- `mimeType` string(100)
- `tamanhoBytes` int
- `carregadoEm` datetime_immutable (`uploaded_at`, no construtor)
- `ordem` int default 0

> `CasoCobranca` ganha as coleções inversas `secoes` (`OneToMany` CobrancaSecao) e `documentos`
> (`OneToMany` CobrancaDocumento), com getters. Não são cascade-persist (persistência é via repositório/UseCase);
> a cascade de remoção é feita no nível do banco (onDelete CASCADE) + explícita na purga.

### Repositórios (não-final; `salvar`/`remover(bool $flush)`; `findOneByIdDoTenant(int,Tenant)`)
- `CobrancaSecaoRepository`: + `secoesDoCaso(CasoCobranca): array` (where caso+tenant, order by ordem),
  `proximaOrdem(CasoCobranca): int` (MAX(ordem)+1, escopado por caso+tenant).
- `CobrancaDocumentoRepository`: + `documentosDoCaso(CasoCobranca): array` (where caso+tenant, order by ordem),
  `proximaOrdem(CasoCobranca): int` (MAX(ordem)+1, escopado por caso+tenant).

### Exceptions (`App\Cobranca\Exception`, `\DomainException`)
- `SecaoNaoEncontradaException(int $id)`
- `DocumentoNaoEncontradoException(int $id)`
- `TipoArquivoNaoPermitidoException(string $mime)`
- `ArquivoMuitoGrandeException(string $nome, int $limiteBytes)`

### Migration (orquestrador; dev+test via `migrations:execute --up`)
Cria `cobranca_secao` e `cobranca_documento` (FKs `caso_id` NOT NULL onDelete CASCADE; `secao_id` NULL onDelete
CASCADE; `tenant_id` NOT NULL NO ACTION), com os índices acima.

### Factories (`app/tests/Factory/Cobranca/`)
`CobrancaSecaoFactory`, `CobrancaDocumentoFactory` (defaults com `tenant`/`caso` independentes; cenários de tenant
passam explícito — padrão dos demais).

### Purga (orquestrador — arquivo compartilhado)
- `ORDEM_DELECAO`: adicionar `cobranca_documento` e `cobranca_secao` **antes** de `cobranca_caso`
  (documento antes de seção; ambos antes do caso). DML explícito por `tenant_id` (padrão do módulo).
- `limparDisco`: injetar `string $cobrancasUploadsDir` + `removerDiretorioDeTenant($cobrancasUploadsDir.'/'.$tenantId)`.
- `services.yaml`: novo argumento no construtor via bind.
- Teste `PurgarEscritorioUseCaseTest`: seed de `cobranca_secao` + `cobranca_documento`.
- `PurgaCoberturaSchemaTest`: ambas caem na `ORDEM_DELECAO` (têm `tenant_id`) → sem mudança na allowlist.

## UseCases (fan-out — 2 frentes independentes)

Todos recebem entidades já resolvidas pelo consumidor + `Tenant $tenant` (+ `User $autor` onde fizer sentido),
guardam tenant, e chamam `flush`. **Nenhum** grava `EventoHistorico` (documento não é evento de domínio §13) —
salvo decisão contrária; MVP não registra.

### Frente A — Documentos (`feature-implementer` #1)
- **`EnviarDocumentoUseCase`** — `executar(CasoCobranca $caso, ?CobrancaSecao $secao, UploadedFile $file, CategoriaDocumentoCobranca $categoria, ?string $descricao, Tenant $tenant, bool $reduzirTamanho=false): CobrancaDocumento`.
  Guards: se `$secao!==null` e `secao->getTenant()!==$tenant` → `AccessDeniedException`; se `secao->getCaso()!==$caso`
  → `SecaoNaoEncontradaException`. Whitelist MIME + limite de tamanho (reusar a tabela do `UploadPecaUseCase`,
  `TipoArquivoNaoPermitidoException`/`ArquivoMuitoGrandeException`). `storage->salvar($file, $cobrancasUploadsDir.'/'.$tenantId)`;
  opcional `compressor->comprimir`. Cria documento, `ordem` = `documentosDoCaso` próxima (ou repo `proximaOrdem`).
- **`MoverDocumentoUseCase`** — `executar(CobrancaDocumento $doc, ?CobrancaSecao $secaoDestino, Tenant $tenant): void`.
  Guards: `doc->getTenant()!==$tenant` → `AccessDeniedException`; se destino!=null: mesmo tenant + `secaoDestino->getCaso()===doc->getCaso()`
  senão `SecaoNaoEncontradaException`. `setSecao`; flush.
- **`ExcluirDocumentoUseCase`** — `executar(CobrancaDocumento $doc, Tenant $tenant): void`.
  Guard tenant. Remove o arquivo físico (`storage->caminho($cobrancasUploadsDir.'/'.$tenantId, caminhoArquivo)` →
  `existe`→`excluir`) e a linha (`remover(flush:true)`).
- DTOs conforme necessidade (Input do Enviar). Testes unit com mocks das interfaces próprias.

### Frente B — Seções (`feature-implementer` #2)
- **`CriarSecaoUseCase`** — `executar(CasoCobranca $caso, string $nome, Tenant $tenant): CobrancaSecao`.
  Guard `caso->getTenant()!==$tenant`. `ordem`=`proximaOrdem`. persist+flush.
- **`RenomearSecaoUseCase`** — `executar(CobrancaSecao $secao, string $novoNome, Tenant $tenant): void`.
  Guard tenant; valida nome não-vazio; salvar(flush:true).
- **`ExcluirSecaoUseCase`** — `executar(CobrancaSecao $secao, Tenant $tenant): void`.
  Guard tenant. **Apaga os arquivos físicos dos documentos da seção ANTES** de remover (loop
  `storage->caminho($cobrancasUploadsDir.'/'.$tenantId, doc->getCaminhoArquivo())`→`existe`→`excluir`), depois
  `remover(secao, flush:true)` (documentos caem por `cascade:['remove']`). **Decisão:** excluir seção **exclui**
  seus documentos (espelha `ExcluirPastaSecaoUseCase`). *(Alternativa "mover para geral" fica para a UI/Etapa 8 se
  o negócio pedir.)*
- Testes unit com mocks.

> **Independência do fan-out:** as duas frentes escrevem UseCases/DTOs/testes em arquivos disjuntos e só dependem
> das **entidades/repos congelados** do andaime — não uma da outra. `ExcluirSecao` (B) lê `CobrancaDocumento` (contrato
> congelado); `MoverDocumento` (A) lê `CobrancaSecao` (contrato congelado). Sem sobreposição de escrita.

## Testes obrigatórios (orquestrador, pós-integração)
1. Unit de cada UseCase (happy + guards + tenant mismatch).
2. **Cross-tenant DB real** (`DocumentosCobrancaIsolamentoTenantTest`, KernelTestCase): documento/seção de outro
   tenant → não encontrado/negado; upload isola no disco por tenant; documento existe **sem** Pasta (INV-25);
   ao judicializar o caso, o documento **permanece** no caso (não migra).
3. `tests/Cobranca` verde + suíte global verde + `PurgaCoberturaSchemaTest`/`PurgarEscritorioUseCaseTest` verdes +
   `tenant-safety-review`.

## Fora do escopo (Etapa 6)
- Controllers/rotas/templates/CSRF e o wiring do front `pasta-arquivos.js` → **Etapa 8**.
- Versionamento de documento, OCR, categorização automática, preview server-side → fora do MVP (§24).
</content>
</invoke>
