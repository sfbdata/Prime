# Acesso restrito às pastas sob responsabilidade e Compartilhar pasta

Frente `acesso-restrito` · base `origin/master` · aberta em 18/11/2026 a pedido do Coordenador (bluejus-c7).
Risco: **MÉDIO** no acesso restrito e no compartilhar com a equipe (perfil, permissão por pasta);
**ALTO** no compartilhar com outra conta (uma pessoa de um escritório lendo pasta de outro: identidade
User/Tenant). Esta etapa é só inventário e plano; nenhum código.

## 0. Fontes

- Desenho (Artefato `3c9fe618...`, versão 1794996999-9a41), cópia fiel em `docs/design/acesso-restrito-18-11-26/`:
  - `05 - Perfis 1.2 - VS 03.11.26.dc.html`: cartão "ACESSO RESTRITO ÀS PASTAS SOB RESPONSABILIDADE" no editor do
    perfil secundário, identificação do perfil, itens travados, selo na lista, "Simular acesso".
  - `03 - EXPEDIENTE - VS 30.10.26.dc.html` (Lista de pastas): ícone de compartilhar na linha, janela "Compartilhar
    pasta N", menu de contexto "COMPARTILHAR", botão e janela "Recebidas", janela "Pasta recebida N", confirmação de
    troca de responsável.
  - `ESPEC_ACESSO_RESTRITO.md` (texto do dono), `bj-escopo.js` e `bj-republicar.js` (regras do protótipo).
- A cópia de `docs/design/redesign-04-11-26/` dos mesmos dois desenhos é **anterior** (sem estas funções). Vale a
  desta pasta.
- Sistema: `docs/AUTORIZACAO.md` (§2 bypasses, §5 recursos, §7 cadeado e meta) e o mapa da investigação (§2 abaixo).

**Produção (18/11, só contagens):** 1.642 pastas em 7 escritórios; 369 sem responsável; 727 com responsável diferente
de quem criou. 38 perfis (29 de sistema). `resource_access`: 0 linhas.

## 1. Regras de negócio (do dono, consolidadas)

1. Perfil secundário ganha a opção "ACESSO RESTRITO ÀS PASTAS SOB RESPONSABILIDADE", desligada por padrão.
   Perfil de sistema (primário) e Master nunca são restritos.
2. Ligada: a pessoa só vê, abre, busca, baixa e movimenta as pastas em que é a responsável **ou** que foram
   compartilhadas com ela (com as metas delas). Pasta sem responsável fica fora.
3. Itens administrativos travados: Administração do escritório inteira, Outros recursos inteiro e os módulos Painel do
   Escritório, Push Processual, Demandas, Minhas Metas, Agenda, Peticionar, Financeiro, FinBlue, Gestão de Cobranças,
   Trabalho Externo, BlueJus IA, Kanban, Ponto, Service Desk, Tarefas e BI. Continuam configuráveis: Expediente,
   Pastas, Processos, Documentos e Clientes (Chat I.A travado, Q2), com Pasta, Documento, Cliente e Processo valendo só
   dentro das pastas da pessoa.
4. Identificação do perfil (Prospectador, Parceiro, Equipe comercial, Correspondente, Colaborador externo), em
   destaque na lista de perfis e no cadastro do usuário.
5. O restrito não troca o responsável de pasta nenhuma. A troca feita por outro desvincula o anterior: se ele for
   restrito, perde a pasta e as metas dela, salvo "Manter como colaborador" (vira compartilhamento interno).
6. Toda pasta nova nasce com quem a criou como responsável (abertura manual e importação em lote), para todos os
   perfis. Pasta antiga não muda.
