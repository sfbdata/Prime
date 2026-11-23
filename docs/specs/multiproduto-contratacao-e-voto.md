# Multiproduto: contratação por escritório e o Voto como produto (BlueJus)

Spec da parte de sistema do pedido do Samuel de 23/11/2026.

**Origem:**
- `bluejus-coordenacao/voto-produto-plano-2311.md` (Plano v2, que substitui o v1);
- `multiproduto-etapa0-relatorio.md` (Revisão de 23/11, A8, D2, Parte F, Apêndice 1);
- `pacote-juridico.md`, bloco "Multiproduto e Voto como produto próprio". O jurídico não trava; ele vira lista para os advogados.

**Aprovado pelo Samuel em 23/11:**
- a tabela `contratacao` (migration reservada `Version20261123180000`);
- a I.A. como produto próprio `ia`, fora do Expediente, que o dono liga no Meu Perfil com teto mensal;
- o teto padrão de 500 centavos (US$ 5), editável pelo Master.

**Risco: ALTO.** A spec mexe na identidade do Tenant e na autorização por rota, e o Ponto e a I.A. entram como produtos.

**Convenção:** todo fato de código cita arquivo:linha conferido no master de 23/11 (b032ce49d). O que ainda não existe está marcado como **proposta**.

## 1. O que é

O BlueJus passa a oferecer produtos separados, de graça por ora:

- `completo`, `voto`, `expediente`, `ia`, `ponto`, `calculo`, `kanban`, `assinatura` e `cobrancas`;
- o **Expediente** é o pacote de Demandas, Metas, Push Processual, Peticionar e Agenda, mais as telas de pastas em que eles se apoiam;
- a **I.A.** é um produto à parte.

Como cada escritório fica:

- **Cadastro pelo site do Voto** (`/cadastro?produto=voto`): o escritório nasce só com o Voto e o núcleo (perfil, equipe e notificações).
- **O dono** liga e desliga ferramentas no Meu Perfil, em **todos** os escritórios, inclusive os 33 que já existem (§9).
- **O Master** vê e altera a contratação de qualquer escritório.
- **Escritório sem nenhuma linha de contratação** continua "completo", exatamente como hoje (a I.A. dele segue a liberação do Master, como hoje).

## 2. Regras que não mudam

1. **Sem linha = completo.** Escritório sem nenhuma linha em `contratacao`, em qualquer situação, vê tudo como hoje. Nenhum escritório existente ganha linha por migration.
2. **A guarda do produto fica fora do `PermissionChecker`.** O primário (`isSystem`) e o Master passam em toda checagem de módulo (Service/PermissionChecker.php:44-58, :311, :330; relatório D2, P1). O produto é uma pergunta anterior; as camadas de hoje (docs/AUTORIZACAO.md §1) não mudam.
3. **Produto não contratado = 404**, nunca 403 nem 503. O Master passa.
4. **Desligar não apaga dado.** As rotas viram 404; religar devolve tudo.
5. **Tudo nasce desligado** por `MULTIPRODUTO_MODO`. Desligados, a guarda e o menu ficam como hoje; o destino pós-login e o resumo diário seguem os produtos desde o PR 5, sem mudar nada para escritório sem linha (§11, D3).
6. **No worker e na linha de comando a guarda não existe** (ela é `kernel.request`). Quem age por escritório fora de uma requisição pergunta ao serviço de produtos com o `Tenant` explícito e `tenant_id = :tenant` escrito à mão.
7. **Dono = `DonoDoEscritorio::ehDono(User, Tenant)`** (Backup/Service/DonoDoEscritorio.php:17-24): compara `Tenant::getCriadoPor()` por id. Sem criador, ninguém é dono.
8. **As travas que já existem continuam e somam-se à contratação:**
   - a chave global `voto` (Master/Service/CatalogoDeRecursos.php:52, :117);
   - a `LiberacaoDoVoto`;
   - a chave geral da I.A. (`master_ia_chave_geral`, Master/Controller/IaDoMasterController.php:57);
   - o aceite da I.A. pelo admin.

## 3. Catálogo de produtos (PR 3, MÉDIO, sem migration)

**Molde:** `Master/Service/CatalogoDeRecursos.php`.

- linhas em :39-94; prefixos em :101-122; sempre abertas em :132-146;
- vence o prefixo mais longo (`chaveDaRota`, :183-202);
- teste do molde: `tests/Master/Unit/CatalogoDeRecursosTest.php`.

**Proposta:** domínio novo `app/src/Produto/`, com `Service/CatalogoDeProdutos` (classe final, só constantes e métodos estáticos).

- **`PRODUTOS`:** chave, título, ícone, `rotaInicial` e `autoativavel`. Todos os ligáveis pelo dono têm `true`, a I.A. inclusive. A flag fica para produto futuro. `completo` não aparece para o dono.
- **Mapas de rota:**
  - `ROTAS` liga cada prefixo do nome da rota a uma `list<produto>`;
  - `ROTAS_EXATAS`, `NUCLEO`, `PUBLICAS` e `PLATAFORMA` são listas fixas.
- **`classificar(string $rota)`:** devolve núcleo, pública, plataforma ou a lista de produtos. Rota sem mapa vale `['expediente']`, ou seja, nasce fechada.

### 3.1 Tabela de classificação

Proposta, montada a partir do Apêndice 1 e dos `#[Route]`.

