# Perfil próprio da I.A. (M5.18)

Spec da Frente C (23/11/2026). Pedido do Farlei (M5, item 9, `bluejus-coordenacao/farlei-2311-m5-ajustes.md:41`):

> Perfil próprio para a I.A. Um usuário identificado como Claude, com permissões definidas por módulo e todas as
> ações registradas na auditoria como feitas por I.A. sob sua supervisão, no mesmo padrão que você já adota nos
> registros das pastas.

Risco: **MÉDIO** (permissão por módulo; a identidade `User`/`Tenant` não muda, ver 2). Caminhos relativos a `app/src/`.
Base: `docs/specs/mcp-remoto-oauth.md` (o conector), em especial 4.3, 6.2 item 14, 6.3 e 14.b; e `docs/AUTORIZACAO.md`.

**Precisa do Samuel antes do código:** a decisão 1 (a I.A. não vira conta de login; isso se afasta da letra do pedido,
ver 2), as duas colunas novas (5.2 e 5.3) e o bloco novo na tela de Configuração da I.A., que não tem desenho (5.3).

## 1. O que existe hoje (mapa conferido em 23/11/2026, `origin/master` `30948e55c`)

- **O conector age como a pessoa.** A conexão é de uma pessoa num escritório (`Conector/Entity/ConcessaoDoConector.php:45-81`);
  o portão monta pessoa, escritório e vínculo (`Conector/DTO/ContextoDoConector.php:19-23`) e o firewall autentica a pessoa
  (`Conector/Security/AutenticadorDoConector.php:56-58`). Os UseCases recebem a pessoa como autora.