7. Cada acesso a pasta e cada tentativa negada do restrito ficam na trilha (usuário, pasta, rota, IP, data e hora).
8. Compartilhar pasta, um só fluxo: **equipe** (vale na hora, sem aceite; é como o restrito recebe pasta de que não
   é responsável) ou **outra conta** pelo código pessoal `BJ-XXXX-XXXX-XXXX` (o destinatário aceita em "Recebidas").
   Permissões: ver pasta, documentos e metas (sempre); abrir e movimentar metas, andamentos e prazos; enviar
   documentos; baixar documentos. Validade opcional (sem prazo, 7, 30, 90 dias). O dono encerra quando quiser; o
   colaborador pode deixar a pasta. O compartilhamento nunca abre o resto da conta de origem.

## 2. O que o sistema já tem (inventário do lado do sistema)

| Peça | Onde | Serve para |
|---|---|---|
| `Pasta.responsavel` e `Pasta.criadoPor` (`?User`, nullable) | `app/src/Pasta/Entity/Pasta.php:143-149` | base do escopo |
| Criação manual: responsável só com `criadorComoResponsavel` (só a "Nova demanda") | `Pasta/UseCase/CriarPastaUseCase.php:45-48`, `Controller/PastaController.php:298` | regra 6 muda isto |
| Importação em lote: responsável nulo | `Expediente/Controller/ImportarLoteController.php:78-79` | regra 6 muda isto |
| Troca de responsável (PATCH, sem confirmação, cartão "resp" no Registro, `audit_log`) | `pasta_atualizar_responsavel` (`PastaController.php:1671`), `AlterarResponsavelDaPastaUseCase.php:32` | regra 5 |
| Cadeado (teto por pasta, `Oculto/Visualizar/Editar`), ponto único | `Pasta/Service/AcessoAPasta.php`, `NivelNoCadeado.php`, `BarreiraDoCadeadoListener`, `PastaDaRota`, `PermissionChecker::cabeNoCadeadoDaPasta` (:131) | **molde** do escopo: mesma barreira por rota e mesmo critério em lote |
| Escopo "ids ou null" já usado em lote | `BuscarRapidoUseCase` (:52-90), `Tarefa/Service/PastasVisiveisNaEsteira`, `Pasta/Service/PastasVisiveisNoRelatorio` | formato do serviço novo |
| Perfil: `TenantRole` (`name`, `description`, `isSystem`); catálogo de cartões | `Entity/Tenant/TenantRole.php`, `Tenant/Service/GruposDePermissaoDoPerfil.php:26-79`, `TenantRoleController`, `templates/tenant_role/*`, `public/js/perfis.js` | colunas novas e cartão |
| Corte de passes e de `admin.*` num modo | `Conector/Service/ModoDoConector.php`, `PermissionChecker.php:62/208/264` | molde do bloqueio de módulos e admin |
| Código `BJ-XXXX-XXXX-XXXX` **da conta** (escritório) | `Tenant.codigoConta`, `Tenant/Service/GeradorDeCodigoDaConta.php` | o desenho pede código **pessoal** (Q3) |
| Item compartilhado com quem não tem o módulo | `ResourceAccess` (view/edit/delete, sem validade; exige vínculo) | não serve para outra conta nem para as permissões do desenho |
| Convite entre contas, com aceite, vigência e permissões; rotas sem escritório | `TrabalhoExterno/Entity/ConviteDemanda.php`, `_sem_escritorio` + `FabricaDeContexto::contextoOuNulo()` | **molde** do compartilhar com outra conta |
| Trilha: `audit_log` (ator, IP, rota, escritório); negação já registrada assim | `Entity/Audit/AuditLog.php`, `Tenant/Service/RegistroDeAcessoNegadoAoEscritorio.php` | regra 7 reaproveita |
| Conector MCP e agente interno | `Conector/Service/PortaoDoConector.php`, `Inteligencia/Service/ConsultaDePastasDoAgente.php` | o escopo vale neles também |
| "Editar nome rápido", "Destacar linha", "Alterar marcador" na Lista | `public/js/expediente-lista.js` | já existem; fora desta frente |

