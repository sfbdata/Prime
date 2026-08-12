---
description: Atualiza a cobrança em produção pelo canal restrito — baixa as planilhas da contábil, envia para a VPS e SIMULA as 3 carteiras. Para antes de gravar e devolve os números ao humano. Só grava com autorização explícita.
argument-hint: (opcional) "simular" para parar no ensaio · AAAA-MM-DD para reusar um lote já baixado
---

Conduza o ciclo de atualização da cobrança em produção. **Você opera; a decisão de
gravar é do humano.** Runbook completo: `docs/gestao-cobrancas/RUNBOOK_IMPORTACAO_REMOTA.md`.
Spec e decisões: `docs/specs/cobranca-importacao-prod-remota.md`.

## Regra que não se negocia

**Nunca rode `importar … --confirmar` sem autorização explícita do humano nesta
conversa.** Autorização dada para uma carteira não vale para as outras, e
autorização de ontem não vale hoje. Na dúvida, pare e pergunte.

O canal é restrito por construção (chave SSH amarrada a `command=` forçado), mas a
tranca protege contra comando arbitrário — **não** contra gravar dinheiro na hora
errada. Essa parte é sua.

## Passo a passo

1. **Linha de base, antes de tudo.** `scripts/importar-lote-prod.sh estado`.
   Guarde a emissão registrada de cada carteira: é contra ela que o resultado
   será comparado no fim. Se o `sha256` divergir, o script recusa sozinho e diz
   o que fazer — não tente contornar.

2. **Baixar o lote**, salvo se `$ARGUMENTS` trouxer uma data de lote já existente:
   `scripts/importar-lote-prod.sh emitir` (leva ~2 min; sai para o sistema da
   contábil com a credencial da secretária). Confirme os 15/15 arquivos.

3. **Enviar:** `scripts/importar-lote-prod.sh enviar`. O wrapper confere nome,
   tipo e tamanho de cada arquivo e recusa o lote inteiro se algo destoar.

4. **Simular as 3 carteiras:** `scripts/importar-lote-prod.sh simular`.
   ⚠️ **Salve a saída num arquivo** (`> arquivo.txt 2>&1`) antes de resumir: ela
   passa de 2.000 linhas e o retorno do terminal corta as primeiras carteiras.

5. **Ler a simulação de verdade e reportar ao humano** — ver a seção seguinte.
   **PARE AQUI** se `$ARGUMENTS` disser "simular", ou em qualquer caso até o
   humano autorizar.

6. **Com autorização, gravar carteira por carteira**, conferindo entre uma e
   outra, da menor para a maior: `amli_br_060` → `top_life_1` → `top_life_2`.
   Comando: `scripts/importar-lote-prod.sh importar <data> <carteira> --confirmar`.
   Se um passo falhar, a carteira para ali — reporte e **não siga** para a próxima.

7. **Conferir o resultado:** `estado` de novo. A emissão tem de ter avançado nos
   4 tipos das 3 carteiras, e os números da execução têm de bater com a simulação.
   Divergência aqui é achado, não detalhe.

## Como ler a simulação (é onde se erra)

**Nem todo número da simulação é previsão.** Os passos 3 a 5 consultam o banco, e
na simulação os passos 1 e 2 não gravaram — então eles projetam contra o estado
anterior ao lote:

| Número | Vale como |
|---|---|
| passo 1 (cadastro), tudo | previsão |
| passo 2 — obrigações, casos, rejeitadas, ignoradas, sacados divergentes | previsão |
| passo 2 — objetos e pessoas criados | **teto** |
| passos 3 a 5 — o que será criado | **teto** |
| passo 3 — *"em obrigação que JÁ existia"* | **piso** (tem de subir na execução real) |

Teto vindo menor na execução real não é defeito. Piso vindo maior também não.
**Obrigação do passo 2 divergindo é**, e merece investigação antes de seguir.

## O que NÃO é problema (não alarme o humano à toa)

- **Rejeições do tipo "boleto sem principal de dívida (apenas encargos/honorários)"**
  — é a regra da Etapa 7 funcionando.
- **O aviso da §9.1** ("recebimentos sem principal"). **Não é decisão pendente**:
  recebimento sem principal é parcela de acordo, e ali não ter principal é normal.
  Medido em prod em 11/08: as 33 obrigações de valor zero são todas parcela de
  acordo. Só merece atenção a linha *"estão sem acordo na coluna J"* — essa sim.
- **"Parcelas que constam LIQUIDADAS na planilha… a baixa NÃO foi feita"** — rótulo
  que mente: o importador nunca checa a baixa.
- **"O saldo devedor das unidades listadas mudou"** — sai mesmo com tudo zero.
- **"Contas que NUNCA tiveram boleto"** — é descrição, não ação.

## O que MERECE parar e perguntar

- Unidade ou pessoa nova em quantidade fora do padrão (o normal tem sido **zero**).
- Acordo novo — diga qual, de quem, quanto entra e quanto sai do saldo.
- Classe de conta fora do mapa conhecido: dinheiro pode estar indo para o balde
  errado, e o total fecha do mesmo jeito.
- Qualquer passo com falha.

## Ao reportar

Traga os números por carteira (recebimentos, valor, obrigações atualizadas,
unidades/pessoas novas, acordos novos) e o total. Linguagem simples — quem lê
decide sobre dinheiro, não sobre código. Diga o que destoa da linha de base do
passo 1, e diga explicitamente quando **nada** destoa.

## Se a conexão cair no meio

**Não repita às cegas.** O processo sobrevive dentro do container; o que se perde é
a saída na tela. Rode `estado` e leia o fim do registro: `FIM VALENDO :: <carteira>`
significa que terminou. Só `INÍCIO` significa que ainda estava rodando — espere e
consulte de novo. O `flock` **não** protege contra o processo órfão; a checagem
está no runbook.
