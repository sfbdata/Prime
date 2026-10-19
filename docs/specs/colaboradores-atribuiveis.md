# Spec: quem pode ser atribuído (seletores de responsável com a equipe da conta)

Risco: BAIXO (seletores e gravação de atribuição; não muda acesso nem permissão). Empilhado sobre o #69
(`equipe-da-conta`), que criou a situação na equipe e o serviço `ColaboradoresAtribuiveis`.
Decisão da coordenação (19/10/2026): suspenso e em análise não recebem atribuição NOVA; editar um registro preserva quem
já estava atribuído.

## Estado da frente

O estado completo da sessão 7d está na seção "Estado da frente" de `docs/specs/equipe-da-conta.md` (decisões do dono,
contratos, PRs e a fila de migrations). Este PR não tem migration e entra depois do #69.

## Regra

`App\Tenant\Service\ColaboradoresAtribuiveis`:
- `doEscritorio(tenant, atuais)`: monta a lista do seletor (aprovados mais quem já está no registro).
- `resolver(id, tenant, atuais)` e `resolverVarios(ids, tenant, atuais)`: conferem na GRAVAÇÃO o id que voltou do
  navegador, com a mesma regra. O que não pode (de outro escritório, suspenso que não estava no registro) fica de fora
  (vários) ou dá 404 (um só, como já era).

## Onde entra

| Tela | Lista | Gravação | Antes |
|---|---|---|---|
| Chamado (ServiceDesk, "Atribuir para") | `doEscritorio` com o responsável atual | `resolver` com o atual | a suspensa responsável sumia da lista e o formulário mandava "Não atribuído"; reenviar dava 404 |
| Pasta (menu do responsável, show) | `doEscritorio` com o atual | `resolver` com o atual (PATCH) | escolher de novo a responsável suspensa dava 404 |
| Meta (detalhe, responsáveis) | `doEscritorio` com os responsáveis | `resolverVarios` com os atuais | oferecia todo mundo do escritório, inclusive suspensos |
| Meta nova pela pasta | (lista da pasta) | `resolverVarios` sem atuais | aceitava um suspenso enviado pelo navegador |
| Kanban (criar mural, editar mural) | `doEscritorio` (com criador e participantes ao editar) | `resolverVarios` (com os participantes atuais ao editar) | oferecia e aceitava suspensos |
| Kanban (responsáveis do cartão) | (participantes do mural) | `resolverVarios` com os responsáveis atuais | aceitava suspensos |

Fora deste PR:
- O formulário de Evento da Agenda é da frente 4b.
- A lista do Expediente é uma só para todas as linhas, e a pessoa atual aparece no chip de cada linha. Pôr os atuais de
  cada linha na lista mostraria a suspensa em todas; o menu só grava quando alguém escolhe outra pessoa, então nada se
  perde. O arquivo também é tocado por vários PRs abertos.
- O campo `responsavel` do `ChamadoType` só existe com `is_admin`, e nenhuma tela usa essa opção (a atribuição é pelo
  formulário próprio do show).
- Listas só de leitura (Dashboard, Central, menções, filtros) já mostram só os ativos.
