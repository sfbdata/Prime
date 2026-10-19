# Perfis 1.2 · Masters primário e secundários (promover e remover Master)

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
| M5 | Só pode ser promovido um **usuário secundário da conta** em que o Master primário está (vínculo ativo, perfil que não é de sistema, conta do usuário ativa, ainda não Master). Rótulo do desenho: "Escolha um usuário secundário da conta *". |
| M6 | Remover um secundário tira só o `ROLE_SUPER_ADMIN`; o vínculo dele com o escritório fica como está. |
| M7 | Promoção e remoção vão para a auditoria: `User` é `Auditavel`, o `AuditLogSubscriber` grava a troca de `roles` no flush. |

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

Rodapé "Promover a Master" (**só para o primário**): select "Escolha um usuário secundário da conta *" com
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
  do ator, primário do escritório, ou já Master (promover); alvo não é Master ou é o primário (remover).
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
