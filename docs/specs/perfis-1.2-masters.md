# Perfis 1.2 · Masters primário e secundários (promover e remover Master)

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


Frente `perfis-masters` · base `origin/master` @ `1d3e664c` · risco **ALTO** (identidade User, poder global
do Master) · aberta em 19/10/2026.

## 1. Fontes

- Desenho aprovado: projeto Claude Design `4f6a5f61-…`, arquivo `05 - Perfis 1.2 - VS 18.10.26.dc.html`
  (cópia em `docs/design/perfis-1.2/`), aba "Permissões" do perfil Master: bloco "Masters do sistema"
  (linhas 317-336), confirmações `promover` e `rmMaster` (linha 624), lógica (linhas 641-652 e 742-743).
- Pedido do dono (19/10/2026): "que o usuário Farlei Assis da Rocha, atual Master do sistema, possa promover
  outro Master".

## 2. Decisão que muda

**D2 da spec `perfis-master-1.0.md` deixa de valer** ("Master é único" e o desvio §2.1 do bloco "Masters do
sistema"). Passa a valer o desenho 1.2:

| # | Decisão |
|---|---|
| M1 | Existem **Master primário** (um só, o criador do BlueJus) e **Masters secundários** (quem ele promover). |
| M2 | Todo Master tem `ROLE_SUPER_ADMIN` (os poderes de hoje, sem mudança). O primário tem **também** `ROLE_MASTER_PRIMARIO`, que só marca quem é o primário; não dá acesso a nada sozinho. |
| M3 | **Só o Master primário promove e remove** Masters secundários ("quem ele promover", texto do papel no desenho). O secundário vê a lista sem as ações. |
| M4 | O **Master primário nunca é removido** pela tela (cadeado). Trocar o primário continua sendo o comando `app:master:definir`, rodado pelo dono. |
| M5 | Só pode ser promovido um **usuário da conta** em que o Master primário está (vínculo ativo, conta do usuário ativa, ainda não Master). **Decisão do dono em 29/10 (revoga a versão anterior, que barrava o primário do escritório):** o primário do escritório (perfil de sistema) também pode ser promovido; as outras guardas ficam (só o Master primário promove, vínculo e conta ativos, não Master, mesmo escritório, auditoria). Desvio do desenho: rótulo "Escolha um usuário da conta *" (o desenho dizia "secundário") e, pelo mesmo motivo, a info "Precisa estar cadastrado antes como usuário da conta." No select, o primário do escritório aparece como "Usuário Primário". |
| M6 | Remover um secundário tira só o `ROLE_SUPER_ADMIN`; o vínculo dele com o escritório fica como está. |
| M7 | Promoção e remoção vão para a auditoria: `User` é `Auditavel`, o `AuditLogSubscriber` grava a troca de `roles` no flush. A troca grava sem escritório (`tenant_id` nulo, `EscopoDeAuditoriaDePlataforma`) e aparece só na trilha do Master, como "Plataforma (sem conta)", nunca na auditoria do escritório da sessão. |

**Falha conhecida, aceita pelo desenho:** `ROLE_SUPER_ADMIN` é bypass total no `PermissionChecker`
(`docs/AUTORIZACAO.md`). O Master secundário ganha esse mesmo bypass. O texto da confirmação diz isso ao
primário ("passará a ter todos os poderes do sistema").

## 3. Dado em produção (medido em 19/10)

Um único `ROLE_SUPER_ADMIN` em PROD (user id 1). A migration desta frente acrescenta `ROLE_MASTER_PRIMARIO`
a ele **só se houver exatamente um** `ROLE_SUPER_ADMIN` e nenhum `ROLE_MASTER_PRIMARIO`; com zero ou mais de
um, não faz nada (o dono resolve com `app:master:definir`). O `down()` tira o papel de todos.

## 4. Comportamento

### 4.1 Tela (aba Permissões de `/master`)

Bloco "Masters do sistema · N Master(s)", uma linha por Master, primário primeiro:

- avatar com iniciais (primário `#7a3e8f`, secundário `#a77bb8`), nome, e-mail e " · OAB n/UF" quando houver;
- selo `MASTER PRIMÁRIO` (branco sobre `#7a3e8f`) ou `MASTER SECUNDÁRIO` (`#7a3e8f` sobre `#f3e9f7`);
- primário: cadeado, title "O Master primário não pode ser excluído";
- secundário, **só para quem vê é o primário**: lixeira, title "Remover este Master", abre a confirmação.

Rodapé "Promover a Master" (**só para o primário**): select "Escolha um usuário da conta *" (M5) com
opções "Nome · Perfil"; ao lado, info "Precisa estar cadastrado antes como usuário secundário." (ou
"e-mail · OAB n/UF" / "e-mail · sem OAB" do escolhido); botão "Promover" (`bi-stars`, `#7a3e8f`). Mensagem
padrão: "Master secundário tem os poderes do sistema, mas não pode excluir o Master primário. Toda promoção e
remoção vai para a auditoria." Sem escolha: "Escolha um usuário secundário cadastrado." em vermelho.

Confirmações (modal interno, como `perfis.js`, botões "Cancelar" + ação):

| Tipo | Título · ícone · cor | Texto | Botão |
|---|---|---|---|
| promover | "Promover a Master" · `bi-stars` · `#7a3e8f` | `"X" passará a ter todos os poderes do sistema (contas, limites, planos, configurações e auditoria), como Master secundário. Ele não poderá excluir o Master primário.` | "Confirmar promoção" |
| remover | "Remover Master" · `bi-person-dash` · `#a3232b` | `"X" deixa de ser Master e perde os poderes do sistema. A remoção fica registrada na auditoria.` | "Remover Master" |

Também do desenho 1.2 na mesma aba: o item "Promover e remover Masters secundários" volta para "Poderes
exclusivos do Master", e o papel MASTER diz "O criador do BlueJus (Master primário) e quem ele promover
(Master secundário)."

Depois de promover ou remover, a página volta para `/master#aba=perm` (o painel abre na aba pelo hash, como
no desenho: `aba=(\w+)` e `sec=(\w+)`) com flash de sucesso ou do motivo da recusa.

### 4.2 Servidor

- `POST /master/masters/promover` (`master_promover`), campo `usuario` (id), CSRF `master_promover`.
- `POST /master/masters/{userId}/remover` (`master_remover`), CSRF `master_remover_{userId}`.
- As duas sob `ROLE_SUPER_ADMIN` na classe **e** a regra M3 no UseCase (secundário recebe recusa).
- Recusas (mensagem no flash): ator não é o primário; alvo inexistente, inativo, sem vínculo ativo com a conta
  do ator, ou já Master (promover); alvo não é Master ou é o primário (remover).
- Comandos: `app:master:definir` passa a mover também o `ROLE_MASTER_PRIMARIO` (o escolhido fica primário, os
  demais perdem os dois papéis, como hoje); `app:create-super-admin` cria o primeiro Master já primário.

## 5. Testes

- Unit dos dois UseCases: cada recusa da §4.2 e o caminho feliz (papéis gravados, flush).
- Functional: primário promove e remove; secundário recebe recusa nos dois POST; usuário comum recebe 403;
  CSRF inválido; HTML do primário tem o formulário e a lixeira, o do secundário não; selo PRIMÁRIO só no
  primário.
- Migration: conferida no banco da frente (um super admin ganha o papel; dois não ganham).

## 6. Fora desta frente

Aba "Telas e recursos", lista de perfis 1.2, "Comercial e e-mails", pedidos de exclusão, limites e suspensão de
conta: frentes próprias (ver o levantamento do 1.2 no PR).