| Produto | Prefixos do nome da rota | Domínios | Módulo de permissão |
|---|---|---|---|
| voto | `voto_` (menos as públicas) | Voto | `voto` |
| expediente | `expediente_`, `pasta_`, `demandas_`, `tarefa_`, `push_processual_`, `djen_`, `processo_`, `api_datajud`, `agenda_`, `app_agenda_`, `app_sync_drive_`, `dashboard_`, `trabalho_externo_`, `servicedesk_`, `service_desk_`, `conector_admin_`, `conector_perfil_`; exatas `cobranca_caso_judicializar`, `cobranca_pastas_buscar` | Expediente, Pasta, Tarefa, Djen, Processo, Agenda, Sync, Dashboard, TrabalhoExterno, ServiceDesk, Conector | `expediente`, `pastas`, `tarefas`, `djen`, `processos`, `agenda`, `bi`, `trabalho_externo`, `servicedesk` |
| ia | `inteligencia_` (inclui `inteligencia_admin_config`), `chat_ia_`, `central_ia_` | Inteligencia (análise, conversa e auditoria da pasta, isto é, o drawer), ChatIa, parte da Central | `inteligencia` (admin `admin.inteligencia.manage`) |
| cobrancas | `cobranca_` | Cobranca | `cobrancas` |
| expediente **ou** cobrancas | `cliente_` | Cliente | `clientes` |
| calculo | `calculo_` | Calculo | `calculo` |
| kanban | `kanban_` | Kanban | `kanban` |
| assinatura | `assinatura_eletronica_` | AssinaturaEletronica | `assinatura_eletronica` |
| ponto | `ponto_`, `app_jornada_`, `app_home_office_`, `app_tenant_sede`, `app_tenant_user_ponto_`, `app_tenant_user_justificativa_` | Ponto, parte de Controller/TenantController.php | `ponto` (admin `admin.ponto.manage`) |
| núcleo | `app_login`, `app_logout`, `auth_`, `conta_`, `tenant_selecionar`, `escritorio_`, `meu_escritorio_`, `app_tenant_` (resto), `app_access_`, `invite_user`, `app_profile`, `backup_`, `notificacao_`, `novidades_`, `ajuda_`, `legal_`, `termo_`, `contribua_`, `indicacao_`, `central_` (menos `central_ia_`), `audit_log_`, `homepage`, `monitor_evento`, `app_feriado_`, `comercial_`, `email_descadastro`, `register_confirm`, `conector_oauth_`, `conector_mcp`, `conector_metadado_` (Conector/Service/ServidorDeAutorizacao.php:36-54; a regra deles fica em `LiberacaoDoConector`, §6.1) | | |
| pública (a guarda não age) | `voto_assembleia_cedula*` e `voto_assembleia_portal*` (Voto/EventListener/CedulaSemTenantFilterListener.php:28-39), `voto_publico_`, `assinatura_eletronica_publica`, `push_compartilhado`, e por nome exato: `trabalho_externo_convite`, `trabalho_externo_convite_criar_conta`, `trabalho_externo_convite_aceitar` (security.yaml:71), `trabalho_externo_vagas_publicas`, `trabalho_externo_vagas_link`, `trabalho_externo_vagas_alertas`, `vagas_publicas_cta` (security.yaml:84), `calculo_verificar`, `validacao_comprovante_ponto*` (Ponto/Controller/ValidacaoDoComprovanteController.php:35-36), `contribua_webhook_cora`, `auth_cadastro*`, `indicacao_link_curto` | | |
| plataforma | `master_`, `admin_platform_`, `agendador_falhas_dispensar` (Shared/Scheduler/Controller/FalhaAgendadaController.php:30) | Master | `ROLE_SUPER_ADMIN` |

**Rotas que parecem I.A. e não são** (conferido):

- `dashboard_inteligencia_*` é o perfil da pessoa no Dashboard (Dashboard/Controller/PerfilDaPessoaDoDashboardController.php). Fica no Expediente.
- `cliente_inteligencia_cadastral*` usa regras internas, sem I.A. (docs/specs/cliente-inteligencia-cadastral.md, F-A). Fica em Clientes.
- `app_profile_aprendizado_apagar` é o aprendizado das Metas (Tarefa/Controller/AprendizadoDasMetasController.php). Fica no núcleo.

**Rotas ambíguas (D12):**

- **Clientes:** abre com Expediente ou com Cobranças. É assim que "ligar Cobranças liga Clientes", sem linha própria.
- **Agenda:** Expediente.
- **Notificações, Perfil, backup, Contribua e Indicação:** núcleo.
- **Central:** núcleo, menos `central_ia_`.
- **Feriados:** núcleo (servem ao Ponto e aos prazos).
- **Dashboard, ServiceDesk, Drive e Trabalho Externo:** Expediente.
- **Conector:** Expediente (D11).
- **`validacao_comprovante_ponto`:** precisa estar na lista pública. Ele não começa com `ponto_` e cairia no padrão.
- **Convites e vagas do Trabalho Externo:** a lista pública é por nome exato. `trabalho_externo_convite_reenviar` e `trabalho_externo_convite_revogar` são rotas logadas do escritório e ficam no Expediente (um curinga `trabalho_externo_convite*` abriria as duas para escritório sem Expediente).

## 4. Contratação (PR 4, MÉDIO, com migration)

Migration `Version20261123180000`, aditiva, com `down()` testado. **DDL proposto:**

```sql
CREATE TABLE contratacao (
  id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  tenant_id INT NOT NULL,
  produto VARCHAR(20) NOT NULL,
  situacao VARCHAR(10) NOT NULL,
  inicio TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  fim TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
  origem VARCHAR(10) NOT NULL,
  alterada_por_id INT DEFAULT NULL,
  criada_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  atualizada_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_contratacao_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,
  CONSTRAINT fk_contratacao_usuario FOREIGN KEY (alterada_por_id) REFERENCES "user" (id) ON DELETE SET NULL,
  CONSTRAINT chk_contratacao_produto CHECK (produto IN ('completo','voto','expediente','ia','ponto','calculo','kanban','assinatura','cobrancas')),
  CONSTRAINT chk_contratacao_situacao CHECK (situacao IN ('ativa','encerrada')),
  CONSTRAINT chk_contratacao_origem CHECK (origem IN ('cadastro','dono','master')),
  CONSTRAINT chk_contratacao_periodo CHECK (fim IS NULL OR fim >= inicio)
);
CREATE UNIQUE INDEX uniq_contratacao_ativa ON contratacao (tenant_id, produto) WHERE situacao = 'ativa';
CREATE INDEX idx_contratacao_tenant ON contratacao (tenant_id);
```

**Mapeamento:** `#[ORM\UniqueConstraint(..., options: ['where' => "(situacao = 'ativa')"])]`, com precedente em Dashboard/Entity/AcompanhamentoDaPessoa.php:29. Usar a skill `criar-migration`.

**Entidade `Contratacao`:**

- é `TenantAware` e `Auditavel` (Service/Audit/AuditLogSubscriber.php:413);
- tem `ativar(origem, User)` e `encerrar(origem, User, agora)`; `origem` e `alterada_por` guardam **a última mudança** da linha (quem ativou ou quem encerrou), e a auditoria guarda o histórico;
- encerrar uma linha já encerrada lança `DomainException`;
- religar cria uma linha nova; o histórico fica.

**Proposta: `Service/ProdutosDoEscritorio::doTenant(Tenant)`.** Lê as linhas com `tenant_id` explícito (funciona com ou sem o filtro) e decide assim:

| Linhas do escritório | Produtos que valem |
|---|---|
| nenhuma, em qualquer situação | todos |
| `completo` ativa | todos |
| outras linhas ativas no período | só esses produtos |
| só encerradas | só o núcleo |

`inclui(Tenant, produto|lista)` responde a partir disso (lista = basta um). O resultado fica em memória durante a requisição (`ResetInterface`).

**Purga:** acrescentar `['contratacao', 'tenant_id = :tenant']` em `ORDEM_DELECAO` (Tenant/UseCase/PurgarEscritorioUseCase.php:105). A FK CASCADE fica como segunda rede.

**Repositório do Master:**

- suspende o filtro de tenant como `findDoTenantParaOMaster` (Voto/Repository/LiberacaoDoVotoRepository.php:43-57);
- a grade usa SQL direto, como `listarEscritoriosParaOMaster` (:66-81).

## 5. Cadastro pelo Voto (PR 5, ALTO)

**Como é hoje (conferido):**

