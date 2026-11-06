---
tipo: correcao
---
## Changelog
- Tela do ponto: depois da batida com sucesso, a tela não recarrega mais. Ela busca o próprio HTML (`PontoBatida.buscarTelaDoPonto`, com prazo de 15 s) e troca só a lista do dia, o banco de horas, as pendências, o card da folha, o estado do relógio (previsão de saída, repouso, excedente) e a sugestão do tipo. A troca é tudo ou nada; se falhar, recarrega como antes.
- A recarga zerava a liberação de som do navegador, e o toque da volta do intervalo e da saída não tocava sem um clique.
- A permissão de notificação do sistema passa a ser pedida no clique da batida (tela do ponto e atalho do cabeçalho), só quando a pessoa ainda não respondeu.
- A notificação do alerta não mostra mais "Horário:" vazio no retorno e na saída, e a da saída não substitui mais a do retorno (cada tipo com sua `tag`).
- Spec: `docs/specs/ponto-atualiza-sem-recarregar.md`.

## Para o cliente
- O aviso sonoro da ida ao intervalo, da volta e do fim da jornada agora toca mesmo com o BlueJus em outra aba ou com o navegador minimizado, desde que a tela do ponto onde você registrou continue aberta. Antes, depois de registrar o intervalo, o som da volta só saía depois de um clique na tela.
- Depois de registrar o ponto, a tela se atualiza na hora, sem piscar nem recarregar: as batidas do dia, o banco de horas e o botão já com o próximo registro.
- Ao registrar o ponto, o navegador pergunta se pode mostrar notificações. Permitindo, o lembrete do ponto aparece no canto da tela mesmo com o navegador minimizado.
