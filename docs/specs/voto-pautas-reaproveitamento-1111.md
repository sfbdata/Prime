# Voto: reaproveitamento da base preservada para Pautas

Data: 11/11/2026. Estado: definição técnica concluída, sem implementação de produto.
Risco ALTO na alteração do registro e da apuração do voto. Spec alvo para implementação e revisão.

## Objetivo e autorização

Samuel autorizou definir o reaproveitamento após a revisão do trabalho não commitado. A implementação da página deve respeitar a comparação antes/depois apresentada e o aceite das mudanças conforme AGENTS.md. O pedido de votar em até N nomes e mostrar os N mais votados já estava aprovado nos logs de 09/11, não depende de nova decisão.

Referência visual atual: `docs/design/voto/export-2026-11-11/consulta/voto.dc.html`. Original intacto na pasta superior. A comparação confirmou equivalência com o desenho atualizado no master pelo PR #719. Decisões e prova de produção: `handoff/revisao-voto-preservado-2026-11-11.md`.

## Decisão de reaproveitamento

Evoluir o modelo da assembleia publicado. Não integrar a worktree `voto-eleicao` inteira nem substituir a assembleia por um modelo paralelo de eleição para entregar Pautas.

| Peça preservada | Destino |
|---|---|
| Enums RegraDeDecisao e BaseDaDecisao | Reaproveitar isoladamente, conferindo nomes e valores com desenho e testes |
| Validações de dígitos e quórum em CargoDaEleicao | Adaptar regras para PautaDaAssembleia; usar exceção e transação do fluxo publicado |
| Testes de invariantes e limites | Aproveitar cenários de comportamento, reescrever as fixtures para assembleia |
| Eleicao, EventoDoVoto, CargoDaEleicao, CandidaturaDaEleicao e seus repositórios | Preservar como trabalho histórico; não introduzir neste incremento |
| JsonCanonico, ConfiguracaoPublicaDaEleicao e selo | Preservar para a etapa de configuração verificável; não necessários para ampliar pautas agora |
| TransacaoDoVoto e migration 170000 preservadas | Não copiar: colidem com contratos e histórico publicados |

A base antiga também não tem vagas no CargoDaEleicao nem as inclui no selo. Portanto não resolve sozinha múltiplos nomes ou a fidelidade completa do desenho atual.

## Contratos de negócio

### Preparação

Quem: gestor já autorizado no módulo. Objetivo: definir os critérios da pauta antes de publicar. Pré-condição: assembleia em rascunho e do escritório atual.

Fluxo: AdicionarPautaUseCase/EditarPautaUseCase recebem DTO validado, conferem posse e rascunho, aplicam configuração e opções, persistem na transação existente. PublicarAssembleiaUseCase confere consistência das pautas e congela sua configuração. Dados inválidos e tentativa de editar publicada são recusados sem escrita parcial.

Configuração alvo: critério (mais, absoluta, dois, unanime), base (validos, presentes, aptos), dígitos de 1 a 5, quórum da primeira/segunda convocação e vagas. Preservar padrões legados: mais votos, válidos, um dígito, uma vaga e quórum 50%/0%. Migração incremental nova; nunca editar migration publicada nem recalcular resultado histórico silenciosamente.

Os presets e seus valores devem ser tirados do desenho, não de regras jurídicas presumidas. Limites exatos de vagas e compatibilidade dos campos devem ser confirmados na região do desenho durante a implementação, sem inventar teto.

### Registro de múltiplas escolhas

Quem: unidade apta pelo link já existente. Objetivo: registrar uma única cédula, com até N nomes distintos ou abstenção.

Pré-condições: pauta da assembleia da unidade, aberta e no prazo; unidade ainda sem participação. Validar todas as escolhas antes de alterar contadores: pertencimento à pauta, quantidade permitida, ausência de duplicadas e abstenção exclusiva. Lista vazia e requisição malformada não viram abstenção implicitamente.