- `GET /cadastro` (Auth/Controller/CadastroController.php:53-63) só redireciona a `app_login?abrir=cadastro`.
- O escritório nasce no `POST /cadastro/criar` (:65-104), em `CriarContaEEscritorioUseCase::executar` (Auth/UseCase/CriarContaEEscritorioUseCase.php:109). A transação fica em :135-137. O `criar()` (:246-269) chama o bootstrap em :256.
- Google: `executarPeloGoogle` (:162, com transação em :179), chamado de CadastroPeloGoogleController.php:67.
- Facebook: CadastroPeloFacebookController.php:60.
- **O produto não precisa viajar até o link de confirmação.** A confirmação por e-mail só cria a senha.
- `ConfirmarCadastroUseCase` (:45, :69, :95) é o caminho legado `/cadastro/confirmar/{token}` (CadastroController.php:182). Não muda.

**Proposta:**

1. **Guardar o produto na sessão.** `GET /cadastro?produto=voto` grava o valor numa lista fechada (só `voto`; o resto é ignorado), como o cupom (Indicacao/Controller/IndicacaoController.php:36; Controller/SecurityController.php:65).
2. **Ler o produto só da sessão (D9).** Os três POST de criação nunca o tiram do JSON. Ele vira um campo nulo no `CriarContaEEscritorioInput`.
3. **Gravar tudo na mesma transação de `criar()`:**
   - chamar `bootstrap($tenant, $user, semearMarcadores: false)`. Hoje os marcadores são semeados sempre (Service/TenantBootstrapService.php:92; método em :113; lista em :39);
   - gravar `Contratacao('voto', origem cadastro)`;
   - gravar `new LiberacaoDoVoto($tenant)` e chamar `liberar()` (Voto/Entity/LiberacaoDoVoto.php:62);
   - não ligar `ia`.
4. **Destino pós-login: `PaginaInicialDoEscritorio::rota(Tenant)`.**
   - Ordem: Expediente; depois voto, cobrancas, ponto, calculo, kanban e assinatura; por último `app_profile`.
   - Trocar a rota fixa em Security/EntradaDoUsuario.php:47 e :86, Auth/Controller/EntradaController.php:43, Controller/HomeController.php:14 e Controller/Tenant/TenantSelecaoController.php:55 e :68.
   - Incluir `voto_index` em `DestinoPosLogin::PAGINAS` (Auth/Service/DestinoPosLogin.php:43). Hoje ela está ausente.
5. **Resumo diário.** O comando `app:perfil:resumo-diario` (Profile/Command/ResumoDiarioCommand.php:25) ainda não está agendado (Shared/Scheduler/AgendaDeTarefas.php:48-49). `EnviarResumoDiarioDoEscritorioUseCase::executar` (Profile/UseCase/EnviarResumoDiarioDoEscritorioUseCase.php:57) passa a devolver `semEnvio` quando o escritório não tem Expediente, com o tenant explícito.
6. **Pré-requisito do lançamento:** a chave global `voto` ligada. Sem ela, o `BloqueioDeRecursoDesativado` responde 503.
7. **Automações do Comercial.** As que o cadastro dispara (Comercial/EventListener/CicloDaContaListener.php:41) usam texto jurídico. O PR 5 confere quais disparam e pula o escritório só Voto, ou registra a pendência.
   **Conferido no PR 5:** nos três caminhos atuais (e-mail, Google, Facebook) o `CicloDaContaListener` não dispara nada. `CadastroConfirmado` só sai do legado `ConfirmarCadastroUseCase`; `ConfirmacaoDeCadastroEnviada` só sai de `CadastroMailer::enviarConfirmacao` (legado; o cadastro novo usa `enviarConfirmacaoDeEmail`, sem evento); os convites não passam pelo cadastro. Sem linha em `ciclo_da_conta`, a dica de 7 dias também não alcança essas contas. **Pendência:** o e-mail de boas-vindas (`val`/`conv`) fala só do Expediente; se um dia for ligado ao cadastro novo, precisa de versão para o Voto. Os Termos de Uso e a Política gerais aceitos no cadastro ficam para os advogados conferirem.

## 6. Guarda do produto (PR 6, ALTO, desligada)

**Molde:** Master/EventListener/BloqueioDeRecursoDesativado.php.

- prioridade 3 (:31);
- só a requisição principal (:44);
- o Master passa (:50; RecursosDoSistema.php:73);
- o molde responde 503 (:62-73); a guarda responde 404.

**Ordem dos listeners (conferida):**

| Prioridade | Listener |
|---|---|
| 16 | `PapelDoContainer` (Voto/EventListener/PapelDoContainer.php:33) |
| 8 | firewall |
| 6 | `TenantContextValidatorListener` (:25) |
| 5 | `TenantFilterListener` (:26) |
| 4 | `TenantUrlScopeListener` (:25), que fixa o filtro no `{tenantId}` da URL |
| 3 | `BarreiraDoEscritorioInativoNaRota` (:41), `CedulaSemTenantFilterListener` (:21), `AssinaturaPublicaListener` (:41) e a guarda nova |

Na mesma prioridade a ordem não é garantida, por isso a guarda não depende de nenhum deles.

**Algoritmo (proposta, `Produto/EventListener/GuardaDoProduto`).** A guarda sai sem fazer nada nestes casos:

1. modo `desligado`, ou requisição que não é a principal;
2. rota de núcleo, pública ou plataforma;
3. ninguém logado (o firewall já barra rota não pública);
4. Master;
5. sem escritório. O escritório é o `{tenantId}` da rota, quando existe (TenantUrlScopeListener.php:38-45); senão, `TenantContext::getCurrentTenant()` (Service/Tenant/TenantContext.php:37). Rota sem escritório fica com a guarda da própria ação (AUTORIZACAO.md, "Rotas que funcionam sem escritório"). É por aqui que o Convidado do Trabalho Externo (rotas com `_sem_escritorio`) passa: sem escritório na sessão, a guarda não age; com escritório, as rotas `trabalho_externo_*` são do Expediente (§3.1), como no `TenantContextValidatorListener`.

Nos demais casos, se nenhum produto da rota estiver incluído:

- no modo `observacao`, registra no canal de log `multiproduto` e deixa seguir;
- no modo `bloqueio`, lança `NotFoundHttpException` (como PapelDoContainer.php:58-61).

### 6.1 Riscos multi-tenant e de autorização

- **Quem é das duas pontas (aceito no PR 6):** a pessoa com escritório próprio sem o Expediente que também é Convidada numa demanda de outro escritório perde o Trabalho Externo em `bloqueio` enquanto esse escritório estiver na sessão. É a mesma regra do `TenantContextValidatorListener` (o atalho `_sem_escritorio` só vale sem escritório na sessão). Rever quando o PR 7 der a troca de escritório no menu.
- **`{tenantId}` de outro escritório (risco aceito no PR 6):** a guarda usa o escritório da URL, e o `TenantUrlScopeListener` não confere vínculo (quem nega é o controller). Em `bloqueio`, uma URL de escritório alheio responde 404 se ele não tem o produto e 403 do controller se tem: dá para deduzir o contrato de outro escritório pelo código da resposta. Não há escalada (o controller nega nos dois casos) e a existência do escritório já se deduzia antes. Fechar exige o legado responder 404 também; fica para quando essas telas forem para o domínio.

