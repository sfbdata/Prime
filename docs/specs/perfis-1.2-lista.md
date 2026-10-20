# Perfis 1.2 · Lista de perfis do escritório

## Estado da frente (Perfis 1.2, sessão b5; atualizado em 19/10/2026)

**Decisões do dono**
- 19/10: o Master primário promove e remove Masters secundários (o D2 "Master é único" deixa de valer).
- 19/10: "o Master pode tudo".
- 19/10: excluir conta = quarentena de 365 dias, com o texto do modal "A conta X sai do ar agora e é apagada de vez em 365 dias. Até lá, pode ser recuperada pelo Master." e a confirmação "digite EXCLUIR".
- 19/10: planos Free 10 GB/R$ 0/3 usuários, Essencial 20/R$ 19,90/8, Profissional 50/R$ 39,90/15, Avançado 100/R$ 69,90/sem limite, editáveis pelo Master.
- 19/10: o bloqueio de envio sai desligado.
- 19/10: verificação visual no navegador autorizada (porta 8103, banco isolado).

**Decisões da coordenação**
- Q2 a Q4 da conta: a conta suspensa ocupa vaga; as rotas antigas recusam a conta do Master.
- Suspender a conta do Master é recusado (C5).
- C12 (bypass do Master em escritório suspenso): pendente do dono; recomendação: manter sem bypass.

**Contratos com outras sessões**
- 7d (Meu Perfil):
  - `PedidoDeExclusaoDeConta` (pendente | desistida | reativada | excluida), "suspensa a pedido" como estado próprio;
  - barreiras de acesso no listener na ordem conta → pedido → vínculo → situação;
  - o `ReativarContaUseCase` não mexe em pedido "excluida";
  - menu do avatar nos parciais `menu_usuario/*`;
  - inscrições de OAB: os três UseCases são deles.
- f6 (Contribua, #64):
  - `SituacaoDoEspaco`/`RetratoDoEspaco`, `DefinirLimiteDe{Armazenamento,Usuarios}UseCase`, `ConfigurarFaixasDoEspacoUseCase`, `AlterarPlanoUseCase`, `CatalogoDePlanos::todos()`;
  - a auditoria é envolvida pelo meu controller: `executarComoConta` para limites, plataforma para faixas e planos.
- 5d (Comercial e e-mails): `#sec=com`, parcial `master/_comercial.html.twig` (include ignore missing) e evento `mp:secao`.
- 4b: "agenda" não é chave do catálogo de Telas e recursos.

**PRs e ordem de merge**
- #52 Masters (base master; migration de dado `Version20261019120000`).
- #72 Contas: suspender, reativar e excluir (empilhado sobre o #52, sem migration).
- #59 Lista de perfis (base master, sem migration).
- #77 IP do cliente (base master, sem migration; depois do merge, o dono troca `SYMFONY_TRUSTED_PROXIES=127.0.0.1` na VPS).
- #56 Telas e recursos (empilhado sobre o #52; migration `Version20261019172536`).
- Ordem: #52 → #72; #59 e #77 a qualquer momento; #56 quando a fila de migrations chegar.

**Fila de migrations:** #52 → Push (0d) → #56 → PR 2 da 7d → C1 da f6. Antes do merge do #56, renomear a migration dele com o timestamp do momento, porque o #55 entra antes.

**Pendências**
- C12, que é do dono.
- Bloco "Pedidos de exclusão" (espera o PR 5 da 7d).
- Planos e limites (espera o #52 e o #72 no master e a f6 trazer o master para a contribua-cora). O rascunho da spec está em `docs/specs/perfis-1.2-planos-e-limites.md`, depois de criado; a referência visual está em `scratchpad/ref-planos/`.
- Religar a revisão de OAB para as inscrições suplementares (depois do PR 3 da 7d).

**Próximo passo:** cortar `perfis-planos` sobre a `contribua-cora` quando ela tiver o #52 e o #72. Até lá, manter as PRs atualizadas com o `origin/master` depois de cada merge.


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
