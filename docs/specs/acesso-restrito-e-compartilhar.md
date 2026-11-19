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

#### 4.5.1 Adendo da fatia 9 (18/11, revisto após a re-revisão): abrir a pasta recebida

**Substitui** o terceiro item do §4.5 (`_sem_escritorio` e filtro ligado no escritório da pasta): a sessão continua
no escritório do destino (B) e a pasta de A é lida por um leitor próprio. Consequência aceita: quem não está em
nenhum escritório ativo (sem vínculo, ou com B suspenso) cai na escolha de escritório e não abre a recebida.

**Por que não `pasta_show`.** Investigação de 18/11: `pasta_show` e as rotas que a página chama (mensagem, andamento,
upload, documento, ZIP, metas) leem tudo pelo escritório da sessão (`TenantContext`, `PermissionChecker` exige
`UserTenant` ativo no escritório da pasta, `PastaDaRota`/`#[PastaPorId]` só acham pasta do escritório da sessão,
`can_access_*` no Twig), e a página tem links para cliente, processo, metas, cobrança, push e vizinhas ‹ ›. Abrir a
pasta de A ali pede desligar o filtro em dezenas de pontos ou dar vínculo em A: os dois furam a regra "nunca abre
outra pasta, cliente, processo ou tela de A". O molde do Trabalho Externo também não serve como está: lá a
`DemandaExterna` não é `TenantAware`; aqui `Pasta`, `PastaDocumento`, `PastaAndamento` e as metas são.

**Desenho.** "Abrir pasta" (dc `rvAbrir`) e "Abrir metas" (`rvMetasIr`) apontam para a página da pasta (02), que não
tem tratamento de pasta recebida (conferido em `redesign-04-11-26/02 - EXPEDIENTES - VS 31.10.26`). A pasta recebida
abre numa **página própria e reduzida**, com os componentes visuais da página da pasta (cabeçalho, abas Andamentos,
Documentos e Metas), sem nada que leve a outra tela de A. Desvio necessário (regra 3 de 09/11), **aceito pelo
Samuel em 18/11** (com o teto da origem e a divisão 9a/9b). ZIP fica fora (a recebida do desenho não o mostra).

**O teto é a origem (regra nova, pela recomendação).** B nunca faz mais do que quem republicou, e só enquanto quem
republicou ainda poderia republicar. Por isso o localizador confere, a cada requisição:

1. o compartilhamento: destino = usuário logado; `interno = false`; status `Aceita`; não venceu;
2. a pasta: não excluída; **sem cadeado ativo** (qualquer modo: o destino não tem vínculo em A e não pode estar na
   lista do cadeado; D3 "compartilhamento nunca fura o cadeado");
3. o escritório A: ativo (suspenso: só leitura, como o `donoForaDoAr` do Trabalho Externo; excluído: 404);
4. a origem: conta ativa (`User::isActive`), vínculo ativo em A, não restrita lá, e `AcessoAPasta::podeEditar(origem, pasta)` (a mesma regra de quem
   republica); se a origem sai de A, perde a edição ou vira restrita, a recebida fecha sozinha;
5. o destino: **sem** vínculo ativo em A (quem entrou em A depois usa o acesso de lá, com o escopo de lá).

