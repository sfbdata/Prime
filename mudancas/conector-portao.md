---
tipo: interno
---
## Changelog
- Conector de I.A. (PR 1, spec `mcp-remoto-oauth`): barreiras de acesso ao escritório extraídas do `TenantContextValidatorListener` para o `BarreirasDoEscritorio` (sem sessão, com o passe do Master como parâmetro); o navegador segue igual.
- `PortaoDoConector` (`conferir` e `abrir`): confere pessoa, vínculo ativo e aprovado, barreiras sem o passe do Master (o primário de conta suspensa a pedido é recusado), Termos de Uso vigentes; liga e confere o filtro `tenant` (falha fechada), fixa o escritório e o vínculo no `TenantContext` sem sessão (`fixarSemSessao`) e liga o `ModoDoConector`.
- `ModoDoConector`: para a pessoa do token, sem os passes do Master e do perfil de sistema no `PermissionChecker` e na `RegraDosPrimarios`, sem `admin.*`; o perfil de sistema fica com toda permissão que não é `admin.*` e passa pelo cadeado e pelas abas; zera no `reset()`.
- Teste de arquitetura `PortaoDoConectorArquiteturaTest`.

## Para o cliente
- Preparamos a base de segurança para conectar o BlueJus a assistentes de inteligência artificial: cada conexão vai valer para um escritório só, com as mesmas travas de acesso do sistema, e sem as funções de administrador. Ainda não há tela nova; o uso do BlueJus no navegador continua igual.