- **Worker:** a guarda não roda. Para a I.A., quem trava o segundo plano é a `DisponibilidadeDeInteligencia`, que exige `liberadaPeloMaster` (Inteligencia/Service/DisponibilidadeDeInteligencia.php:104). Por isso existe a D15.
- **Rotas públicas:**
  - sem sessão, a guarda não age;
  - `/assinar/{token}` de outro escritório não pode dar 404 a quem está logado num escritório só Voto; por isso essas rotas estão na lista pública;
  - as públicas do Voto já conferem a liberação do escritório dono do link (Voto/Service/AssembleiaDoPortal.php:25; Voto/UseCase/MontarPortalDaAssembleiaUseCase.php:31; Voto/Service/UnidadeDoLink.php:38).
- **`/api/*`:** é classificada pelo nome da rota. Os webhooks (security.yaml:84) não têm sessão.
- **Conector MCP:**
  - o `/mcp` tem firewall sem sessão (security.yaml:21-23) e só fixa o escritório no despacho (TenantContext.php:27);
  - a regra vai em `LiberacaoDoConector::exigir(Tenant)` (Conector/Service/LiberacaoDoConector.php:38), que passa a exigir `expediente` (D11).

## 7. Menu (PR 7, MÉDIO)

**Proposta:** função Twig `produto_inclui('x')`, que aceita lista, num `Produto/Twig/ProdutoExtension`. O molde é Master/Twig/RecursosExtension.php:36. No modo `desligado`, devolve sempre `true`. Ela soma-se a `can_access_module()` (Twig/PermissionExtension.php:33), que deixa o primário passar.

**`_sidebar.html.twig`:**

- **sem guarda hoje:** Expediente (:39-46) e Demandas (:130-136) ganham `expediente`;
- **`expediente`:** Dashboard (:29), Push/Processos (:51, :63), Trabalho Externo (:76), Metas (:120), Agenda (:138) e ServiceDesk (:158);
- **`ia`:** Chat I.A. (:195-201) e configuração da I.A. (:317-320);
- **cada um com o seu produto:** Cobranças (:88), Cálculo (:98), Assinatura (:109), Kanban (:148), Ponto (:170), Voto (:183) e Sedes (:297, `ponto`).

**`base.html.twig`:**

- logo (:133): passa a levar à rota inicial (§5, item 4);
- barra secundária (:334-370): `expediente`;
- modal do Ponto (:409): `ponto`.

**Menu do usuário:**

- `menu_usuario/_presenca.html.twig:13`: `ponto`;
- `menu_usuario/_dropdown.html.twig` (:36-128): não tem item de produto; o PR só confere.

**Como ficou no PR 7:**

- `produto_inclui` e `ia_disponibilidade()` usam o mesmo `ProdutosNaTela`, com a régua da guarda: mostra tudo com a chave `desligada`, sem login, para o Master ou sem escritório na sessão. Em `observacao` o menu já segue os produtos (D3).
- A barra secundária fica, vazia, para escritório sem o Expediente: a altura do cabeçalho é fixa no CSS (`--subnav-height`).
- O ServiceDesk da área de administração (`servicedesk_index`) também leva `expediente`, como no catálogo.
- Os avisos globais que chamam rotas de produto também seguem: lembretes da Agenda e busca rápida (`expediente`), pendência do Ponto (`ponto`).
- O logo segue a tela inicial mesmo com a chave desligada, como o destino pós-login (D3): para os escritórios de hoje continua o Expediente. Ele aponta para `/inicio` (`homepage`), que redireciona; assim a página não faz consulta a mais (`ExpedienteQueriesDaPaginaTest`).
- O menu usa o escritório da sessão, também nas telas `/tenant/{tenantId}/...` (o `TenantUrlScopeListener` só fixa o filtro do Doctrine): os links do menu são do escritório em que a pessoa está.
- `ia_disponibilidade()` responde `DesligadaNoEscritorio` para escritório sem `ia`.
- Cadastro (pergunta da revisão do PR 5): o "Criar conta" avisa quando a conta vai nascer só com o Voto e oferece "Prefiro a conta com todas as ferramentas", que leva a `/cadastro?produto=completo` e tira o produto da sessão (ajuste da D9).

**I.A. dentro de outras telas** (drawer da pasta, Push, janela da Central: `templates/pasta/_push_processual.html.twig`, `templates/inteligencia/_conversa_rodape.html.twig`, `templates/central/_janela.html.twig`):

- o ponto único é `ia_disponibilidade()` (Inteligencia/Twig/InteligenciaExtension.php:78);
- proposta: ela responde "indisponível" quando o escritório não inclui `ia`.

## 8. Isolamento do Ponto (PR próprio, pré-requisito do PR 8)

O Ponto é autoativável, então esta fatia entra **antes** do PR 8, num PR próprio (ALTO, com re-revisão).

**O que falta (o núcleo da fatia):**

- `Sede`, `Cargo` e `Lotacao` ainda não são `TenantAware` (Entity/Tenant/Sede.php:10, Cargo.php:10, Lotacao.php:10).
- As três seguem em `PENDENTE_DE_CORRECAO` (tests/Shared/Functional/TenantAwareCoberturaTest.php:411-415).
- A coluna já existe e é NOT NULL (Sede.php:35-37; Cargo.php:22; Lotacao.php:22). Não precisa de migration.
- Ligar o filtro muda toda consulta delas. Antes, testar as telas de administração (docs/specs/isolamento-multitenant-cobertura.md:303).
- Chamadores a conferir:
  - Controller/TenantController.php;
  - SedeType, CargoType, LotacaoType e EditUserTenantRoleType;
  - Ponto/Service: SedesDaCercaDoPonto, SedesEFeriadosDoPonto e RemocaoDaSedeDoPonto;
  - SalvarSedeDoPontoUseCase, ApagarLotacoesQueSairamUseCase e Auditoria/UseCase/DesfazerAlteracaoAuditLogUseCase.

**Fica documentado, sem mudar:** `BlocoJornada` e `BlocoJornadaColaborador` (TenantAwareCoberturaTest.php:421-423), isolados pelo pai.

**R1 já está fechado:** Tenant/EventListener/BarreiraDoEscritorioInativoNaRota.php:41.

**R6 continua aberto:** Pasta/EventListener/PushCompartilhadoSemTenantFilterListener.php:24 e :38 tiram o filtro por prefixo `push_compartilhado`. Trocar por lista exata, no mesmo PR (é pequeno).

## 9. Ferramentas no Meu Perfil (PR 8, ALTO)

