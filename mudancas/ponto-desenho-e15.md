---
tipo: alteracao
---
## Changelog
- Ponto Eletrônico, etapa E15 do desenho 16 (VS 08.11.26), fase A: número sequencial do registro (NSR) por escritório em toda batida que a própria pessoa registra, sem buraco e sem repetir (D2; `NumeroSequencialDoRegistro`, contador `ponto_contador_nsr` com `INSERT ... ON CONFLICT ... RETURNING` na transação da batida, `RegistrarBatidaDaPessoaUseCase`). Inclusão do gestor e batida de esquecimento abonado não recebem NSR (viram ajuste na E10).
- Comprovante da batida (P48, P49): tabela `ponto_comprovante` (NSR, código `BJP-XXXX-XXXX-XXXX`, HMAC, versão da chave, dados assinados), aba "Comprovantes" na /ponto com "Baixar" e "Validar código", e a imagem PNG do comprovante gerada no servidor com QR Code, marca d'água e padrões de segurança. A assinatura passa pela porta `SeloDoComprovante`; nesta fase ela fica indisponível, então nada é emitido e a aba não aparece até o segredo do HMAC existir (fase B, com `ChavesDoHmacDoPonto`).
- Validação pública (P50, D20): `/ponto/validar/{codigo}` sem login, com limite de 20 consultas por minuto por IP (`ponto_validacao`), "Comprovante autêntico." só quando o código existe e o HMAC confere, "Código não localizado." igual para código errado ou inexistente; mostra o nome inteiro, sem CPF nem localização; `noindex`. Nomes das rotas públicas sem o prefixo `ponto_`, para não caírem quando a tela do Ponto for desligada.
- Chave `ponto-comprovante` no catálogo de recursos, ligada. A purga de escritório apaga os comprovantes e o contador antes das batidas. Migration `Version20261109240000`.

## Para o cliente
- Cada registro de ponto passa a receber um número sequencial do escritório, que nunca se repete nem pula.
- O Ponto ganha a base do comprovante de registro: uma imagem com os dados da batida, um código de validação e um QR Code. A aba "Comprovantes" aparece quando a emissão for ligada.
- Qualquer pessoa pode conferir se um comprovante é verdadeiro pelo QR Code ou pelo código, sem precisar entrar no sistema. A página mostra só o nome do trabalhador e os dados da batida, sem CPF nem localização.