**Não existe:** escopo "só minhas pastas", rótulo de perfil, código pessoal de usuário, compartilhamento de pasta,
caixa "Recebidas", confirmação na troca de responsável, trilha de leitura.

## 3. Inventário desenho x sistema

Legenda: **novo** = não existe; **muda** = existe e muda; **igual** = já existe como o desenho mostra.

### 3.1 Perfis (`05 - Perfis 1.2 - VS 03.11.26`)

| # | Região | Item do desenho | Valor do desenho | Sistema | Fatia |
|---|---|---|---|---|---|
| P1 | Editor do secundário | Cartão com caixa (20px, `accent-color:#c0392b`), escudo `bi-shield-lock-fill` 18px `#c0392b`, título 15px bold `#7a1b22` `letter-spacing:.4px`, chip ATIVADO/DESATIVADO (`#c0392b`/`#9aabb7`); fundo `#fff6f5`/`#fff`, borda 2px `#c0392b`/`#e3c6c6`, raio 4px, `padding:16px 20px` | CSS do `.dc.html` L189740+ | novo | 4 |
| P2 | idem | Texto "O usuário terá acesso exclusivamente às pastas em que estiver cadastrado como responsável, sem visualizar os demais arquivos e informações do escritório. Indicado para equipe comercial, prospectadores e parceiros externos." | marcação | novo | 4 |
| P3 | idem, ligado | Grade de 8 regras (ícone `#c0392b` 15px, texto 13px `#243845`), `grid auto-fit minmax(260px,1fr)`, borda `#f1d3d0` | `rsRegras` | novo | 4 |
| P4 | idem, ligado | "IDENTIFICAÇÃO DO PERFIL" (select 38px) com os 5 rótulos; dica "Aparece em destaque na lista de perfis e no cadastro do usuário..." | `ROTULOS` | novo | 4 |
| P5 | idem, ligado | "SIMULAR COMO" + botão "Simular acesso" (borda `#9a3d0b`) | `rsSimular` | novo | fora (Q1) |
| P6 | idem, ligado | Aviso "As permissões abaixo valem apenas dentro dessas pastas. Os itens administrativos ficam bloqueados enquanto o acesso restrito estiver ativo. Os perfis atuais não mudam." | marcação | novo | 4 |
| P7 | Cartões de permissão | Item travado: caixa desmarcada e desabilitada, texto `#9aabb7`, cadeado `bi-lock-fill` `#c0392b` 12px, `title` "Bloqueado no acesso restrito às pastas sob responsabilidade" | `TRAVA`, `travado()` | novo | 4 |
| P8 | idem | Grupo todo travado: "Bloqueado no acesso restrito" no lugar de "Marcar todos" (`#a3232b`), opacidade .6 | `tudoTrav` | novo | 4 |
| P9 | idem | Faixa no topo de Pasta, Documento, Cliente e Processo: "Vale só para as pastas sob responsabilidade do usuário" (+ " (clientes/documentos/processos dessas pastas)"), fundo `#fff6f5`, 12px bold `#a3232b` | `escopoTem`, `escopoTx` | novo | 4 |
| P10 | Lista de perfis | Selo com o rótulo em maiúsculas (`title` "Só vê as pastas em que é responsável") e botão de simular (`title` "Simular o acesso deste perfil") | `p.restr`, `simular` | novo | 4 (botão de simular fora, Q1) |
| P11 | Lista de perfis | Descrição do restrito: "Acesso restrito às pastas sob responsabilidade" no lugar de "Usuário secundário" | `salvar` | novo | 4 |
| P12 | Cadastro do usuário | Rótulo em destaque (regra 4; o desenho não traz a tela, o texto do dono sim) | ESPEC §1.3 | novo | 4 |
| P13 | Desenho do catálogo | Recurso "Documento" com Visualizar/Baixar/Enviar/Excluir | `GRUPOS` | o sistema não tem `resources.documento.*` | fora (ver §6) |

### 3.2 Lista de pastas (`03 - EXPEDIENTE - VS 30.10.26`)

