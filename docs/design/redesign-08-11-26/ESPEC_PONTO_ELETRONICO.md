# Ponto Eletrônico: regras para o sistema real

O protótipo (16 - Ponto Eletronico) guarda tudo no navegador. Em produção, os pontos abaixo são obrigatórios no backend.

## Registro
- A hora da batida vem sempre do servidor (NTP); a hora do aparelho só é guardada como informação.
- Raio validado no servidor contra `ponto_sede` (lat, lng, raio), usando a precisão informada pelo GPS.
- Batida fora do raio só entra como trabalho externo, com motivo e análise do gestor.
- Alertas de intervalo e interjornada nunca bloqueiam: a batida precisa refletir o horário real.
- Sem internet: fila local cifrada, com a hora do aparelho e a marca de sincronização posterior; o servidor registra as duas horas.

## Integridade
- `ponto_batida` é somente inserção. Correção é registro novo em `ponto_ajuste` (batida, campo, valor anterior, valor novo, autor, data, justificativa).
- `ponto_auditoria` com hash encadeado (cada linha assina a anterior).
- Comprovante e folha gerados no servidor, com HMAC; a rota pública `/ponto/validar/{codigo}` consulta o registro. O código exibido no protótipo é calculado no navegador.

## Facial
- Exige fornecedor de biometria com prova de vida; o protótipo apenas usa a câmera e simula a comparação.
- Guardar template, não foto, salvo opção do escritório, com prazo de retenção e exclusão automática.
- Autorização registrada; quem não autoriza registra pelos demais métodos, sem penalidade.

## Tabelas
`ponto_batida`, `ponto_ajuste`, `ponto_justificativa`, `ponto_comprovante`, `ponto_folha_aceite`, `ponto_sede`, `ponto_feriado`, `ponto_escala`, `ponto_banco_lote`, `ponto_config_escritorio`, `ponto_auditoria`. As chaves globais ficam em `recurso_estado` (ids `ponto-*`).

## A confirmar antes do lançamento
- Vigência da Portaria MTP 671/2021 diante da Portaria Consolidada MTE nº 1/2025 (texto do DOU não conferido) e requisitos para REP-P.
- Vigência do art. 13 da Portaria 3.626/1991, citado na folha.
- Prazos do banco de horas conforme o acordo de cada escritório.
