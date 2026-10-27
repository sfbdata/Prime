# Login: limite de tentativas configurável (Master, Segurança)

Risco ALTO (acesso). Depende do leitor `ConfiguracaoDaPlataforma` (PR do cadastro, #436).
Chave `tentativas` (número, padrão 5, de 1 a 50), já gravada pelo Master e hoje sem consumidor.

## Hoje
`security.yaml` > `login_throttling` com `max_attempts: 5` e `interval: 15 minutes`, estático. O
Symfony registra o `DefaultLoginRateLimiter` com dois limitadores: usuário+IP (`max_attempts`) e IP
(`5 * max_attempts`), janela fixa.

## Como fica
- Limitador próprio `App\Security\LimitadorDeTentativasDeLogin` (implementa a interface peekable abaixo),
  apontado em `login_throttling.limiter`. Por requisição lê `tentativas` do contrato
  `ConfiguracaoDaPlataforma`, monta os dois `RateLimiterFactory` (fixed_window, intervalo 15 min,
  limite `n` e `5 * n`, mesmo storage `cache.rate_limiter` e mesma chave/hash do
  `DefaultLoginRateLimiter`) e delega a um `DefaultLoginRateLimiter`.
- Valor ausente, não numérico ou fora de 1..50 (e qualquer falha do leitor) cai em 5. Nunca derruba o login.
- O intervalo de 15 minutos continua fixo (fora do catálogo).
- Mudança no Master vale para as janelas novas: o Symfony guarda o limite junto da janela, então a
  janela já aberta (no máximo 15 minutos) termina com o limite antigo.
- O limitador implementa `PeekableRequestRateLimiterInterface` (Symfony 7.4): só falha de login consome
  tentativa; `peek` olha sem gastar. Segredo do hash: `%kernel.secret%` (os contadores passam a
  sobreviver a deploys; o padrão do Symfony os zerava a cada build).
- Sem bloqueio de conta (PR 2b, à parte). Sem migration.

## Testes
- Unit do limitador: limite padrão 5; valor 3 bloqueia na 4ª; valor 10 deixa a 6ª; valor inválido e
  falha do leitor caem em 5; o limite por IP é 5x.
- Funcional: login errado N vezes com `tentativas` = 3 dá a resposta de excesso na 4ª; com padrão, na 6ª;
  login certo zera o contador do usuário+IP (`reset`).