| # | Região | Item do desenho | Sistema | Fatia |
|---|---|---|---|---|
| L1 | Linha | Ícone `bi-arrow-repeat` com contagem (`#1e7b45` com colaboradores, `#9aabb7` sem), `title` "Compartilhar pasta (equipe ou outra conta)" ou "Compartilhada com N colaborador(es) · clique para gerenciar"; some para o restrito e na pasta recebida | novo | 6 |
| L2 | Linha recebida | Marca "Recebida · <conta>" e dica "Republicada por X (conta). Você colabora nesta pasta com as permissões concedidas."; "Compartilhada com você" na interna | novo | 6 / 8 |
| L3 | Janela "Compartilhar pasta N" | "COMPARTILHAR COM": Equipe do escritório / Outra conta (código); "USUÁRIO DA EQUIPE" (lista sem o responsável) com dica "Sem aceite: a pasta aparece para ele na hora. Vale também para quem tem acesso restrito (parceiro, prospectador)."; "CÓDIGO DO USUÁRIO QUE VAI COLABORAR" (`BJ-XXXX-XXXX-XXXX`); "O QUE ELE PODE FAZER" (fixo + 3 caixas); "VALIDADE"; "MENSAGEM (OPCIONAL)" (300 caracteres); Fechar / Compartilhar; texto "O colaborador vê só esta pasta e as metas dela..." | novo | 6 (equipe), 8 (código) |
| L4 | idem | "Quem já colabora nesta pasta" com estado e "Encerrar acesso" | novo | 6 |
| L5 | idem | Erros: "Escolha o usuário da equipe.", "A pasta já está compartilhada com esse usuário.", "Código inválido. Use o formato BJ-XXXX-XXXX-XXXX.", "Este é o seu próprio código.", "Esta pasta já está republicada para esse usuário."; sucessos "Pasta compartilhada com X. Ela já aparece para ele, inclusive no acesso restrito." e "Pasta republicada. X recebe o convite e passa a colaborar quando aceitar."; "Código válido. O destinatário confirma ao aceitar." | novo | 6 / 8 |
| L6 | Menu de contexto | Seção "COMPARTILHAR": até 3 colaboradores recentes em "1 clique" (ver, metas e documentos), "Compartilhar com a equipe…", "Republicar para outra conta…", "Quem colabora", "Copiar meu código"; na recebida: "RECEBIDA DE <CONTA>", "Ver permissões e metas", "Deixar de colaborar" | novo | 6 / 8 |
| L7 | Menu "Alterar responsável" | Restrito: aviso "No acesso restrito, a pasta fica sob a sua responsabilidade. Peça ao escritório para transferir." | novo | 2 |
| L8 | Confirmação de troca | "Trocar o responsável da pasta N?", "X passa a ser o responsável. Se Y tiver acesso restrito, deixa de ver esta pasta e as metas dela.", caixa "Manter Y como colaborador (a pasta fica compartilhada com ele)", "A troca fica registrada no histórico da pasta.", Cancelar / Confirmar troca; borda superior `#c08a12`. Pasta sem responsável troca direto | muda (hoje troca sem confirmar) | 7 |
| L9 | Cabeçalho | Botão "Recebidas" com contagem de pendentes (`#e6f4ec`/`#1e7b45`) | novo | 8 |
| L10 | Janela "Pastas recebidas de outras contas" | "Seu código para receber pastas:" + "Copiar código"; vazio "Nenhuma pasta recebida ainda. Envie seu código a quem quiser republicar pastas para você."; cartão com "Republicada por", mensagem, estado (Aguardando você, Colaborando, Expirada, Acesso encerrado), Recusar / Aceitar e colaborar / Deixar de colaborar / Ver pasta | novo | 8 |
| L11 | Janela "Pasta recebida N" | permissões, metas abertas ("Nenhuma meta aberta"), "Abrir metas", "A pasta pertence à outra conta. Você colabora só nela, e o dono pode encerrar o acesso a qualquer momento.", Deixar de colaborar / Abrir pasta | novo | 9 |
| L12 | Lista | Pastas recebidas aceitas entram na lista do destinatário | novo | 9 |
| L13 | Simulação | Faixa "Simulação: você está vendo como X..." e tela "Fora do seu acesso" / "Pasta fora da sua responsabilidade" com "Ir para minhas pastas" | protótipo (ESPEC §4) | fora (Q1) |