**Onde:** cartão "Ferramentas do escritório" em `templates/profile/index.html.twig`.

- Molde: o cartão `#mpBackup` (:515-548), que só aparece para o dono por `backup_da_pessoa()` (Backup/Twig/BackupNoPerfilExtension.php:32, :36-44).
- CSS em `public/css/meu-perfil.css`, junto de `.mp-bk-*` (:258-262).

**Proposta:**

- `POST /perfil/ferramentas/{produto}`, nome `app_profile_ferramenta` (núcleo);
- corpo em JSON e CSRF no header (molde Master/Controller/VotoDoMasterController.php:57-71);
- `LigarFerramentaUseCase` e `DesligarFerramentaUseCase`.

**Para quem aparece (Samuel, 23/11):** o cartão vale para **todos** os escritórios, inclusive os 33 que já existem (sem linha = completo).

- **Estado mostrado de cada ferramenta:** ligada quando o produto está incluído (`ProdutosDoEscritorio`). Para o Voto e a I.A. vale também a trava de hoje:
  - Voto aparece ligado só se a `LiberacaoDoVoto` do escritório estiver liberada;
  - I.A. aparece ligada só se `liberadaPeloMaster` for verdadeiro.
- **Chave global desligada** (`voto` em CatalogoDeRecursos.php:52, :117; chave geral da I.A., `master_ia_chave_geral`): a ferramenta aparece "Indisponível no momento", sem botão.
- **Travada pelo Master** (D16): aparece "Desligada pelo BlueJus", sem botão.
- Assim, um escritório antigo vê ligado tudo o que já usava, e o Voto e a I.A. só onde o Master já liberou.

**Regras:**

1. **Só o dono.** Os demais recebem 403 antes de qualquer outra checagem, e o cartão não aparece para eles.
2. **O que liga e desliga:** Expediente, I.A., Cobranças, Cálculo, Kanban, Assinatura, Ponto e Voto. Desligar encerra a linha e não apaga dado.
   - **Ligar o Voto** pelo dono: primeiro confere a trava do Master (D16; travado = 422); depois garante a contratação `voto` (se o escritório já tem linhas) e libera a `LiberacaoDoVoto` (cria se não existir; `liberar()`, Voto/Entity/LiberacaoDoVoto.php:62), com auditoria no escritório. A chave global `voto` do Master continua valendo.
   - **Ligar a I.A.:** regra 3.
   - **Primeira mudança num escritório sem linhas (materialização, D5):** antes de desligar qualquer ferramenta, o UseCase grava uma linha ativa (origem `dono`) para **cada produto do catálogo, menos o escolhido**, Voto e I.A. inclusive, mesmo que não estejam liberados. A linha diz "incluído"; a liberação continua decidindo se o Voto e a I.A. funcionam. Assim, se o Master liberar o Voto ou a I.A. depois, pelas abas de hoje, eles funcionam sem outra linha. Ligar algo num escritório sem linhas não grava linha de produto (ele já é completo); só mexe na liberação do Voto ou da I.A.
   - **Concorrência:** ligar, desligar e materializar correm dentro de `pg_advisory_xact_lock` por escritório (molde do `PedirBackupUseCase`), para dois cliques não quebrarem o índice `uniq_contratacao_ativa` nem gravarem um conjunto errado.
3. **Ligar a I.A.**, tudo na mesma transação, depois de conferir a trava do Master (D16; travado = 422):
   - garante a contratação `ia` ativa com origem `dono` (só se o escritório já tem linhas e ela não está ativa; sem linhas ele já inclui tudo);
   - busca a `ConfiguracaoDeInteligencia` do escritório ou cria uma (Inteligencia/Entity/ConfiguracaoDeInteligencia.php:36; construtor em :88);
   - marca `liberadaPeloMaster = true` (campo em :71-73), por um método próprio que grava o mesmo campo, para a auditoria mostrar o dono. O molde é `liberarPeloMaster()` (:131);
   - **só se `orcamentoMensalCentavos` estiver nulo** (:78-80; nulo = sem teto), grava `definirCobranca(ModoDeCobrancaDaIa::Incluso, tetoPadrao)` (:142-150) (D14);
   - o `tetoPadrao` vem da `configuracao_global`, chave `ia.tetoPadraoCentavos` (§10), com **500 de reserva** se estiver vazia. A leitura é direta, como `ConfiguracaoGlobalRepository::envioDeCampanhas` (:62);
   - `Incluso` existe com esse nome (Inteligencia/Enum/ModoDeCobrancaDaIa.php:14) e trava no teto (`travaNoOrcamento()`, :32-35; usado em DisponibilidadeDeInteligencia.php:161, :174-183).
4. **O aceite continua com o admin, como hoje.** Ligar não grava aceite.
   - Até o admin aceitar, a I.A. fica `DesligadaNoEscritorio` (DisponibilidadeDeInteligencia.php:108).
   - O aceite é dado em `/admin/inteligencia`, por quem tem `admin.inteligencia.manage` (Inteligencia/Controller/ConfiguracaoController.php:28, :40, :56).
   - Caminho: `AtualizarConfiguracaoDeInteligenciaUseCase.php:31`, depois `ConfiguracaoDeInteligencia::atualizar` (:108-121), que grava `consentimentoEnvioExternoEm`. Texto do aceite em `templates/inteligencia/admin/configuracao.html.twig:142-150`.
   - O cartão mostra o link para lá.
   - A chave geral do Master continua valendo (DisponibilidadeDeInteligencia.php:100).
5. **O Master muda o teto de um escritório depois** na aba "I.A.": `POST master_ia_escritorio` (IaDoMasterController.php:111-112), que chama `AlterarIaDoEscritorioUseCase` (:35-49; `definirCobranca` em :46). Tela em `templates/master/_ia.html.twig:14`.
6. **Desligar a I.A.** encerra a contratação e chama `bloquearPeloMaster()` (ConfiguracaoDeInteligencia.php:137-140). O aceite é preservado (:136) (D15).
7. **Voto com assembleia aberta não desliga.** Proposta: `AssembleiaRepository::existeAbertaNoEscritorio(Tenant)`, que olha as assembleias publicadas e não arquivadas com `Assembleia::acabou()` (Voto/Entity/Assembleia.php:428-446; estados em Voto/Enum/EstadoDaAssembleia.php:13-14).
8. **Ligar Cobranças abre Clientes** pelo catálogo (§3.1).
9. **Judicializar só com Expediente:**
   - `$podeAcessarPastas` (Cobranca/Controller/ObjetoController.php:128, :135) passa a exigir também `inclui(expediente)`;
   - o modal só aparece com `forms.judicializar` (`templates/cobranca/caso/_acoes_modais.html.twig:270`);
   - as rotas fecham pela guarda; hoje exigem só o módulo `pastas` (Cobranca/Controller/CasoController.php:234-240, :276-286);
   - `cobranca_caso_cancelar_judicializacao` (:347) fica com Cobranças: tirar o vínculo nunca trava.
