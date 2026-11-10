---
tipo: interno
---
## Changelog
- Runbooks `ligar-ia-em-producao.md` (rebuild e rollback) e `mcp-investigacao-prod.md` (deploy): o `deploy-prod-tls.sh` passa a ser chamado com `PULAR_GIT_PULL=1`, como faz o deploy automático. Sem a variável, o script atualiza o código pelo repositório: num checkout na tag ele para antes do build, e num checkout no master traria para produção o que entrou depois da última versão publicada, sem backup nem volta.

## Para o cliente
- Ajuste nas instruções internas de manutenção do servidor: ao ligar ou desligar a inteligência artificial, o sistema continua exatamente na versão publicada, sem receber por engano partes ainda não lançadas.