## 4. Modelo e regra no servidor

### 4.1 Perfil

- `tenant_role.escopo_pasta` (`varchar`, `'todas'` padrão, ou `'responsavel'`) e `tenant_role.rotulo` (`varchar(40)`
  nulo). Enum PHP `EscopoDePastas` (`Todas`, `Responsavel`). Migration só acrescenta colunas com padrão: nenhum perfil
  muda. Perfil `isSystem` recusa `Responsavel` no UseCase (não só na tela).
- O escopo é do **vínculo** (`UserTenant.tenantRole`). Perfil nulo segue negando tudo (AUTORIZACAO §2).

### 4.2 Ponto único: `App\Pasta\Service\EscopoDePastas`

Mesmo formato dos serviços em lote que já existem:

- `restrito(User, Tenant): bool`; `idsNoEscopo(User, Tenant): ?array` (null = sem restrição; senão as pastas em que
  é responsável **mais** as compartilhadas ativas com ele); `noEscopo(User, Pasta): bool`;
  `criterioDql(alias, User, Tenant)` para combinar nas consultas (`p.responsavel = :u OR p.id IN (subconsulta dos
  compartilhamentos ativos)`).
- **Decisão de implementação: sem Doctrine SQL Filter.** O filtro valeria também para os proxies (`$doc->getPasta()`
  daria `EntityNotFoundException`), para jobs e relatórios do escritório, e não pega consulta de filha sem JOIN. O
  cadeado já provou o caminho explícito + teste de arquitetura.

### 4.3 Onde vale (mesmos trilhos do cadeado)

1. **Por id:** `NivelNoCadeado`/`AcessoAPasta::nivel` devolve `Oculto` para pasta fora do escopo do restrito (antes
   do cadeado; o cadeado só subtrai depois). Com isso `PermissionChecker::canAccessResource('pasta')`,
   `BarreiraDoCadeadoListener` (todas as rotas de pasta), `AcessoAMeta`, documento, ZIP, processo (todas as pastas
   ocultas = não abre) já recusam. Diferença do cadeado: para o restrito a pasta fora do escopo responde **404** e
   `pasta_show` não desenha "Pasta trancada" nem oferece "Pedir acesso".
2. **Listas e contagens:** a pasta fora do escopo **não aparece nem por número e cliente** (diferente da oculta pelo
   cadeado). Expediente (lista, acervo, trilho, CSV), busca Ctrl+K, lista de processos e de clientes (só os ligados
   às pastas do escopo), aba Metas da pasta, avisos e e-mails da pasta (`QuemAcompanhaAPasta`), relatórios de pastas
   paradas e sem processo, agente interno e conector MCP (`ConsultaDePastasDoAgente`).
3. **Módulos e admin travados:** `PermissionChecker` nega, para vínculo restrito, os `admin.*` e os `modules.*` da
   lista travada, igual ao corte do `ModoDoConector`. Mapa desenho x código fica na fatia 2 (atenção à F7: "Financeiro"
   usa a chave `clientes`).
4. **Ações do restrito:** recusa a troca de responsável (403 com a frase de L7) e o compartilhar.
5. **Teste de arquitetura:** toda rota de pasta passa pela barreira (o do cadeado já cobre); toda consulta de lista de
   pasta listada em §4.3.2 tem teste funcional "restrito não vê pasta alheia nem na contagem".

### 4.4 Compartilhamento