Falhou qualquer um: 404, sem dizer por quê. Tudo isso roda com o filtro desligado (o cadeado e o vínculo de A ficam
invisíveis com o filtro em B: lido com filtro, "sem cadeado" seria falso positivo). O localizador é a **primeira**
consulta de A na requisição: `PermissionChecker`, `EscopoDePastas` e `VisibilidadeDasAbasDaPasta` guardam o resultado
por pessoa e escritório durante a requisição, e um resultado nascido com o filtro de B ligado ("sem regra", "sem
vínculo") valeria até o fim dela.

**Abas.** Some para B, na página e nas rotas dela, a aba oculta para a origem
(`VisibilidadeDasAbasDaPasta::ocultaPara(pasta, aba, origem)`) **e** a aba com a regra "para todos" ligada, mesmo que
a origem seja primária ou Master (quem configura vê a própria aba oculta; B não configura nada em A).

**Uma porta só.** Toda rota nova tem nome `expediente_recebida_*` (prefixo liberado pela barreira do restrito, fora de
`pasta_*`, logo fora de `PastaDaRota`, do cadeado e da aba oculta, que o localizador substitui), caminho
`/expediente/recebidas/{compartilhamentoId}/…`, e a primeira coisa que faz é
`PastaRecebidaDoUsuario::localizar(compartilhamentoId, usuário logado)`. Um teste de arquitetura (molde
`TrabalhoExternoSemEscritorioArquiteturaTest`) recusa rota `expediente_recebida*` cuja ação não chame o localizador,
com duas exceções nomeadas: `expediente_recebidas` (a caixa) e `expediente_recebida_responder` (aceitar um pendente).

**Leitura.** O leitor (`LeitorDaPastaRecebida`) desliga o `TenantFilter` (`SemFiltroDeTenant`) durante a leitura
**e a montagem do DTO** (proxies preguiçosos morrem com o filtro ligado em B) e filtra **sempre** por `tenant = A` **e**
`pasta = a do compartilhamento`; nenhuma consulta por id solto. Devolve DTO (nunca entidade ao Twig): NUP, cliente,
ação, fase atual, andamentos e anotações, documentos (nome, data, tamanho; sem os da lixeira) e metas abertas (título,
prazo, situação). Fora: link para cliente, processo, cobrança, push, IA, marcadores, responsável, atribuíveis,
vizinhas e "N de M".

**Rotas (fatia 9a, leitura).**

| Nome | Método e caminho | Exige | Faz |
|---|---|---|---|
| `expediente_recebida_ver` | GET `…/{compartilhamentoId}` | localizar | JSON da janela L11: de, conta, cliente, ação, permissões, número de metas abertas, só leitura |
| `expediente_recebida_pasta` | GET `…/{compartilhamentoId}/pasta` | localizar | página reduzida (`?aba=metas` para "Abrir metas") |
| `expediente_recebida_documento` | GET `…/{compartilhamentoId}/documento/{documentoId}/{modo}` | localizar + documento desta pasta, fora da lixeira, aba Documentos visível; `baixar` exige `baixarDocumentos` | `ver` mostra (o desenho dá "Ver a pasta e os documentos" a todo convite, como o `pasta_documento_view` interno); `baixar` entrega o arquivo pela chave |

**Fatia 9b (escrita): mini-adendo (18/11, pela recomendação).** Investigação de 18/11: nenhuma escrita da pasta
funciona com a sessão em B como está (as rotas leem `TenantContext` e `canAccessResource` exige vínculo em A; com o
filtro de B ligado, o prazo do texto não nasce, a fase cria andamento duplicado, o `refresh` com trava não acha a
linha e o código do documento perde a unicidade). Por isso:

- **Molde comum.** Toda rota de escrita é POST com CSRF, nome `expediente_recebida_*`, chama primeiro o localizador e
  recusa (403) com A suspenso (`soLeitura`) ou sem a caixa do convite. O trabalho **e o flush** rodam inteiros dentro
  de `semFiltroDeTenant`, sempre com o escritório A e a pasta do convite que o localizador devolveu (nada por id
  solto: resposta, documento e fase são lidos com `tenant = A` e `pasta = a do convite`). A auditoria vai para A, com
  o ator de B (a trilha da outra conta que A precisa ver).
- **`expediente_recebida_anotar`** (POST `…/{compartilhamentoId}/anotacao`; caixa `movimentar`; aba Dados visível):
  `EnviarMensagemPastaUseCase::executar(pasta, B, conteúdo, A, null)`. Só anotação nova (responder a registro fica
  fora). Os tokens de menção (`@[..](user:ID)`) saem do texto antes: B não conhece nem notifica a equipe de A. O
  prazo escrito no texto nasce como hoje (`PrazosDosRegistrosListener`, que precisa do flush sem filtro). O aviso a
  quem acompanha a pasta segue a regra de hoje, calculado sem filtro (quem desligou o acompanhamento não recebe).
- **`expediente_recebida_fase`** (POST `…/{compartilhamentoId}/fase`; caixa `movimentar`): UseCase próprio e fino
  sobre `AndamentoDaPasta::marcar(pasta, B, A, fase, semPular, motivo, sincronizarContrato: false)` (o
  `MudarFaseDaPastaUseCase` barra quem não tem vínculo, e a sincronização do contrato mexeria no Financeiro, que fica
  fora para B).
- **`expediente_recebida_documento_enviar`** (POST `…/{compartilhamentoId}/documento`; caixa `enviarDocumentos`; aba
  Documentos visível): só arquivo (sem Drive, texto, imagem nem seção), pelo `UploadPecaUseCase` com o escritório A e
  dentro de `TransacaoComArquivoNovo` (o arquivo só é apagado se o COMMIT comprovadamente não aconteceu); categoria
  de contrato e as do Financeiro recusadas. O aviso a quem acompanha sai depois do COMMIT, sem filtro. O envio ao
  Drive de A segue o que o `UploadPecaUseCase` já faz se o worker não depender do vínculo de quem enviou; se depender,
  fica fora e é registrado.
- **Metas: só leitura para B.** A regra D3 (só o responsável ou quem criou conclui e reabre a meta) continua valendo,
  e B nunca é nenhum dos dois. O "movimentar metas" do desenho fica coberto pela anotação e pela fase; concluir e
  reabrir meta por outra conta, se o dono quiser, é decisão de negócio nova.
- **Tela.** A página reduzida ganha, só com a caixa marcada: o campo de anotação (aba Andamentos), a troca de fase
  (cabeçalho) e o envio de arquivo (aba Documentos), com os componentes da página da pasta. Sem caixa, nada aparece.
- **Testes obrigatórios (9b).** Cada rota: grava em A com a caixa; 403 sem a caixa, com A suspenso e sem CSRF; 404
  nos casos do localizador; anotação com prazo no texto cria o prazo; menção some e ninguém de A é notificado por
  ela; fase não duplica andamento e não mexe no contrato; documento grava a chave pelo escritório A, código único com
  outro documento de A, contrato recusado, arquivo apagado se o flush falha; auditoria em A com o ator de B; filtro
  de B religado depois de cada escrita, inclusive em erro.

**Lista (L2, L12).** Como no desenho (`rpRows()` concatena as recebidas às linhas), as pastas que o localizador abre
entram **na própria Lista** do destinatário, no topo, com a mesma linha, a marca "Recebida · <conta>", a dica L2 e o
responsável "<origem> (dono)"; clicar abre a janela L11. A ordem (no topo, fora da ordenação e da paginação do
acervo) é o único desvio. A fonte é o JSON `expediente_recebidas`, que passa a marcar em cada item `abre`
(o mesmo critério do localizador, conferido em lote: cadeados, vínculos e contas das origens numa consulta cada,
para os até 100 itens da caixa); a Lista só mostra os que abrem, e "Ver pasta" da caixa só aparece neles. Não
entram no `findByFilters` (escopado ao escritório B). Limites da 9a: o restrito em B não tem o acervo geral, então
chega à recebida só pela caixa "Recebidas" ("Ver pasta"); com a Lista vazia (sem tabela) as linhas não aparecem, e a
caixa continua sendo o caminho.

**Trilha (§4.6).** Abrir a página, `ver` e `baixar` gravam `leitura` no `audit_log` de A (`TrilhaDoAcessoRestrito`
com o escritório A, só ids, rota, IP e o usuário de B); o 403 de `baixar` sem a caixa grava `negado`. A recusa do
localizador não grava (não há pasta a quem mostrar). A janela L11 (`expediente_recebida_ver`) não grava: é um
resumo (permissões e contagem de metas), não leitura do conteúdo (decisão de 18/11, pela recomendação).

**Cobrança fica fora.** Contrato de honorários e qualquer documento da aba Financeiro nunca aparecem para B, nem com a
aba visível (decisão de 18/11, pela recomendação). Na caixa "Recebidas", ação, prioridade, situação e metas só saem
no item que abre.

**Testes obrigatórios (9a).**
- Abre: convite aceito, página, `expediente_recebida_ver`, `ver` e `baixar` com a caixa; restrito em B também abre.
- 404 na página, no `ver` da janela e no documento: pendente, recusado, encerrado, vencido, de outra pessoa, pasta
  excluída, pasta com cadeado (`Todos` e `PorPessoa`), A excluído, origem removida ou inativa em A, origem sem edição,
  origem restrita, origem com a conta desativada, destino com vínculo ativo em A.
- A suspenso: abre só leitura.
- Aba "para todos" oculta por origem primária: some para B.
- Documento de outra pasta de A pelo mesmo compartilhamento, documento na lixeira e documento de aba oculta: 404;
  `baixar` sem `baixarDocumentos`: 403 e linha `negado` em A.
- O leitor não traz andamento, meta ou documento de outra pasta de A nem de B com o mesmo id; o filtro volta ligado
  em B depois de cada leitura.
- A página não tem link para `cliente_*`, `processo_*`, `tarefa_*`, `cobranca_*`, `push_*`.
- A trilha em A registra a leitura; ninguém de B sem convite chega a nada; a Lista e a caixa só mostram `abre` certo.

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
| 9 | Outra conta: abrir a pasta recebida | ALTO | não | Adendo §4.5.1. 9a: localizador, leitura, página reduzida, documento, L2, L11, L12, trilha. 9b: anotar e enviar documento. Re-revisão em cada uma |

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
- 18/11: fatia 4 (tela de Perfis). Cartão "ACESSO RESTRITO ÀS PASTAS SOB RESPONSABILIDADE" no formulário do
  secundário (P1 a P4, P6 a P9), com as 8 regras, a IDENTIFICAÇÃO DO PERFIL (os 5 rótulos do desenho) e o aviso;
  ligado, os itens fora de `PermissoesDoAcessoRestrito::LIBERADAS` ficam desmarcados e travados (cadeado), o
  grupo todo travado mostra "Bloqueado no acesso restrito" com opacidade .6 e os grupos de Cliente, Pasta e
  Processo ganham a faixa "Vale só para as pastas sob responsabilidade do usuário". Desligar devolve as marcações de
  antes (só na tela). O servidor decide: ligado, grava só as liberadas. Lista: selo com o texto fixo do desenho
  (L63; o desenho manda sobre o "rótulo em maiúsculas" da P10) e descrição vazia "Acesso restrito às pastas sob
  responsabilidade" (P11). P12: rótulo em maiúsculas (badge do padrão da tela, sem desenho) na lista de usuários e
  na ficha do usuário. Simular fora (Q1).
- 18/11: fatia 5a (servidor do compartilhar com a equipe; migration `Version20261118110000`, tabela
  `pasta_compartilhamento`). O escopo do restrito soma as pastas compartilhadas e ativas (aceito e dentro da
  validade, sem job). Caixas: sem "Abrir e movimentar" o restrito fica em `Visualizar` (`NivelNoCadeado`), "Baixar
  documentos" e "Enviar documentos" valem por rota na barreira do cadeado (`ROTAS_DE_BAIXAR`, `ROTAS_DE_ENVIAR`; o
  envio passa por cima do `Visualizar` do compartilhamento só se o cadeado deixaria editar), recusa com "O
  compartilhamento desta pasta não permite esta ação.". Decisão: as caixas só limitam quem chega à pasta pelo
  compartilhamento (o restrito que não é o responsável); para quem não é restrito o compartilhamento vale só como
  ver a pasta (§4.4). Rotas JSON `pasta_compartilhamento_ver|criar|encerrar` (o GET devolve o token dos POSTs).
  Encerrar: quem compartilhou, o responsável, o destino (deixar de colaborar), o primário e o Master. A tela (L1,
  L3, L4, L5, L6) vem na 5b.
