# TenantAwareCoberturaTest sem conflito de merge

Risco: BAIXO (só teste e um atributo de declaração; sem migration, sem mudança de comportamento do sistema).

## Problema

`TenantAwareCoberturaTest` guarda a lista `FORA_DO_ESCOPO` e o teto `MAX_FORA_DO_ESCOPO` (número fixo, com um
comentário de histórico por alteração). Toda entidade nova que não é filtrada por tenant mexia em três pontos do mesmo
arquivo (a lista, o número e o comentário). Como o teto é uma linha só, dois PRs que sobem o número conflitam sempre.

## Decisão

- Entidade nova que não é filtrada por tenant declara isso **na própria classe**:
  `#[SemIsolamentoDeTenant('motivo de pelo menos 30 caracteres')]` (`App\Shared\Attribute`). Nenhum arquivo
  compartilhado muda, então não há o que conflitar.
- As duas listas existentes (`FORA_DO_ESCOPO` com 95 entradas, `PENDENTE_DE_CORRECAO` com 13) ficam **congeladas**: o
  teto continua valendo e só se edita para BAIXAR, ao tirar uma entrada. Migrar as 95 para atributo seria um PR
  que toca 95 entidades e conflita com todo mundo; não vale a pena.
- O teto numérico deixa de existir para o que entra por atributo. A garantia passa a ser: motivo obrigatório e com
  tamanho mínimo, conferido por teste, e a declaração aparece no diff da entidade, onde a revisão a vê.
- Testes: classificação aceita o atributo; `testDeclaracaoPorAtributoTemMotivoEMerece` recusa motivo curto, atributo em
  entidade que já é TenantAware e entidade declarada em dois lugares; `testAtributoClassificaAEntidade` prova a regra
  com classes anônimas.

## Para quem tem PR aberto mexendo na lista

Rebase uma vez: troque a entrada nova da lista (e a subida do teto) pelo atributo na entidade.