Tabela `pasta_compartilhamento`: `id`, `tenant_id` (conta de origem), `pasta_id`, `usuario_origem_id`,
`usuario_destino_id`, `interno` (bool), `pode_movimentar`, `pode_enviar_documentos`, `pode_baixar_documentos`,
`valido_ate` (date nula), `status` (`pendente`, `aceita`, `recusada`, `encerrada`), `mensagem` (300), `criado_em`,
`aceito_em`, `encerrado_em`, `encerrado_por_id`. Índice único parcial: um ativo por pasta + destino.

- **Interno:** nasce `aceita`. Destino precisa de vínculo ativo no mesmo escritório. Abre a pasta no escopo do
  restrito; para quem não é restrito vale como permissão de ver a pasta (como o `ResourceAccess`). **Nunca fura o
  cadeado** (teto continua teto). Ações dentro da pasta = permissões do perfil **e** as do compartilhamento.
- **Quem compartilha:** quem edita a pasta (`AcessoAPasta::podeEditar`) e não é restrito. Encerrar: quem compartilhou,
  o responsável, primário ou Master. Deixar: o próprio destino.
- **Validade:** vencida = inativa na hora (consulta compara com hoje); sem job.
- **Outra conta:** ver §4.5. Tudo grava em `audit_log` (entidade `Auditavel`).

### 4.5 Outra conta (ALTO)

- Código pessoal por usuário (Q3, decidido): `user.codigo_pessoal`, mesmo gerador e mesmo alfabeto do código da conta,
  único entre os dois (conferir as duas colunas). Mostrado em "Seu código" e "Copiar meu código".
- Convite `pendente` aparece em "Recebidas" do destino (aviso pela Central). Aceite exige o usuário logado dono do
  código.
- **Acesso sem trocar de escritório:** a pessoa de B não ganha vínculo em A. As rotas da pasta recebida seguem o molde
  do Trabalho Externo: atributo `_sem_escritorio`, contexto montado pelo compartilhamento aceito e vigente
  (`FabricaDeContexto`), TenantFilter ligado no escritório da pasta só dentro dessas rotas, e cada ação conferida
  contra as permissões do compartilhamento. Nunca abre outra pasta, cliente, processo ou tela de A.
- Antes de codar a fatia 9, adendo desta spec com as rotas exatas e re-revisão (risco ALTO).

### 4.6 Trilha (regra 7)

Só para vínculo restrito e para quem entra por compartilhamento de outra conta: uma linha em `audit_log` por
abertura de pasta (`pasta_show`, download, ZIP) e por recusa da barreira (ação `leitura` / `negado`, só ids, rota, IP).
Perfis sem restrição não ganham trilha de leitura (volume e nenhuma regra pede).

## 5. Fatias (PRs pequenos, em ordem)

Migration: fatias 2, 5 e 8 (uma por vez). A fatia 4 (tela) só sai depois da 3: ligar a opção antes de todo o servidor
aplicar o escopo daria a falsa impressão de restrição.

