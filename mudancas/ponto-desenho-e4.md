---
tipo: alteracao
---
## Changelog
- Ponto Eletrônico, etapa E4 do desenho 16 (VS 08.11.26): as janelas "Justificar falta", "Registro de esquecimento" e "Editar justificativa" da /ponto saem do modal do Bootstrap para a moldura do desenho (`role="dialog"`, Esc fecha, clicar fora não fecha, foco preso, erro na linha do desenho). Comportamento em `ponto-janelas.js`, sobre a moldura do `ponto-abas.js`.
- "Motivo" obrigatório ao justificar e ao pedir esquecimento (P44 e P46): coluna nova `motivo` em `justificativa_ponto` (migration `Version20261109210000`), até 2000 caracteres, o mesmo em cada dia do lote. Aparece na aba "Minhas Justificativas", na janela de editar e na ficha do gestor.
- Horário do registro de esquecimento com segundos (P46).
- Na edição, o motivo novo vale para todos os dias do lote ainda em análise, e o horário esquecido gravado com segundos só muda se a hora ou o minuto mudar.
- O tipo da justificativa aparece nos 6 grupos (P45), na janela de justificar e na de editar.
- A janela alta (a da falta, com o calendário) rola quando passa da altura da tela, em vez de esconder o topo.
- Ficam do sistema dentro da moldura nova (P45 e P47): vários dias de uma vez, os tipos, o abono parcial com horário, o anexo PDF, JPG ou PNG e a edição da justificativa em análise. Os formulários da ficha do gestor não mudam.
- Depois de editar uma justificativa, a tela volta na aba "Minhas Justificativas", no mês dela.

## Para o cliente
- No Ponto Eletrônico, as janelas de justificar falta, de pedir o registro de um horário esquecido e de editar a justificativa ganharam o visual novo.
- Ao justificar uma falta ou pedir um registro esquecido, você escreve o motivo. O gestor vê esse motivo ao analisar, e você o vê na lista das suas justificativas.
- O horário esquecido pode ser informado com os segundos.
- Ao editar o motivo de uma justificativa de vários dias, ele muda em todos os dias que ainda estão em análise. Editar só o motivo não altera o horário esquecido que você informou.
- Os tipos de justificativa aparecem separados por grupo, o que facilita achar o certo.
- Em telas baixas e no celular, a janela de justificar falta rola até o topo.
- Depois de editar uma justificativa, a tela volta para a lista das suas justificativas, no mês dela.
