---
tipo: alteracao
---
## Changelog
- Ponto Eletrônico, etapa E12 do desenho 16 (VS 08.11.26): fora do raio, a tela /ponto mostra a sede mais próxima, a distância e o raio, e oferece "Registrar como trabalho externo". A janela pede o local e o motivo (até 500 caracteres), e a batida segue para a análise do gestor. Sem internet, ela vai pela fila da E14 com os dois campos.
- Migration `Version20261109230000`: `trabalho_externo` e `motivo_trabalho_externo` em `registro_ponto`; `registro_ponto_id` em `justificativa_ponto` (ON DELETE SET NULL, com índice).
- O raio passa a considerar a precisão do GPS (`CercaDoPonto`, teto de 50 m). Com o GPS impreciso e a pessoa podendo estar dentro, o servidor responde 422 e a tela pede nova leitura. Nada que entrava antes passa a ser recusado.
- `POST /ponto/batida` aceita `trabalhoExterno` e `motivoTrabalhoExterno`. A batida entra sem sede e, na mesma gravação, cria uma justificativa "Trabalho Externo" em análise ligada a ela. Essa justificativa nunca abona saldo, e o colaborador não pode excluí-la nem editá-la. A folha mostra a etiqueta "Trabalho externo" e o subtítulo da justificativa diz a batida ("Entrada às 08:12").
- Trilha do Ponto: a batida de trabalho externo leva " · trabalho externo", entre " · home office" e " · sem internet" (`DetalheDaTrilhaDoPonto`).
- Aba Configurações: caixa "Permitir trabalho externo, com motivo e análise" (rota `ponto_configuracao_trabalho_externo`), ligada por padrão.

## Para o cliente
- Fora do raio da sede, o Ponto mostra a sede mais próxima e a distância, e oferece "Registrar como trabalho externo": você informa o local e o motivo, e a batida segue para a análise do gestor.
- A batida de trabalho externo conta normalmente enquanto está em análise. Na folha, o dia aparece com a etiqueta "Trabalho externo".
- Com o GPS impreciso, o Ponto pede para atualizar a localização antes de recusar a batida.
- Na trilha do Ponto, a batida de trabalho externo aparece marcada como "trabalho externo".
- O escritório decide, na aba Configurações, se permite o trabalho externo.