- **Não há marca visível de "feito pela I.A."** em lugar nenhum. O "padrão dos registros das pastas" que o pedido cita é o
  cartão do sistema no Registro (`Pasta/Service/RegistroDoSistemaNaPasta.php:20-66`, "escrito pelo sistema em nome de quem
  fez a mudança"), que mostra só o nome da pessoa (`templates/pasta/_dados_anotacoes.html.twig:291,308`).
- **Auditoria:** o `audit_log` grava ator, escritório e rota (`Entity/Audit/AuditLog.php:9-49`). A rota sai da requisição
  principal (`Service/Audit/AuditLogSubscriber.php:419-443`); pelo conector é `conector_mcp`
  (`Conector/Service/ServidorDeAutorizacao.php:44`, `ROTA_MCP`). O contexto grava `'origin' => 'web'` fixo (`:370`), campo
  que ninguém lê. A Trilha do escritório só mostra a rota no detalhe recolhido (`templates/audit/index.html.twig:202-203`).
  A Trilha do Master lista só entidades administrativas (`Master/Repository/PainelMasterRepository.php:106-125`): nem pasta
  nem Comercial.
- **Precedentes de "fala da I.A." com a pessoa como autora:** `Central/Entity/Mensagem.php:58-63` (`deIa`, nome exibido
  `BlueJus IA`, `Central/DTO/MensagemOutput.php:24`) e o Registro de auditoria da BlueJus I.A. da pasta
  (`Inteligencia/DTO/RegistroDeAuditoriaOutput.php:11-35`, "quem pediu").
- **Permissão da I.A.:** hoje é a da pessoa, mais o escopo do token (`mcp:leitura`, `mcp:escrita`), mais a
  `Conector/Ferramenta/TabelaDeAbaEChave.php` (módulo e chave por ação, conferidos em `GuardaDaAcaoDoConector::exigir`) e o
  teto da 6.3. O escritório só liga ou desliga o conector inteiro (`Conector/Entity/EscritorioDoConector.php:37-49`).
- **Não existe usuário de sistema ou robô** (`Entity/Auth/User.php` não tem tipo).

## 2. Decisão 1: "Claude" é a identidade visível, não uma conta de login

**Isto se afasta da letra do pedido** ("um usuário identificado como Claude"). A I.A. **não** ganha um `User` nem um
`UserTenant`: continua entrando pela conexão da pessoa, e a pessoa continua autora responsável (`actor_user_id`, autora do
registro). O que muda é o que se vê: toda ação feita numa chamada do conector aparece como **"Claude (I.A.), sob supervisão
de <nome da pessoa>"**, e o escritório define o que a I.A. pode fazer por módulo (5.3).

Por que não a conta própria (fatos do código):

1. **Quem supervisiona sumiria.** Com o robô como autor, a pessoa que pediu e confirmou não fica gravada em lugar nenhum,
   e é ela o "sob sua supervisão" do pedido.
2. **O OAuth é preso à pessoa:** concessão por `user`, impressão da senha e `sessoesEncerradasEm` (spec do conector, 4) e o
   termo aceito por pessoa (`Conector/Service/PortaoDoConector.php:109-114`). Um robô sem senha exige outro login.
3. **Ocuparia vaga e entraria no ponto:** `Auth/Service/VagaNoEscritorio.php:30-35` conta os vínculos ativos
   (`Contribua/Repository/UsuariosDoEscritorioRepository.php:21-30`) e `UserTenant.registraPonto` nasce `true`
   (`Entity/Auth/UserTenant.php:58`).
4. **Apareceria como colega** em cerca de 20 listas de equipe (cadeado, responsáveis, menções, redistribuição; ex.
   `Pasta/Service/EquipeDoCadeado.php:26-33`).
5. **E-mail único** (`User.php:23`): N escritórios exigiriam N contas ou um robô com N vínculos.

**O que a conta própria traria e o rótulo não traz:** filtrar a Trilha pelo "usuário Claude" como por qualquer pessoa
(aqui vira o filtro "Feito pela I.A.", 4.2) e um perfil (`TenantRole`) editável na tela de perfis (aqui vira o bloco de
níveis por módulo, 5.3). O custo dela: os itens 1 a 5, mais excluir o robô de vagas, ponto, listas de equipe e cobrança.

O rótulo é fixo, "Claude (I.A.)", como o pedido nomeia. Se o conector atender outro cliente de I.A., o rótulo passa a vir
do nome do cliente (`ClienteDoConector`); fora deste pedido.

## 3. Origem da ação (fonte única)

Serviço novo `Conector/Service/OrigemDaAcao` (`ehDaIa(): bool`): verdadeiro quando a **requisição principal** tem a rota
`ServidorDeAutorizacao::ROTA_MCP`. É a mesma fonte que o `AuditLogSubscriber` usa para gravar `route`, então a marca no
Registro e a da auditoria nunca divergem. Os UseCases não mudam de assinatura: quem precisa da marca pergunta ao serviço.

**Escopo da marca: só o que grava dentro da requisição `/mcp`** (inclusive o `depoisDoCommit` da confirmação, que roda na
mesma requisição, `ConfirmarPropostaDoConectorUseCase.php:121`). O que um worker faz depois, por consequência (ex.: a
sincronização com o Google, `Agenda/MessageHandler/SincronizarGoogleDaPessoaHandler.php`), não é ação da I.A.: sai como
hoje, sem rota. A ação de origem (o compromisso que a I.A. gravou) é a que leva a marca.

O `'origin' => 'web'` do contexto fica como está (ninguém lê); a regra de exibição é a coluna `route`.

## 4. Auditoria "feita por I.A. sob supervisão"

1. **Sem gravação nova e sem coluna nova no `audit_log`.** Toda gravação do conector já sai com `route = 'conector_mcp'`.
2. **Rótulo em um lugar só:** `Shared/Audit/RotuloDoResponsavel` monta "Claude (I.A.), sob supervisão de <nome>" quando a
   linha tem `route = conector_mcp`, e o nome (ou e-mail) nos demais casos. Texto pelas traduções, sempre escapado no Twig.
3. **Trilha do escritório (`/auditoria`):** o "Responsável" usa o rótulo (o e-mail continua no detalhe) e ganha o filtro
   "Feito pela I.A." (`route = conector_mcp`).
4. **Trilha do Master:** usa o mesmo rótulo nas linhas que já lista. O filtro de entidades não muda; hoje nenhuma delas é
   gravada pelo conector, então o efeito só aparece quando uma for.
5. **Linha do tempo da pasta:** o "Histórico do sistema" lê o `audit_log` (`Pasta/Service/PastaTimelineAssembler.php:253-265`);
   o `TimelineItemDTO` ganha `viaIa` (de `route = conector_mcp`) e o autor usa o rótulo
   (`Pasta/Service/MontadorDaTimelineInteligente.php:292-314`).

## 5. Registro da pasta e permissões por módulo

### 5.1 O cartão no Registro da pasta

Mensagem do Registro gravada numa chamada do conector mostra **"Claude (I.A.) · sob supervisão de <nome>"** no lugar do
nome, com o selo da I.A. Vale para a mensagem comum (`registrar_na_pasta`) e para o cartão do sistema que `mudar_andamento`,
`atualizar_pasta` e `prazos_e_metas_da_pasta` geram.

- A mensagem comum continua da pessoa: ela edita e exclui na janela, como hoje
  (`Pasta/UseCase/EditarMensagemPastaUseCase.php:57-58`). **Editar pela tela zera a marca** (o texto passa a ser dela); o
  `audit_log` guarda as duas versões.
- O cartão do sistema não é editável (o mesmo `:57-58`), como hoje.

### 5.2 Onde fica a marca (coluna nova, precisa do Samuel e de número de migration)

`pasta_mensagem.via_ia boolean NOT NULL DEFAULT false`, preenchida por `OrigemDaAcao` nos **dois** pontos de criação:
`Pasta/UseCase/EnviarMensagemPastaUseCase.php:124` e `Pasta/Service/RegistroDoSistemaNaPasta.php:57`. Como
`Mensagem.deIa` da Central. Aditiva, sem preenchimento retroativo.

### 5.3 Permissões da I.A. por módulo (coluna nova e bloco de tela sem desenho)

O administrador do escritório (`admin.inteligencia.manage`, a guarda de `Inteligencia/Controller/ConfiguracaoController.php:28`)
escolhe, na Configuração da I.A., o que a I.A. pode fazer em cada módulo: **Nada**, **Só ler** ou **Ler e gravar**. Os
módulos são os que o conector toca; Financeiro e Documentos ficam separados de Pastas porque já têm guarda própria na
pasta (aba Financeiro, recurso de peças).

**O módulo é da ação, não da ferramenta.** Cada par (ferramenta, ação) da `TabelaDeAbaEChave` cai em exatamente um
módulo; a regra é "o módulo do que a ação toca". Ponto de partida, a conferir item a item no PR 3:

| Módulo da I.A. | Ações |
|---|---|
| Pastas | `buscar_pastas`; `ler_pasta` (menos `financeiro`); `atualizar_pasta`; `mudar_andamento`; `registrar_na_pasta` (menos `relatorio_financeiro`); `prazos_e_metas_da_pasta` de prazo; `processos_da_pasta` |
| Documentos e peças | `ler_documentos_e_modelos`; `documentos_e_pecas`; `ler_clientes` `documentos_do_cliente` |
| Financeiro da pasta | `financeiro_da_pasta`; `ler_pasta` `financeiro`; `registrar_na_pasta` `relatorio_financeiro` |
| Clientes | `ler_clientes` (menos `documentos_do_cliente`); `atualizar_cliente` |
| Agenda e metas | `ler_agenda` (inclusive `minhas_metas`); `gravar_na_agenda`; `prazos_e_metas_da_pasta` de meta (`criar_meta`, `analisar_prazo_da_meta`) |
| Processos | `ler_processos` (inclusive `cnj`) |

- `quem_sou_eu` e `confirmar_acao` ficam sempre. As quatro do Master (`CatalogoFechadoDoConector::SO_DO_MASTER`) são da
  plataforma e não entram nos níveis do escritório.
- **"Só ler"** libera as ações de ferramentas do tipo Leitura (`CatalogoFechadoDoConector::FERRAMENTAS`) e recusa toda ação
  de ferramenta do tipo Gravação, inclusive as de análise dentro delas (conservador). Consultar o CNJ (`ler_processos`
  `cnj`) é leitura.
- **Onde a guarda roda:** em `GuardaDaAcaoDoConector::exigir($chamada, $ferramenta, $acao, ...)`, o funil comum que o
  `executar` de cada ferramenta e a confirmação já chamam antes do `reconferir`
  (`ConfirmarPropostaDoConectorUseCase.php:~173`). Na confirmação, vale a ferramenta da proposta, não `confirmar_acao`.
  Assim, baixar o nível entre propor e confirmar recusa a proposta pendente.
- **Recusa:** `ErroDaFerramenta::semPermissao` com o texto fixo novo "O escritório não liberou isso para a I.A." (constante).
- **Quem vê:** o `tools/list`, a `quem_sou_eu` e o guia escondem a ferramenta cujas ações estão todas em "Nada". O filtro do
  PR 16 do conector (`VisibilidadeDasFerramentasDoMaster::visivelPara(string, ?User)`, estático, usado em
  `DespachoDasFerramentas.php:60-70`, `GuiaDoConector` e `FerramentaEscondidaDaPessoa`) vira um serviço que recebe também
  o escritório da requisição e lê os níveis **uma vez por requisição** (sem cache entre requisições: mudar o nível vale na
  chamada seguinte, para subir ou para descer).
- **Padrão:** "Ler e gravar" em todos, que é o comportamento de hoje (o piloto não quebra). O administrador reduz.
- **Onde grava:** `conector_ia_escritorio.permissoes_da_ia jsonb NULL` (`EscritorioDoConector`, já `Auditavel`: a troca entra
  na Trilha). Nulo vale o padrão; chave que falta vale o padrão; chave desconhecida é ignorada na leitura e recusada ao
  salvar; valor fora de `nada|ler|gravar` é recusado ao salvar e lido como "Nada" se já estiver no banco (falha fechada).
- **Tela:** bloco "O que a I.A. pode fazer" na Configuração da I.A. do escritório (`templates/conector/_no_escritorio.html.twig`),
  uma linha por módulo com as três opções, salvar com mensagem fixa de sucesso ou erro. **Não há desenho**: segue a
  convenção da própria tela. Em "Conexões de I.A." (Meu Perfil), a pessoa vê os níveis do escritório, só leitura.

## 6. Fora deste pedido

Conta de login da I.A. (decisão 1); marca no que um worker grava depois (3); rótulo nos avisos do sino (continuam com o
nome de quem agiu, 6.2 item 14 do conector); marca em outras entidades que mostram "criado por" (compromisso, prazo,
documento, cliente: a marca fica na auditoria e na linha do tempo); BlueJus I.A. interna (`ChatIa/`); nível por pessoa (o
nível é do escritório); a Trilha do Master passar a listar entidades de pasta ou do Comercial.

## 7. Testes

1. `OrigemDaAcao`: verdadeiro numa chamada do `/mcp`; falso na tela, no worker e no comando.
2. Trilha do escritório: linha com `route = conector_mcp` mostra "Claude (I.A.), sob supervisão de"; o filtro "Feito pela
   I.A." traz só elas; a linha da tela mostra o nome. Nome de pessoa com HTML sai escapado.
3. Linha do tempo: evento do conector com o rótulo; da tela, com o nome.
4. Registro: mensagem e cartão do sistema pelo conector gravam `via_ia = true` e mostram o rótulo; pela tela, `false`; a
   autora edita a própria mensagem da I.A. na janela e a marca zera.
5. Módulo em "Só ler": leitura passa, gravação recusa com `sem_permissao`; proposta feita antes de baixar o nível recusa
   na confirmação. Módulo em "Nada": a ferramenta com todas as ações nele some do `tools/list`, da `quem_sou_eu` e do guia,
   e a chamada pelo nome recusa como ferramenta desconhecida. Ação que cruza módulo (`ler_pasta` `financeiro` com
   Financeiro em "Nada" e Pastas em "Ler e gravar") recusa.
6. Escritório sem configuração (nulo), com chave faltando ou desconhecida, e com valor inválido no banco: comportamento
   da 5.3. O nível de um escritório não muda nada em outro.
7. Só o administrador com `admin.inteligencia.manage` salva os níveis; a troca entra no `audit_log`.
8. Arquitetura: todo par (ferramenta, ação) da `TabelaDeAbaEChave` está em exatamente um módulo da I.A., exceto
   `quem_sou_eu`, `confirmar_acao` e `SO_DO_MASTER` (lista fixa, para não quebrar quando as quatro do Master entrarem).

## 8. PRs

| PR | O quê | Migration |
|---|---|---|
| 1 | `OrigemDaAcao`, `RotuloDoResponsavel`, rótulo e filtro na Trilha do escritório, rótulo na do Master e na linha do tempo (3, 4) | não |
| 2 | `pasta_mensagem.via_ia` e o cartão no Registro (5.1, 5.2) | sim (número do Coordenador) |
| 3 | `permissoes_da_ia`, módulo por ação, guarda no `exigir`, filtro do `tools/list`, tela (5.3) | sim (número do Coordenador) |

## 9. Decisões

1. Sem conta de login para a I.A.; "Claude" é a identidade visível, a pessoa é a supervisora e autora responsável (2).
2. Fonte única da origem: a rota da requisição principal (`conector_mcp`); só o que grava na requisição `/mcp` leva a marca (3).
3. Sem coluna nova no `audit_log`; rótulo montado num lugar só (4).
4. Marca no Registro por coluna `via_ia`, nos dois pontos de criação; editar pela tela zera (5.1, 5.2).
5. Permissão da I.A. por módulo e por escritório, com o módulo definido por ação, três níveis, padrão igual ao de hoje,
   guarda no `GuardaDaAcaoDoConector::exigir` (5.3).
6. Rótulo fixo "Claude (I.A.)" (2).
