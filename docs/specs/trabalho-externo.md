# Spec: Trabalho Externo (central de advocacia correspondente)

Frente `modulo-trabalho-externo`, base `origin/master` @ `a3919b5c`. Risco **MÉDIO** (módulo e permissão
novos, leitura entre escritórios) com uma fatia **ALTO** (F3, Convidado: identidade User/Tenant).

Desenho aprovado: `docs/design/trabalho-externo/06 - Trabalho Externo 1.0.dc.html` (Claude Designer,
projeto `4f6a5f61-…`, cópia local idêntica de 13/10; o MCP `claude_design` não estava conectado na sessão).
**O desenho manda** onde mostra algo; onde é omisso, vale a convenção do sistema.

## 1. Decisões do dono (15/10/2026)

| # | Decisão |
|---|---|
| D1 | Existe **um master global** do sistema, reconhecido pelo **`ROLE_SUPER_ADMIN`**. Vale para todos os efeitos globais. (Hoje o papel está com o Samuel; trocar é dado de produção, do dono.) |
| D2 | **Equipe** = colaboradores ativos do **escritório-sede da Central**, escolhido pelo Master. Os demais escritórios são Solicitantes. |
| D3 | **Convidado** acessa com conta: o link do convite leva ao cadastro se a pessoa ainda não tiver conta, ou ao login se já tiver. |
| D4 | **WhatsApp como no desenho**: contatos com consentimento, mensagens prontas por fase, envio manual pelo `wa.me`. Nenhuma API externa. O link da demanda exige login. |
| D5 | **Chat próprio da demanda**, sem depender da Central (`designer-central-ia`). |

## 2. Perfis (resolvidos no servidor, nunca escolhidos pelo navegador)

O seletor "Simula o acesso de cada perfil" do protótipo **não** existe no sistema: o perfil sai de quem está logado.

| Perfil | Quem | Vê |
|---|---|---|
| Master | usuário com `ROLE_SUPER_ADMIN`, em qualquer escritório | todas as demandas não-rascunho, de todos os escritórios |
| Equipe | colaborador ativo do escritório-sede com a permissão do módulo | o mesmo que o Master, se a configuração `equipe.ver` estiver ligada |
| Solicitante | usuário de qualquer outro escritório com a permissão do módulo | as demandas do **escritório atual** (inclusive rascunhos) |
| Convidado (F3) | usuário com convite aceito e vigente | só a(s) demanda(s) do convite, nas situações aceita, em execução, validação e concluída |

Sem a permissão `modules.trabalho_externo.view` (e sem ser Master ou Convidado): a tela redireciona com aviso e a API responde 403. Os administradores de
escritório (`TenantRole::isSystem`) já passam pelo bypass existente do `PermissionChecker`.

Enquanto o Master não definir a sede, ninguém é Equipe.

## 3. Isolamento

A demanda é a **única** entidade do sistema lida entre escritórios, e só pela Central. Por isso ela **não**
implementa `TenantAware` (o `TenantFilter` a esconderia do Master). Em compensação:

- ela guarda o escritório solicitante em `tenant` (coluna `tenant_id`, não nula);
- todo acesso passa pelo `DemandaExternaRepository`, que só tem consultas explicitamente escopadas:
  `daCentral()`/`daCentralPorId()` (Master e Equipe) e `doEscritorio()`/`doEscritorioPorId()` (Solicitante);
  o Convidado chega pela demanda do próprio convite (`ConviteDemandaRepository::vigentesDo`);
- busca por código **sempre** passa pelo `LocalizadorDeDemanda`, que confere `PoliticaDaDemanda::podeVer`;
  demanda de outro escritório responde **404** (não 403, para não confirmar que existe);
- filhos (histórico, mensagens, propostas, documentos, contatos) só são lidos a partir da demanda;
- teste funcional de isolamento: Solicitante do escritório B não lista, não abre, não age e não baixa
  documento da demanda do escritório A; Equipe de escritório que não é a sede não vê nada da Central.

## 4. Situações e transições

Situações: `rascunho, nova, analise, info, proposta, aceita, execucao, validacao, concluida, arquivada,
recusada, cancelada`. "Pagamento registrado" não é situação: é o marco `pagoEm` + linha `pago` no histórico.

Fechadas (contam como encerradas no prazo e nos KPIs): `concluida, arquivada, recusada, cancelada, rascunho`.

