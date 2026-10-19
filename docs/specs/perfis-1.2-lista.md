# Perfis 1.2 · Lista de perfis do escritório

Frente `perfis-lista` · base `origin/master` · risco **MÉDIO** (TenantRole/Profile, só leitura e tela) · aberta
em 19/10/2026. Sem migration.

## 1. Fonte

Desenho `05 - Perfis 1.2 - VS 18.10.26.dc.html` (cópia em `docs/design/perfis-1.2/`, trazida pela frente
`perfis-masters`; se ainda não estiver no master, copie de `.claude/worktrees/perfis-masters/docs/design/perfis-1.2/`):
template das linhas 48-64 e lógica das linhas 661-662. O que não mudou do 1.0 continua como está (spec
`perfis-master-1.0.md` §4.1: linha Master virtual só para o Master, "Ver como Master / Primário", "Novo Perfil",
linha clicável, exclusão com a confirmação interna).

## 2. O que muda (o desenho manda)

- **Resumo `pfResumo`** acima da tabela: 4 chips com ícone azul `#0a6fa0`, número em destaque e rótulo:
  "N perfis" (`bi-diagram-3`), "N usuários" (`bi-people`, soma dos usuários de cada perfil), "N fixos do sistema"
  (`bi-lock`: Master e Primário), "N personalizados" (`bi-person-gear`: secundários). Respeitam o "Ver como":
  a linha Master só conta para o Master e some da contagem na prévia "Primário".
- **Tabela com 5 colunas** e ícone no cabeçalho: "PERFIL" (`bi-diagram-3`), "CLASSIFICAÇÃO" (`bi-tag`),
  "PERMISSÕES" (`bi-key`), "USUÁRIOS" (`bi-people`), "AÇÕES". Saem DESCRIÇÃO e SISTEMA. Grade, bordas, cores e
  espaçamentos do desenho (linha 56).
- **Célula PERFIL**: nome em negrito e MAIÚSCULAS; selo "FIXO" com cadeado (title "Perfil do sistema: não pode
  ser excluído") quando não é secundário; descrição numa linha abaixo do nome.
- **Célula PERMISSÕES**: Master mostra "Acesso total" / "sistema"; os demais "N permissões" / "de TOTAL" com a
  barra de 4px proporcional; o Primário mostra TOTAL de TOTAL. TOTAL é o total do catálogo que o controller já
  calcula (`$totalDoCatalogo`) e passa a ir para o template.
- **Hover** da linha: fundo `#eaf3f9`.
- Descrição da linha Master conforme o 1.2 (Master primário e secundários), sem "Único".

## 3. Testes

Functional da lista: os 4 chips com os números certos para o Master e para o primário (sem a linha Master);
5 colunas no cabeçalho (filho direto da grade); selo FIXO só em Master e Primário; "Acesso total" no Master;
"N permissões" / "de TOTAL" nos demais; ausência de DESCRIÇÃO e SISTEMA. Ajustar testes antigos da lista que
conferiam as 7 colunas.
