# Login: verificação em duas etapas (2FA) por TOTP

Risco ALTO (identidade/acesso). SPEC PARA APROVAÇÃO DO DONO. Não implementar antes do aval.
Hoje não existe 2FA no sistema. A configuração `dois` do Master (desenho 05) já grava master|prim|todos|opc
e nada consome. Recomendação do Coordenador: TOTP opcional por usuário, com códigos de recuperação.

## Escopo
- Segundo fator: TOTP (RFC 6238, SHA-1, 6 dígitos, 30 s, janela de 1 passo para frente e para trás),
  compatível com Google Authenticator, Authy, 1Password etc.
- Política `dois` do Master: `opc` (cada um decide, padrão proposto), `master` (obrigatória só para o Master),
  `prim` (Master e primários), `todos`. Quem é obrigado e ainda não ativou: depois do login com senha,
  é levado a ativar antes de entrar no sistema (sem pular).
- Fora: SMS, e-mail como segundo fator, chaves de segurança (WebAuthn): ficam para depois.

## Como funciona
1. Ativar (tela "Segurança" no Meu Perfil): gera o segredo, mostra QR code e a chave em texto; o usuário
   digita o primeiro código para confirmar; só então liga. Mostra 10 códigos de recuperação uma única vez.
2. Login: senha certa (ou Google/Facebook) → se o usuário tem 2FA, a sessão fica "meio autenticada" (token
   com papel apenas de pré-2FA) e vai para `/login/2fa`; código correto completa o login. "Manter conectado"
   só vale depois do 2FA. Reuso do mesmo código na mesma janela é recusado.
3. Recuperação: um código de recuperação vale uma vez e é consumido. Sem código nem aplicativo: o Master
   desativa o 2FA da conta (ação auditada, com aviso por e-mail ao dono da conta).
4. Desativar exige senha atual + um código. Trocar o aparelho = desativar e ativar de novo.
5. Contador próprio de tentativas no passo 2FA (mesmo limitador do login) para impedir força bruta de 6 dígitos.

## Dados (migration, número a pedir ao Coordenador)
- Tabela `segundo_fator`: `user_id` (único, cascade), `segredo_cifrado` (reuso de `CifradorDeSegredo`, sodium
  secretbox, chave fora do banco; mover/duplicar a classe para `Shared` se necessário), `ativado_em`,
  `ultimo_passo_usado` (anti-reuso), `criado_em`.
- Tabela `codigo_de_recuperacao`: `user_id`, `hash` (password_hash do código), `usado_em`. 10 por usuário,
  regenerar invalida os anteriores.
- Allowlists: TenantAware (por usuário, sem tenant) e Auditavel (ativar, desativar, usar código, destravar
  pelo Master entram na trilha).

## Riscos
1. Perda do aparelho sem códigos: bloqueio do usuário. Mitigação: códigos na ativação, Master desativa.
2. Master com 2FA obrigatório e sem segundo fator ativo no deploy: tem de poder ativar sem ficar de fora.
   Fluxo: primeiro login pós-deploy leva à ativação; nunca bloqueia o acesso de quem ainda não ativou
   antes de ter onde ativar.
3. Segredo vazado do banco: protegido por cifra com a chave do servidor (mesmo padrão do Drive).
4. Relógio do aparelho/servidor: janela de ±1 passo; NTP do servidor.
5. Login pelo Google/Facebook: o 2FA do BlueJus vale também depois deles? Recomendo SIM (senão é bypass).
6. API/remember_me: cookie "lembrar-me" reautentica; com 2FA ativo o remember_me só vale se o token foi
   emitido depois do 2FA (decisão de implementação: o remember_me do Symfony não carrega esse estado,
   exigir 2FA de novo ao reautenticar por cookie é a opção segura, ao custo de pedir o código ao voltar).
7. Biblioteca: avaliar `spomky-labs/otphp` (ou implementar o TOTP, ~40 linhas com `hash_hmac`); dependência
   nova em composer.json = arquivo quente, aviso antes.

## Decisões do dono
- Padrão da política `dois`: `opc` ou `master`?
- 2FA depois do login social: sim (recomendado)?
- Remember_me exige o código ao voltar (recomendado)?
- Aprova adicionar `spomky-labs/otphp` ou prefere implementação própria?

## Testes previstos
Unit do TOTP (vetores do RFC 6238, janela, anti-reuso), do UseCase de ativar/desativar/recuperar, dos códigos
(consumo único); funcional do fluxo senha → /login/2fa → entrada, código errado, códigos de recuperação,
política por perfil, Master obrigatório sem ativação, auditoria.