| Ação | Quem | De | Para | Motivo |
|---|---|---|---|---|
| Enviar solicitação | Solicitante | (nova) / rascunho | nova | não; valida obrigatórios; bloqueado com a central suspensa e `bloquearSuspenso` |
| Salvar rascunho / continuar | Solicitante | (nova) / rascunho | rascunho | não |
| Excluir rascunho | Solicitante | rascunho | (apaga) | não |
| Iniciar análise | Interno¹ | nova | analise | não |
| Recusar demanda | Interno¹ | nova, analise | recusada | sim |
| Solicitar informações | Interno¹ | analise | info | sim |
| Retomar análise | Interno¹ | info | analise | não |
| Enviar informações | Solicitante | info | analise | sim |
| Enviar proposta (v1, v2…) | Interno² | analise, proposta | proposta | valor obrigatório; anteriores `enviada`/`ajustes` viram `substituida` |
| Aceitar proposta | Solicitante | proposta | aceita | não |
| Solicitar ajustes | Solicitante | proposta | analise | sim |
| Recusar proposta | Solicitante | proposta | recusada | sim |
| Cancelar | Solicitante: nova, analise, info · Interno¹: proposta | | cancelada | sim |
| Iniciar execução | Interno¹ | aceita | execucao | não |
| Enviar para validação | Interno¹ · Convidado com `concluir` | execucao | validacao | não |
| Devolver para execução | Interno¹ | validacao | execucao | sim |
| Concluir serviço | Interno¹ | validacao | concluida | não |
| Registrar pagamento | Interno¹ | aceita, execucao, validacao, concluida (sem `pagoEm`) | (marco) | não |
| Arquivar | Interno¹ | concluida | arquivada | não |
| Reabrir | Interno¹ | concluida, arquivada, recusada, cancelada | analise | sim |
| Informar impedimento / Solicitar prorrogação | Convidado | execucao | (sem mudança) | sim |

¹ Master sempre; Equipe só com `equipe.situacao`. ² Master; Equipe com `equipe.proposta`.
Convidar/revogar (F3): Master; Equipe com `equipe.convidar`; em aceita e execucao.

