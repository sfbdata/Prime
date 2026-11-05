---
tipo: correcao
---
## Changelog
- Auditoria 4.07 (resto): as senhas dos testes E2E não têm mais valor padrão no repositório. `e2e/senha-do-ambiente.js` (`senhaDoAmbiente(nome)`) lê da variável de ambiente e, sem ela, para o teste com mensagem dizendo qual variável definir no `e2e/.env`. Vale para `E2E_USER_PASSWORD` e para as senhas dos smokes (`E2E_VISUAL_SENHA`, `CENTRAL_E2E_SENHA`, `PONTO_ATALHO_E2E_SENHA`, `FECHAR_E2E_SENHA`, `SMOKE_SENHA`). Os dois smokes que se pulavam sem configuração seguem pulando quando a senha falta.
- `e2e/.env.example`: `E2E_USER_PASSWORD` vazio e comentário atualizado para a trava nova da migration.

## Para o cliente
- Reforço de segurança interno: as senhas usadas nos testes automáticos do sistema deixaram de ficar escritas no código.
