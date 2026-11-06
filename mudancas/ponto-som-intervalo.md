---
tipo: novidade
---
## Changelog
- Ponto: o alerta da ida ao intervalo (`repouso`) também toca som, com um 3º toque (duas notas iguais, agudas), distinto da volta (duas subindo) e do fim da jornada (três descendo). Mesmas regras: um toque por tipo por dia, "Sons de alerta" do Meu Perfil e `alertaHabilitado` da jornada. A entrada e o aviso das 6 horas continuam sem som.
- Meu Perfil: logo abaixo de "Sons de alerta", "Ouvir os toques do ponto" com um botão por toque (Ida ao intervalo, Volta do intervalo, Fim da jornada). Só para quem recebe o alerta de ponto (módulo ponto e jornada no escritório). O desenho 07 não tem esta linha: botões de contorno do próprio perfil (`mp-btn-contorno`, compacto).
  - `ponto-alerta-som.js`: `ouvir(tipo)` toca na hora (o clique libera o áudio), sem marcar o dia e sem olhar a chave; carregado no Meu Perfil e no modal, liga uma vez só.
  - Testes: `SomDoAlertaDePontoTest` ajustado, `OuvirSonsDoPontoNoPerfilTest`.

## Para o cliente
- O alerta do ponto agora também toca um som na hora de ir para o intervalo. São três toques diferentes: ida ao intervalo, volta do intervalo e fim da jornada.
- Em Meu Perfil, logo abaixo de "Sons de alerta", há um botão "Ouvir" para cada toque, para conhecer os sons antes de eles tocarem. Os botões aparecem para quem registra ponto.
- Para desligar os sons, use a chave "Sons de alerta" em Meu Perfil.
