# Spec: equipe da conta (PR 4 do redesenho do Meu Perfil, VS 18.10.26)

## Estado da frente (sessão 7d, redesenho Meu Perfil e menu do usuário; atualizado em 19/10/2026)

Mesma seção nas specs `meu-perfil-pagina.md`, `meu-perfil-oab-inscricoes.md` e `equipe-da-conta.md`. Depois de uma
compactação de contexto, releia antes de continuar.

**Decisões do dono**
- 19/10: tudo o que está no desenho entra (03 EXPEDIENTE e 07 Meu Perfil, VS 18.10.26); nada existente sai.
- 19/10: fidelidade ao desenho é critério de pronto (lista ✓/✗ e capturas antes do PR); o navegador está liberado na
  porta 8102, com o banco isolado `demo_menu7d`.
- 19/10: "Convites do escritório" só para o usuário primário (e o Master), como no desenho; os itens do Master sem
  tela no sistema (Central de vagas, Campanha por e-mail) aparecem como "em preparação"; idioma do sistema entra só
  como seletor (tradução das telas é projeto à parte); ordem PR 1 (menu) e depois PR 2 (página).
- 19/10: excluir conta mantém a quarentena de 365 dias, recuperável pelo Master; nada do lado do primário diz
  "irreversível".
- Decisões da coordenação (19/10): o secundário edita a própria data de admissão, com auditoria; suspenso e em análise
  não recebem atribuição nova, e editar preserva quem já estava (`ColaboradoresAtribuiveis`).

**Contratos com outras sessões**
- b5: ordem das barreiras no `TenantContextValidatorListener`: (a) escritório suspenso ou excluído, (b) pedido de
  exclusão pendente (PR 5), (c) vínculo, (d) situação. Nomes "escritório suspenso" e "acesso suspenso / em análise".
  Quarentena = Tenant com `excluidoEm` (purga em `tenant_carencia_purga_dias`, 365); os 365 dias contam da decisão do
  Master. Entre o pedido e essa decisão, o pedido fica "suspensa a pedido". `PedidoDeExclusaoDeConta` "excluida" com
  escritório ativo = recuperada; o `ReativarContaUseCase` não mexe no pedido. O botão do Master é "Excluir conta".
- 0d: também acrescenta coluna em `user_tenant` (sem conflito de nome).
- 5d: presença, "Bater ponto" (módulo de Ponto existente), o parcial `layout/_globais/fechar.html.twig` e
  "Instalar aplicativo" no menu do usuário (vai avisar o arquivo).
- f6: põe o item Contribua e a linha do pageTitle; ponto de inclusão `contribua/_assinatura_no_perfil.html.twig`.
- 4b: dona do formulário de Evento (fica fora do PR de `ColaboradoresAtribuiveis`).
- "Comercial e e-mails": lê a tabela de indicações por um UseCase público de consulta, com limites (PR de Indicar).

**PRs e pilha** (atualizado em 20/10/2026)
- #53 menu do usuário: mergeado.
- #60 página Meu Perfil (`meu-perfil-pagina`, base master). Em cima dele: #144 (a7: sons e o selo da f6).
- #65 inscrições na OAB (`meu-perfil-oab`, EMPILHADO sobre #60). Em cima dele: #143 (a7), #125 e #131 (0d e b5,
  exclusão de conta).
- #69 equipe da conta (`equipe-da-conta`, base master; master de 20/10 trazido, com o conflito do listener com o #72
  resolvido).
- #89 seletores com `ColaboradoresAtribuiveis` (`colaboradores-atribuiveis`, EMPILHADO sobre #69, sem migration).
- #161 saída da equipe (`saida-da-equipe`, EMPILHADO sobre #69; rascunho até a suíte).
- Listener de acesso (`TenantContextValidatorListener`) é quente: #73 #79 #84 #87 #98 #99 #103 #116 #117 #119 #125
  #155. Quem entra depois resolve.