| # | PR | Risco | Migration | Conteúdo |
|---|---|---|---|---|
| 1 | Responsável automático | BAIXO | não | Regra 6: `CriarPastaUseCase` sempre grava o autor como responsável; importação em lote idem. Comandos de acervo e reconciliação continuam como estão. Teste unit + functional |
| 2 | Escopo no servidor (núcleo) | MÉDIO | sim | Colunas do §4.1, `EscopoDePastas`, teto em `NivelNoCadeado` (404, sem "Pedir acesso"), módulos e admin travados no `PermissionChecker`, Expediente (lista, acervo, trilho, CSV) e Ctrl+K, recusa da troca de responsável pelo restrito (L7). Sem tela: ninguém liga ainda |
| 3 | Escopo no servidor (resto) | MÉDIO | não | Processos, clientes, aba Metas, avisos e e-mails da pasta, relatórios, agente e conector (combinar com o PR #763), teste de arquitetura das listas |
| 4 | Tela de Perfis | MÉDIO | não | P1 a P12 fiéis ao desenho; rótulo no cadastro do usuário |
| 5 | Compartilhar com a equipe | MÉDIO | sim | Tabela §4.4, janela L3 (aba Equipe), L1, L4, L5, L6 (sem "outra conta"), escopo do restrito somando compartilhadas |
| 6 | Trilha do restrito | BAIXO | não | §4.6 |
| 7 | Troca de responsável com confirmação | MÉDIO | não | L8 com "Manter como colaborador" (compartilhamento interno), cartão no Registro |
| 8 | Outra conta: código e convite | ALTO | sim | Código pessoal, aba "Outra conta (código)", "Recebidas" (L9, L10), aceitar/recusar/deixar, aviso na Central |
| 9 | Outra conta: abrir a pasta recebida | ALTO | não | Adendo §4.5, rotas sem escritório, L2, L11, L12, permissões por ação, re-revisão |

## 5.1 Fatia 2 em detalhe (núcleo do escopo no servidor)

Migration `Version20261118100000` (número do Coordenador). Tudo abaixo vale só para vínculo cujo perfil tem
`escopo_pasta = 'responsavel'`; ninguém liga isso antes da fatia 4.

1. **Permissões por lista de liberação** (`App\Tenant\Service\PermissoesDoAcessoRestrito`, chamada no
   `PermissionChecker::hasPermissionFromUserTenant`, o ponto por onde passam módulo, admin e recurso): o restrito só
   tem, das permissões do perfil, `modules.{expediente,pastas,processos,clientes}.view` e
   `resources.{pasta,cliente,processo}.{view,edit,delete}`. Todo o resto é negado, inclusive código novo que entrar
   no catálogo depois (D4, Q2: BlueJus IA, Chat I.A, Voto, Assinatura, Agenda e `resources.agenda.ver_prazos`,
   Cobrança e "Outros recursos", todo `admin.*`). Lista de liberação, e não de bloqueio: o que ninguém lembrou fica
   fechado.
2. **Barreira de rotas** (`App\Pasta\EventListener\BarreiraDoAcessoRestritoListener`, `kernel.request`, depois do
   escritório da sessão): o restrito só alcança as rotas da Lista e da pasta (Expediente, pasta e filhas, processo,
   cliente), Meu Perfil, Contribua, sair e a troca de escritório; o resto recebe a tela "Fora do seu acesso" (403,
   textos do `bj-escopo.js`), e a página inicial manda para a Lista. É a segunda camada: módulo sem permissão já
   recusa, mas tela sem módulo (Painel, Central) não. Também passam o que não mostra dado do escritório (sino,
   convites recebidos, termos, política, páginas públicas). Dentro do Expediente ficam fechados os relatórios
   gerais (acervo geral, pastas paradas, relatório de processos), a lixeira, a importação em lote e o cadastro de
   marcadores; os convites da equipe também. Rota sem sessão (o conector MCP, por token) fica fora desta barreira: lá valem a lista de liberação e o teto do `NivelNoCadeado` com o escritório do token, e o resto do conector é da fatia 3.
3. **Teto por pasta** (`NivelNoCadeado`): para o restrito, pasta em que ele não é o responsável é `Oculto`, antes do
   cadeado. Por aí já fecham `canAccessResource`, `BarreiraDoCadeadoListener`, `AcessoAMeta`, documento, ZIP, e o
   processo cujas pastas são todas ocultas (K3). `idsDasPastasOcultas` passa a incluir as fora do escopo (metas,
   agenda, auditoria, processos). `pasta_show` responde 404 para o restrito em pasta fora do escopo (não desenha
   "Pasta trancada" nem oferece pedido).
4. **Listas**: na Lista do Expediente, no acervo, no trilho, no CSV e na busca Ctrl+K a pasta fora do escopo não
   aparece nem por número e cliente (`EscopoDePastas::restringir` no `PastaRepository` e no `BuscarRapidoUseCase`).
   As setas ‹ › e o "N de M" do cabeçalho da pasta andam só pelas pastas do restrito (senão mostrariam número e
   cliente da vizinha).
5. **Troca de responsável**: o restrito recebe 403 com "No acesso restrito, a pasta fica sob a sua responsabilidade.
   Peça ao escritório para transferir." (`AlterarResponsavelDaPastaUseCase`).
6. **Testes**: unit da lista de liberação; functional: restrito não abre pasta alheia (404), não a vê na Lista, no
   CSV, na contagem nem na busca; abre a própria; recebe "Fora do seu acesso" no Painel; não troca responsável;
   perfil comum sem mudança nenhuma.

## 6. Decisões de implementação (tomadas aqui; o Coordenador pode rever)

- D1. Sem SQL Filter (§4.2).
- D2. Fora do escopo do restrito = 404 sem "Pedir acesso" (não confirma que a pasta existe).
- D3. Compartilhamento nunca fura o cadeado; compartilhar exige editar a pasta.
- D4. Módulos que o desenho não lista (Voto, Assinatura eletrônica) ficam **travados** no restrito, como "Outros
  recursos" (o desenho é omisso; travar é o lado seguro).
- D5. O desenho mostra "Recursos · Documento" (Visualizar/Baixar/Enviar/Excluir), que o sistema não tem. Fica fora
  desta frente: as permissões de documento do restrito são as da pasta; as do compartilhamento valem por cima.
- D6. Trilha de leitura só para restrito e outra conta (§4.6).
- D7. Pastas sem responsável (369 em produção) seguem sem: só perfis sem restrição as veem.

## 7. Decisões de negócio (Coordenador, 18/11, pela recomendação)

Registro: `~/projetos/bluejus-coordenacao/decisoes-acesso-restrito-1811.md`.

- Q1. "Simular acesso" (P5, P10, L13): **não implementar** (a ESPEC diz "só no protótipo"; ver como outro usuário no
  servidor é risco). Sai das fatias.
- Q2. "Chat I.A (conversas da equipe)": **travado** no restrito. O texto do dono proíbe ver informação fora da
  permissão e prevalece sobre o desenho, que o deixava livre. Entra na lista travada da fatia 2 e no P7 da fatia 4.
- Q3. Código para receber pasta: **pessoal, por usuário** (novo), não o código da conta.

## 8. Estado da frente

- 18/11: inventário e plano (este arquivo, PR #768). Q1 a Q3 decididas. Fatia 1 liberada. Fatia 2: pedir o número da
  migration ao Coordenador. Fatia 3: só depois do smoke do conector (combina com o #763).
- 18/11: frente única na branch `acesso-restrito` (PR #770 em rascunho), um commit por fatia. Fatia 1 feita. Fatia 2
  feita (migration `Version20261118100000`); ninguém liga o restrito antes da fatia 4.
- 18/11: revisão de vazamento da fatia 2. Corrigidos nela: sugestões da Nova pasta (nome e total de pastas de
  cliente alheio), total do trilho e liberação item a item (`ResourceAccess`) que passava da lista de liberação.
- 18/11: fatia 2b (decisão do Coordenador: não esperar a fatia 3, que fica só com o conector). O restrito só
  alcança o cliente que está numa pasta dele (principal ou vinculado) e o processo ligado a uma pasta dele: o resto
  dá 404 em toda rota `cliente_*` e `processo_*` por id (inclusive documento do cliente e nota técnica), some da
  lista de processos (também o processo sem pasta) e da busca de cliente para vincular; nas telas do cliente e do
  processo a pasta alheia nem aparece. Rotas `pasta_*` por id de filha: cobertas pelo
  `BarreiraDoCadeadoRotasArquiteturaTest` e pelo 404 do escopo na barreira do cadeado. Ainda a conferir na fatia 3:
  o aviso de meta sem link no sino.
