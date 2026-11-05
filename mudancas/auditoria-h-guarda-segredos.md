---
tipo: correcao
---
## Changelog
- Auditoria 4.07: a migration `Version20260508170000` só cria o usuário `e2e@jusprime.local` quando o banco termina em `_test`/`_e2e` ou com `E2E_SEMEAR_USUARIO=1` (variável real); a senha saiu do comentário. O `UserChecker` recusa conta `@jusprime.local` em `prod`.
- Auditoria 4.08: `app:create-super-admin` pede a senha sem eco quando o argumento falta, aplica a régua `ForcaDaSenha` e recusa a senha de exemplo.
- Auditoria 4.11: comando novo `app:conferir-segredos` (APP_SECRET com 32+ caracteres e diferente do modelo; `SYNC_CRYPTO_KEY` e `CONTRIBUA_CRYPTO_KEY` com 32 bytes e diferentes do modelo; nunca imprime valor), chamado pelo `scripts/go-live-check.sh`. Não entra no entrypoint nem no deploy por ora.
- Auditoria 4.09: a guarda de segredos reconhece a chave do Asaas (`$aact_`), só aceita `$` como referência quando o valor inteiro é uma variável curta, e acusa nomes com CHAVE, SEGREDO (em maiúsculas), CREDENCIA e CREDENTIAL. `.gitleaks.toml` com as regras equivalentes.
- Auditoria 4.10: no modo `--intervalo`, arquivo criado na resolução de um merge passa pela checagem de nome, e binário novo vira `binario-na-resolucao`. O workflow Segredos ganhou o passo "gitleaks na resolução dos merges" (`extrair-resolucao-merges.py` + `gitleaks dir`). Runbook atualizado.

## Para o cliente
- Reforço de segurança interno: a verificação automática que impede senhas e chaves de entrarem no código passou a reconhecer mais tipos de credencial (como a do gateway de pagamento e a do armazenamento de arquivos), e a criação de contas administrativas exige senha forte.