Toda ação confirmada grava histórico (de, para, quem, quando, motivo) e uma mensagem `sistema` no chat
("Demanda recusada: <motivo>"), como no protótipo. Ação fora da situação de origem: 409 ("recarregue a
tela"), conferida ANTES da permissão; ação que o perfil não tem: 403; motivo em branco: 409.

## 5. Regras de tela vindas do desenho

- **Período** (Tudo/Mês/Ano) afeta **só os KPIs**, como no protótipo; lista e contagem dos chips ignoram o período.
- KPIs: Abertas · Aguardando aceite · Em execução (aceita+execucao+validacao) · Prazo em até 3 dias (abertas, inclui atrasadas) · Concluídas. Clicar filtra pelo grupo.
- Chips: Todas · Novas · Em análise (analise+info) · Aguardando aceite · Em execução · Concluídas · Recusadas e canceladas · Arquivadas · Rascunhos (só Solicitante).
- Busca: código, tipo, descrição, município/UF de execução, município de origem, processo, escritório.
- Prazo da linha: "encerrado" (fechadas) · "atrasada N dia(s)" · "vence hoje" · "em N dia(s)"; cores do desenho.
- Responsável: o executor designado (nome do convidado ativo, F3) ou "A definir".
- Para externos (Solicitante e Convidado), sem `priv.nomeResp`, nomes de Master e Equipe aparecem como "Central BlueJus".
- Contato do solicitante oculto ao Convidado sem `priv.contatoConv`. Notas internas, convites e permissões nunca aparecem a externos.
- Chat: Enter envia; nota interna só para Master e Equipe; Equipe conversa só com `equipe.chat`; sem chat em rascunho.
- Checklist fixo de 4 itens e "Registrar andamento": só em execução, por Master, Equipe ou Convidado com `andamento`.
- Andamento da demanda: 8 marcos (Solicitada, Em análise, Proposta, Aceite, Pago, Em execução, Validação, Finalizada) com a data em que cada um foi alcançado.
- Configuração do Master (menu ⋮): visibilidade da aba (todos · só Master e equipe · suspensa e oculta), disponibilidade da central (disponível · com restrições · suspensa), "Equipe e privacidade…" e "Copiar link da aba". Valores iniciais iguais ao protótipo.
- Abrir com `?te=TE-0001` abre a demanda.
- Ao carregar, havendo mensagem não lida de outra pessoa, aparece o aviso "Abrir conversa".

## 6. Fatias

| Fatia | Conteúdo | Risco |
|---|---|---|
| F1 | domínio, migration, permissão, link na barra lateral (abaixo de Push Processual) e na subnav, lista, KPIs, filtros, nova solicitação e rascunho, painel lateral (Resumo, Proposta, Execução, Chat, Histórico), documentos, configuração do Master | MÉDIO |
| F2 | aba WhatsApp | BAIXO |
| F3 | convites do Convidado (e-mail, cadastro ou login, acesso só à demanda, permissões, reenviar, revogar, validade) | ALTO: revisão e re-revisão |

## 7. Fora do escopo / diferenças assumidas

- O cabeçalho azul e a subnav do protótipo são a moldura do BlueJus: usa-se a do sistema (`base.html.twig`), acrescentando "Trabalho Externo" na subnav.
- Avisos em tempo real entre abas (`storage` do protótipo): o aviso de não lidas aparece ao carregar e ao abrir a demanda; sem push.
- "Salvar edição" e "Novo escritório" do bloco Solicitante gravariam dados do escritório (Tenant) a partir da solicitação: **decisão pendente do dono**; na F1 o seletor lista os escritórios reais da conta e só preenche a solicitação.
- Texto sem travessão (regra do dono de 15/10): o "—" do protótipo para vazio vira "Não informado" ou some.
- Aba bloqueada para externos também barra no servidor a criação de solicitação e de rascunho (o
  protótipo só escondia a tela).
- "Avisar fase atual" com a demanda numa situação sem fase de WhatsApp (aguardando informações,
  recusada, cancelada, arquivada): o botão não aparece e o servidor responde 409. O protótipo caía em
  "Solicitação recebida".
- A mensagem de boas-vindas do WhatsApp não é de fase: na fila aparece sem fase e, nas enviadas, como
  "Conexão" (o protótipo usava a situação do momento).
- O responsável é mascarado para externos ("Profissional designado pela Central") também na lista,
  não só no Resumo, quando a privacidade "nome de quem atende" está desligada.
- O nome do escritório na solicitação é texto do formulário (o dono real é o `tenant`): pode diferir
  do cadastro do escritório. Ligado à decisão pendente de "Salvar edição"/"Novo escritório".
- Concorrência: duas versões de proposta ao mesmo tempo dão 409; duas leituras do chat ao mesmo tempo
  são idempotentes. A configuração da Central é uma linha só por convenção (só o Master a grava).

## 8. F3: Convidado (risco ALTO, identidade User/Tenant)

D3 do dono: o link do convite só dá acesso com conta; quem não tem conta cria uma.

**Convite** (`trabalho_externo_convite`): demanda, nome, e-mail, função, validade (padrão hoje + 15 dias),
as 6 permissões do desenho (ver instruções e documentos, baixar documentos, enviar arquivos, conversar,
registrar andamento, marcar como concluída), situação (enviado, aceito, revogado), o usuário que aceitou
e o **hash** (SHA-256) do token. O token em claro só existe no e-mail.

- Quem convida/revoga/reenvia: Master, ou Equipe com `equipe.convidar`, em demanda aceita ou em execução.
- Convidar revoga o convite ativo anterior da mesma demanda (o desenho tem um executor por vez) e põe o
  nome do convidado como responsável. Revogar corta o acesso na hora e limpa o responsável. Tudo deixa
  registro no chat. Reenviar manda o mesmo link de novo (até 3 vezes).
- O convite NÃO cria vínculo (`UserTenant`) com escritório nenhum. Acesso de convidado nunca dá acesso a
  outro módulo.

**Aceite** (`/trabalho-externo/convite/{token}`, público):

| Quem abre | O que acontece |
|---|---|
| token inexistente, revogado ou vencido | página de aviso, sem dizer de que demanda é |
| anônimo, e-mail do convite sem conta | formulário: nome, senha (mín. 8), aceite dos Termos; cria a conta (sem escritório), registra o aceite do termo, aceita o convite, entra e abre a demanda |
| anônimo, e-mail com conta | pede para entrar e abrir o link de novo (o login do sistema não volta sozinho para a página; não mexemos no autenticador) |
| logado com outro e-mail | "este convite é para outro e-mail" |
| logado com o e-mail do convite | botão "Aceitar convite" (POST com CSRF) e abre a demanda |

**Acesso como Convidado**: o perfil é **por demanda**. Quem tem convite aceito, vigente e não revogado
vê aquela demanda como Convidado (mesmo que seja Solicitante no próprio escritório) e só nas situações
aceita, em execução, validação e concluída. Sem escritório escolhido, as rotas do Trabalho Externo
passam pela guarda de escritório (allowlist do `TenantContextValidatorListener`), e só o perfil
Convidado resolve nesse caso. Aba bloqueada para externos (visibilidade ≠ todos) vale para ele também.

Permissões do Convidado: documentos listados com "ver"; download com "ver" e "baixar"; anexar com
"enviar"; chat com "conversar"; checklist e andamento com "registrar andamento"; "Marcar como
concluída" (vai para validação) com "concluir"; "Informar impedimento" e "Solicitar prorrogação"
sempre em execução. Sem abas Proposta e WhatsApp; contato do solicitante só com `priv.contatoConv`.

E-mail: `TrabalhoExternoMailer`, remetente da casa (`IdentidadeDeEmail`). Em dev o `MAILER_DSN` é nulo.
