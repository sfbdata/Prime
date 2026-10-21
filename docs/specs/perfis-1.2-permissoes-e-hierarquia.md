# Perfis 1.2: número da conta, permissões do desenho de 21/10 e hierarquia de perfis

Risco: MÉDIO (TenantRole, Permission, quem pode editar perfil). Dono da frente: sessão b5.
Migration reservada: `Version20261020148000` (só na parte B, número dado pela coordenação em 21/10).

## Estado da frente

- 21/10: spec escrita a partir da investigação read-only. Divide-se em três partes que viram PRs separados, nesta
  ordem: A (número da conta), C (hierarquia de perfis), B (catálogo de permissões).
- **Para o dono (decisões abertas, marcadas com DONO abaixo):** B2 a B6. A, C e B1 seguem com a decisão proposta.
- 21/10: **A e C implementadas** na branch `master-perfis-hierarquia` (um commit, sem migration; a parte B não entrou).
  - A: `App\Tenant\Service\NumeroDaConta` (o `Solicitante::numeroDaConta()` da Contribua passou a usá-lo, mesmo
    formato); `ConsultarContasUseCase` devolve `numero`; `painel.js` com o selo "Nº 000123" (valores de L173), o
    placeholder "Buscar nº, conta ou primário" e a busca por número com ou sem zeros à esquerda.
  - C: `App\Tenant\Service\HierarquiaDePerfis` aplicado no `TenantRoleController` (lista, new, edit, delete) e no
    edit-role e no convite (subconjunto). Tela "Acesso restrito" (`tenant_role/acesso_restrito.html.twig`, 403) com
    "Pedir acesso" (`PedirAcessoAosPerfisUseCase`, rota `app_tenant_role_pedir_acesso`): o teto de 1 por pessoa por
    dia é o próprio aviso (link = edit-role de quem pediu), sem tabela nova.
  - Decisões dentro do escopo, a confirmar: (1) a tela do perfil primário continua aberta ao primário (C4, gestão
    de primários), embora o `podeMexer` do desenho (L723) dê nível igual ao primário sobre o perfil primário;
    (2) na lista, a lixeira de quem não pode aparece DESABILITADA (pedido da coordenação), onde o desenho (L65) a
    esconde; (3) "Novo Perfil" desabilitado para nível 1, com a mesma mensagem C6 (o desenho é omisso); (4) o aviso
    do "Pedir acesso" leva ao edit-role da pessoa, não à lista de Perfis ("Conceder em Perfis" no desenho).
  - Pendências conhecidas: o aceite do convite não revalida o subconjunto; o toast do desenho virou um aviso
    simples no rodapé (o markup do toast não está no arquivo baixado).
- 21/10 (correções da revisão, decisões da coordenação): a decisão (1) acima vai ao dono, sem mudança; as
  decisões (2), (3) e (4) foram trocadas:
  - lixeira OCULTA quando não pode excluir (desenho L65); lápis, linha e "Novo Perfil" com a aparência normal e o
    aviso C6 no clique (o servidor continua recusando);
  - o aviso do "Pedir acesso" leva à lista de Perfis com `?pedido=<id>` ("Conceder em Perfis", L822); a Central
    não tem campo de rótulo da ação no aviso (o menu mostra "Abrir"), então fica o rótulo do tipo, "Pedido de
    acesso";
  - edit-role: quem não é primário nem Master não troca a senha nem o perfil de quem tem perfil atual fora do
    subconjunto dele (nem rebaixa, nem deixa sem perfil);
  - busca do painel: só dígitos (com ou sem "nº"/"n°") casa o número exato; nome e primário por trecho;
  - o nível do ator sai uma vez por requisição (`HierarquiaDePerfis` guarda por usuário e escritório, `reset`).

## 1. Fontes

- Desenho: `docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html` (baixado truncado no fim do
  script; as partes usadas aqui estão inteiras): número da conta L173 e L752; `GRUPOS` das permissões L567-575;
  hierarquia L720-723 e L822-824; "Acesso restrito" L496 e L822. Versão anterior: `05 - Perfis 1.2 - VS 18.10.26`
  (GRUPOS em L472-479).
