# Painel: Criar acompanhamento e Redistribuir (perfil individual da pessoa)

Spec de duas ações do perfil individual da pessoa no painel Intelligence. Decisão do dono: "o desenho propõe, a gente cria; destino novo exige spec antes".
Origem: `docs/specs/dashboard-parte-1.md`, PR-I (perfil), que deixou as duas fora por falta de destino. Esta spec cria o destino.
Desenho: `docs/design/redesign-25-10-26/01 - Dashboard - VS 25.10.26.dc.html` (fora do git) e `bluejus-equipe.js`.
Risco: MÉDIO (CLAUDE.md). Não toca ponto eletrônico nem identidade User/Tenant/TenantRole/Permission. Mas mexe em responsável de meta alheia e guarda um registro
sobre uma pessoa (LGPD), então segue o rigor de MÉDIO: spec, revisão com `feature-review-agent` e teste cross-tenant e de cadeado.

## 1. O que o desenho mostra

Perfil da pessoa (dc L2431-2480), bloco `acoes` (dc L2460-2464), botões de 30 px: "Ver tarefas", "Criar acompanhamento", "Redistribuir", "Marcar como analisado".

| Botão | Quando aparece | Ao clicar no dc |
|---|---|---|
| "Criar acompanhamento" (azul #0a6fa0, primário) | Master, ou perfil com a permissão `acomp` ("Criar acompanhamento", dc L2363) | `marcar('acomp', 'criou acompanhamento')`: status vira "Em acompanhamento" (chip amarelo #fbf5dc / #8a6a06), grava na auditoria do dc "criou acompanhamento · nome", toast "Acompanhamento criado · Primeiro" com link `/tarefas/nova?titulo=Acompanhar desempenho de <nome>&revisao=7d` (ícone `bi-calendar-check`) |
| "Redistribuir" (cinza) | Só quando a situação da pessoa é carga, acompanhamento ou crítico (`sel.cond`) | Toast "Redistribuir tarefas de Primeiro" com link `/tarefas/equipe?responsavel=<id>&acao=redistribuir` (ícone `bi-arrow-left-right`). O dc não desenha a tela de redistribuição |
| "Marcar como analisado" | Sempre | `marcar('analisado', ...)`: chip verde "Analisado" |

Status do perfil (dc L2456): "Analisado", "Em acompanhamento" ou "Pendente". Os três são uma marca por pessoa no navegador (`eqStatus`); criar acompanhamento sobrescreve "Analisado" e vice-versa.
O dc fecha o plano de ação com "Prazo de revisão" (`sel.plano.revisao`) e mostra "Acompanhar novamente em 24 horas" (dc L1340, rodapé do painel). O link do toast usa `revisao=7d`.
O dc não mostra o que a pessoa acompanhada vê, nem confirmação antes de redistribuir, nem aviso a quem recebe.

## 2. O que o sistema tem hoje

- **PR-I já entregue**: `GET /dashboard/inteligencia/pessoa/{id}` e `POST .../marca` em `app/src/Dashboard/Controller/PerfilDaPessoaDoDashboardController.php`;
  `ObterPerfilDaPessoaUseCase`, `MarcarPessoaDaInteligenciaUseCase`, marcas por usuário em `preferencia_usuario` (`app/src/Dashboard/Preferencia/MarcasDasPessoas.php`);
  fragmento `app/templates/dashboard/_perfil_pessoa.html.twig`. "Analisado" e "Pendente" existem; "Em acompanhamento" não.
- **Quem pode**: `RegraDosPrimarios::podeGerirPrimarios` (`app/src/Tenant/Service/RegraDosPrimarios.php`): Master ou primário ativo. Hoje o perfil inteiro já exige isso. A permissão `acomp` do dc (perfil por permissão) não existe no sistema e fica fora.
- **Meta**: `App\Entity\Tarefa\Tarefa` (`app/src/Entity/Tarefa/Tarefa.php`). Exige pasta (não há tarefa avulsa, decisão do PR-L). Responsáveis: ManyToMany `tarefa_responsaveis` (`addResponsavel`/`removeResponsavel`).
  Já existe `tarefa_acompanhamento` (`acompanhantes`): marca pessoal de "acompanhar uma META", outro conceito; não reaproveitar nem confundir.
  Status: `pendente`, `em_revisao`, `concluida`. "Ativa" no painel = não concluída (`ListarMetasDaEquipeUseCase`, status `ativas`).
- **Reatribuição de meta**: não existe UseCase de trocar responsável de meta já criada. Só a criação (`GravarMetaNovaUseCase`) e o responsável da PASTA (`app/src/Pasta/UseCase/AlterarResponsavelDaPastaUseCase.php`, que é outra coisa).
- **Histórico da meta**: `TarefaMensagem` com `AcaoDoDespacho` (`app/src/Tarefa/Enum/AcaoDoDespacho.php`: despacho, envio, devolucao, lembrete, etapa). Coluna `acao` varchar(20): um caso novo no enum NÃO exige migration.
  O Lembrete é o precedente: grava texto no histórico sem código de registro, não se edita (`LembrarMetaUseCase`).
- **Auditoria**: `Tarefa` é `Auditavel`, mas o subscriber de auditoria não enxerga mudança em ManyToMany (a conferir na implementação; se enxergar, não duplicar). Precedente de `audit_log` à mão: `RegistrarAuditoriaDoDesempenhoIndividualUseCase` (`ia_pessoa`, `ia_marca`, `changes` só com evento e id). `action` tem 10 caracteres.
- **Aviso**: `NotificacaoService::criar(...)` e o tipo livre `notificacao.tipo` mapeado na Central (`LembrarMetaUseCase::TIPO_NOTIFICACAO`, `TiposDeAvisoDaCentral`). `veAPastaDaMeta` respeita o cadeado.
- **Cadeado**: `AcessoAPasta::idsOcultosPara` e `AcessoAMeta::exigir` (404 para meta de pasta oculta ou de outro escritório). Primário e Master não têm pasta oculta, mas o cadeado é sempre repassado (defesa), como no PR-I.
- **Concorrência**: `TransacaoDaPassagem` (trava a linha e relê) e `TarefaMensagemRepository::emTransacaoTravadaPelaMeta`, usados por `ReabrirMetaUseCase` e `LembrarMetaUseCase`.
- **Zerar** (`ZerarColunaDoDashboardUseCase`): só muda o número mostrado no card, não toca em meta. A lista de redistribuição vem das metas reais.
- **Agenda**: `Evento` (`app/src/Agenda/Entity/Evento.php`) é `ConteudoParticular`; criar evento de revisão sobre avaliação de pessoa não entra nesta spec.

## 3. Lacunas

1. Não há onde guardar "esta pessoa está em acompanhamento", com data de revisão, de quem e desde quando.
2. A meta de acompanhar a pessoa não cabe em `Tarefa` (precisa de pasta, aparece nas listas e no "Metas da equipe" para quem vê a pasta, e alimenta contagens do painel: viraria dado de avaliação em tela de colegas).
3. Não há UseCase de trocar responsável de meta existente, nem texto de histórico, nem aviso para isso.
4. Não existe a tela de redistribuição.

## 4. Proposta A: Criar acompanhamento

### Decisão de modelo: entidade nova, não `Tarefa`

Nova entidade `App\Dashboard\Entity\AcompanhamentoDaPessoa` (tabela `dashboard_acompanhamento_pessoa`), `Auditavel` + `TenantAware`, no padrão de `DashboardMetaPastas`:
`id`, `tenant_id` (NOT NULL), `pessoa_id` (User, NOT NULL, sem ação: o sistema não apaga usuário), `criado_por_id` (User, NOT NULL), `criado_em`, `revisar_em` (date), `encerrado_em` (datetime nullable), `encerrado_por_id` (nullable).
Sem texto livre (nenhuma anotação sobre a pessoa), sem nota, sem classificação. Índice `(tenant_id, pessoa_id, encerrado_em)`. Só pode haver UM aberto por pessoa e escritório (índice único parcial `WHERE encerrado_em IS NULL`).
Por quê: separa a decisão do gestor das metas de trabalho, não polui listas e contagens, e o registro mínimo (sem texto) reduz o risco LGPD.
**Migration: SIM**, uma tabela nova, pela skill `criar-migration` (sem numerar aqui; uma migration por vez entre os PRs abertos).

### Regras

- **Quem**: Master ou primário ativo (`RegraDosPrimarios::podeGerirPrimarios`), módulo `bi`, escritório da sessão. A permissão `acomp` do dc não existe: vale a regra do perfil. Demais: botão ausente e POST 403.
- **Pessoa-alvo**: colaboradora ativa do escritório da sessão, e não pode ser o próprio gestor (ele não se coloca em acompanhamento) nem o Master (a conta do Master só ele mexe). Fora do escritório: 404 (mesma exceção `ColaboradorForaDoEscritorioException`).
- **Prazo de revisão**: o gestor escolhe ao criar entre 24 horas (1 dia), 48 horas (2 dias) e 7 dias; padrão 7 dias, como o `revisao=7d` do dc. Guarda-se a DATA. Vencida, o chip passa a "Revisar acompanhamento" (amarelo). Nada acontece sozinho no vencimento.
- **Confirmação**: clicar no botão abre um `<dialog>` com a pessoa, o prazo e o texto "Isto registra que você vai acompanhar o trabalho de <nome>. Não cria nota, não altera metas e não avisa a pessoa." Só o botão "Criar acompanhamento" do dialog grava (POST com CSRF). Nada é criado sem esse clique.
- **Encerrar**: botão "Encerrar acompanhamento" no mesmo lugar (mesma permissão, mesmo CSRF); grava `encerrado_em` e `encerrado_por`. Também há "Renovar" (novo prazo) sem criar linha nova: atualiza `revisar_em` e audita.
- **O que NUNCA acontece**: criar, renovar ou encerrar sozinho (nenhuma Command nem listener); reatribuir meta por causa do acompanhamento; nota, ranking, pontuação, bloqueio, desconto ou aviso de desempenho; qualquer efeito em ponto, remuneração ou permissão. LGPD art. 20: não há decisão automatizada sobre a pessoa; o painel apenas sugere e uma pessoa (o gestor) decide e registra, por regras fixas (nenhuma IA).
- **A pessoa acompanhada vê?** NÃO vê o registro nem recebe aviso. Justificativa: é anotação de gestão, sem consequência para a pessoa e sem texto; avisá-la criaria constrangimento sem nada a contestar, e o dc também só mostra isso ao gestor. Compensações de transparência: (a) a existência fica na auditoria do escritório, visível ao Master e aos primários; (b) encerrar tira o registro da tela, mas a linha permanece para a auditoria; (c) a exclusão da conta da pessoa (LGPD) apaga o registro pelo fluxo de exclusão existente; (d) o texto do diálogo é honesto. Se o dono preferir que a pessoa veja, é mudança de política, não de arquitetura (seção 8).
- **Cadeado**: não expõe pasta. Nenhum número do perfil muda.
- **Zerar**: não interfere; o acompanhamento não depende de contagem.
- **Tenant**: todo acesso por `tenant_id` do escritório da sessão; repositório com filtro explícito além do `TenantFilter`.
- **Auditoria** (`audit_log` à mão, padrão do PR-I, `entity_class` User, `entity_id` = id da pessoa, `changes` só `{evento, pessoa_id, revisar_em}`, sem nome nem texto): `acomp_cria`, `acomp_rnv`, `acomp_fim` (todas com até 10 caracteres).
- **Notificações**: nenhuma.

### O que muda no perfil

- Chip de status "Em acompanhamento" (amarelo #fbf5dc / #8a6a06, do dc) quando há acompanhamento aberto para a pessoa, visível a todos os gestores do escritório (é dado do escritório, não pessoal). Precedência: Em acompanhamento, depois Analisado, depois Pendente. "Analisado" continua pessoal por usuário (PR-I).
- Botão "Criar acompanhamento" (azul, primário, 30 px) vira "Encerrar acompanhamento" (cinza) enquanto houver um aberto, com "Revisar até dd/mm" ao lado do chip.
- Toast "Acompanhamento criado · Primeiro" (sem link para `/tarefas/nova`, que não se aplica) e "Acompanhamento encerrado · Primeiro".
- Desvio deliberado do dc: ele leva a `/tarefas/nova` (tarefa avulsa, inexistente) e usa estado local; aqui é registro próprio no banco. Rótulos e cores do dc mantidos.

### Estados e erros

Já em acompanhamento: o botão mostra "Encerrar"; POST duplicado devolve 409 com "Esta pessoa já está em acompanhamento.". Pessoa inativa ou de outro escritório: 404. Sem permissão: 403. CSRF inválido: 403. Prazo fora de 1/2/7: 400. Falha de gravação: toast de erro, nada pela metade (transação).

## 5. Proposta B: Redistribuir

### Decisão de modelo: sem entidade nova

Reaproveita `Tarefa` e `tarefa_responsaveis`. **Migration: NÃO** (o caso novo do enum `AcaoDoDespacho::Redistribuicao` cabe no varchar(20)). A tela é um `<dialog>` no próprio perfil (padrão do PR-L), sem rota de página nova.

Novo UseCase `App\Tarefa\UseCase\RedistribuirMetasDaPessoaUseCase` (a regra é de Tarefa; o Dashboard só chama). Rotas no controller do perfil:
- `GET /dashboard/inteligencia/pessoa/{id}/redistribuir` (XHR): lista as metas ativas da pessoa, com destino sugerido, e os destinos possíveis.
- `POST /dashboard/inteligencia/pessoa/{id}/redistribuir` (JSON, CSRF no header): `{movimentos: [{meta_id, destino_id}]}`.

### Regras

- **Quem**: Master ou primário ativo, módulo `bi`. Mesma guarda e mesmo 403/404 do perfil.
- **Quando o botão aparece**: o dc usa a situação carga, acompanhamento ou crítico; como o PR-I não implementa o chip de situação, a regra por dado real é: a pessoa tem pelo menos 1 meta ativa em que é responsável e está acima da média de metas ativas por colaborador do escritório. Rótulo "Redistribuir" (dc).
- **Quais metas entram**: metas não concluídas (`pendente`, `em_revisao`) do escritório da sessão em que a pessoa é responsável, de pasta visível ao gestor (cadeado). Meta em que ela é só criadora não entra. Meta concluída nunca.
- **Quem recebe**: colaboradora ativa do escritório (`UserTenantRepository::existeVinculoAtivo`), diferente da origem, que veja a pasta da meta (`NotificacaoService::veAPastaDaMeta`), nunca a conta do Master e nunca conta inativa.
- **Sugestão por regras fixas**: para cada meta, o destino é a pessoa elegível com MENOS metas ativas como responsável; empate pelo menor id. A cada meta sugerida a contagem do destino sobe 1 (não empilha tudo em uma pessoa). Texto na tela: "Sugestão por regras fixas: quem tem menos metas ativas. Sem IA, sem avaliação de desempenho." O gestor pode trocar o destino de cada meta ou desmarcá-la.
- **Confirmação explícita**: a lista chega com tudo DESMARCADO; o gestor marca as metas, revisa os destinos e confirma no resumo "Passar N metas de <origem> para <A (x), B (y)>. A pessoa de origem e quem recebe serão avisados." Só o botão "Confirmar redistribuição" grava. Nunca por regra, agendamento ou clique único no perfil.
- **O que faz**: por meta, em transação com trava da meta (`TransacaoDaPassagem`): relê, confere que ainda é ativa e que a origem ainda é responsável (senão, pula e lista no resultado como "mudou antes da confirmação"), remove a origem de `responsaveis` e adiciona o destino (se o destino já é responsável, só sai a origem). Nunca deixa a meta sem responsável. Não mexe em prazo, status, etapa, pasta, criador nem acompanhantes.
- **Histórico da meta**: uma `TarefaMensagem` com `AcaoDoDespacho::Redistribuicao` (rótulo novo no catálogo; precedente do Lembrete: sem código de registro, natureza nem prazo, não se edita): "Responsável alterado de <A> para <B> por <gestor>." Sem avaliação de desempenho e sem o motivo.
- **Auditoria** (`audit_log` à mão): `meta_redis` (10 caracteres), `entity_class` Tarefa, `entity_id` = id da meta, `changes` `{origem_id, destino_id}`. Mais `ia_redis` (9) por operação com `{pessoa_id, total}`. Sem nomes.
- **Avisos** (`NotificacaoService::criar`, tipo novo `tarefa_redistribuida`, a registrar em `TiposDeAvisoDaCentral`): a quem recebe, UM aviso por pessoa: "N meta(s) passaram para você", com link para "Minhas metas" (ou para a meta, se for uma). À pessoa de origem, UM aviso: "N meta(s) suas passaram para outras pessoas" (ela não fica sem saber por que a lista encolheu). Texto neutro, sem citar desempenho, carga nem acompanhamento. Quem tem a pasta oculta pelo cadeado não é avisado do que não vê.
- **Tenant e IDOR**: `meta_id` e `destino_id` vêm do POST; cada um é conferido no escritório da sessão (meta: `AcessoAMeta::exigir`; destino: vínculo ativo). Meta de outro escritório: 404, sem confirmar existência. Lote com id inválido: a operação inteira é recusada (400) antes de gravar.
- **Zerar**: não conta nem esconde meta; a tela avisa "Mostra as metas reais da pessoa; pode diferir do número do card se ele foi zerado".
- **Limite**: no máximo 50 metas por confirmação (400 acima).
- **Sem desfazer** nesta fatia (o gestor pode redistribuir de volta; o histórico da meta registra tudo).

### Estados e erros

Pessoa sem meta ativa: o botão não aparece; por URL, o GET devolve a lista vazia com "Nenhuma meta ativa para redistribuir.". Sem destino elegível: a meta aparece desabilitada com "Sem pessoa disponível com acesso a esta pasta.". Meta mudou no meio: pulada e dita ao final ("2 de 5 metas foram passadas; 3 mudaram antes da confirmação"). 403 sem permissão, 404 pessoa ou meta fora do escritório, 400 corpo inválido, 403 CSRF inválido.

## 6. Testes necessários

- Unit (`app/tests/Dashboard/Unit`, `app/tests/Tarefa/Unit`): UseCases de criar, renovar e encerrar acompanhamento (prazos 1/2/7, duplicado, alvo é o gestor, alvo Master, alvo fora do escritório); `RedistribuirMetasDaPessoaUseCase` (sugestão por fila menor e empate, destino sobe a contagem, meta só com criador fora, meta concluída fora, destino já responsável, meta mudou no meio, meta de pasta oculta, destino sem acesso à pasta, limite de 50, nunca deixa meta sem responsável, histórico e auditoria gravados, um aviso por pessoa).
- Functional (`app/tests/Dashboard/Functional`): 200 como primário e Master; 403 como secundário e sem módulo `bi`; 404 com pessoa de outro escritório; 404 com `meta_id` de outro escritório (cross-tenant); CSRF; cadeado (meta de pasta oculta não aparece nem é movida); chip "Em acompanhamento" e botão trocado; sem travessão (`SemTravessaoNovoArquiteturaTest`); ação de auditoria com até 10 caracteres.
- `doctrine:schema:validate` e a migration (fatia A). `scripts/qa.sh` (PHPStan 5 e CS-Fixer).

## 7. Fatias de PR

- **Fatia A, Criar acompanhamento** (PR próprio, COM migration): entidade, repositório, UseCases (criar, renovar, encerrar), rotas, `<dialog>`, chip e botão, auditoria, testes. Entrega sozinha o status "Em acompanhamento".
- **Fatia B, Redistribuir** (PR próprio, SEM migration; independe da A, mas as duas tocam o controller e o template do perfil: avisar como arquivo quente e atualizar a segunda após o merge da primeira): caso `Redistribuicao` no enum, UseCase, GET e POST, `<dialog>`, avisos, tipo de aviso na Central, auditoria, testes.
- Cada fatia atualiza `docs/specs/dashboard-parte-1.md` (PR-I, "Fora") e traz o arquivo em `mudancas/`.

## 8. Riscos e pontos que o dono pode mudar

- Risco MÉDIO: mexe em responsável de meta de outra pessoa (efeito real no trabalho dela) e guarda registro sobre pessoa. Controles: só Master e primário, confirmação explícita, histórico e auditoria, aviso a quem sai e a quem entra, nenhuma automação.
- Percepção de punição: a redistribuição pode ser lida como sanção. Mitigado por texto neutro e por não citar o motivo; risco aceito.
- Acompanhamento invisível à pessoa: recomendado por ser anotação de gestão sem consequência. Se o dono quiser transparência total, basta um aviso à pessoa na criação (um `NotificacaoService::criar` a mais, sem migration).
- Concorrência entre dois gestores redistribuindo a mesma pessoa: coberta pela trava por meta e pela releitura.
- Arquivos quentes: `PerfilDaPessoaDoDashboardController`, `_perfil_pessoa.html.twig`, `TiposDeAvisoDaCentral`, migrations (uma por vez).
- Fora destas fatias: permissão `acomp` própria, evento na Agenda para a revisão, acompanhamento por equipe, texto livre, "Explicar contexto".

## 9. Conferência com o desenho

| Item do dc | Estado |
|---|---|
| Rótulos "Criar acompanhamento", "Redistribuir", "Marcar como analisado" | mantidos |
| Cores e tamanho (30 px, #0a6fa0 primário, cinza #eef3f6) | mantidos |
| Chip "Em acompanhamento" amarelo | mantido, agora com dado real |
| Toast "Acompanhamento criado · Primeiro" | mantido (sem link para tarefa avulsa) |
| Link `/tarefas/nova?revisao=7d` | desvio: não há tarefa avulsa; 7 dias é o padrão do prazo |
| Redistribuir leva a `/tarefas/equipe?...&acao=redistribuir` | desvio: dialog no próprio perfil, com confirmação (o dc é omisso) |
| Botão "Redistribuir" só para carga, acompanhamento e crítico | adaptado: pessoa acima da média de metas ativas (não há chip de situação) |
| Permissão `acomp` | fora: vale Master e primário |
| Auditoria "criou acompanhamento · nome" | adaptada: ação curta, sem nome |