Na transação publicada, manter a trava da pauta e a restrição única de participação por unidade/pauta. Gravar uma participação e incrementar cada opção escolhida com o peso da unidade. Preservar a atualização de TODAS as opções, inclusive as não escolhidas, usada pelo repositório atual para evitar que a mudança de linha revele a escolha. Não chamar o registro atual N vezes. Erro em qualquer escolha desfaz o conjunto. Não persistir vínculo entre participação/unidade e escolhas; manter o sigilo prático atual. Preservar POST de escolha única ou adaptá-lo com compatibilidade explícita.

### Apuração

- Peso e quantidade de participantes vêm das participações, contando cada unidade uma vez. `ParticipacaoNaPautaRepository::contarPorPauta()` já fornece esses valores e filtra escritório. Não somar os contadores de opções para calcular presença ou quantidade de votantes em múltipla escolha.
- Contadores das opções continuam medindo escolhas e seus pesos. Diferenciar votos em candidatos de participantes nas DTOs e textos que exibem totais.
- Para vaga única: mais votos, maioria absoluta (mais da metade), dois terços (pelo menos dois terços) e unanimidade conforme a base escolhida e o quórum. Manter BCMath com strings decimais, não transportar os floats do protótipo.
- Para múltiplas vagas: seguir o desenho, selecionar até N opções com peso positivo e conferir empate na última vaga contra a próxima opção. O protótipo retorna empate e não proclama vencedores nesse caso. Empate entre eleitos sem atingir o corte não impede eleição.
- O protótipo aplica os três critérios de maioria apenas para vaga única; para múltiplas vagas usa ranking e quórum (`bj-voto-nucleo.js:37`). Preservar esse comportamento na fidelidade. Se o produto exigir outra combinação, registrar decisão antes de desviar.
- Resultados, ata e todas as apresentações devem entender a lista de vencedores. Não implementar múltipla escolha apenas na cédula deixando apuração/ata antigas.

## Sequência de implementação

1. Abrir frente isolada a partir do master remoto atual, sem tocar na raiz conflitada nem na worktree preservada. Conferir PRs/migration em andamento.
2. Escrever testes de comportamento atual e novo: critérios, quóruns, limites, configuração congelada, até N escolhas e contagem única de participação. Functional deve cobrir HTTP, autorização, isolamento entre escritórios, publicação e voto repetido. Analisar UseCases antes das demais camadas.
3. Implementar campos e nova migration aditiva, critérios e DTOs, mantendo os defaults do legado. Gerar migration pelo ritual da skill e conferir o SQL real.
4. Conectar página de Pautas, cédula, resultados e ata com os componentes e medidas do desenho. Preservar funções existentes. Testar ordenação/layout por filho direto quando aplicável.
5. Rodar testes direcionados, revisão read-only contra esta spec, corrigir e re-revisar por risco ALTO, depois gates requeridos e suíte completa. Smoke visual do Samuel. PR pequeno com mudancas/. Não publicar nem fazer merge.

## Provas de aceitação

- Pauta e voto antigos continuam funcionando e conservam resultados históricos.
- Uma unidade escolhendo três nomes continua sendo uma participante, com seu peso contado uma vez no quórum.
- Duplicada, excesso, opção externa, abstenção misturada e repetição da cédula são recusados sem contadores parciais.
- Concorrência com encerramento mantém a garantia atual; votação vencida é recusada.
- Frações, limites exatos dos critérios, zero votos, empate no corte e menos candidatos votados que vagas têm testes explícitos.
- Publicação congela também regras, vagas, dígitos, opções e ordenação; mudanças recusadas não alteram o banco.
- Página segue o trecho do desenho aprovado; resultados e ata mostram todos os eleitos e situações corretas.

## Limites desta definição

Não foram criadas tabelas, alterados dados, executados testes nem copiados arquivos de produto. Implementação de criptografia, novo portal, outros cenários, procurações e modelo completo de eleições ficam nas respectivas etapas. Os achados da revisão permanecem documentados na worktree histórica; este plano evita importar os dois conflitos e exige a guarda de edição na adaptação.
