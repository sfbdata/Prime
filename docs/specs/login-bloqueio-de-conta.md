# Login: bloqueio temporário da conta por tentativas (2b)

Risco ALTO (identidade/acesso). SPEC PARA APROVAÇÃO DO DONO. Não implementar antes do aval.
Depende do que já está no master: leitor `ConfiguracaoDaPlataforma`, chave `tentativas` (1..50, padrão 5),
`LimitadorDeTentativasDeLogin` (#465: limite por usuário+IP e por IP, 15 min), `avisoLoginNovo` (#488).

## Problema
O limitador de hoje conta por usuário+IP e por IP. Ele para quem ataca de um IP só. Não para um ataque
distribuído (muitos IPs, uma conta): cada IP tem seu contador, e a conta-alvo nunca trava. O rótulo do
desenho 05 ("Bloquear login após (tentativas)") promete bloqueio da conta.

## Proposta
- Contador por conta: colunas novas em `user`: `tentativas_falhas` (int, 0), `bloqueada_ate`
  (timestamp nulo), `ultima_falha_em` (timestamp nulo). Migration (número a pedir ao Coordenador).
- Limite da conta = `tentativas` x 2 (default 10), para o usuário digitando errado não ser barrado antes
  do limitador por IP (5). DECISÃO DO DONO: mesmo valor de `tentativas` ou múltiplo.
- Falha de senha incrementa; no limite, `bloqueada_ate = agora + 15 min` e o contador volta a zero ao
  expirar. Login certo zera o contador. Bloqueio SEMPRE temporário (nunca permanente): evita que um terceiro
  tranque a conta de alguém para sempre.
- Resposta idêntica à de senha errada ("E-mail ou senha incorretos."), sem dizer que a conta está bloqueada
  (não revela existência da conta nem o estado). O e-mail ao dono da conta avisa do bloqueio (uma vez por
  bloqueio) com o link "Esqueci a senha".
- Redefinição de senha por link e "Esqueci a senha" continuam funcionando durante o bloqueio e, ao concluir,
  zeram o bloqueio (quem prova a posse do e-mail destrava).
- Master destrava manualmente pela ficha do usuário (ação auditada). Conta inexistente: nada é gravado.
- Onde: `UserChecker::checkPreAuth` (conta bloqueada = mesmo erro de credencial) e listeners de
  `LoginFailureEvent`/`LoginSuccessEvent`. Atualização por UPDATE atômico (`tentativas_falhas = tentativas_falhas + 1`)
  para não perder contagem em tentativas simultâneas e para não fechar o EntityManager.
- Ordem: o limitador por IP continua antes; o bloqueio da conta é a segunda camada.

## Riscos e decisões que ficam com o dono
1. DoS de conta: um terceiro que erra a senha de propósito trava a vítima por 15 min, repetidamente.
   Mitigação: bloqueio curto, destravar por "Esqueci a senha", e-mail de aviso. Aceita esse risco?
2. Master e contas de serviço: bloquear também o Master? Recomendo SIM com o mesmo critério (é o alvo mais
   valioso), com destravar por redefinição.
3. Login pelo Google/Facebook não passam pela senha: não contam nem são barrados pelo bloqueio da conta.
4. Quanto vale o bloqueio (15 min fixo ou progressivo 15/60/240 min). Recomendo progressivo com teto de 4 h.
5. Retenção: `ultima_falha_em` antiga zera o contador (janela de 24 h sem falha).

## Testes previstos
Unit do UseCase de registro de falha (incrementa, bloqueia no limite, expira, zera no sucesso, não grava
conta inexistente), funcional (N falhas de IPs diferentes bloqueiam a senha certa; senha certa após o prazo
entra; redefinição destrava; resposta igual à de senha errada), concorrência por UPDATE atômico, e-mail uma
vez por bloqueio, destravar do Master auditado.