- 18/11: fatia 5b (tela do compartilhar na Lista do Expediente). Ícone da linha (L1: verde com a contagem e a dica
  "Compartilhada com N colaborador(es)", só para quem pode compartilhar; o restrito não vê), janela Compartilhar
  pasta (L3 a L6: equipe sem o responsável e sem quem pede, os 3 recentes, as caixas, validade, mensagem, quem
  colabora e o histórico) e a seção COMPARTILHAR do menu ⋮ (1 clique com um recente e "Deixar de colaborar" para
  quem recebeu). Decisões, todas seguindo o desenho: (1) validade num select "Sem prazo / 7 / 30 / 90 dias"; (2) o
  1 clique dá movimentar e enviar documentos, sem baixar (L830; `PermissoesDoCompartilhamento::umClique()` alinhado);
  (3) dicas com `data-tip`, não `title`; (4) o menu reabre sozinho quando chega o GET da pasta; (5) o GET marca o
  compartilhamento de quem pergunta (`meu`) e o "Deixar de colaborar" usa esse, nunca outro que a pessoa apenas
  possa encerrar; (6) Demandas fica sem a mudança (o desenho é da Lista), o ícone verde do recebido continua.
  Revisão da 5b, corrigido: os recentes do 1 clique só trazem quem pode receber (vínculo ativo, sem o responsável e
  sem quem pede) e ainda não colabora na pasta (L826-827); quem não pode compartilhar (o destino, inclusive o
  restrito) recebe no GET só a própria linha; o ícone da linha só sai para quem edita a pasta (`pastasEditaveis`,
  mesmo critério do servidor); as cores do JS e da janela passaram para os tokens `--cp-*` com versão escura.
  Ficam para depois: "Ver permissões e metas" da recebida (janela L11, fatia 9); "Outra conta (código)" desabilitado
  com a dica "Em breve" até a fatia 8 (texto fora do desenho, sai na fatia 8); armadilha de foco da janela e aviso
  quando o GET do menu falha (baixos).
