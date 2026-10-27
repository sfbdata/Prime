---
tipo: novidade
---
## Changelog
- Login: o limite de tentativas de entrada com senha errada passa a vir da configuração "Tentativas" do Master (Segurança), de 1 a 50, padrão 5. Antes era 5 fixo no `security.yaml`.
- Novo `App\Security\LimitadorDeTentativasDeLogin` (`login_throttling.limiter`): lê o valor a cada requisição, mantém a janela fixa de 15 minutos e o limite por IP em 5 vezes o limite do usuário+IP. Valor ausente, inválido ou falha na leitura valem 5; o login nunca cai por causa da configuração. Sem migration.
- A mudança vale para as próximas janelas de contagem; a janela já aberta termina, em no máximo 15 minutos, com o limite com que começou.

## Para o cliente
- O Master do BlueJus pode ajustar quantas senhas erradas são aceitas antes de o login ser bloqueado por 15 minutos. Sem ajuste, continua em 5.
