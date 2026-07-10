# Cobranças — Etapa 7: Importação em massa (SPEC §21)

> Risco: MÉDIO (importação em lote, idempotência, dedup intra-tenant). Fonte de verdade:
> `docs/gestao-cobrancas/FEATURE_GESTAO_COBRANCAS_SPEC_FINAL.md` §21 + invariáveis §23.
> Status: **PARCIALMENTE BLOQUEADA** — ver §1. Entregue nesta sessão apenas o ponto seguro (§3).

---

## 1. Bloqueio de entrada (motivo pelo qual o importador NÃO foi implementado)

A SPEC §21 e o PLAN §9 **exigem que o importador seja construído contra o relatório real**
(anonimizado) da primeira fonte específica — os relatórios da contabilidade:

- §21: *"As regras detalhadas de reimportação e identificação de duplicidades devem ser
  definidas ao implementar o primeiro importador real, com base em relatórios reais da mesma
  origem."*
- §21: *"Cada fonte específica deve adaptar os dados externos para os conceitos gerais da
  feature."* — logo, o **adapter** (mapeamento de colunas) depende do formato real.
- PLAN §9: *"importador da fonte específica (relatórios da contabilidade)… testes com relatório
  real anonimizado (fixture)."*
- §24 proíbe **importador universal de planilhas**.

**Verificação do repositório (2026-07-10):** não há nenhuma fonte real de cobrança/contabilidade
versionada nem presente no working tree. As únicas planilhas do projeto são os CSVs de
reconciliação de **pastas/acervo** em `tmp/acervo/` (colunas `nup;cliente;parte_contraria;acao`
— domínio Pasta/Drive, gitignored, PII), **sem relação** com o domínio de Cobranças. Busca
executada: `*.xlsx/*.xls/*.csv/*.ods` + `git ls-files` por `import|planilha|modelo|contab|fixture`
+ grep por `contabilidade`. Resultado: **nenhuma fonte de importação de cobrança**.

**Consequência (regra da tarefa: "sem inventar colunas ou regras da fonte"):** implementar agora
o adapter, o mapeamento de colunas, a chave de idempotência de reimportação (`referenciaExterna`)
ou heurísticas de matching específicas da fonte seria **inventar** o formato — proibido. Portanto
o núcleo do importador fica **diferido** até o relatório real (anonimizado) entrar no repositório.

### Para desbloquear (o que o humano precisa fornecer)
Um relatório real **anonimizado** da contabilidade (1 amostra basta), em `.xlsx`/`.csv`, colocado
em local versionável (ex.: `app/tests/Fixtures/Cobranca/importacao/`). Com ele definiremos:
colunas → conceitos do domínio; chave estável de reimportação; regras finas de dedup/matching.

---

## 2. O que a SPEC já fixa (independe da fonte) — contrato do importador quando desbloqueado

Quando o relatório real existir, o importador (Etapa 7) deve, reusando os UseCases do núcleo
(mesmas regras do cadastro manual — §21):

1. ocorrer **dentro de uma Carteira escolhida explicitamente** (§21);
2. ser um **adapter específico** da fonte → normaliza para os conceitos gerais (Carteira, Objeto,
   Pessoa, Caso, Obrigação); **não** transformar a planilha no modelo do sistema; **não** universal (§24);
3. fluxo **upload → parse → preview/validação → confirmação → relatório de resultado**
   (importado / ignorado / rejeitado, com motivo por linha);
4. **idempotente** na reimportação: sem duplicidade silenciosa (chave estável definida com o real);
5. **dedup de Pessoa somente intra-tenant**, por CPF/CNPJ **quando informado**, **nunca** cruzando
   tenants (§21, invariáveis §23.1/§23.24) — ver §3, já entregue;
6. dados importados passam pelas **mesmas regras de negócio** do cadastro manual.

Decisão de design em aberto (resolver com o real): entidade de "job de importação" persistida
(log/idempotência) **vs.** fluxo stateless. PLAN §9: *"talvez tabela de log de importação, se
necessário para idempotência."* Não decidir sem o formato real.

---

## 3. Ponto seguro entregue nesta sessão — dedup de Pessoa por dígitos (intra-tenant)

Único trabalho **independente da fonte** e **mandado por invariável** que avança a Etapa 7 sem
inventar nada: fechar o núcleo do follow-up #3 (dedup por dígitos), que **qualquer** importador
vai depender e que também melhora o cadastro manual.

**Regra (invariável §23.24):** *"CPF e CNPJ são opcionais para Pessoa; quando informados, ajudam
a evitar duplicidades somente dentro do mesmo tenant."* A sugestão continua **advisory** (informa,
não bloqueia — SPEC §7).

**Gap corrigido:** a sugestão de duplicadas comparava CPF/CNPJ por **string exata**, então
`123.456.789-01` (formatado) e `12345678901` (dígitos) eram tratados como pessoas diferentes —
falha real que um relatório com documentos formatados dispararia.

**Solução (mínima, sem tocar o caminho de escrita nem o schema das entidades do núcleo):**
- `NormalizadorDocumento::apenasDigitos()` (`Cobranca/Service`): utilitário puro, ponto único de
  normalização (null/vazio → `null`). Reusado pelo cadastro/sugestão e pelo futuro importador.
- `SugerirPessoasDuplicadasUseCase`: normaliza o parâmetro CPF/CNPJ para **apenas dígitos** antes
  de consultar (curto-circuito quando ambos ausentes, sem tocar o banco).
- `PessoaRepository::buscarPossiveisDuplicadas`: **fronteira auto-defensiva** — normaliza o
  parâmetro para dígitos (qualquer chamador pode passar formatado) e compara por **dígitos** via
  `regexp_replace(coalesce(cpf,''), '\D', '', 'g')`, casando independentemente da formatação
  armazenada (cobre linhas gravadas formatadas por factory/importador/legado). Escopo **sempre**
  `tenant_id = :tenant` (nunca cruza escritórios). Consulta nativa hidratando entidades `Pessoa`.
- Migration `Version20260710130000`: **índices funcionais**
  `(tenant_id, regexp_replace(coalesce(cpf,''),'\D','','g'))` e o equivalente para `cnpj` —
  o "índice funcional" que o follow-up #3 previa, dando performance à busca por dígitos.

**Achados da revisão (não-bloqueantes, registrados):**
- **Drift de schema (tratado):** índices funcionais não têm mapeamento na entidade, então
  `doctrine:migrations:diff` gera `DROP INDEX` deles. A migration `Version20260710130000` traz aviso
  explícito para **remover esses DROPs à mão** ao gerar a próxima migration por diff. Não confiar no
  diff automático do Doctrine.
- **Índices planos redundantes (cosmético, aceito):** `idx_cobranca_pessoa_tenant_cpf`/`_cnpj`
  (colunas cruas, via `#[ORM\Index]`) não são mais usados pela dedup. Não removidos: dropá-los
  criaria drift reverso (Doctrine os recriaria). Custo só de escrita/disco. Follow-up opcional.
- **`\D` PCRE × Postgres (irrelevante):** divergem apenas para dígitos Unicode não-ASCII; CPF/CNPJ
  são ASCII. Sem impacto no domínio.

**Fora do escopo desta entrega** (fica com o importador real): normalizar CPF/CNPJ na **escrita**
(mudaria o formato de exibição — decidir na Etapa 8/importador), `referenciaExterna`, qualquer
adapter/mapeamento de colunas, entidade de job de importação.

**Multi-tenancy:** a busca é intra-tenant por construção; teste cross-tenant prova que documento
igual em tenants diferentes **não** vaza (formatado num tenant × dígitos no outro).
