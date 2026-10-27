# Spec: prazo, cumprido e meta na publicação do Push (desenho 11, PR 4c)

Risco **MÉDIO**: mostra e grava data de prazo processual; prazo errado pode levar a perder prazo real.

## Decisões do dono (27/10)
1. Vale `prazo-do-push.md`: o prazo vem do teor, nada é presumido. O seletor 5/10/15/30/Outro é escolha do
   usuário, sem opção pré-marcada. Com prazo lido do teor, o modal o mostra.
2. Publicação avulsa (sem pasta) não cria meta (a Tarefa exige pasta): botão desabilitado, dica
   "Vincule uma pasta para criar a meta".

## Regra
- **Prazo exibido**, em ordem: (a) `publicacao_djen.prazo_dias`, escolhido pelo usuário (sempre dias úteis,
  calendário da UF do escritório, `CalculadoraDoPrazoDoPush::vencimento`); (b) a data do `pasta_prazo`
  (`origem=push`, `registro_ref=push:<id>`), quando o processo está numa pasta e o teor traz prazo em dias;
  (c) nenhum: sem selo, o modal mostra o seletor sem opção marcada.
- **Selo** (desenho): restante r = dias úteis de amanhã até o vencimento; vencido r<0. Textos: "Vencido",
  "Vence hoje", "Vence em N d". Cor: r<0 `#a3232b`, r<=2 `#c0392b`, r<=5 `#c76a12`, senão `#e6edf2`.
  Some quando cumprido.
- **Cumprido**: `prazo_cumprido_em` na publicação. Se há `pasta_prazo` do Push em aberto e o usuário edita a
  pasta, ele também é cumprido lá (sai dos filtros do Expediente). Desmarcar só vale quando nenhum
  `pasta_prazo` do Push está cumprido (o prazo da pasta não tem "descumprir").
- **Meta**: "Criar meta com este prazo" só com o processo numa pasta e prazo calculado. Cria a Tarefa
  (título "Manifestação: intimação de dd/mm/aaaa · número", prazo = vencimento, responsável = quem clicou,
  etapa Jurídico, prioridade Urgente se r<=5 senão Alta, descrição com o prazo e o intervalo) por
  `GravarMetaNovaUseCase`; guarda `tarefa_id` na publicação (uma meta por publicação).
  Desvincular o processo não apaga a meta; ela fica na pasta onde nasceu.
- **Aviso fixo no modal**: o prazo é calculado em dias úteis; confira o termo inicial na intimação
  (corridos, dobro, intimação pessoal e feriado municipal não entram na conta do seletor).

## Dados (migration 20261027152600)
`publicacao_djen`: `prazo_dias` smallint null, `prazo_cumprido_em` timestamp null, `tarefa_id` FK `tarefa`
ON DELETE SET NULL.

## Fora
Abrir o modal por clique na linha substitui a página da publicação (ela continua por URL direta).
