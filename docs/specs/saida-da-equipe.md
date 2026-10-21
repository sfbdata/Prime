# Spec: saída da equipe (data em que a pessoa deixou o escritório)

Risco: MÉDIO (grava junto da remoção do vínculo User↔Tenant, sem mudar quem entra). Empilhado sobre o #69
(`equipe-da-conta`). Pedido da frente b5 (desenho 05, item C6: seta de tendência por estado do painel do Master, que
conta entradas e SAÍDAS de pessoas nos últimos 30 dias). Combinado com a b5 e o coordenador em 19/10/2026.

## Estado da frente

O estado completo da sessão 7d está em `docs/specs/equipe-da-conta.md` ("Estado da frente"). Este PR tem 1 migration,
na fila depois da do #69.

## Problema

Remover alguém do escritório apaga o `user_tenant` (RemoverColaboradorDoEscritorioUseCase), então não sobra a data
da saída. Uma coluna em `user_tenant` não serve, porque a linha some.

## Regra

- Tabela `saida_da_equipe`, só de inclusão: `tenant_id` (NOT NULL), `user_id` (SET NULL se o usuário for apagado),
  `motivo` (`removido` | `saiu`, com CHECK), `era_primario` (o vínculo tinha perfil de sistema no momento da saída) e
  `saiu_em`. Índices (tenant_id, saiu_em) e (saiu_em).
- Gravada no `RemoverColaboradorDoEscritorioUseCase`, DENTRO da transação da remoção. Esse é o único caminho que apaga
  o vínculo: atende a tela de equipe (`removido`), o "Demitir" da equipe da conta (`removido`) e o "Sair do
  escritório" (`saiu`). Se a remoção for recusada ou falhar, nada é gravado.
- Suspender ou mandar para análise NÃO é saída: o vínculo continua e é reversível.
- Quem volta por convite ganha um vínculo novo (é uma entrada); a saída antiga fica no histórico.
- A purga do escritório apaga as saídas (`ORDEM_DELECAO`, antes de `user_tenant`).
- O domínio `Tenant` passa a ter entidades mapeadas (`config/packages/doctrine.yaml`, bloco `AppTenant`).

## Contrato com a b5

- Nome final: `saida_da_equipe.saiu_em`, `era_primario`, `user_id`, `tenant_id`.
- Leitor: `SaidaDaEquipeRepository::contarNoPeriodo($de, $ate, $soPrimarios)` (SQL direto, sem TenantFilter, porque o
  painel é da plataforma). A b5 calcula a UF da pessoa pelo mesmo critério das entradas e, se `user_id` vier nulo, usa
  a UF do escritório.
