---
tipo: alteracao
---
## Changelog
- Ponto Eletrônico, etapa E8 do desenho 16 (VS 08.11.26): a batida abre a validação em tela cheia (relógio completando a volta, "Horário oficial confirmado", "Localização validada", "Registro selado") e termina na confirmação verde ou no erro vermelho, com "Tentar novamente" e "Fechar" (`js/ponto-tela-cheia.js`, `templates/ponto/_tela_cheia.html.twig`).
- Fora do raio, a tela vermelha traz "Registrar como trabalho externo" quando o escritório permite (ligação com a E12).
- Aviso flutuante (`js/ponto-aviso-flutuante.js`) no lugar da caixa de resultado e dos flashes de envio, abono e recusa de justificativa na /ponto, inclusive o "Enviado para aprovação do gestor.".
- Sons de confirmação e de erro sem arquivo de áudio (`js/ponto-sons.js`), vibração no celular e botão para silenciar no rodapé do cartão, guardado no aparelho.
- Lembrete de saída: depois do horário previsto sem a saída batida, uma vez por dia, com som, aviso e notificação do navegador quando já permitida.
- Chaves novas no catálogo do Master, ligadas por padrão: `ponto-animacao`, `ponto-sons` e `ponto-lembrete`.
- A janela do alerta de horário passa para a moldura de janela do desenho, em toda página (`css/ponto-alerta-janela.css`), com os mesmos textos, som e notificação. Os outros avisos globais continuam esperando por ela.

## Para o cliente
- Ao bater o ponto, a tela mostra a validação e depois a confirmação em verde, ou o motivo em vermelho com o botão para tentar de novo.
- Fora do raio da sede, a tela vermelha oferece registrar a batida como trabalho externo, quando o escritório permite.
- O Ponto toca um som curto na confirmação e outro no erro, e o celular vibra. O botão de som no cartão silencia os sons.
- Passou do horário de saída e você não registrou? O Ponto lembra uma vez por dia.
- Os avisos de envio, abono e recusa de justificativa aparecem num aviso flutuante na parte de baixo da tela.
- O escritório pode desligar cada uma dessas funções.