- 18/11: fatia 6 (trilha do restrito, regra 7). `TrilhaDoAcessoRestrito` grava direto no `audit_log` (pela conexão,
  fica mesmo com 404 ou 403): ação `leitura` ao abrir a pasta (`pasta_show`, download de documento, ZIP e documento
  do financeiro, só GET) e `negado` em toda recusa: pasta fora do escopo (404) e recusa do cadeado ou do
  compartilhamento (`BarreiraDoCadeadoListener`), tela "Fora do seu acesso" (`entity_class` = `rota`) e cliente ou
  processo fora do escopo (`BarreiraDoAcessoRestritoListener`, com a classe e o id). `changes` só com o evento, a rota
  e o método; IP e escritório nas colunas próprias. Quem não é restrito não ganha trilha. A trilha de quem entra pela
  outra conta vem com a fatia 9.
- 18/11: fatia 8 (outra conta: código e convite; migration `Version20261118120000`, `user.codigo_pessoal`). Código
  pessoal sorteado na primeira vez que a pessoa o vê (`CodigoPessoal::garantir`), único também contra o código da
  conta (as duas gerações conferem as duas colunas) e fora da auditoria. Aba "Outra conta (código)" na janela, convite
  `Pendente` (`PastaCompartilhamento::externo`), caixa "Recebidas" (L9, L10) nas rotas `expediente_recebidas` e
  `expediente_recebida_responder` (aceitar, recusar, deixar; só o destino; alheio = 404). Decisões: (1) **o convite de
  outra conta não abre nada no escritório da pasta**: o escopo do restrito, o `PermissionChecker` e a barreira só
  olham `interno = true` (a abertura é da fatia 9, com o adendo); (2) o servidor não diz de quem é um código antes do
  envio (a tela mostra só "Código válido. O destinatário confirma ao aceitar."); depois do envio, quem republicou vê
  "Nome · Conta" (o código foi dado por ela); (3) código de quem tem qualquer vínculo com o escritório da pasta é
  recusado ("use Equipe do escritório"), e código inexistente tem mensagem própria (os dois textos fora do desenho,
  necessários); (4) o 1 clique para outra conta manda o `destinoId` e só vale para quem já recebeu desta pessoa por
  código neste escritório; (5) o aviso vai para o sino do primeiro escritório ativo do destino, sem número da pasta
  nem nome da conta, com link para `?recebidas=1`; a trilha da conta destino registra o aviso **sem** quem agiu na
  origem (e-mail, IP e rota vazios: `AuditLogSubscriber::avisoParaOutraConta`); (6) "Ver pasta" da caixa e a janela
  L11 ficam para a fatia 9; (7) como no desenho, a caixa só traz pendente e aceito (recusado e encerrado saem; estados
  Aguardando, Colaborando, Expirada) e quem recusou não volta ao 1 clique; (8) o aviso "Aceita: adicionada às suas
  pastas" é o texto do desenho e só fica verdadeiro com a fatia 9 (as duas vão juntas para o master).
