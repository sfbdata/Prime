# Login: expiração da sessão e aviso de login novo (Master, Segurança)

Risco ALTO (acesso). Rascunho para aprovação do Coordenador/dono antes de codar. Depende do leitor
`ConfiguracaoDaPlataforma` (já no master). Sem 2FA aqui (spec própria).

## 1. Expiração da sessão (`sessao`, minutos, 5..43200, padrão 480)

### Hoje
- Sessões em arquivo (`framework.session: true`, sem handler nem `cookie_lifetime`/`gc_maxlifetime`
  no projeto). O tempo real de vida vem do `php.ini` do container (padrão do PHP: `gc_maxlifetime`
  1440 s) e precisa ser CONFERIDO em produção antes de decidir o padrão.
- `remember_me` de 30 dias refaz o login quando a sessão some.
- Não existe tabela de sessões nem lista de "sessões ativas".

### Como fica
- Listener `App\Security\ExpiracaoDaSessaoListener` (kernel.request, depois do firewall): se há
  usuário autenticado POR SESSÃO (não por remember_me) e `agora - session.getMetadataBag()->getLastUsed()`
  passa de `sessao` minutos, invalida a sessão e manda para `/login` com aviso por tradução
  ("Sua sessão expirou por inatividade. Entre novamente."). Requisições em `/api` e XHR devolvem 401
  (o `base.html.twig` já trata 401 no sino).
- `gc_maxlifetime` passa a ser 43200 min (teto) para o arquivo nunca sumir antes do listener;
  quem manda é a configuração.
- Valor inválido ou falha do leitor: padrão. Nunca derruba página; `/login`, `/master` login e assets não são afetados.
- DECISÃO DO DONO: o padrão 480 min muda o comportamento atual se a produção hoje expira em 24 min.
  Só entra com o dono ciente. Alternativa: padrão do catálogo = o que a produção faz hoje.
- Mudança no Master vale na requisição seguinte (o listener lê a cada requisição).

### Testes
Unit do listener (relógio injetado): dentro do prazo mantém; passou invalida e redireciona; remember_me
não expira; anônimo ignorado; valor inválido usa o padrão. Funcional: sessão velha cai no login com o aviso.

## 2. Aviso de login novo (e-mail ao usuário)

- Tabela `acesso_conhecido` (migration pedida ao Coordenador: 153500): `user_id`, `impressao`
  (hash HMAC de navegador + faixa do IP /24, nunca o IP cru), `primeiro_em`, `ultimo_em`.
- Listener em `LoginSuccessEvent`: se a impressão não existe e o usuário já tinha algum acesso
  conhecido, grava e envia e-mail ("Novo acesso à sua conta", data, navegador, cidade aproximada
  fora do escopo) pelo `IdentidadeDeEmail`; no primeiro login de todos apenas grava. Falha de e-mail
  ou de gravação nunca derruba o login (try/catch, log).
- Retenção: apaga acessos não vistos há `retencao` dias (chave `retencao` do catálogo; 365 padrão).
- Só liga se o Master ativar? NÃO há chave no catálogo hoje: proposta de nova chave
  `seguranca.aviso_login_novo` (on|off, padrão off até o dono aprovar o texto do e-mail).

## 3. Fora

Lista de sessões ativas com "encerrar" (exige handler de sessão em banco, migration própria e
decisão de infraestrutura), 2FA, bloqueio de conta, nome do sistema.

## Perguntas para o Coordenador/dono (uma por vez)
1. Qual o tempo real de sessão em produção hoje (php.ini)? O padrão `sessao` mantém ou muda?
2. Aviso de login novo entra (e-mail) ou fica para depois do 2FA?
