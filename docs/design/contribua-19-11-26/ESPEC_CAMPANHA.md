# Modo campanha: BlueJus Black (Desafio R$ 1)

## Conceito
Inscrição opcional de valor simbólico, com garantia de devolução multiplicada (dobro ou triplo, definido pelo Master) para quem não gostar. O BlueJus continua gratuito; o espaço de armazenamento continua na modalidade atual.

## Fonte única de verdade
Uma configuração (tabela campanha) gera: aba do topo, página da campanha, regras, card e FAQ da tela Contribua. Nenhum texto de oferta é escrito à mão em outro lugar.

campanha (id, nome, chamada, preco, multiplicador, prazo_pedido_dias, prazo_pagamento_dias_uteis, inicio, fim, vagas, mostrar_contador, aba_topo, beneficios[], ativa, versao_regras, criado_por, criado_em)

participacao (id, campanha_id, versao_regras, usuario_id, cpf_hash, oab, valor_pago, multiplicador, pix_txid, pago_em, status [aguardando_pagamento, ativa, devolucao_pedida, devolvida, arrependimento], aceite_regras_em, ip)

devolucao (id, participacao_id, valor, chave_pix_cifrada, motivo, pedido_em, pago_em, comprovante_e2e_id, pago_por)

## Regras de negócio
1. Inscrição só vale depois da confirmação do Pix pelo banco (webhook). O protótipo apenas simula e avisa que nada foi cobrado.
2. Uma inscrição por CPF e por inscrição na OAB (único no banco, CPF guardado como hash).
3. Multiplicador e regras ficam congelados na inscrição (versao_regras). Mudanças do Master valem só para novas inscrições; reduzir benefícios com a campanha no ar é vedado na tela.
4. Devolução: pedido em até N dias da inscrição, sem exigir justificativa; pagamento por Pix em até X dias úteis, com comprovante (E2E id) gravado.
5. Arrependimento (CDC, art. 49): devolução integral do valor pago em até 7 dias, independentemente do Desafio.
6. Pedido de devolução encerra só os benefícios da campanha; nenhum dado ou pasta é apagado.
7. Contador de inscritos só com números reais.
8. Trilha de auditoria de toda alteração de configuração (quem, quando, antes e depois).

## Pontos para validação jurídica antes de publicar
- Texto da oferta e regras (CDC, arts. 30, 31, 37 e 49; Decreto 7.962/2013, comércio eletrônico).
- Confirmar que a mecânica não se enquadra como distribuição gratuita de prêmios (Lei 5.768/1971): não há sorteio, concurso ou vale-brinde; todos que pedem recebem.
- Uso do termo "Black Friday" e de marcas de terceiros (não usar logotipo da OAB nas peças da campanha).
- LGPD: finalidade e retenção de CPF e chave Pix.
- Tratamento contábil e fiscal das devoluções acima do valor pago.