- `docs/AUTORIZACAO.md` (camadas, bypasses, perfil nulo nega tudo), `app/src/Tenant/Service/GruposDePermissaoDoPerfil.php`
  (`CARTOES`), `app/src/DataFixtures/PermissionFixture.php`, `app/src/Controller/TenantRoleController.php`,
  `app/src/Tenant/Service/RegraDosPrimarios.php`, `app/src/Contribua/DTO/Solicitante.php` (`numeroDaConta()`).

## Parte A. Número da conta (Contas e escritórios do Painel do Master)

- O desenho mostra um selo com o número da conta e a busca aceita o número ("Buscar nº, conta ou primário").
- A1. **Decisão proposta:** o número é o MESMO "conta Nº 000123" que o sistema já usa (id do escritório com 6 dígitos,
  `Solicitante::numeroDaConta()`), não a posição calculada na tela. Motivo: o desenho calcula a posição pela ordem de
  abertura, que muda quando uma conta é purgada e diverge do número que o Master já recebe nos avisos do Contribua e no
  txid do Pix. O id é estável e já é a ordem de criação (com buracos).
- A2. O cálculo sai do DTO da Contribua para um ponto comum (por exemplo `Tenant::getNumeroDaConta()` ou um serviço do
  domínio Tenant) e a Contribua passa a usá-lo, sem mudar o formato.
- A3. `ConsultarContasUseCase` devolve o número; `painel.js` mostra o selo (valores do desenho L173) e a busca casa o
  número digitado (com ou sem zeros à esquerda) além do nome e do primário.
- Sem migration. Testes: o número na saída, a busca por número, e o Contribua inalterado.

## Parte C. Hierarquia de perfis (lista de Perfis do escritório)

Regra do desenho (L720-724, L822-824): nível do perfil = 3 Master, 2 primário, 1 secundário; meu nível = 3 se sou
Master, 2 se sou primário, senão 1. Só mexe (edita, exclui) em perfil de nível ABAIXO do seu (o Master mexe em todos).
A lista mostra só perfis de nível até o meu. Quem não tem acesso vê a tela "Acesso restrito", com "Pedir acesso".

Hoje (escalada aberta): um secundário com `admin.roles.manage` edita qualquer perfil secundário, inclusive o próprio,
marca qualquer permissão, cria perfis com tudo e exclui o perfil de um colega. O `edit-role` (`admin.users.manage`) e
o convite (`admin.users.invite`) atribuem qualquer perfil secundário.

- C1. Serviço único `App\Tenant\Service\HierarquiaDePerfis`: `nivelDoAtor(User, Tenant)` (3 Master pela
  `RegraDosMasters`, 2 primário ativo pela `RegraDosPrimarios::podeGerirPrimarios`, senão 1) e
  `nivelDoPerfil(TenantRole)` (2 se `isSystem`, senão 1).
- C2. Ver a lista: nível >= 2 ou `admin.roles.manage`. Sem isso, a tela "Acesso restrito" (L496) em vez do 403 seco.
  "Pedir acesso" avisa os primários pela Central (`NotificacaoService`), com limite de 1 pedido por pessoa por dia.
- C3. Criar, editar e excluir perfil: só com nível do ator MAIOR que o do perfil, aplicado no SERVIDOR (new, edit,
  delete) e refletido na tela. Na prática: primário e Master mexem em secundários; o secundário com
  `admin.roles.manage` fica só com leitura da lista de secundários (é literalmente o desenho). Criar perfil novo
  (sempre secundário) exige nível >= 2.
- C4. O `isSystem` continua só pela gestão de primários (`podeGerirPrimarios`), sem mudança.
- C5. Mesma barreira no `edit-role` e no convite: quem não é primário nem Master não troca o próprio perfil e só
  atribui perfis cujas permissões estão contidas nas dele (subconjunto). Primário e Master atribuem qualquer secundário.
