# Aviso de login novo (e-mail ao usuário)

Risco ALTO (identidade e acesso). Parte 2 da spec `login-config-sessao-e-login-novo.md` (a parte 1, expiração
da sessão, é outra frente). Decisão do Coordenador (27/10): entra agora, sem depender de 2FA.

## Comportamento

Cada login interativo com sucesso (formulário, Google, Facebook; nunca lembrar-me nem API) grava a "impressão" do
acesso do usuário e, quando ela é nova para quem já tinha acessos, manda o e-mail "Novo acesso à sua conta".

| Situação | Grava | E-mail |
|---|---|---|
| Usuário sem nenhum acesso conhecido (primeiro login) | sim | não |
| Impressão já conhecida | atualiza `ultimo_em` | não |
| Impressão nova, usuário com acessos | sim | sim |
| Impressão nova, chave `avisoLoginNovo` = `off` | sim | não |
| Leitor da configuração falha | trata como `on` | conforme a linha acima |
| Falha de gravação ou de envio | nada propaga: o login segue, a falha vai para o log | |

## Impressão

`HMAC-SHA256(kernel.secret, navegador|sistema|faixa)`, em hexadecimal (64 caracteres).
- Navegador (só a família, sem versão: atualizar o navegador não gera aviso; o e-mail segue mostrando a versão) e sistema saem do User-Agent (família: Edge, Opera, Firefox, Chrome, Safari, Internet
  Explorer; sistema: Windows, macOS, Android, iOS, Chrome OS, Linux). Desconhecido vira `desconhecido`.
- Faixa: IPv4 /24 (último octeto zerado) ou IPv6 /48. IP ilegível vira `desconhecido`.
- O IP cru nunca é gravado, enviado por e-mail nem logado.

## Dados

Tabela `acesso_conhecido` (migration `Version20261027153500`): `id`, `user_id` (FK `user`, ON DELETE CASCADE),
`impressao` varchar(64), `primeiro_em`, `ultimo_em`; único (`user_id`, `impressao`). Sem tenant: a identidade do
User é global (mesma justificativa da `RedefinicaoSenha`), entra em `FORA_DO_ESCOPO` do `TenantAwareCoberturaTest`
(teto 88 para 89) e em `NAO_AUDITAVEIS` (a linha É o registro).

## Configuração

Chave `avisoLoginNovo` no grupo `seguranca` do `CatalogoDeConfiguracoes` (OPCOES `on|off`, padrão `on`), rotulada
em `master.js.config.campo.avisoLoginNovo` e `master.js.config.opcao.avisoLoginNovo.*`. Lida por
`ConfiguracaoDaPlataforma`.

## E-mail

Assunto "Novo acesso à sua conta". Corpo (`emails.acesso_novo.*`, template `email/acesso_novo.html.twig`): data e
hora no fuso da chave `fuso`, navegador e sistema, botão "Trocar a senha" para `/senha/esqueci` e a frase
"Se foi você, ignore". Sem IP. Um e-mail por impressão nova (cabe na cota da Hostinger).

## Retenção

`app:purgar-dados-expirados` ganha a etapa "Acessos conhecidos": apaga `ultimo_em` mais velho que `retencao` dias
(chave do catálogo, 365 padrão), com `--dry-run`.

## Fora

Cidade aproximada, lista de sessões, bloqueio de conta, 2FA.

## Testes

Unit: impressão (mesma faixa mesma impressão, outra faixa outra, IP cru ausente, IPv6), navegador/sistema, UseCase
(primeiro login, mesma impressão, impressão nova, `off`, falha do e-mail, falha do repositório, leitor que falha),
listener (interativo sim, lembrar-me não, falha não propaga), purga. Funcional: dois logins pelo formulário com
User-Agent e IP diferentes enviam 1 e-mail, só no segundo.
