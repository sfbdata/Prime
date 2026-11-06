---
tipo: alteracao
---
## Changelog
- `UserTenantRepository::findAtivoPorUserETenant` e `existeVinculoAtivo` guardam por requisição o vínculo ativo já achado (chave usuário + escritório). O layout pedia o mesmo vínculo de 4 a 7 serviços diferentes (cadeado, Central, ponto eletrônico, avisos, edição sem prazo, guarda da exclusão); agora vai ao banco uma vez.
- Resposta igual à da consulta: a memória só devolve o vínculo que continua no EntityManager e cujo `is_active` no banco (dado original do UnitOfWork) é true. Vínculo desativado ou removido some depois do flush, como antes; o "não achou" nunca fica guardado. Zera no `kernel.reset` (fim da requisição e cada mensagem do worker).
- Meu Perfil (página que mede o layout), somando a memória do layout (#663): de 57 para 40 consultas (primário) e de 55 para 37 (colaborador). Teto do `QueriesDoLayoutTest` baixado.
- Teste: `UserTenantRepositoryMemoriaTest` (equivalência com e sem memória, uma consulta para N leituras, desativar com e sem flush, reativar, remover, clear, reset do kernel).

## Para o cliente
- As páginas do sistema abrem um pouco mais rápido: o sistema confere uma vez só, em cada página, se a pessoa faz parte do escritório, em vez de conferir a mesma coisa várias vezes. Quem pode ver e fazer cada coisa continua exatamente igual.