10. **Ligar Expediente semeia os marcadores** se o escritório não tiver nenhum, por um método público e idempotente sobre `seedMarcadoresPadrao` (TenantBootstrapService.php:113). Ligar Expediente não liga a I.A.
11. **Perfis próprios não ganham módulo sozinhos.** Ao ligar, aparece o aviso "Ajuste o acesso em Perfis", com link para `app_tenant_role_index`. O perfil primário já tem todas as permissões (bootstrap `attachAllPermissions`).
12. **Auditoria:** `Contratacao` e `ConfiguracaoDeInteligencia` são `Auditavel` (:36), com `origem = dono` e `alterada_por`.

**Como ficou no PR 8a (regra e rota; o cartão é o PR 8b):**

- **Trava por escritório:** `ContratacoesDoEscritorio::CLASSE_TRAVA = 4801`, `pg_advisory_xact_lock(4801, tenant_id)` numa transação da conexão. O mesmo serviço serve ao dono e às abas do Master.
- **Marcador do Master (D16):** quando o Master desliga um produto que não tem linha ativa (já encerrado pelo dono, ou sem linha), grava uma linha aberta e encerrada no mesmo instante, com origem `master`. Desligar de novo não duplica.
- **Desligar o Voto também bloqueia a `LiberacaoDoVoto`** (muda a D6): o cartão e a liberação andam juntos; religar pelo dono libera de novo.
- **Ligar o Voto com a chave global `voto` desligada é aceito:** grava a contratação e a liberação, mas o Voto só funciona quando o Master ligar a chave.
- **D7 entrou:** o dono não desliga a última ferramenta ligada (422, `FerramentaRecusadaException::ultimaFerramenta`). Conta produtos ativos de fato (`ContratacoesDoEscritorio::eOUnicoLigado`); escritório sem linhas ou com `completo` ativa nunca é "último".
- **D7 e o Master:** a trava do Master não confere a D7. Se o Master bloqueia o Voto num escritório que só tem o Voto, o escritório fica sem ferramenta até o Master liberar. Aceito: o veto é do Master, e o PR 9 mostra isso no painel.
- **Trava só na troca:** salvar a aba sem mudar o estado (ou "bloqueado" num escritório que nunca foi liberado) não cria linha nem trava. O estado anterior é lido dentro da trava do escritório (`sobTrava`), para Master e dono ao mesmo tempo decidirem sobre o que está gravado.
- **"Para todos" do Master:** liberar ou bloquear todos passa, escritório por escritório, pelo mesmo UseCase da aba de um escritório. "Bloquear todos" também trava a contratação, e "liberar todos" destrava. Na I.A., o modo e o teto de cada escritório ficam como estão.
- **Sem escritório na sessão:** o validador do escritório manda para `/escritorio/selecionar` antes do controller; a checagem de dono (403) vale para quem já tem escritório.

**Como ficou no PR 8b (o cartão):**

- `templates/profile/_ferramentas.html.twig`, incluído antes do Backup; função Twig `ferramentas_do_escritorio()` (`Produto/Twig/FerramentasNoPerfilExtension`), que só o Meu Perfil chama (nenhuma consulta a mais nas outras páginas). Null para quem não é o dono: o cartão some.
- `ListarFerramentasDoEscritorioUseCase` decide o estado na ordem: indisponível (chave `voto` do Master; `DisponibilidadeDeInteligencia::plataformaConfigurada()` para a I.A.), travada (D16, `ContratacoesDoEscritorio::travadosPeloMaster()`, uma consulta), ligada (produto incluído e, no Voto e na I.A., a liberação de hoje) e desligada.
- Ligar ou desligar recarrega a página (o menu muda junto). Ao ligar, a página volta com `?ferramenta_ligada=1` e mostra o aviso dos Perfis (regra 11). A I.A. ligada sem o aceite mostra o link para `inteligencia_admin_config` (regra 4).
- Sem desenho: classes do Meu Perfil (molde `.mp-bk-*`), CSS `.mp-fe-*` em `meu-perfil.css`.
- "Indisponível" vem antes da trava e do estado: com a chave global desligada nem o Desligar aparece, e a contratação fica como está durante a pausa.
- Os nomes seguem o menu (`translations/moldura`): "BlueJus IA", "Ponto Eletrônico"; em inglês "BlueJus AI", "Voting", "Time Clock".
- Desligar a última ferramenta ligada ou o Voto com assembleia em andamento mostra a recusa do servidor (422) na própria seção.

## 10. Master (PR 9, MÉDIO)

**Molde:**

- `VotoDoMasterController`: `IsGranted` em :34, GET em :47, POST por escritório em :54 e para todos em :74;
- `templates/master/_voto.html.twig`;
- Voto/UseCase/AlterarLiberacaoDoVotoUseCase.php:33-50, que audita no escritório alterado (`EscopoDeAuditoriaDePlataforma::executarComoConta`, :46);
- as chaves globais estão em Master/Controller/RecursosDoSistemaController.php:31.

**Aba "Produtos" (proposta):**

- `GET /master/produtos` mostra a grade dos escritórios ativos com os produtos de cada um.
- `POST /master/produtos/escritorios/{tenantId}` recebe `{produto, ligado}` e grava com `origem = master`.
- O Master pode pôr `completo` ou voltar a ele.
- Ligar `voto` também libera a `LiberacaoDoVoto` (D10).
- Ligar `ia` segue a regra do dono (§9, regra 3).
- Respostas: 403 no CSRF; 404 para escritório inexistente ou inativo; 422 para produto fora da lista.

**Teto padrão da I.A. ligada pelo dono (proposta, na aba "I.A.", `/master#aba=ia`).** A aba está em `templates/master/painel.html.twig:75` e o conteúdo em `templates/master/_ia.html.twig`.

- **Guardado em:** `configuracao_global`, sem migration nova.
  - A tabela tem uma linha única, `id = 1`, e `valores` em JSON (migrations/Version20261026151300.php:24).
  - A entidade é `Auditavel`, gravada no escopo de plataforma (Master/Entity/ConfiguracaoGlobal.php:19, :31).
  - Ela já guarda chaves com prefixo fora da tela de configurações (:34-38), como a das campanhas (:78-97).
- **Entidade:**
  - prefixo novo `ia.` em `PREFIXOS_FORA_DO_CATALOGO` (:38), para que a tela de configurações não mostre nem apague a chave;
  - métodos `definirTetoPadraoDaIa(int, User, agora)` e `getTetoPadraoDaIa(): ?int`;
  - a chave no JSON é `ia.tetoPadraoCentavos`.
