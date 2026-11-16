---
tipo: interno
---
## Changelog
- Conector de I.A. (spec mcp-remoto-oauth, PR 2): domínio `App\Conector` com as entidades e repositórios das 7 tabelas `conector_ia_*` da 13.2 (cliente global, escritório, consentimento, concessão, código, token de acesso e de renovação numa tabela só, proposta), guardando só o SHA-256 de código, token e proposta; concessão com limite de 7 dias e motivo da revogação; rotação da renovação com janela de 60 s; migration `Version20261116190000`; purga do escritório apagando as 6 tabelas com escritório (o cliente é global e fica).
- `mcp/sdk` sobe de 0.7.1 para 0.10.0 (versão exata) e entra o `symfony/psr-http-message-bridge`; o servidor MCP de leitura (`mcp:server`) só troca dois namespaces e segue igual.

## Para o cliente
- Preparamos a base para ligar o BlueJus ao Claude e ao ChatGPT da sua equipe: o sistema já sabe guardar, com segurança, quais conexões cada pessoa autorizou em cada escritório e por quanto tempo elas valem. Ainda não há tela nem conexão disponível; elas chegam nas próximas versões, primeiro num escritório piloto.
