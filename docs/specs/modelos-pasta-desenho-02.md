# Modelos da pasta (desenho 02 de 27/10): mini-spec

**Risco:** MÉDIO (entidade nova, migration, conteúdo jurídico) · **Frente:** `modelos-spec-27` · **Data:** 27/10/2026
**Estado:** só levantamento. Nada implementado. Depende de decisões do dono (seção "Decisões").

## O que o desenho pede

Desenho: `docs/design/redesign-27-10-26/02 - EXPEDIENTES - VS 27.10.26.dc.html` + `bj-modelos.js` (100 KB).

- A aba `detalhes` da pasta passa a se chamar **Modelos** (ícone `bi-journal-text`; o id interno continua `detalhes`, L.6470).
  Em cima, o bloco `data-bj-modelos` (banco de modelos de petição). Embaixo, um `<details>` recolhido
  "Relatório inicial da pasta (objetivos, acordos e resultado esperado)" (L.2400-2403).
- **Modelo:** `{titulo, matéria, tipo, corpo, semente, usos, documento opcional}`; favoritos e recentes por usuário.
- **Ações:** buscar (título, tipo, matéria, corpo); filtrar por matéria (16) e tipo (14); favoritar (modelo e matéria);
  ordenar (usos, recente, A-Z); novo, editar, duplicar, excluir, copiar, **Usar nesta pasta**, baixar Word,
  importar arquivo (.doc/.docx/.pdf/.txt/.rtf/.html, em lote, com classificação automática), exportar JSON,
  **Salvar a minuta** (documentos da pasta, metas, banco de modelos, Word), **Criar meta** com a minuta anexada.
- **Variáveis:** `{cliente} {cpf} {processo} {pasta} {data} {cidade} {advogado} {oab}`; sem valor vira `___`.
- **Fora do núcleo (extras do protótipo):** timbrado do escritório, teses prontas, checklist por tipo,
  sincronia com Google Drive (só uma flag no protótipo), central de documentos (`bj-central-docs.js`: hash/CPF do
  documento, vínculo entre pastas, alertas de controladoria).
- No protótipo tudo é `localStorage`: nada disso tem backend.

## O que já existe

- Aba: `templates/pasta/show.html.twig:315` (`id="detalhes"`), enum `AbaDaPasta` (Detalhes), Relatório inicial em
  `_detalhes_obs.html.twig`.
- DOCX: `phpoffice/phpword` + `ExportarPecaTextoUseCase` (HTML para Word) e rota `pasta_documento_exportar_texto`.
- Peticionar: `PeticionarController`, `PastaDocumento`. Não há substituição de variáveis em lugar nenhum.
- Meta: entidade `Tarefa` e `src/Tarefa/UseCase/*`.
- Modelo de petição: **não existe**. Padrão de referência: `Comercial` (`SalvarModeloDeCampanhaUseCase`).

## O que falta (backend)

`ModeloDePeca` (tenant, matéria, tipo, título, corpo, autor, usos, semente) + `ModeloFavorito` (usuário) + migration,
repository (filtro de tenant), DTOs, UseCases (listar, criar, editar, duplicar, excluir, favoritar, registrar uso),
controller e JS da aba; serviço de variáveis (contexto de Pasta, Cliente e usuário); "Usar nesta pasta" gerando
`PastaDocumento`; baixar Word; criar meta com minuta; importar arquivo com classificação por palavras-chave;
sementes; reorganização da aba.

## Fatias propostas

| Fatia | Conteúdo | Tamanho |
|---|---|---|
| M1 | Entidade, migration, CRUD, busca, filtros, favoritos, aba reorganizada (Modelos + Relatório inicial em `<details>`) | grande |
| M2 | Variáveis, "Usar nesta pasta" (gera `PastaDocumento`), baixar Word, copiar | médio |
| M3 | Criar meta com minuta; salvar minuta como modelo | médio |
| M4 (opcional) | Importar arquivo + classificação automática | médio |
| M5 (opcional) | Timbrado, teses, checklist, Drive, central de documentos | grande, separar |

## Decisões que exigem o dono

1. Modelos só do escritório (tenant) ou também do sistema (sementes)? Quem edita e exclui (todos, só o autor, só admin)?
2. Renomear a aba Detalhes para Modelos e rebaixar o Relatório inicial a bloco recolhido: confirmado? Permissão e aba
   oculta continuam sob `detalhes`?
3. Formato do Word: .docx real (PhpWord) em vez do .doc HTML do protótipo? Timbrado entra agora ou depois?
4. Importação com classificação automática, Drive e central de documentos: escopo agora ou depois?
5. Conteúdo jurídico das sementes (revisão por advogado) e lista de matérias e tipos: fixa ou configurável?
6. Meta com minuta: prazo, caixa e responsável padrão.

## Decisões aceitas (27/10/2026) e estado da M1

- Só modelos do escritório (sem sementes jurídicas). Matérias (16) e tipos (14) fixos em enum; o menu de botão direito que edita matérias e tópicos fica fora.
- **Permissão:** ver a pasta basta para consultar o banco e para favoritar (a estrela é preferência pessoal, por usuário). **Criar, editar, duplicar e excluir modelo valem para quem edita a pasta aberta** (padrão do `PastaChecklistModelo`), sem permissão nova.
- Aba `detalhes` renomeada para Modelos; o Relatório inicial passa para um `<details>` recolhido. Id e permissão da aba continuam `detalhes`.
- Corpo em HTML, escrito no TinyMCE do sistema e sanitizado no servidor ao gravar. Sem .docx real, sem importação, sem Drive no MVP.
- "Mais usados" empata em 0 até o uso existir (M2): desempate pelo mais recente.
- M1 entregue no #523 (migration 154700); M1b: favoritar exige só `view`.