**Fila de migrations** (a coordenação controla; o nome final é passado ao coordenador antes de renomear)
- Nomes finais dados pela coordenação (20/10): #60 `Version20261020131000` (mergeado, feeedb23) → #65
  `Version20261020131500` → #69 `Version20261020132000` → #161 `Version20261020136500` (o #89 não tem migration).

**Passadas a outras sessões (20/10)**
- Login (desenho 10): bluejus-9b, #101. O inventário da 7d e a confirmação do dono sobre os textos de segurança foram
  entregues.
- Indicar o BlueJus: bluejus-9b, #119.
- PR 5 (excluir conta em 2 etapas): bluejus-0d, #125.

**Para o dono**
- Remover a worktree vazia `.claude/worktrees/login-vs1910` (aberta pela 7d, não usada): `git worktree remove
  .claude/worktrees/login-vs1910`.
- Pendente com a coordenação: terceiro campo do convite, Cargo (desenho) ou Perfil (implementado no #69).

**Pendências**
- Depois de cada merge: trazer o origin/master para as branches que ficaram para trás, migrar o banco de teste da
  frente e rodar a suíte (fila única: `flock /tmp/bluejus-suite-completa.lock ... -p 3`).

**Próximo passo**
- Fechar #69 (suíte), #89 e #161; atender as revisões e os merges na ordem da fila.

## 1. O que a tela mostra (aba "Minha conta" do modal "Conta do escritório")

Só para o **primário do escritório ou o Master** (como "Abrir conta"); o secundário continua vendo só "Trocar conta".

1. Resumo: "Escritório: {nome}" e "Você: {nome} · Usuário primário".
2. **EQUIPE DA CONTA (N)**: uma linha por pessoa com vínculo no escritório (aprovados, em análise e suspensos):
   avatar com iniciais (azul para o primário, cinza para os demais), nome, "cargo · e-mail", selo PRIMÁRIO ou
   SECUNDÁRIO e, para quem não é primário, o selo da situação: **APROVADO** (verde), **EM ANÁLISE** (âmbar),
   **SUSPENSO** (vermelho). Botão ⋮ e clique com o botão direito na linha abrem o mesmo menu.
3. Menu da pessoa (título "{nome} · {situação}"): **Aprovar** (ou "Reativar (aprovar)" se suspensa), **Voltar para
   análise**, **Suspender acesso** (confirmação: "Os dados e o histórico continuam guardados."), separador,
   **Demitir (remover da conta)** (confirmação). A opção da situação atual não aparece. Sobre o primário, sobre o
   Master e sobre a própria pessoa: "Sem ações disponíveis". "Entrar como (demonstração)" é só do protótipo e não entra.
4. **CONVIDAR (ENTRA COMO SECUNDÁRIO)**, com a nota "Clique com o botão direito (ou em ⋮) numa pessoa para aprovar,
   suspender, voltar para análise ou demitir." e os campos Nome, E-mail e Perfil, e o botão "Enviar convite".
   (Ver §5, pergunta em aberto, sobre o terceiro campo.)

## 2. Situação do usuário no escritório

- Coluna nova `user_tenant.situacao`: `aprovado | em_analise | suspenso`, padrão `aprovado`. Vínculos existentes
  ficam aprovados; convites aceitos nascem aprovados (o desenho não muda isso).
- **`isActive` acompanha a situação** (ativo só se aprovado). Assim todas as portas que já conferem o vínculo ativo
  passam a barrar suspensos e em análise sem mudar nada: login e seleção (`findActiveByUser`), troca de escritório
  (`setCurrentTenant`), permissões (`PermissionChecker`), seletores de responsável, menções, Central, notificações a
  gestores e contagens. A situação dá o motivo para a mensagem certa.
- Regras (UseCase `AlterarSituacaoNaEquipeUseCase`): só o primário do escritório ou o Master
  (`RegraDosPrimarios::podeGerirPrimarios`); nunca sobre o primário (vínculo de perfil de sistema), nunca sobre um
  Master, nunca sobre si mesmo. Mesma situação: nada muda. Auditado (`UserTenant` é Auditavel).
- **Demitir** usa `RemoverColaboradorDoEscritorioUseCase` (o mesmo "Remover do escritório" da tela de equipe), sem
  substituto: o que estava com a pessoa fica sem responsável, como na opção "Deixar sem responsável" de lá. Valem
  todas as travas atuais (titular, último administrador, Master) e o primário não é demitido por aqui (como no menu).
  Quem está suspenso ou em análise é reaprovado e removido na MESMA transação (a remoção só acha vínculo ativo); se
  uma trava recusar, nada muda. Na auditoria fica a reaprovação técnica seguida do registro explícito da remoção.
- **Convite não passa por cima da situação**: criar convite para quem está suspenso ou em análise no escritório é
  recusado ("Altere a situação dela na equipe da conta"), e aceitar convite também (com a mensagem de acesso).
- **Atribuição** (decisão da coordenação, 19/10/2026): suspenso e em análise não aparecem para atribuição NOVA; editar
  um registro preserva quem já estava atribuído. Ponto único: `App\Tenant\Service\ColaboradoresAtribuiveis`
  (aprovados + atuais do registro). Os formulários de outros domínios passam a usá-lo em PR próprio, logo depois deste
  (o de Evento fica com a frente da Agenda). Dashboard e metas da equipe ficam como estão (sem quem está suspenso).

## 3. Acesso (contrato com a b5, registrado também em `perfis-1.2-contas.md` C3 e C13)

`TenantContextValidatorListener`, um método privado por barreira, nesta ordem; vale a mensagem da primeira que falhar:

1. (a) **escritório suspenso ou excluído** (b5; a mensagem "Este escritório está suspenso. Fale com o responsável pela
   conta." entra com o PR da b5, até lá vale a mensagem atual)
2. (b) **pedido de exclusão pendente** (PR 5).
3. (c) **vínculo ausente** (removido) ou inativo de legado: mensagem atual.
4. (d) **situação do usuário** (este PR):
   - suspenso: "Seu acesso ao escritório {nome} está suspenso pelo usuário primário. Seus dados e o histórico
     continuam guardados."
   - em análise: "Seu acesso ao escritório {nome} está em análise pelo usuário primário."

O Master não passa pela (d). A mesma mensagem da (d) aparece na tela de seleção para os vínculos suspensos ou em
análise (que não entram na lista de escritórios), para a pessoa entender por que não vê o escritório.

## 4. Rotas

- `GET /escritorio/equipe`: aba "Minha conta" (parcial carregado sob demanda, como "Trocar conta"). 403 para quem não
  é primário nem Master.
- `POST /escritorio/equipe/{userTenantId}/situacao` (`situacao`, CSRF): só vínculos do escritório da sessão (404 se
  for de outro).
- `POST /escritorio/equipe/{userTenantId}/demitir` (CSRF).
- Convidar: o formulário da aba envia para a rota que já existe (`auth_gerenciar_convites_criar`), com as mesmas
  regras e o mesmo e-mail; com `_voltar=1`, a resposta volta para a página anterior (só endereço do mesmo site).
  "Convites do escritório" (menu e abas do perfil) abre esta aba, como no desenho; a página de convites pendentes
  (revogar e reenviar) fica no link "Ver convites pendentes" da aba.

## 5. Pergunta em aberto (enviada ao coordenador)

O desenho convida com **Nome, E-mail e Cargo** (lista fixa: Advogado, Advogada, Controladoria, Coordenadora,
Assistente, Estagiário, Financeiro) e diz que o convidado entra como secundário. O sistema exige um **perfil de
permissões** (TenantRole) no convite e o escritório não nasce com perfil secundário nenhum; o cargo (`Cargo`) é por
escritório e não existe no convite. Recomendação: o terceiro campo lista os perfis secundários do escritório
(`findSecundarios`), com o rótulo "Perfil"; sem perfil secundário cadastrado, a aba mostra o atalho para criar um.
