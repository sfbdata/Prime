# Bônus de espaço por indicação ("5 GB extras no 1º mês")

Risco MÉDIO (cota de armazenamento, domínio Contribua). Origem: o desenho `10 - Login - VS 25.10.26` promete
"Cupom válido · você ganha 5 GB extras no 1º mês". Regra do dono (26/10): o que o desenho propõe e o sistema não tem,
cria-se. Regras 1 a 6 abaixo foram aprovadas como **provisórias** pelo coordenador (o dono revê na tela depois).

## Regras

1. **Quem ganha:** só o escritório da conta nova (o indicado). O indicador não ganha bônus.
2. **Quando concede:** quando existe a `Indicacao` do cadastro (`porCadastro`, `indicado_user_id` = a conta nova) **e** o
   e-mail da conta está confirmado. Dois gatilhos, mesma regra idempotente: `EmailDaContaConfirmado` (cadastro por
   e-mail, onde a conta nasce não confirmada) e `ContaCriadaPeloCadastro` quando a conta já nasce confirmada (Google).
   Sem confirmar o e-mail não há bônus (anti-fraude com e-mail descartável). Auto-indicação e conta já atribuída já são
   recusadas pela atribuição existente.
3. **Valor e prazo:** 5 GB por 30 dias corridos a partir da concessão. Valor e prazo são constantes nomeadas (não config
   nova).
4. **Uma vez por conta:** `UNIQUE (indicacao_id)`; a concessão é idempotente (repetir o evento não duplica). Não vale
   retroativamente: só concede quando a regra acima fecha depois do deploy (contas já confirmadas antes não são
   revisitadas; só os eventos novos concedem).
5. **Soma:** o bônus vigente (`expira_em` maior que agora) soma ao limite resolvido (plano ou limite do Master). **Sem
   limite ignora o bônus** (sem efeito). A leitura é por data, sem job de expiração: vencido, o limite volta ao do plano.
   Nada é apagado; só novos envios bloqueiam acima da cota (regra existente "reduzir não apaga").
6. **Aviso de vencimento por e-mail:** não. Só os avisos de faixa que já existem.
7. **Tela:** o texto "· você ganha 5 GB extras no 1º mês" aparece junto do "Cupom válido" na conferência ao vivo do
   cupom (PR do cupom ao vivo, #409), somente com cupom válido. O texto vem por tradução. Mostra o valor e o prazo
   reais (5 GB, 1º mês), então a tela só pode prometer o que as constantes concedem.
8. **Visível:** na página do espaço do escritório (Contribua) uma linha "Inclui 5 GB de bônus até dd/mm/aaaa" enquanto
   vigente; no painel do Master, a origem e a validade do bônus na conta. A concessão entra no `audit_log`.

## Dados

- Tabela nova `contribua_bonus_de_espaco` (migration **151600**, dada pelo coordenador): `id`, `tenant_id` (FK, índice),
  `indicacao_id` (FK, **UNIQUE**), `gigabytes` (int), `concedido_em`, `expira_em` (datetime_immutable como no resto do projeto, `TIMESTAMP(0) WITHOUT TIME ZONE`; índice em `expira_em`), `origem`
  (varchar, `indicacao`). Como `EspacoDoEscritorio`, **não** é `TenantAware` (decisão de lá: consultada só pelo
  Contribua), com repositório próprio e o teste de arquitetura equivalente ao `EspacoDoEscritorioSoNoContribuaArquiteturaTest`.
  Auditável.
- Reversível (`down` apaga a tabela).

## Onde mexe (mapa da investigação)

- `Contribua/Service/SituacaoDoEspaco::limiteEmBytes()` (único ponto que resolve o limite): soma o bônus vigente; o
  `RetratoDoEspaco` ganha `bytesBonus` e `bonusAte`. `retratarVarios` (painel Master) carrega os bônus **em lote** (uma
  consulta), sem N+1.
- Caso de uso novo no Contribua, `ConcederBonusDeEspacoPorIndicacaoUseCase`: dado o `userId` da conta nova, confere
  (Indicacao existe, e-mail confirmado, ainda sem bônus) e grava em transação. O Contribua não importa internals da
  Indicacao: o listener fica no domínio Indicacao e chama o caso de uso do Contribua pela classe pública; ele recebe
  o `indicacaoId` e o `tenantId` já resolvidos.
- Listeners (Indicacao): `EmailDaContaConfirmado` e, no `CadastroPeloLinkListener`, depois da atribuição, quando a conta
  já está confirmada. Falha ao conceder é registrada em log e não derruba o cadastro nem a confirmação (como o listener
  de hoje engole `Throwable`); a idempotência permite reprocessar.
- `DefinirLimiteDeArmazenamentoUseCase::exigirQueNaoFiqueAbaixoDoUso` não considera o bônus (compara com o limite
  base): conservador.
- O painel agregado do Master (`ConsultarVisaoGeralUseCase`) soma o limite com o bônus vigente.
- Planos e pedidos de espaço (`PedirEspacoUseCase`, pacotes da página) comparam com o limite base (sem o bônus).
- `AlterarPlanoUseCase` e troca de plano não perdem o bônus (ele é outra tabela).
- Fora: bônus manual pelo Master, bônus ao indicador, teto de bônus por indicador, aviso de vencimento.

## Riscos aceitos

- Cupom plantável por `GET /i/{codigo}` na sessão de terceiro (spec `indicar-bluejus`): agora há recompensa, mas o
  bônus vai para a conta **nova** que confirmou o e-mail dela; o indicador não ganha nada, então plantar não traz
  vantagem a quem planta. O risco restante é abusar do bônus criando contas novas com e-mail confirmado (cada uma vale
  5 GB por 30 dias); a mitigação é o e-mail confirmado e a validade curta.
- Ao vencer, o uso pode ficar acima do limite: bloqueia novos envios, não apaga, e os avisos de faixa existentes disparam.

## Testes

- Unit do caso de uso: concede (e-mail confirmado, indicação existente); não concede sem e-mail confirmado, sem
  indicação, duas vezes (idempotente) nem com o `userId` inexistente.
- Unit/integração de `SituacaoDoEspaco`: com bônus vigente soma ao limite do plano e ao do Master; vencido não soma;
  sem limite ignora; `retratarVarios` sem N+1 (uma consulta de bônus).
- Funcional: cadastro com cupom + confirmação do e-mail concede; cadastro pelo Google com cupom concede; sem cupom não;
  cupom inválido não; o upload acima do limite base e abaixo do limite com bônus passa; vencido, bloqueia.
- Arquitetura: o repositório do bônus só é usado no Contribua.
- Tela: a linha "Inclui 5 GB de bônus até ..." só com bônus vigente; o texto do cupom ao vivo só com cupom válido.