- **Repositório:** `tetoPadraoDaIa(): ?int`, lido direto do banco como `envioDeCampanhas` (ConfiguracaoGlobalRepository.php:62).
- **Rota e caso de uso:**
  - `POST /master/ia/teto-padrao`, nome `master_ia_teto_padrao`, no `IaDoMasterController` (molde `chaveGeral`, :57-71: CSRF no header e resposta com o estado);
  - `AlterarTetoPadraoDaIaUseCase`, no molde de `AlterarEnvioDeCampanhasUseCase` (:40-52): `buscarUnica() ?? new ConfiguracaoGlobal()`, `salvar` e `EscopoDeAuditoriaDePlataforma`;
  - validação: de 0 a `ConfiguracaoDeInteligencia::ORCAMENTO_MAXIMO_CENTAVOS` (:41); fora disso, 422.
- **Alcance:** vale só para quem o dono ligar depois da troca. Os tetos já gravados por escritório não mudam (§9, regra 3, só grava quando o teto está nulo).
- **Valor padrão:** 500. O PR 8 funciona sem o PR 9, usando a reserva.

## 11. Decisões (23/11)

**Tomadas pelo Samuel (não reabrir):**

- **Contratação:** tabela `contratacao`; "sem linha = completo".
- **Produtos:** os 9.
  - **Expediente:** Demandas, Metas, Push Processual, Peticionar e Agenda.
  - **Cobranças:** cobranças sem Judicializar, mais Clientes.
- **I.A.:**
  - produto próprio `ia`, que o dono liga (`autoativavel = true`);
  - ao ligar: modo `Incluso`, teto padrão e `liberadaPeloMaster = true`;
  - aceite com o admin, como hoje;
  - teto padrão de 500, editável pelo Master na aba I.A.; só vale para quem ligar depois da troca.
- **Cadastro:** `DonoDoEscritorio`; `?produto=voto`.
- **Guarda:** prioridade 3, fora do `PermissionChecker`, responde 404, o Master passa, o padrão é Expediente e nasce desligada.
- **Menu e perfil:** `produto_inclui`; as regras do Meu Perfil. O cartão "Ferramentas do escritório" vale para todos os escritórios, inclusive os atuais, que aparecem com tudo o que já tinham; Voto e I.A. aparecem ligados só onde o Master já liberou, e o dono pode ligá-los sozinho.
- **Ponto:** liberado ao dono depois do §8 (jurídico não trava; anotado em `pacote-juridico.md`).
- **Entrega:** a sequência de PRs.

**Decididas pela recomendação (orquestrador, 23/11):**

- **D1.** Domínio novo `app/src/Produto/`.
- **D2.** `MULTIPRODUTO_MODO` com três valores: `desligado`, `observacao` (registra sem bloquear) e `bloqueio`.
- **D3.** A mesma chave governa a guarda e o menu. Desligada, os dois ficam como hoje. **Ajuste do PR 5:** o destino pós-login e o resumo diário seguem os produtos sem esperar a chave. Escritório sem linha (todos os de hoje) continua indo ao Expediente e recebendo o resumo; só o escritório nascido pelo Voto, que só existe depois do PR 5, abre no Voto e fica sem o resumo. O PR 6 não liga a chave a esses dois.
- **D4.** "Sem linha" = nenhuma linha em qualquer situação; só linhas encerradas deixam só o núcleo.
- **D5.** (Samuel, 23/11: o cartão vale para todos.) Escritório sem linhas só ganha linhas na primeira vez que o dono **desliga** algo: o UseCase grava uma linha ativa para cada produto do catálogo, menos o escolhido (Voto e I.A. inclusive, porque a liberação continua valendo à parte), na mesma transação e sob trava por escritório. Escritório com linha `completo` (posta pelo Master) segue a mesma regra: a `completo` é encerrada e trocada pelas linhas. Consequência aceita: depois disso, um produto novo que entrar no catálogo não liga sozinho para esse escritório; o dono liga no cartão ou o Master põe `completo` de novo.
- **D6.** O Voto entra no cartão: liga (contratação e `LiberacaoDoVoto`) e desliga sem assembleia aberta. Desligar encerra só a contratação e não mexe na `LiberacaoDoVoto`; com a contratação encerrada, a guarda fecha as rotas e o cartão mostra desligado.
- **D7.** Não deixa desligar o último produto. A conta parte dos produtos efetivamente ligados (num escritório sem linhas, todos), nunca do número de linhas.
- **D8.** Rotas públicas:
  - os verificadores (`calculo_verificar`, `validacao_comprovante_ponto*`, `assinatura_eletronica_publica_validar*`) ficam sempre abertos;
  - `push_compartilhado`, a assinatura pública e as vagas conferem o produto do escritório do token, na própria ação, num PR pequeno logo depois do PR 6.
- **D9.** O produto do cadastro vem só da sessão. **Ajuste do PR 7:** `?produto=completo` (o link do aviso no "Criar conta") tira o produto guardado; os outros valores fora da lista continuam sem apagar.
- **D10.** Ligar o Voto pelo Master também libera a `LiberacaoDoVoto`.
- **D11.** O Conector (`conector_admin_`, `conector_perfil_` e `LiberacaoDoConector::exigir`) fica no **Expediente**. Ele usa a I.A. da conta da própria pessoa, sem custo Anthropic para o BlueJus (CatalogoDeRecursos.php:93), e as ferramentas dele leem pastas e clientes.
- **D12.** Rotas ambíguas como na §3.1.
- **D13.** O Master em produto não contratado não vai para o `audit_log`. **Ajuste do PR 6:** também não vai para o log de observação; a guarda sai antes de conferir o produto (§6, caso 4), como o molde `BloqueioDeRecursoDesativado`.
- **D14.** O teto padrão só é gravado quando o `orcamentoMensalCentavos` do escritório está nulo. Desligar e religar não muda o teto que o Master ajustou, e trocar o padrão não mexe nos tetos já gravados.
- **D16. Veto do Master.** O Samuel quer o dono ligando Voto e I.A. sozinho, mas o Master precisa poder travar um escritório. A trava é a última linha do produto **encerrada com `origem = 'master'`** (marca estável, já no CHECK, sem coluna nova; não depende de o usuário Master continuar existindo, porque `alterada_por_id` é `ON DELETE SET NULL`). Com ela, o dono não religa o produto (422) e o cartão mostra "Desligada pelo BlueJus"; só o Master religa. O estado de hoje (Voto ou I.A. nunca liberados) **não** é trava: o dono pode ligar. **No PR 8** (junto com o cartão, para não abrir janela em que o dono desfaz um bloqueio do Master): os bloqueios das abas de hoje "Voto" e "I.A." (`AlterarLiberacaoDoVotoUseCase`, `AlterarIaDoEscritorioUseCase`) passam a encerrar também a contratação do produto com `origem = 'master'` (materializando, se preciso), e a liberação feita pelo Master nessas abas reabre a contratação com `origem = 'master'`. A aba Produtos do PR 9 usa a mesma regra.
- **D15.** Desligar a I.A. também desliga `liberadaPeloMaster` (`bloquearPeloMaster()`). Os trabalhos em segundo plano não passam pela guarda e só olham `isLiberadaPeloMaster()` (DisponibilidadeDeInteligencia.php:104); só encerrar a contratação deixaria o gasto correr. O aceite fica guardado.

