# Diário da OAB, entrega 3: pasta, prazo e meta

Contexto: `docs/specs/push-diario-oab-manual.md` (entregas 1 e 2 prontas).

## Escopo

1. **Pílula "Pasta N" / "Avulsa"** na linha (verde #1e7b45 / cinza #6b7c88), hoje fixa em "Avulsa" implícita.
2. **Vincular / Trocar / Desvincular pasta** (menu da linha e botão na linha aberta), reaproveitando o modal "Vincular a uma pasta" da aba Processos.
3. **Prazo**: coluna PRAZO mostra "Vence em N d" (verde > 5 dias úteis, âmbar 2 a 5, vermelho 0 a 1 e vencido), calculado por `PrazoDaPublicacaoCalculador` a partir de `dataPublicacao` + `prazoDias`. Hoje a coluna mostra só "N dias úteis".
4. **Criar meta com este prazo** (`bi-bullseye`), só com pasta vinculada (a Tarefa exige pasta, decisão do dono de 27/10).

## Modelo

- Decisão (desvio do rascunho): o vínculo é DIRETO com a pasta (`pasta_id`), não via `processo_id`. O número do Diário (ex.: "PED 2026.1234") normalmente não é CNJ e não existe como `Processo`.
- Migration única `Version20261027154500` (aditiva): `pasta_id` e `tarefa_id` nulos, FKs `ON DELETE SET NULL`, índice em `pasta_id`.
- Uma meta por publicação (`tarefa_id`). Sem pasta, sem meta (decisão do dono, 27/10): a linha mostra "Vincule a uma pasta para criar a meta".
- Prazo usa o selo do Push (`corDoSelo`, `selo_dias`, `selo_hoje`, `selo_vencido`), calculado por `PrazoDaPublicacaoCalculador`.

## Regras

- Só quem EDITA a pasta vincula/desvincula (`AcessoAPasta`); criar meta exige `podeVer` e permissão de criar tarefa.
- Publicação ética (sigilosa): a meta não copia o texto, só o título "Manifestação: ato de <data> · <ato>" (sigilo do Diário).
- Prazo vencido: selo "Vencido" (cor do selo do Push). Sem `prazoDias`: coluna vazia.
- Tudo por usuário e tenant, como as entregas 1 e 2.

## Testes (escrever primeiro)

Unit: `VincularPublicacaoDoDiarioAPastaUseCaseTest` (pasta alheia, sem permissão, desvincular), `CriarMetaDaPublicacaoDoDiarioUseCaseTest` (sem pasta, já tem meta, prazo ausente, título sem texto da publicação), cálculo do prazo (vencido, hoje, 3 dias).
Functional: vincular/desvincular por POST com token, isolamento por usuário e tenant, pílula Pasta N/Avulsa, "Vence em N d" por faixa, botão da meta só com pasta, meta criada aparece na Tarefa.