- C6. Mensagens do desenho: "Seu perfil não pode editar um perfil do mesmo nível ou acima." (sem travessão).
- C7. Testes: secundário com `admin.roles.manage` recebe 403 ao editar, excluir e criar, inclusive no próprio perfil;
  primário edita secundário; Master edita tudo; "Acesso restrito" para quem não tem acesso; `edit-role` e convite
  recusam perfil acima do subconjunto e a troca do próprio perfil; os testes antigos do TenantRoleController continuam
  verdes onde o ator é primário.
- Sem migration.

## Parte B. Catálogo de permissões (aba Permissões do Editar Perfil)

O desenho de 21/10 tem 48 permissões: Administração (10, todas existem), Módulos (22), Recursos de Cliente, Pasta e
Processo (9, existem), Outros recursos (3, existem) e o grupo novo "Recursos · Documento" (4). O sistema tem 38, e
`resources.agenda.ver_prazos` existe mas não está no desenho.

- B1. **Sem perguntar (existem e têm efeito):** reordenar o cartão Módulos como o desenho; incluir
  `modules.trabalho_externo.view` em `CARTOES` com o rótulo "Trabalho Externo (vagas e currículos)"; "Tarefas (visão
  do usuário)" e "Minhas Metas" (ver B4); manter `resources.agenda.ver_prazos` logo depois de Agenda (o desenho é
  omisso; tirar quebraria a Agenda).
- B2. **DONO:** Dashboard e Demandas como permissões próprias (`modules.dashboard.view`, `modules.demandas.view`).
  Hoje o Dashboard usa `modules.bi.view` e Demandas usa `modules.pastas.view`. Proposta: criar as duas, conceder na
  MESMA migration a todo perfil que tem o padrinho (bi, pastas) e aos `isSystem`, e trocar a checagem no mesmo PR.
  Assim ninguém perde acesso; "BI (futuro)" fica como está, sem efeito.
- B3. **DONO:** "Documentos" (módulo), "Planilha financeira (FinBlue)" e "Chat I.A". Documentos não tem tela própria
  (é aba da pasta), o FinBlue não existe no BlueJus, e o Chat I.A (Central) não usa permissão por decisão (D3 da
  central-comunicacao-a1: vínculo ativo). Criar essas caixas deixaria permissões SEM EFEITO que enganam quem configura.
  Proposta: não criar; ou criar e mostrar "(em breve)" desabilitado; ou, para o Chat I.A, criar a permissão de verdade
  (exige rever a D3 e conceder a todos os perfis).
- B4. **DONO:** "Minhas Metas" e "Tarefas (visão do usuário)" são a mesma permissão hoje (`modules.tarefas.view`).
  Proposta: um item só, rotulado "Minhas Metas (tarefas)", em vez de duas caixas que marcam a mesma coisa.
- B5. **DONO:** "Recursos · Documento" (Visualizar, Baixar, Enviar, Excluir). Hoje o documento herda tudo da pasta.
  Fazer valer exige guarda nova em todas as portas de documento (ver, baixar, zip, copiar, exportar, Peticionar, a
  leitura pela IA, o Drive e o link externo do Push), conceder a TODOS os perfis existentes na migration (para ninguém
  perder acesso) e decidir se vale para documento de cliente e de cobrança. É uma frente própria, maior.
  Proposta: frente separada depois desta, com spec própria.
- B6. **DONO:** "Peticionar" como permissão própria (`modules.peticionar.view`), concedida a quem tem
  `resources.pasta.edit` e aos `isSystem`, com guarda nova nas rotas `pasta_peticionar*`. Proposta: criar.
- B7. Concessão: o padrão do `Version20261020134100` (permissão com `WHERE NOT EXISTS`, concessão com
  `ON CONFLICT DO NOTHING` a `isSystem` e a quem tem o padrinho), constantes iguais às da `PermissionFixture` (teste
  que amarra) e o `down` desfazendo na ordem. Vínculo com perfil nulo continua negando tudo de perfil (AUTORIZACAO §2b).
- B8. Testes: a tela mostra os cartões na ordem do desenho; cada permissão nova concedida aos perfis certos pela
  migration; a checagem trocada não tira acesso de quem tinha o padrinho; fixture e migration com os mesmos códigos.