## 12. Testes obrigatórios

**PR 3, catálogo:**

- Unit:
  - vence o prefixo mais longo;
  - `cliente_` cai em dois produtos;
  - `inteligencia_`, `chat_ia_` e `central_ia_` caem em `ia`;
  - `dashboard_inteligencia_` e `cliente_inteligencia_cadastral` não caem em `ia`;
  - `conector_perfil_` cai no Expediente;
  - rota desconhecida cai no Expediente;
  - públicas e núcleo.
- Arquitetura: percorrer o router (molde tests/Arquitetura/TrabalhoExternoSemEscritorioArquiteturaTest.php:119-122). O teste quebra se alguma rota não tiver classificação explícita, e se alguma rota da lista pública não tiver caminho com `PUBLIC_ACCESS` no security.yaml (ou rota anônima conhecida).

**PR 4, contratação:**

- Unit da entidade (transições).
- Functional:
  - o índice único recusa duas linhas ativas do mesmo produto;
  - as regras do `ProdutosDoEscritorio` (sem linha, só encerradas, `completo`);
  - um escritório não vê as linhas do outro;
  - a purga apaga as linhas;
  - o `down()` da migration roda.

**PR 5, cadastro:**

- Unit, com `voto`:
  - grava contratação e liberação;
  - não semeia marcadores e não inclui `ia`;
  - produto inválido é ignorado;
  - sem produto, fica igual a hoje.
- Functional:
  - a sessão e o POST;
  - o caminho do Google;
  - o destino `voto_index`;
  - o resumo pula o escritório só Voto.

**PR 6, guarda (functional nos três modos):**

- `desligado`: nada muda.
- `bloqueio`, escritório só Voto:
  - `expediente_index` e `cliente_show` dão 404;
  - `voto_index` e `app_profile` dão 200;
  - o primário leva 404 (prova que a guarda fica fora do `PermissionChecker`) e o Master passa.
- Escritório com Expediente e sem `ia`: `chat_ia_pagina`, `inteligencia_admin_config` e `central_ia_pergunta` dão 404.
- Públicas e escopo:
  - a cédula anônima passa;
  - `/assinar/{token}` de outro escritório passa;
  - `/tenant/{tenantId}/sedes` usa o escritório da URL.
- JSON também dá 404.
- `observacao` registra e deixa passar.
- O `/mcp` recusa escritório sem Expediente.

**PR 7, menu:**

- só Voto: sem links do Expediente, e o logo leva a `voto_index`;
- sem `ia`: sem Chat I.A., e `ia_disponibilidade()` indisponível;
- completo: os mesmos links de hoje (filho direto do `ul`).

**PR do isolamento do Ponto (§8):** testes das telas de administração antes e depois; `TenantAwareCoberturaTest` sem as três entidades; R6 com lista exata.

**PR 8, ferramentas:**

- Unit:
  - só o dono;
  - escritório sem linhas: o cartão mostra tudo ligado, com Voto e I.A. conforme a liberação do Master;
  - primeiro desligar num escritório sem linhas grava as linhas dos produtos ligados e encerra só o escolhido; ligar num escritório sem linhas não grava linha de produto;
  - ligar o Voto pelo dono libera a `LiberacaoDoVoto` (cria se faltar);
  - a materialização grava todos os produtos menos o escolhido, Voto e I.A. inclusive, também a partir de uma linha `completo` ativa;
  - Master libera o Voto pela aba antiga depois da materialização: o Voto funciona sem linha nova;
  - produto travado pelo Master (D16) não religa pelo dono (422) e aparece "Desligada pelo BlueJus";
  - bloquear pelas abas de hoje "Voto" e "I.A." vira trava; liberar por elas desfaz a trava; a trava continua valendo se o usuário Master for apagado;
  - chave global do Voto ou da I.A. desligada: "Indisponível no momento", sem botão;
  - num escritório sem linhas, desligar sete produtos funciona e o oitavo é recusado (D7);
  - dois desligamentos simultâneos não quebram o índice nem deixam conjunto errado;
  - ligar a I.A. grava a contratação, `liberadaPeloMaster = true` e `Incluso` com o teto da `configuracao_global`, ou 500 quando a chave está vazia;
  - ligar a I.A. mantém o orçamento já gravado;
  - sem aceite, a I.A. fica `DesligadaNoEscritorio`;
  - desligar a I.A. encerra a contratação, chama `bloquearPeloMaster` e preserva o aceite;
  - o Voto com assembleia aberta não desliga;
  - os marcadores são semeados uma vez só;
  - o último produto não desliga;
  - desligar não apaga dado.
- Functional:
  - CSRF;
  - o Judicializar some e a rota dá 404 sem Expediente;
  - Clientes abre com Cobranças;
  - a auditoria fica gravada.

**PR 9, Master (functional):**

- Aba Produtos:
  - quem não é Master leva 403;
  - liga e desliga produtos de outro escritório, `ia` inclusive;
  - a auditoria fica no escritório alterado;
  - CSRF inválido dá 403; escritório inexistente dá 404.
- Teto padrão:
  - só o Master altera; fora da faixa dá 422;
  - a mudança entra na trilha de auditoria;
  - a tela de configurações não mostra nem apaga a chave `ia.tetoPadraoCentavos`;
  - mudar o padrão não altera nenhum `orcamentoMensalCentavos` já gravado;
  - o próximo escritório que o dono ligar recebe o padrão novo.
- Trava (D16): desligar pela aba Produtos impede o dono de religar.

## 13. Ordem dos PRs desta parte

1. Esta spec.
2. PR 3: catálogo de produtos (MÉDIO).
3. PR 4: contratação, com a migration `Version20261123180000` (MÉDIO).
4. PR 5: cadastro pelo Voto (ALTO).
5. PR 6: guarda do produto, desligada (ALTO).
6. PR 7: menu por produto (MÉDIO).
7. Isolamento do Ponto (§8, ALTO).
8. PR 8: ferramentas no Meu Perfil (ALTO).
9. PR 9: Master, aba Produtos e teto padrão da I.A. (MÉDIO).

Um PR por vez, cada um saído do `origin/master`.

## 14. Fora do escopo

- **De outra sessão:** PR 1 (site do Voto, repositório `sites`) e PR 2 (vhost `voto.bluejus.com.br`).
- **Do Samuel:** o runbook `docs/runbooks/voto-subir-na-vps.md` (DNS, certbot, `.env.prod`, `COMPOSE_PROFILES=voto`, ligar a chave do Voto e ligar a guarda).
- **Para depois:**
  - cobrança por produto;
  - período de teste;
  - homologação e testes cruzados A, B e C (Parte F, PRs 1 e 10).
- **Para os advogados:** a lista do `pacote-juridico.md`. Acrescentar a I.A. ligada pelo dono, com envio a suboperador externo depois do aceite do admin.
- **Não muda:** os textos "escritório" da interface (relatório D2, P5).
