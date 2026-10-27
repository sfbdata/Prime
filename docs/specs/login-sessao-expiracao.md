# Login: expiração da sessão (Master, Segurança)

Risco ALTO (acesso). Parte 1 da spec `login-config-sessao-e-login-novo.md`. Depende do leitor `ConfiguracaoDaPlataforma`.

## Expiração da sessão (`sessao`, minutos, 5..43200, padrão 24)

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
- DECISÃO FINAL (Coordenador, 27/10; produção confirmada: gc_maxlifetime 1440 s, cookie_lifetime 0): o padrão de `sessao` é o valor ATUAL real da produção, para não mudar
  comportamento. Parâmetro `app.sessao_padrao_minutos` (env `SESSAO_PADRAO_MINUTOS`, provisório 24 =
  gc_maxlifetime 1440 s do PHP) usado pelo catálogo; o Coordenador manda o número real (ini_get na imagem
  de prod) e só então se confirma.
- Mudança no Master vale na requisição seguinte (o listener lê a cada requisição).

### Testes
Unit do listener (relógio injetado): dentro do prazo mantém; passou invalida e redireciona; remember_me
não expira; anônimo ignorado; valor inválido usa o padrão. Funcional: sessão velha cai no login com o aviso.
