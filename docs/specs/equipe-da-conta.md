# Spec: equipe da conta (PR 4 do redesenho do Meu Perfil, VS 18.10.26)

Risco: **ALTO** (vínculo User↔Tenant: quem entra no escritório). Revisão read-only antes de seguir e de novo depois
das correções.
Desenho: `docs/design/meu-perfil-18-10/bj-contas.js` (função `desenhar`, aba `conta`; `situacao`, `abrirMnu`, `convidar`,
`remover`) e o print do dono "Conta do escritório · Minha conta" (equipe com PRIMÁRIO/SECUNDÁRIO e APROVADO, ⋮, convidar).
Contratos com outras frentes: b5 (suspensão da conta, ordem das barreiras no acesso), 0d (coluna em `user_tenant`).

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
  uma trava recusar, nada muda.
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
